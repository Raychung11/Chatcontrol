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
            'key'          => 'fnb_branch_router',
            'name'         => 'F&B ordering — with branch router (multi-outlet)',
            'category'     => 'F&B',
            'icon'         => '🗺',
            'description'  => 'For multi-outlet restaurants. Welcome → ask area → 🗺 AI picks nearest branch → delivery/pickup → send menu → AI cart → confirm. Requires branches to have address + area keywords filled in.',
            'requires_fnb' => true,
            'builder'      => 'flow_template_fnb_branch_router',
        ],
        [
            'key'          => 'furniture_showroom',
            'name'         => 'Furniture showroom lead capture',
            'category'     => 'Retail',
            'icon'         => '🛋',
            'description'  => 'For multi-outlet furniture / home retail. Welcome → 🗺 AI routes to nearest showroom → interest picker (showroom visit / home consult / catalogue / trade quote) → save qualified lead → hand off to sales at that branch.',
            'requires_fnb' => false,
            'builder'      => 'flow_template_furniture_showroom',
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
        [
            'key'          => 'feedback_survey',
            'name'         => 'Feedback survey',
            'category'     => 'Support',
            'icon'         => '⭐',
            'description'  => 'Ask for a 1-5 star rating + optional comment. Low scores (1-2) auto-route to support so a human can recover the customer.',
            'requires_fnb' => false,
            'builder'      => 'flow_template_feedback_survey',
        ],
        [
            'key'          => 'order_status_lookup',
            'name'         => 'Order status lookup',
            'category'     => 'Support',
            'icon'         => '📦',
            'description'  => 'Customer sends an order number; the flow collects it, saves a lookup note, and routes to staff who reply with the current status.',
            'requires_fnb' => false,
            'builder'      => 'flow_template_order_status_lookup',
        ],
        [
            'key'          => 'delivery_tracking',
            'name'         => 'Delivery tracking',
            'category'     => 'Logistics',
            'icon'         => '🚚',
            'description'  => 'Ask for the tracking or order number + best contact time, save note, and hand off to logistics for a status update.',
            'requires_fnb' => false,
            'builder'      => 'flow_template_delivery_tracking',
        ],
        [
            'key'          => 'birthday_optin',
            'name'         => 'Birthday reminder opt-in',
            'category'     => 'Marketing',
            'icon'         => '🎂',
            'description'  => 'Collect the customer\'s name + birthday (day/month) for future promo campaigns. Saves an internal note tagged for the marketing list.',
            'requires_fnb' => false,
            'builder'      => 'flow_template_birthday_optin',
        ],
        [
            'key'          => 'refund_request',
            'name'         => 'Refund request',
            'category'     => 'Support',
            'icon'         => '💸',
            'description'  => 'Ask order number, purchase date, reason, and refund method; save the full case as a note and hand off to support / finance.',
            'requires_fnb' => false,
            'builder'      => 'flow_template_refund_request',
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
 * Pick the best-fit active department for this template, ranked by a
 * priority list of common names. Returns 0 if none match — assign_dept
 * nodes no-op safely on 0, so this is always safe to call.
 */
function flow_template_pick_dept(PDO $db, int $companyId, array $priorityNames): int
{
    if (!$priorityNames) return 0;
    // Build a MySQL FIELD() ordering that prefers earlier names.
    $quoted = array_map(fn($n) => "'" . str_replace("'", "\\'", mb_strtolower($n)) . "'", $priorityNames);
    $order  = 'FIELD(LOWER(name),' . implode(',', $quoted) . ')';
    try {
        $s = $db->prepare(
            "SELECT id FROM departments
             WHERE company_id = ? AND status = 'active'
             ORDER BY {$order} DESC, id ASC LIMIT 1"
        );
        $s->execute([$companyId]);
        return (int)($s->fetchColumn() ?: 0);
    } catch (Throwable $e) { return 0; }
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

/**
 * Feedback survey — 1-5 star rating + optional comment. Low scores
 * (1 or 2) branch to human handoff so support can recover the customer;
 * high scores get a quick thank-you and end.
 */
function flow_template_feedback_survey(PDO $db, int $companyId, int $userId, bool $goLive): int
{
    $db->beginTransaction();
    try {
        [$fid, $node, $wire] = flow_template_bootstrap(
            $db, $companyId, $userId, 'Feedback survey (starter)', $goLive, 'feedback,review,rating,how was'
        );
        $deptId = flow_template_pick_dept($db, $companyId, ['support','customer service','success','experience']);

        $nAsk     = $node('send_message', 'Ask for rating',
            ['text' => "Thanks for choosing us! ⭐\n\nHow was your experience? Reply with a number 1–5:\n\n1 · terrible\n2 · not great\n3 · ok\n4 · good\n5 · amazing"]);
        $nWRate   = $node('wait_reply',   'Wait for rating',       ['var_name' => 'rating']);
        $nBranch  = $node('branch',       'Score branch');

        // Low-score path — apologise, ask what went wrong, route to support.
        $nSorry   = $node('send_message', 'Low score: apologise',
            ['text' => "Really sorry to hear that. 😔 Would you mind telling me in a sentence or two what went wrong? A human from our team will read it and follow up personally."]);
        $nWReason = $node('wait_reply',   'Wait for reason',       ['var_name' => 'complaint']);
        $nNoteLow = $node('save_note',    'Save complaint note',
            ['template' => "⚠️ NEGATIVE feedback ({{rating}}★)\nReason: {{complaint}}"]);
        $nAssign  = $node('assign_dept',  'Route to support',
            $deptId > 0 ? ['department_id' => $deptId] : []);
        $nByeLow  = $node('send_message', 'Confirm human follow-up',
            ['text' => "Thank you for sharing. Our team has been notified — someone will reply here shortly to make this right. 🙏"]);

        // High-score path — thank + ask for optional public review.
        $nThanks  = $node('send_message', 'High score: thank',
            ['text' => "Thank you so much! 🙌 If you'd like to leave us a Google review, we'd really appreciate it — it helps a small business a lot. (Reply \"skip\" if you'd rather not.)"]);
        $nWMaybe  = $node('wait_reply',   'Wait for review reply', ['var_name' => 'review_reply']);
        $nNoteHi  = $node('save_note',    'Save positive feedback',
            ['template' => "⭐ Positive feedback ({{rating}}★). Response to review ask: {{review_reply}}"]);
        $nByeHi   = $node('send_message', 'Sign off',
            ['text' => "You're the best 💛 — see you next time!"]);

        $nEnd     = $node('end',          'End');

        $wire([
            $nAsk     => $nWRate,
            $nWRate   => $nBranch,
            // low score chain
            $nSorry   => $nWReason,
            $nWReason => $nNoteLow,
            $nNoteLow => $nAssign,
            $nAssign  => $nByeLow,
            $nByeLow  => $nEnd,
            // high score chain
            $nThanks  => $nWMaybe,
            $nWMaybe  => $nNoteHi,
            $nNoteHi  => $nByeHi,
            $nByeHi   => $nEnd,
        ]);

        // Branch edges: 1 or 2 → low path, everything else → high path.
        $eIns = $db->prepare(
            'INSERT INTO flow_edges (flow_id, from_node_id, to_node_id, condition_type, condition_value, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $eIns->execute([$fid, $nBranch, $nSorry,  'keyword', '1',        1]);
        $eIns->execute([$fid, $nBranch, $nSorry,  'keyword', '2',        2]);
        $eIns->execute([$fid, $nBranch, $nSorry,  'keyword', 'terrible', 3]);
        $eIns->execute([$fid, $nBranch, $nSorry,  'keyword', 'bad',      4]);
        $eIns->execute([$fid, $nBranch, $nThanks, 'default', null,       5]);

        flow_template_finalize($db, $fid, $nAsk);
        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Order status lookup — customer sends order number, flow saves it as
 * a lookup note + routes to staff. No DB lookup node yet (would need a
 * dedicated node type + engine hook); staff manually check the F&B
 * dashboard and reply. Fast to build, actually useful today.
 */
function flow_template_order_status_lookup(PDO $db, int $companyId, int $userId, bool $goLive): int
{
    $db->beginTransaction();
    try {
        [$fid, $node, $wire] = flow_template_bootstrap(
            $db, $companyId, $userId, 'Order status lookup (starter)', $goLive, 'order status,where is my order,my order,status'
        );
        $deptId = flow_template_pick_dept($db, $companyId, ['support','order status','customer service','operations']);

        $nAsk    = $node('send_message', 'Ask for order number',
            ['text' => "Sure! Please share your order number so I can check it for you.\n\n(It usually looks like A00123 or similar — check your last confirmation from us.)"]);
        $nWNum   = $node('wait_reply',   'Wait for order number', ['var_name' => 'order_number']);
        $nNote   = $node('save_note',    'Save lookup request',
            ['template' => "📦 Order status lookup requested\nOrder #: {{order_number}}"]);
        $nAssign = $node('assign_dept',  'Route to staff',
            $deptId > 0 ? ['department_id' => $deptId] : []);
        $nBye    = $node('send_message', 'Ack + hand off',
            ['text' => "Got it — checking on order *{{order_number}}* now. A team member will reply here in a moment with the latest status. 🙏"]);
        $nEnd    = $node('end',          'End');

        $wire([
            $nAsk    => $nWNum,   $nWNum  => $nNote,
            $nNote   => $nAssign, $nAssign=> $nBye,   $nBye => $nEnd,
        ]);
        flow_template_finalize($db, $fid, $nAsk);
        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Delivery tracking — asks tracking number + best contact time so
 * logistics can call back without playing phone tag.
 */
function flow_template_delivery_tracking(PDO $db, int $companyId, int $userId, bool $goLive): int
{
    $db->beginTransaction();
    try {
        [$fid, $node, $wire] = flow_template_bootstrap(
            $db, $companyId, $userId, 'Delivery tracking (starter)', $goLive, 'delivery,tracking,where is my delivery,shipment,parcel'
        );
        $deptId = flow_template_pick_dept($db, $companyId, ['logistics','delivery','operations','support']);

        $nAsk     = $node('send_message', 'Ask tracking or order #',
            ['text' => "Happy to help track your delivery. 🚚\n\nWhat's your tracking number or order number?"]);
        $nWNum    = $node('wait_reply',   'Wait for number',     ['var_name' => 'tracking_number']);
        $nWhen    = $node('send_message', 'Ask best contact time',
            ['text' => "Got it. When's a good time for us to update you today? (e.g. \"before 3pm\", \"anytime\", \"after work\")"]);
        $nWWhen   = $node('wait_reply',   'Wait for contact window', ['var_name' => 'contact_window']);
        $nNote    = $node('save_note',    'Save tracking request',
            ['template' => "🚚 Delivery tracking\nNumber: {{tracking_number}}\nCall back window: {{contact_window}}"]);
        $nAssign  = $node('assign_dept',  'Route to logistics',
            $deptId > 0 ? ['department_id' => $deptId] : []);
        $nBye     = $node('send_message', 'Confirm hand off',
            ['text' => "Thanks! Our logistics team is looking up *{{tracking_number}}* now — you'll get an update by {{contact_window}}. 📦"]);
        $nEnd     = $node('end',          'End');

        $wire([
            $nAsk    => $nWNum,   $nWNum  => $nWhen,   $nWhen  => $nWWhen,
            $nWWhen  => $nNote,   $nNote  => $nAssign, $nAssign=> $nBye, $nBye => $nEnd,
        ]);
        flow_template_finalize($db, $fid, $nAsk);
        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Birthday reminder opt-in — collect day + month (year optional) and
 * save as a note tagged for the marketing list. Future phase: add a
 * proper contacts.birthday column + monthly cron to send greetings.
 */
function flow_template_birthday_optin(PDO $db, int $companyId, int $userId, bool $goLive): int
{
    $db->beginTransaction();
    try {
        [$fid, $node, $wire] = flow_template_bootstrap(
            $db, $companyId, $userId, 'Birthday reminder opt-in (starter)', $goLive, 'birthday,promo,discount,special offer'
        );

        $nAsk    = $node('send_message', 'Explain + ask name',
            ['text' => "🎂 We'd love to send you a little birthday surprise every year.\n\nWhat should we call you?"]);
        $nWName  = $node('wait_reply',   'Wait for name',    ['var_name' => 'contact_name']);
        $nBday   = $node('send_message', 'Ask birthday',
            ['text' => "Thanks {{contact_name}}! And what's your birthday? Just day and month is fine (e.g. \"15 August\" or \"15/8\")."]);
        $nWBday  = $node('wait_reply',   'Wait for birthday',['var_name' => 'birthday']);
        $nNote   = $node('save_note',    'Save marketing opt-in',
            ['template' => "🎂 Birthday opt-in\nName: {{contact_name}}\nBirthday: {{birthday}}\n(Add to birthday-promo list)"]);
        $nBye    = $node('send_message', 'Confirm',
            ['text' => "You're on the list, {{contact_name}}. 🎉 See you around your birthday — no spam otherwise, promise."]);
        $nEnd    = $node('end',          'End');

        $wire([
            $nAsk    => $nWName,  $nWName => $nBday,   $nBday => $nWBday,
            $nWBday  => $nNote,   $nNote  => $nBye,    $nBye  => $nEnd,
        ]);
        flow_template_finalize($db, $fid, $nAsk);
        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Refund request — collects order #, date, reason, preferred refund
 * method; hands off to support/finance with a full case note.
 */
function flow_template_refund_request(PDO $db, int $companyId, int $userId, bool $goLive): int
{
    $db->beginTransaction();
    try {
        [$fid, $node, $wire] = flow_template_bootstrap(
            $db, $companyId, $userId, 'Refund request (starter)', $goLive, 'refund,return,cancel,money back'
        );
        $deptId = flow_template_pick_dept($db, $companyId, ['refunds','finance','support','customer service']);

        $nAsk    = $node('send_message', 'Ack + ask order #',
            ['text' => "Sorry to hear this — happy to help. 💸\n\nCan you share your order number?"]);
        $nWNum   = $node('wait_reply',   'Wait for order number', ['var_name' => 'order_number']);
        $nDate   = $node('send_message', 'Ask purchase date',
            ['text' => "Thanks. When did you place order {{order_number}}? (rough date is fine)"]);
        $nWDate  = $node('wait_reply',   'Wait for date',         ['var_name' => 'purchase_date']);
        $nReason = $node('send_message', 'Ask reason',
            ['text' => "In a sentence or two, what's the reason for the refund?"]);
        $nWReason= $node('wait_reply',   'Wait for reason',       ['var_name' => 'refund_reason']);
        $nMethod = $node('send_message', 'Ask refund method',
            ['text' => "How would you prefer the refund?\n\n1. Back to the original payment card / e-wallet\n2. Bank transfer\n3. Store credit"]);
        $nWMethod= $node('wait_reply',   'Wait for method',       ['var_name' => 'refund_method']);
        $nNote   = $node('save_note',    'Save refund case',
            ['template' => "💸 REFUND REQUEST\nOrder #: {{order_number}}\nPurchase date: {{purchase_date}}\nReason: {{refund_reason}}\nRefund method: {{refund_method}}"]);
        $nAssign = $node('assign_dept',  'Route to refunds/finance',
            $deptId > 0 ? ['department_id' => $deptId] : []);
        $nBye    = $node('send_message', 'Confirm timeline',
            ['text' => "Got it — your refund case is with our team now. We usually confirm within 1 working day and process within 5–7 working days from confirmation. 🙏"]);
        $nEnd    = $node('end',          'End');

        $wire([
            $nAsk    => $nWNum,     $nWNum   => $nDate,   $nDate   => $nWDate,
            $nWDate  => $nReason,   $nReason => $nWReason,$nWReason=> $nMethod,
            $nMethod => $nWMethod,  $nWMethod=> $nNote,   $nNote   => $nAssign,
            $nAssign => $nBye,      $nBye    => $nEnd,
        ]);
        flow_template_finalize($db, $fid, $nAsk);
        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * F&B ordering — multi-outlet variant.
 *
 * Welcome → ask which area → 🗺 AI-map to nearest branch → confirm
 * branch pick + ask delivery/pickup → send menu → AI cart parse →
 * loop until "done" → collect name + address → create order.
 *
 * Prereq: branches must have address + area_keywords filled in
 * (/admin/branches.php) for the AI mapper to work well. If nothing
 * matches, the flow uses the first branch as fallback (or leaves the
 * contact unassigned).
 */
function flow_template_fnb_branch_router(PDO $db, int $companyId, int $userId, bool $goLive): int
{
    $db->beginTransaction();
    try {
        [$fid, $node, $wire] = flow_template_bootstrap(
            $db, $companyId, $userId,
            'F&B ordering with branch router (starter)',
            $goLive,
            'order,menu,food,makan,pesan'
        );

        // Pick the alphabetically-first active branch as the AI-mapper's
        // safety-net fallback. Zero if none configured — the assign_nearest
        // node handles that gracefully (leaves the contact unassigned).
        $fallbackBranch = (int)($db->query(
            'SELECT id FROM branches WHERE company_id = ' . (int)$companyId
          . ' AND status = "active" ORDER BY name LIMIT 1'
        )->fetchColumn() ?: 0);

        $nHello    = $node('send_message', 'Welcome + ask area', ['text' =>
            "Hi 👋 Welcome!\n\n"
          . "Which area are you at so we can serve you from the nearest outlet?\n\n"
          . "_(Reply with your neighborhood, town, or postcode — e.g. Bangsar, TTDI, 46200)_"
        ]);
        $nWaitLoc  = $node('wait_reply', 'Wait for location',
            ['var_name' => 'customer_location']);
        $nRoute    = $node('assign_nearest_branch', '🗺 Assign to nearest branch',
            ['location_var' => 'customer_location', 'fallback_branch_id' => $fallbackBranch]);
        $nConfirm  = $node('send_message', 'Confirm branch + ask order type', ['text' =>
            "✅ Routed to *{{assigned_branch_name}}*\n"
          . "📍 {{assigned_branch_address}}\n\n"
          . "How would you like your order?\n\n"
          . "1. Delivery\n2. Self-pickup\n\n"
          . "_Reply with 1 or 2._"
        ]);
        $nWaitType = $node('wait_reply', 'Wait for order type',
            ['var_name' => 'order_type']);
        $nSendMenu = $node('fnb_send_menu', 'Send menu');
        $nWaitOrd  = $node('wait_reply', 'Wait for order details',
            ['var_name' => 'raw_order']);
        $nCart     = $node('fnb_cart_add', 'AI: parse into cart');
        $nWaitDone = $node('wait_reply', 'Wait for done or more items',
            ['var_name' => 'more_items']);
        $nDoneBr   = $node('branch', 'Done or add more?');
        $nAskName  = $node('send_message', 'Ask for customer name',
            ['text' => "Got it. What name should we put on the order?"]);
        $nWaitName = $node('wait_reply', 'Wait for name',
            ['var_name' => 'customer_name']);
        $nAskAddr  = $node('send_message', 'Ask for address / pickup time',
            ['text' => "Please share your *delivery address* (or *pickup time* if picking up)."]);
        $nWaitAddr = $node('wait_reply', 'Wait for address',
            ['var_name' => 'delivery_address']);
        $nCreate   = $node('fnb_create_order', 'Create the order');
        $nEnd      = $node('end', 'End');

        $wire([
            $nHello    => $nWaitLoc,   $nWaitLoc  => $nRoute,     $nRoute    => $nConfirm,
            $nConfirm  => $nWaitType,  $nWaitType => $nSendMenu,  $nSendMenu => $nWaitOrd,
            $nWaitOrd  => $nCart,      $nCart     => $nWaitDone,  $nWaitDone => $nDoneBr,
            $nAskName  => $nWaitName,  $nWaitName => $nAskAddr,   $nAskAddr  => $nWaitAddr,
            $nWaitAddr => $nCreate,    $nCreate   => $nEnd,
        ]);

        // Branch 'done' → collect name; anything else → re-parse as more items
        $eIns = $db->prepare(
            'INSERT INTO flow_edges (flow_id, from_node_id, to_node_id, condition_type, condition_value, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $eIns->execute([$fid, $nDoneBr, $nAskName, 'keyword', 'done',    1]);
        $eIns->execute([$fid, $nDoneBr, $nAskName, 'keyword', 'confirm', 2]);
        $eIns->execute([$fid, $nDoneBr, $nAskName, 'keyword', 'yes',     3]);
        $eIns->execute([$fid, $nDoneBr, $nCart,    'default', null,      4]);

        flow_template_finalize($db, $fid, $nHello);
        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Furniture showroom lead capture — for multi-outlet furniture /
 * home retail (sofa, mattress, kitchen, tiles).
 *
 * Welcome → ask area → 🗺 AI-map to nearest showroom → confirm branch
 * + ask the customer what they need (numbered choices: showroom visit,
 * home consult, catalogue, trade quote) → branch on choice → capture
 * name + interest details → save a qualified lead note → assign to
 * Sales department for follow-up.
 *
 * Uses no F&B nodes — safe for any workspace regardless of fnb_plan.
 */
function flow_template_furniture_showroom(PDO $db, int $companyId, int $userId, bool $goLive): int
{
    $db->beginTransaction();
    try {
        [$fid, $node, $wire] = flow_template_bootstrap(
            $db, $companyId, $userId,
            'Furniture showroom lead capture (starter)',
            $goLive,
            'sofa,furniture,showroom,catalogue,catalog,visit,order'
        );

        // Fallback branch for the AI mapper.
        $fallbackBranch = (int)($db->query(
            'SELECT id FROM branches WHERE company_id = ' . (int)$companyId
          . ' AND status = "active" ORDER BY name LIMIT 1'
        )->fetchColumn() ?: 0);

        // Prefer a Sales department for the final handoff; fall back to
        // Enquiries / Support / first active dept if Sales isn't set up.
        $deptId = flow_template_pick_dept($db, $companyId, ['Sales', 'Enquiries', 'Showroom', 'Support']);

        $nHello    = $node('send_message', 'Welcome + ask area', ['text' =>
            "Hi 👋 Welcome!\n\n"
          . "Thanks for reaching out — we've got showrooms across Malaysia.\n\n"
          . "Which area are you at so we can connect you with the nearest one?\n\n"
          . "_(Reply with your neighborhood / town / postcode — e.g. Bangsar, TTDI, 46200)_"
        ]);
        $nWaitLoc  = $node('wait_reply', 'Wait for location',
            ['var_name' => 'customer_location']);
        $nRoute    = $node('assign_nearest_branch', '🗺 Assign to nearest showroom',
            ['location_var' => 'customer_location', 'fallback_branch_id' => $fallbackBranch]);
        $nConfirm  = $node('send_message', 'Confirm showroom + ask interest', ['text' =>
            "✅ Great — nearest showroom to you:\n\n"
          . "*{{assigned_branch_name}}*\n"
          . "📍 {{assigned_branch_address}}\n\n"
          . "How can we help today?\n\n"
          . "1. 🛋 Visit the showroom\n"
          . "2. 🏡 Home consultation / measurement\n"
          . "3. 📖 Get our catalogue (PDF)\n"
          . "4. 🏢 Corporate / trade quote\n"
          . "5. 💬 Something else\n\n"
          . "_Reply with 1, 2, 3, 4, or 5._"
        ]);
        $nWaitIntr = $node('wait_reply', 'Wait for interest choice',
            ['var_name' => 'interest_choice']);
        $nIntrBr   = $node('branch', 'Route by interest');

        // Path 1 — Showroom visit
        $nVisit    = $node('send_message', 'Ask preferred visit day', ['text' =>
            "Great — what day works for your visit? We're open Mon–Sun 10am–8pm."
        ]);
        $nWVisit   = $node('wait_reply', 'Wait for visit day',
            ['var_name' => 'visit_day']);
        $nVisitName= $node('send_message', 'Ask name for visit',
            ['text' => "Perfect. What name should we put down?"]);
        $nWVName   = $node('wait_reply', 'Wait for name (visit)',
            ['var_name' => 'customer_name']);

        // Path 2 — Home consultation
        $nHome     = $node('send_message', 'Ask home visit details', ['text' =>
            "Nice — home consult includes free measurement + 3D rendering (RM 500 deductible from purchase over RM 8,000).\n\n"
          . "What's the area you'd like designed? (e.g. living room, master bedroom, kitchen)"
        ]);
        $nWHome    = $node('wait_reply', 'Wait for home area',
            ['var_name' => 'home_area']);
        $nHomeAddr = $node('send_message', 'Ask home address',
            ['text' => "Got it. What's the full address for the consultation?"]);
        $nWHAddr   = $node('wait_reply', 'Wait for home address',
            ['var_name' => 'home_address']);
        $nHomeName = $node('send_message', 'Ask name for home consult',
            ['text' => "And your name + best phone number?"]);
        $nWHName   = $node('wait_reply', 'Wait for name (home)',
            ['var_name' => 'customer_name']);

        // Path 3 — Catalogue
        $nCat      = $node('send_message', 'Ask catalogue category', ['text' =>
            "Which range would you like?\n\n"
          . "1. Sofa / living\n"
          . "2. Bedroom / mattress\n"
          . "3. Dining\n"
          . "4. Everything (full catalogue)"
        ]);
        $nWCat     = $node('wait_reply', 'Wait for catalogue pick',
            ['var_name' => 'catalog_pick']);
        $nCatName  = $node('send_message', 'Ask name for catalogue',
            ['text' => "Great, sending over shortly. What name + email should we send it to?"]);
        $nWCName   = $node('wait_reply', 'Wait for name (catalogue)',
            ['var_name' => 'customer_name']);

        // Path 4 — Corporate / trade
        $nTrade    = $node('send_message', 'Ask trade / corporate info', ['text' =>
            "Fantastic — for corporate / trade we offer 10–20% off on orders above RM 15,000 (offices, hotels, cafés).\n\n"
          . "What's the project? (e.g. \"20 chairs + 6 tables for new café in Bangsar, ready in 6 weeks\")"
        ]);
        $nWTrade   = $node('wait_reply', 'Wait for trade brief',
            ['var_name' => 'trade_brief']);
        // Ask company info first (goes into its OWN var so the compound
        // "Acme Corp, John Doe, john@acme.com" reply doesn't get rendered
        // as {{customer_name}} in the save-note + hand-off template),
        // then ask for the contact person's actual name separately so
        // downstream {{customer_name}} substitutions stay clean.
        $nTradeCo  = $node('send_message', 'Ask company + email',
            ['text' => "Your company name + best contact email please?"]);
        $nWTradeCo = $node('wait_reply', 'Wait for company info',
            ['var_name' => 'trade_company']);
        $nTradeNm  = $node('send_message', 'Ask trade contact name',
            ['text' => "And who's the best person to reach at your side?"]);
        $nWTradeNm = $node('wait_reply', 'Wait for trade contact name',
            ['var_name' => 'customer_name']);

        // Path 5 — Other
        $nOther    = $node('send_message', 'Ask free-text query', ['text' =>
            "Sure — in a sentence or two, what are you looking for? A team member will get back to you shortly."
        ]);
        $nWOther   = $node('wait_reply', 'Wait for free-text',
            ['var_name' => 'other_query']);
        $nOtherNm  = $node('send_message', 'Ask name (other)',
            ['text' => "Got it. And your name?"]);
        $nWOName   = $node('wait_reply', 'Wait for name (other)',
            ['var_name' => 'customer_name']);

        // Convergent save-note + handoff (renders whatever vars were captured).
        $nNote     = $node('save_note', 'Save qualified lead note', ['template' =>
            "🛋 Furniture lead — {{customer_name}}\n"
          . "Nearest showroom: {{assigned_branch_name}} — {{assigned_branch_address}}\n"
          . "Location: {{customer_location}}\n"
          . "Interest: {{interest_choice}}\n"
          . "\n— Visit day: {{visit_day}}"
          . "\n— Home area: {{home_area}} · address: {{home_address}}"
          . "\n— Catalogue pick: {{catalog_pick}}"
          . "\n— Trade brief: {{trade_brief}}"
          . "\n— Trade company / email: {{trade_company}}"
          . "\n— Other query: {{other_query}}"
        ]);
        $nAssign   = $node('assign_dept', 'Route to Sales / Showroom',
            $deptId > 0 ? ['department_id' => $deptId] : []);
        $nBye      = $node('send_message', 'Confirm and hand off', ['text' =>
            "Thanks {{customer_name}}! 🙌 Your details are saved and our *{{assigned_branch_name}}* team will WhatsApp you shortly to follow up.\n\n"
          . "📍 {{assigned_branch_address}}\n\n"
          . "Anything else, just message anytime."
        ]);
        $nEnd      = $node('end', 'End');

        // Linear next-node wiring (except the branch node which is edges-only).
        $wire([
            $nHello    => $nWaitLoc,   $nWaitLoc  => $nRoute,   $nRoute    => $nConfirm,
            $nConfirm  => $nWaitIntr,  $nWaitIntr => $nIntrBr,

            // Path 1 — Visit
            $nVisit    => $nWVisit,    $nWVisit   => $nVisitName, $nVisitName => $nWVName,
            $nWVName   => $nNote,

            // Path 2 — Home
            $nHome     => $nWHome,     $nWHome    => $nHomeAddr,  $nHomeAddr  => $nWHAddr,
            $nWHAddr   => $nHomeName,  $nHomeName => $nWHName,    $nWHName    => $nNote,

            // Path 3 — Catalogue
            $nCat      => $nWCat,      $nWCat     => $nCatName,   $nCatName   => $nWCName,
            $nWCName   => $nNote,

            // Path 4 — Trade
            $nTrade    => $nWTrade,    $nWTrade   => $nTradeCo,   $nTradeCo   => $nWTradeCo,
            $nWTradeCo => $nTradeNm,   $nTradeNm  => $nWTradeNm,  $nWTradeNm  => $nNote,

            // Path 5 — Other
            $nOther    => $nWOther,    $nWOther   => $nOtherNm,   $nOtherNm   => $nWOName,
            $nWOName   => $nNote,

            // Converge
            $nNote     => $nAssign,    $nAssign   => $nBye,       $nBye       => $nEnd,
        ]);

        // Branch edges — match on the customer's number choice OR any
        // reasonable keyword. Default = the "Other" free-text path so
        // no reply falls through the cracks.
        $eIns = $db->prepare(
            'INSERT INTO flow_edges (flow_id, from_node_id, to_node_id, condition_type, condition_value, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $sort = 0;
        // Path 1 — Visit
        foreach (['1', 'visit', 'showroom', 'come'] as $kw) $eIns->execute([$fid, $nIntrBr, $nVisit,  'keyword', $kw, ++$sort]);
        // Path 2 — Home
        foreach (['2', 'home',  'consult', 'measure']  as $kw) $eIns->execute([$fid, $nIntrBr, $nHome,   'keyword', $kw, ++$sort]);
        // Path 3 — Catalogue
        foreach (['3', 'catalog','catalogue','pdf']    as $kw) $eIns->execute([$fid, $nIntrBr, $nCat,    'keyword', $kw, ++$sort]);
        // Path 4 — Trade / corporate
        foreach (['4', 'trade', 'corporate', 'bulk', 'office'] as $kw) $eIns->execute([$fid, $nIntrBr, $nTrade, 'keyword', $kw, ++$sort]);
        // Path 5 / default — Other
        $eIns->execute([$fid, $nIntrBr, $nOther, 'keyword', '5',     ++$sort]);
        $eIns->execute([$fid, $nIntrBr, $nOther, 'default', null,    ++$sort]);

        flow_template_finalize($db, $fid, $nHello);
        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
