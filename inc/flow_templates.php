<?php
/**
 * Flow template library.
 *
 * Each template is a small helper that seeds a full working flow into
 * flows + flow_nodes + flow_edges for one workspace. The templates
 * mirror the shape and safety guarantees of fnb_seed_starter_flow():
 *
 *  - Wrapped in a transaction — a mid-seed failure leaves no orphans
 *  - Set the flow's entry_node_id at the very end (after all nodes
 *    exist), so the flow_engine never lands on an incomplete row
 *  - Accept a $goLive flag — true ships as status=active +
 *    trigger=new_conversation; false ships as draft + keyword trigger
 *    so the operator can review before flipping live
 *
 * Registration:
 *  - Add an entry to flow_templates_registry() with a builder callable
 *  - The gallery on /admin/flows.php picks it up automatically
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/fnb_helpers.php';   // reuses fnb_seed_starter_flow for F&B ordering

function flow_templates_registry(): array
{
    return [
        [
            'key'          => 'fnb_ordering',
            'name'         => 'F&B ordering flow',
            'category'     => 'F&B',
            'icon'         => '🍜',
            'description'  => 'Ask delivery vs pickup → send menu → AI parses cart → collect name + address → create order.',
            'requires_fnb' => true,
            'builder'      => 'flow_template_fnb_ordering',
        ],
        [
            'key'          => 'restaurant_reservation',
            'name'         => 'Restaurant reservation',
            'category'     => 'F&B',
            'icon'         => '📅',
            'description'  => 'Take table reservations — asks date, time, party size, name, and phone; saves as an internal note for staff to confirm.',
            'requires_fnb' => false,
            'builder'      => 'flow_template_restaurant_reservation',
        ],
        [
            'key'          => 'appointment_booking',
            'name'         => 'Appointment booking',
            'category'     => 'Services',
            'icon'         => '🩺',
            'description'  => 'Clinic / salon / studio bookings — asks service, preferred date, name, and phone; hands off to a human to confirm.',
            'requires_fnb' => false,
            'builder'      => 'flow_template_appointment_booking',
        ],
        [
            'key'          => 'faq_triage',
            'name'         => 'FAQ triage / menu picker',
            'category'     => 'General',
            'icon'         => '💬',
            'description'  => 'Greet the customer with 4 numbered choices — menu / hours / location / speak-to-us — and reply with the matching info.',
            'requires_fnb' => false,
            'builder'      => 'flow_template_faq_triage',
        ],
        [
            'key'          => 'lead_qualifier',
            'name'         => 'Lead qualifier',
            'category'     => 'Sales',
            'icon'         => '🎯',
            'description'  => 'Ask name, business, what they need, and rough budget; assign the conversation to the sales department for follow-up.',
            'requires_fnb' => false,
            'builder'      => 'flow_template_lead_qualifier',
        ],
    ];
}

function flow_templates_lookup(string $key): ?array
{
    foreach (flow_templates_registry() as $t) {
        if ($t['key'] === $key) return $t;
    }
    return null;
}

// ---------------------------------------------------------------------
// Shared helpers used by every builder
// ---------------------------------------------------------------------

/**
 * Create the flow row + return its id + a $node() closure the builder
 * can call to insert nodes cheaply, and a $wire() closure to set
 * next_node_id after all nodes exist.
 */
function flow_template_bootstrap(PDO $db, int $companyId, int $userId,
                                  string $name, bool $goLive, string $keyword = ''): array
{
    $status  = $goLive ? 'active'           : 'draft';
    $trigger = $goLive ? 'new_conversation' : 'keyword';
    $kw      = $goLive ? null               : ($keyword ?: 'start');

    $db->prepare(
        'INSERT INTO flows (company_id, name, trigger_type, trigger_keywords, status, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$companyId, $name, $trigger, $kw, $status, $userId]);
    $fid = (int)$db->lastInsertId();

    $nins = $db->prepare(
        'INSERT INTO flow_nodes (flow_id, node_type, label, config) VALUES (?, ?, ?, ?)'
    );
    $node = function (string $type, string $label, array $config = []) use ($nins, $fid, $db): int {
        $nins->execute([$fid, $type, $label, json_encode($config, JSON_UNESCAPED_UNICODE)]);
        return (int)$db->lastInsertId();
    };

    $upd = $db->prepare('UPDATE flow_nodes SET next_node_id = ? WHERE id = ?');
    $wire = function (array $nextMap) use ($upd): void {
        foreach ($nextMap as $from => $to) $upd->execute([$to, $from]);
    };

    return [$fid, $node, $wire];
}

function flow_template_finalize(PDO $db, int $flowId, int $entryNodeId): void
{
    $db->prepare('UPDATE flows SET entry_node_id = ? WHERE id = ?')
       ->execute([$entryNodeId, $flowId]);
}

// ---------------------------------------------------------------------
// Templates
// ---------------------------------------------------------------------

/**
 * F&B — reuses the existing fnb_seed_starter_flow helper.
 */
function flow_template_fnb_ordering(PDO $db, int $companyId, int $userId, bool $goLive): int
{
    return fnb_seed_starter_flow($db, $companyId, $userId, $goLive);
}

/**
 * Restaurant reservation — grabs date / time / party size / name / phone
 * and saves an internal note for staff to confirm out-of-band.
 */
function flow_template_restaurant_reservation(PDO $db, int $companyId, int $userId, bool $goLive): int
{
    $db->beginTransaction();
    try {
        [$fid, $node, $wire] = flow_template_bootstrap(
            $db, $companyId, $userId, 'Restaurant reservation (starter)', $goLive, 'reserve,reservation,booking,table'
        );

        $nHello  = $node('send_message', 'Greet + ask date',
            ['text' => "Hi 👋 Happy to help with a reservation.\n\nWhat date would you like to come in? (e.g. \"tomorrow\", \"20 Aug\")"]);
        $nDate   = $node('wait_reply',   'Wait for date',       ['var_name' => 'reservation_date']);
        $nTime   = $node('send_message', 'Ask time',
            ['text' => "Got it — {{reservation_date}}. What time works for you? (e.g. \"7pm\")"]);
        $nWTime  = $node('wait_reply',   'Wait for time',       ['var_name' => 'reservation_time']);
        $nPax    = $node('send_message', 'Ask party size',
            ['text' => "How many people?"]);
        $nWPax   = $node('wait_reply',   'Wait for party size', ['var_name' => 'party_size']);
        $nName   = $node('send_message', 'Ask name',
            ['text' => "What name should we put on the reservation?"]);
        $nWName  = $node('wait_reply',   'Wait for name',       ['var_name' => 'customer_name']);
        $nPhone  = $node('send_message', 'Ask contact number',
            ['text' => "And a contact number in case we need to reach you?"]);
        $nWPhone = $node('wait_reply',   'Wait for phone',      ['var_name' => 'customer_phone']);
        $nNote   = $node('save_note',    'Save reservation note',
            ['template' => "📅 Reservation request\nDate: {{reservation_date}}\nTime: {{reservation_time}}\nParty: {{party_size}}\nName: {{customer_name}}\nPhone: {{customer_phone}}"]);
        $nBye    = $node('send_message', 'Confirm and hand off',
            ['text' => "Thanks {{customer_name}}! We've saved your request for {{party_size}} on {{reservation_date}} at {{reservation_time}}. Our team will confirm shortly by WhatsApp. 🙏"]);
        $nEnd    = $node('end',          'End');

        $wire([
            $nHello  => $nDate,   $nDate  => $nTime,   $nTime  => $nWTime,
            $nWTime  => $nPax,    $nPax   => $nWPax,   $nWPax  => $nName,
            $nName   => $nWName,  $nWName => $nPhone,  $nPhone => $nWPhone,
            $nWPhone => $nNote,   $nNote  => $nBye,    $nBye   => $nEnd,
        ]);
        flow_template_finalize($db, $fid, $nHello);

        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Appointment booking — service / date / name / phone, then assigns
 * to the first department found (Sales / Bookings / Support fallback).
 */
function flow_template_appointment_booking(PDO $db, int $companyId, int $userId, bool $goLive): int
{
    $db->beginTransaction();
    try {
        [$fid, $node, $wire] = flow_template_bootstrap(
            $db, $companyId, $userId, 'Appointment booking (starter)', $goLive, 'book,booking,appointment,slot'
        );

        // Try to find a plausible department to route to — Bookings /
        // Reservations / Sales / Support / whatever exists first. Null
        // is safe: the assign_dept node no-ops if department_id = 0.
        $deptId = 0;
        try {
            $s = $db->prepare(
                "SELECT id FROM departments
                 WHERE company_id = ? AND status = 'active'
                 ORDER BY FIELD(LOWER(name),'bookings','reservations','sales','front desk','support') DESC, id ASC
                 LIMIT 1"
            );
            $s->execute([$companyId]);
            $deptId = (int)($s->fetchColumn() ?: 0);
        } catch (Throwable $e) { /* ok */ }

        $nHello  = $node('send_message', 'Greet + ask service',
            ['text' => "Hi! 👋 Which service would you like to book? (e.g. haircut, dental cleaning, yoga class)"]);
        $nSvc    = $node('wait_reply',   'Wait for service',  ['var_name' => 'service']);
        $nDate   = $node('send_message', 'Ask date + time',
            ['text' => "Great — {{service}}. What day and time works for you? (e.g. \"Sat 3pm\")"]);
        $nWDate  = $node('wait_reply',   'Wait for date',     ['var_name' => 'preferred_slot']);
        $nName   = $node('send_message', 'Ask name',
            ['text' => "What name should we put on the booking?"]);
        $nWName  = $node('wait_reply',   'Wait for name',     ['var_name' => 'customer_name']);
        $nPhone  = $node('send_message', 'Ask phone',
            ['text' => "And your contact number?"]);
        $nWPhone = $node('wait_reply',   'Wait for phone',    ['var_name' => 'customer_phone']);
        $nNote   = $node('save_note',    'Save booking note',
            ['template' => "🩺 Booking request\nService: {{service}}\nPreferred slot: {{preferred_slot}}\nName: {{customer_name}}\nPhone: {{customer_phone}}"]);
        $nAssign = $node('assign_dept',  'Route to bookings team',
            $deptId > 0 ? ['department_id' => $deptId] : []);
        $nBye    = $node('send_message', 'Thank + hand off',
            ['text' => "Thanks {{customer_name}}! We've noted {{service}} for {{preferred_slot}}. Our team will confirm shortly — hang tight. 🙏"]);
        $nEnd    = $node('end',          'End');

        $wire([
            $nHello  => $nSvc,    $nSvc   => $nDate,   $nDate  => $nWDate,
            $nWDate  => $nName,   $nName  => $nWName,  $nWName => $nPhone,
            $nPhone  => $nWPhone, $nWPhone=> $nNote,   $nNote  => $nAssign,
            $nAssign => $nBye,    $nBye   => $nEnd,
        ]);
        flow_template_finalize($db, $fid, $nHello);

        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * FAQ triage — numbered menu picker, four common branches.
 */
function flow_template_faq_triage(PDO $db, int $companyId, int $userId, bool $goLive): int
{
    $db->beginTransaction();
    try {
        [$fid, $node, $wire] = flow_template_bootstrap(
            $db, $companyId, $userId, 'FAQ triage (starter)', $goLive, 'help,menu,info,hi,hello'
        );

        $nHello   = $node('send_message', 'Welcome + numbered menu',
            ['text' => "Hi 👋 How can we help?\n\n1. See our menu / catalog\n2. Opening hours\n3. Where are we located?\n4. Talk to a human\n\n_Reply with 1–4 or just tap below._"]);
        $nWait    = $node('wait_reply',   'Wait for choice',        ['var_name' => 'menu_choice']);
        $nBranch  = $node('branch',       'Route to matching info');

        $nMenu    = $node('send_message', 'Reply: menu',
            ['text' => "Here's what we're serving today — just ask if you want more detail on any item!\n\n_Edit this message to include your live menu or a link to your catalog._"]);
        $nHours   = $node('send_message', 'Reply: opening hours',
            ['text' => "Our opening hours:\n\nMon–Fri · 10am–10pm\nSat–Sun · 9am–11pm\n\nPublic holidays: same as weekends.\n\n_Edit this message with your actual hours._"]);
        $nLoc     = $node('send_message', 'Reply: location',
            ['text' => "📍 We're at:\n\n123 Jalan Contoh, Kuala Lumpur\n\n_Edit this message with your actual address + Google Maps link._"]);
        $nHuman   = $node('send_message', 'Reply: hand off to human',
            ['text' => "Sure! I'll get a team member to reply. They usually respond within 15 minutes during opening hours. 🙋"]);

        $nEnd     = $node('end', 'End');

        // Wire linear ends to the terminal end node so the flow completes.
        $wire([
            $nHello => $nWait,
            $nWait  => $nBranch,
            $nMenu  => $nEnd,
            $nHours => $nEnd,
            $nLoc   => $nEnd,
            $nHuman => $nEnd,
        ]);

        // Branch edges — numeric matches + keyword aliases + default fallback.
        $eIns = $db->prepare(
            'INSERT INTO flow_edges (flow_id, from_node_id, to_node_id, condition_type, condition_value, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $eIns->execute([$fid, $nBranch, $nMenu,  'keyword', '1',        1]);
        $eIns->execute([$fid, $nBranch, $nMenu,  'keyword', 'menu',     2]);
        $eIns->execute([$fid, $nBranch, $nHours, 'keyword', '2',        3]);
        $eIns->execute([$fid, $nBranch, $nHours, 'keyword', 'hours',    4]);
        $eIns->execute([$fid, $nBranch, $nLoc,   'keyword', '3',        5]);
        $eIns->execute([$fid, $nBranch, $nLoc,   'keyword', 'location', 6]);
        $eIns->execute([$fid, $nBranch, $nHuman, 'keyword', '4',        7]);
        $eIns->execute([$fid, $nBranch, $nHuman, 'keyword', 'human',    8]);
        $eIns->execute([$fid, $nBranch, $nHuman, 'default', null,       9]);

        flow_template_finalize($db, $fid, $nHello);

        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Lead qualifier — collect 4 qualification fields, route to Sales dept.
 */
function flow_template_lead_qualifier(PDO $db, int $companyId, int $userId, bool $goLive): int
{
    $db->beginTransaction();
    try {
        [$fid, $node, $wire] = flow_template_bootstrap(
            $db, $companyId, $userId, 'Lead qualifier (starter)', $goLive, 'sales,quote,pricing,demo,enquiry'
        );

        // Pick a sales-ish dept if one exists.
        $deptId = 0;
        try {
            $s = $db->prepare(
                "SELECT id FROM departments
                 WHERE company_id = ? AND status = 'active'
                 ORDER BY FIELD(LOWER(name),'sales','biz dev','business development','account','partnerships','support') DESC, id ASC
                 LIMIT 1"
            );
            $s->execute([$companyId]);
            $deptId = (int)($s->fetchColumn() ?: 0);
        } catch (Throwable $e) { /* ok */ }

        $nHello  = $node('send_message', 'Greet + ask name',
            ['text' => "Hi 👋 Thanks for reaching out! Let me collect a few details so the right person can help you.\n\nFirst — what's your name?"]);
        $nWName  = $node('wait_reply',   'Wait for name',       ['var_name' => 'lead_name']);
        $nBiz    = $node('send_message', 'Ask business',
            ['text' => "Nice to meet you {{lead_name}}. What's the name of your business or the company you represent?"]);
        $nWBiz   = $node('wait_reply',   'Wait for business',   ['var_name' => 'lead_business']);
        $nNeed   = $node('send_message', 'Ask what they need',
            ['text' => "Great. In a sentence or two — what are you looking for or trying to solve?"]);
        $nWNeed  = $node('wait_reply',   'Wait for need',       ['var_name' => 'lead_need']);
        $nBudget = $node('send_message', 'Ask budget / timeline',
            ['text' => "Rough budget or timeline, if you have one? (No worries if not — just helps us plan.)"]);
        $nWBud   = $node('wait_reply',   'Wait for budget',     ['var_name' => 'lead_budget']);
        $nNote   = $node('save_note',    'Save qualification note',
            ['template' => "🎯 Lead qualified\nName: {{lead_name}}\nBusiness: {{lead_business}}\nNeed: {{lead_need}}\nBudget / timeline: {{lead_budget}}"]);
        $nAssign = $node('assign_dept',  'Route to sales team',
            $deptId > 0 ? ['department_id' => $deptId] : []);
        $nBye    = $node('send_message', 'Thank + hand off',
            ['text' => "Thanks {{lead_name}}! I've flagged this for our sales team — expect to hear back within one working day. 🙏"]);
        $nEnd    = $node('end',          'End');

        $wire([
            $nHello  => $nWName,  $nWName => $nBiz,    $nBiz   => $nWBiz,
            $nWBiz   => $nNeed,   $nNeed  => $nWNeed,  $nWNeed => $nBudget,
            $nBudget => $nWBud,   $nWBud  => $nNote,   $nNote  => $nAssign,
            $nAssign => $nBye,    $nBye   => $nEnd,
        ]);
        flow_template_finalize($db, $fid, $nHello);

        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
