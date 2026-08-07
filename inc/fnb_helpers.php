<?php
/**
 * F&B module shared helpers — order number, line total, status labels,
 * plus Layer 3 helpers used by the flow engine's F&B node handlers
 * (menu rendering, Claude-based cart parser, cart materialization).
 */

require_once __DIR__ . '/helpers.php';

/**
 * Load all active products with their variants + addons for the AI
 * parser + the render-menu helper. Returns:
 *   [ { id, name, price, category, variants:[{group,name,price_delta}], addons:[...] }, ... ]
 * sorted by category sort_order then product sort_order.
 */
function fnb_active_menu(int $companyId): array
{
    $db = aiserve_db();
    $ps = $db->prepare(
        'SELECT p.*, c.name AS category_name, c.sort_order AS cat_sort
         FROM fnb_products p
         LEFT JOIN fnb_categories c ON c.id = p.category_id
         WHERE p.company_id = ? AND p.status = "active"
         ORDER BY COALESCE(c.sort_order, 999), c.name, p.sort_order, p.name'
    );
    $ps->execute([$companyId]);
    $rows = $ps->fetchAll();
    if (!$rows) return [];

    $ids = array_map('intval', array_column($rows, 'id'));
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    $vs = $db->prepare("SELECT * FROM fnb_variants WHERE product_id IN ($ph) ORDER BY group_name, sort_order");
    $vs->execute($ids);
    $varByProduct = [];
    foreach ($vs->fetchAll() as $v) $varByProduct[(int)$v['product_id']][] = $v;

    $as = $db->prepare("SELECT * FROM fnb_addons WHERE product_id IN ($ph) ORDER BY sort_order");
    $as->execute($ids);
    $addByProduct = [];
    foreach ($as->fetchAll() as $a) $addByProduct[(int)$a['product_id']][] = $a;

    $out = [];
    foreach ($rows as $p) {
        $pid = (int)$p['id'];
        $out[] = [
            'id'          => $pid,
            'name'        => (string)$p['name'],
            'description' => (string)($p['description'] ?? ''),
            'price'       => (float)$p['price'],
            'category'    => (string)($p['category_name'] ?? 'Uncategorized'),
            'variants'    => array_map(fn($v) => [
                'id'          => (int)$v['id'],
                'group'       => (string)$v['group_name'],
                'name'        => (string)$v['name'],
                'price_delta' => (float)$v['price_delta'],
                'is_default'  => (int)$v['is_default'] === 1,
            ], $varByProduct[$pid] ?? []),
            'addons'      => array_map(fn($a) => [
                'id'          => (int)$a['id'],
                'name'        => (string)$a['name'],
                'price_delta' => (float)$a['price_delta'],
            ], $addByProduct[$pid] ?? []),
        ];
    }
    return $out;
}

/**
 * Format the active menu as a WhatsApp-friendly text block, grouped by
 * category, with numbered items so the customer can reply "2 of #1".
 * Returns '' when the menu is empty (caller should handle).
 */
function fnb_render_menu_message(int $companyId, string $currency): string
{
    $menu = fnb_active_menu($companyId);
    if (!$menu) return '';
    $lines = ["🍽️ *Our Menu*", ''];
    $lastCat = null;
    $n = 0;
    foreach ($menu as $p) {
        if ($p['category'] !== $lastCat) {
            if ($lastCat !== null) $lines[] = '';
            $lines[] = '📌 *' . mb_strtoupper($p['category']) . '*';
            $lastCat = $p['category'];
        }
        $n++;
        $priceStr = $currency . ' ' . number_format($p['price'], 2);
        $lines[] = sprintf("%d. %s — %s", $n, $p['name'], $priceStr);
        if (!empty($p['description'])) {
            $lines[] = '   _' . mb_strimwidth($p['description'], 0, 100, '…') . '_';
        }
    }
    $lines[] = '';
    $lines[] = "Reply with what you'd like (e.g. \"2 chicken rice, 1 nasi lemak less spicy\").";
    return implode("\n", $lines);
}

/**
 * Format the current cart contents for a customer-facing confirmation.
 * Cart shape (from flow_instances.state.cart):
 *   [ { product_id, product_name, quantity, unit_price, variants:[], addons:[], instructions, line_total }, ... ]
 */
function fnb_render_cart(array $cart, string $currency): string
{
    if (!$cart) return "🛒 Your cart is empty.";
    $lines = ["🛒 *Your order so far:*", ''];
    $subtotal = 0.0;
    foreach ($cart as $i => $it) {
        $num   = $i + 1;
        $q     = (int)($it['quantity'] ?? 1);
        $name  = (string)($it['product_name'] ?? '');
        $lt    = (float)($it['line_total'] ?? 0);
        $subtotal += $lt;
        $lines[] = sprintf("*%d.* %dx %s — %s %s", $num, $q, $name, $currency, number_format($lt, 2));
        foreach ((array)($it['variants'] ?? []) as $v) {
            $lines[] = "     ◦ " . (string)($v['group'] ?? '') . ": " . (string)($v['name'] ?? '');
        }
        foreach ((array)($it['addons'] ?? []) as $a) {
            $lines[] = "     + " . (string)($a['name'] ?? '');
        }
        if (!empty($it['instructions'])) {
            $lines[] = "     _📝 " . (string)$it['instructions'] . "_";
        }
    }
    $lines[] = '';
    $lines[] = "Subtotal: *" . $currency . ' ' . number_format($subtotal, 2) . "*";
    return implode("\n", $lines);
}

/**
 * Call Claude to parse a customer's free-text message against the
 * current menu AND the running cart. Detects intent so a single node
 * type handles both "2 chicken rice" (add) and "remove item 2" / "take
 * out the nasi lemak" (remove) / "clear cart" (clear).
 *
 * $currentCart = 1-based numbered list from state.cart (as rendered)
 * so the AI can map "item 2" to a cart index.
 *
 * Returns:
 *   { ok, intent, items, remove_line_numbers, clarification, error }
 *
 * intent:
 *   'add'    - customer added items (items[] populated)
 *   'remove' - customer wants to remove cart lines (remove_line_numbers populated)
 *   'clear'  - customer wants to start over (empty the cart)
 *   'none'   - couldn't determine intent (clarification usually set)
 *
 * Fails gracefully if the AI provider isn't configured — caller can
 * fall back to asking the customer to be more specific.
 */
function fnb_parse_order_ai(array $company, string $customerMessage, array $menu, array $currentCart = [], string $lastBotAsk = ''): array
{
    require_once __DIR__ . '/ai_api.php';

    // Every early-return shape stays consistent so callers don't have to
    // branch on partial responses.
    $empty = [
        'ok' => false, 'intent' => 'none', 'items' => [],
        'remove_line_numbers' => [], 'unmatched' => [],
        'clarification' => null, 'error' => null,
    ];

    if (!ai_is_configured($company)) {
        return array_merge($empty, ['error' => 'AI is not configured for this workspace.']);
    }
    $apiKey = ai_api_key($company);
    if ($apiKey === '') {
        return array_merge($empty, ['error' => 'No Anthropic API key.']);
    }
    // Per-feature model — F&B cart parse can run on the cheaper tier
    // than customer-facing chat since it's a structured extraction call.
    require_once __DIR__ . '/ai_api.php';
    $model = ai_model_for_feature($company, 'fnb_cart_parse');

    // Minimal menu context for the model. Variants/addons kept lean.
    $menuLines = [];
    foreach ($menu as $p) {
        $line = "id={$p['id']}  {$p['name']}  ({$p['category']})  price={$p['price']}";
        $vGroups = [];
        foreach ($p['variants'] as $v) {
            $vGroups[$v['group']][] = "vid={$v['id']} \"{$v['name']}\" +{$v['price_delta']}";
        }
        foreach ($vGroups as $g => $opts) {
            $line .= "\n   variants[{$g}]: " . implode(' | ', $opts);
        }
        if ($p['addons']) {
            $addonBits = [];
            foreach ($p['addons'] as $a) $addonBits[] = "aid={$a['id']} \"{$a['name']}\" +{$a['price_delta']}";
            $line .= "\n   addons: " . implode(' | ', $addonBits);
        }
        $menuLines[] = $line;
    }

    // Render the current cart with 1-based numbering so the AI can map
    // "item 2" or "the chicken rice" to a specific cart index.
    $cartLines = [];
    foreach ($currentCart as $i => $ln) {
        $q  = (int)($ln['quantity']     ?? 1);
        $nm = (string)($ln['product_name'] ?? '');
        $bits = ["#" . ($i + 1) . " {$q}x {$nm}"];
        foreach ((array)($ln['variants'] ?? []) as $v) {
            $bits[] = "(" . ($v['group'] ?? '') . ": " . ($v['name'] ?? '') . ")";
        }
        foreach ((array)($ln['addons'] ?? []) as $a) {
            $bits[] = "+ " . ($a['name'] ?? '');
        }
        $cartLines[] = implode(' ', $bits);
    }
    $cartText = $cartLines ? implode("\n", $cartLines) : "(cart is empty)";

    $systemPrompt =
        "You interpret a restaurant customer's WhatsApp message about their order. "
      . "You decide if they want to ADD items, REMOVE items from the current cart, "
      . "CLEAR the cart entirely, or if the intent is unclear (NONE). Return ONLY a "
      . "single JSON object, no markdown fences, no preamble.\n\n"
      . "Schema:\n"
      . "{\n"
      . "  \"intent\": \"add\" | \"remove\" | \"clear\" | \"none\",\n"
      . "  \"items\": [                                // ONLY when intent = add\n"
      . "    {\n"
      . "      \"product_id\": int,\n"
      . "      \"quantity\": int (default 1),\n"
      . "      \"variants_pick\": [int, int],\n"
      . "      \"addons_pick\": [int, int],\n"
      . "      \"instructions\": string|null\n"
      . "    }\n"
      . "  ],\n"
      . "  \"remove_line_numbers\": [int, int],       // 1-based CART indexes to remove, ONLY when intent = remove\n"
      . "  \"unmatched\": [string],                    // customer phrases that don't map to menu / cart\n"
      . "  \"clarification\": string|null              // one short question to ask if ambiguous\n"
      . "}\n\n"
      . "Rules:\n"
      . "- ADD intent examples: '2 chicken rice', 'add nasi lemak', 'also 1 lemon tea'.\n"
      . "- REMOVE intent examples: 'remove item 2', 'take out the nasi lemak', 'cancel #1', 'delete chicken rice'.\n"
      . "  For remove, populate remove_line_numbers using 1-based indexes from the CURRENT CART shown below.\n"
      . "  If the customer names a product ('remove the chicken rice'), find its CART index and use that.\n"
      . "- CLEAR intent examples: 'clear cart', 'start over', 'cancel everything'. Leave arrays empty.\n"
      . "- Match product_id EXACTLY from the menu. Fuzzy-match names generously, including typos "
      . "(e.g. 'nasi gorend' == 'nasi goreng'), abbreviations, and language variants.\n"
      . "- '#N', 'item #N', 'item N', 'no N', or just a bare integer N ALWAYS refers to menu item at that "
      . "1-based position in the MENU list below. Treat that as an unambiguous product identifier and "
      . "add quantity=1 unless a quantity is explicitly given.\n"
      . "- PREFER ACTING OVER ASKING. If you can identify the product with reasonable confidence, add it "
      . "with quantity=1 (or the stated quantity) and set clarification=null. Only fall back to "
      . "intent=none + clarification if you truly cannot pick any product from the menu.\n"
      . "- If the PRIOR BOT QUESTION (below) already asked to disambiguate a specific item, and the "
      . "customer's message can plausibly be read as confirming that item ('yes', 'yes please', 'ok', "
      . "the item name, 'that one', a number), COMMIT to that item — do not re-ask.\n"
      . "- Instructions ('less spicy', 'no onion') go on the individual item, not as a separate line.\n"
      . "- Never invent a product_id, variant_id, addon_id, or cart index that doesn't exist.\n"
      . "- If nothing matches at all, intent = 'none', clarification set to a short helpful question.";

    $priorAsk = trim($lastBotAsk) !== ''
        ? "PRIOR BOT QUESTION (the bot just sent this to the customer — interpret the customer's "
        . "message as their answer):\n" . trim($lastBotAsk) . "\n\n"
        : '';

    $userPrompt =
        "MENU:\n" . implode("\n\n", $menuLines) . "\n\n"
      . "CURRENT CART (1-based indexes):\n" . $cartText . "\n\n"
      . $priorAsk
      . "CUSTOMER MESSAGE:\n" . $customerMessage;

    $payload = [
        'model'      => $model,
        'max_tokens' => 800,
        'system'     => [['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']]],
        'messages'   => [['role' => 'user', 'content' => $userPrompt]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return array_merge($empty, ['error' => 'Network error: ' . $err]);
    }
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['content'][0]['text'])) {
        return array_merge($empty, ['error' => 'AI returned an unexpected shape (HTTP ' . $code . ')']);
    }

    // Billing: log the F&B cart-parse call against this workspace so
    // AI chatbot metering catches it. Best-effort, silent on failure.
    if (!empty($company['id']) && !empty($data['usage'])) {
        require_once __DIR__ . '/ai_billing.php';
        ai_log_usage((int)$company['id'], null, 'fnb_cart_parse',
            $data['usage'] ?? null, (string)($data['model'] ?? $model));
    }

    $raw = trim((string)$data['content'][0]['text']);
    if (str_starts_with($raw, '```')) {
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $raw);
    }
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return array_merge($empty, ['error' => 'AI did not return valid JSON: ' . mb_substr($raw, 0, 200)]);
    }

    $intent = (string)($parsed['intent'] ?? 'none');
    if (!in_array($intent, ['add', 'remove', 'clear', 'none'], true)) $intent = 'none';

    return [
        'ok'                  => true,
        'intent'              => $intent,
        'items'               => (array)($parsed['items']               ?? []),
        'remove_line_numbers' => array_values(array_filter(array_map('intval',
                                    (array)($parsed['remove_line_numbers'] ?? [])),
                                    fn($n) => $n > 0)),
        'unmatched'           => (array)($parsed['unmatched']           ?? []),
        'clarification'       => $parsed['clarification'] ?? null,
        'error'               => null,
    ];
}

/**
 * Turn a list of AI-parsed items + the menu snapshot into cart lines
 * with hydrated price info + line totals. Called from the fnb_cart_add
 * flow node. Filters out any product_ids that aren't in the menu.
 */
function fnb_cart_lines_from_ai(array $aiItems, array $menu): array
{
    $byId = [];
    foreach ($menu as $p) $byId[(int)$p['id']] = $p;
    $lines = [];
    foreach ($aiItems as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        if (!isset($byId[$pid])) continue;
        $p = $byId[$pid];
        $qty = max(1, (int)($it['quantity'] ?? 1));

        // Pick variants: use AI's chosen ids, else defaults.
        $vIds  = array_map('intval', (array)($it['variants_pick'] ?? []));
        $vHave = [];
        foreach ($p['variants'] as $v) {
            if (in_array((int)$v['id'], $vIds, true)) $vHave[] = $v;
        }
        if (!$vHave) {
            // No pick - insert defaults per group.
            $seenGroups = [];
            foreach ($p['variants'] as $v) {
                if (!empty($v['is_default']) && !isset($seenGroups[$v['group']])) {
                    $vHave[] = $v;
                    $seenGroups[$v['group']] = true;
                }
            }
        }
        $variants = array_map(fn($v) => [
            'id' => (int)$v['id'], 'group' => (string)$v['group'],
            'name' => (string)$v['name'], 'price_delta' => (float)$v['price_delta'],
        ], $vHave);

        $aIds = array_map('intval', (array)($it['addons_pick'] ?? []));
        $addons = [];
        foreach ($p['addons'] as $a) {
            if (in_array((int)$a['id'], $aIds, true)) {
                $addons[] = ['id' => (int)$a['id'], 'name' => (string)$a['name'],
                             'price_delta' => (float)$a['price_delta']];
            }
        }

        $lineTotal = fnb_line_total((float)$p['price'], $variants, $addons, $qty);
        $lines[] = [
            'product_id'   => $pid,
            'product_name' => (string)$p['name'],
            'quantity'     => $qty,
            'unit_price'   => (float)$p['price'],
            'variants'     => $variants,
            'addons'       => $addons,
            'instructions' => (string)($it['instructions'] ?? ''),
            'line_total'   => $lineTotal,
        ];
    }
    return $lines;
}

/**
 * Materialize a completed cart + captured customer vars into fnb_orders +
 * fnb_order_items. Links the source conversation + contact so the order
 * detail page can "→ Open conversation" back to WhatsApp.
 *
 * Reads from state:
 *   state.cart                      - list of cart lines
 *   state.vars.order_type           - delivery|pickup
 *   state.vars.customer_name
 *   state.vars.customer_phone       - falls back to contact.wa_id if empty
 *   state.vars.delivery_address
 *   state.vars.delivery_notes
 *   state.vars.pickup_time
 *
 * Returns the created order id + human number, or ['ok' => false, ...] on error.
 */
function fnb_create_order_from_flow_state(array $state, int $conversationId, int $companyId): array
{
    $cart = (array)($state['cart'] ?? []);
    $vars = (array)($state['vars'] ?? []);
    if (!$cart) return ['ok' => false, 'error' => 'Empty cart.'];

    // Normalize order type — customer may have typed "delivery" / "pickup"
    // or tapped a numbered choice ("1" / "2") from a quick-reply pill.
    $rawOrderType = mb_strtolower(trim((string)($vars['order_type'] ?? 'delivery')));
    $orderType = match (true) {
        in_array($rawOrderType, ['1', 'd', 'delivery', 'deliver'], true) => 'delivery',
        in_array($rawOrderType, ['2', 'p', 'pickup', 'self-pickup', 'take away', 'takeaway', 'self pickup'], true) => 'pickup',
        default => 'delivery',
    };

    $db = aiserve_db();
    // Look up the conversation for contact_id, contact.wa_id fallback,
    // and the channel's branch_id so the order snapshots its location.
    // COALESCE prefers the channel's branch (a QR sitting on a counter is
    // the most authoritative signal); falls back to the contact's branch
    // if the channel isn't tagged yet.
    $cs = $db->prepare(
        'SELECT c.contact_id, c.channel_id,
                ct.wa_id,
                COALESCE(ch.branch_id, ct.branch_id) AS branch_id
         FROM conversations c
         INNER JOIN contacts  ct ON ct.id = c.contact_id
         LEFT  JOIN channels  ch ON ch.id = c.channel_id
         WHERE c.id = ? AND c.company_id = ? LIMIT 1'
    );
    $cs->execute([$conversationId, $companyId]);
    $convRow = $cs->fetch();
    if (!$convRow) return ['ok' => false, 'error' => 'Conversation not found.'];
    $branchId = (int)($convRow['branch_id'] ?? 0) ?: null;

    $custName  = trim((string)($vars['customer_name']    ?? ''));
    $custPhone = trim((string)($vars['customer_phone']   ?? '')) ?: (string)$convRow['wa_id'];
    $address   = trim((string)($vars['delivery_address'] ?? ''));
    $notes     = trim((string)($vars['delivery_notes']   ?? ''));
    $pickup    = trim((string)($vars['pickup_time']      ?? ''));

    $subtotal = 0.0;
    foreach ($cart as $ln) $subtotal += (float)($ln['line_total'] ?? 0);
    $deliveryFee = (float)($vars['delivery_fee'] ?? 0);
    $total       = round($subtotal + $deliveryFee, 2);

    try {
        $db->beginTransaction();
        $ins = $db->prepare(
            'INSERT INTO fnb_orders
                (company_id, conversation_id, contact_id, branch_id, order_type,
                 customer_name, customer_phone, delivery_address, delivery_notes, pickup_time,
                 subtotal, delivery_fee, total, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "new")'
        );
        $ins->execute([
            $companyId, $conversationId, (int)$convRow['contact_id'], $branchId, $orderType,
            $custName ?: '(unknown)', $custPhone ?: null,
            $orderType === 'delivery' ? ($address ?: null) : null,
            $notes ?: null,
            $orderType === 'pickup' && $pickup ? $pickup : null,
            $subtotal, $deliveryFee, $total,
        ]);
        $orderId = (int)$db->lastInsertId();
        $orderNum = fnb_order_number_from_id($orderId);
        $db->prepare('UPDATE fnb_orders SET order_number = ? WHERE id = ?')->execute([$orderNum, $orderId]);

        $iIns = $db->prepare(
            'INSERT INTO fnb_order_items
                (order_id, product_id, product_name, quantity, unit_price,
                 variants_json, addons_json, instructions, line_total, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($cart as $i => $ln) {
            $iIns->execute([
                $orderId,
                (int)($ln['product_id'] ?? 0) ?: null,
                (string)($ln['product_name'] ?? '(unknown)'),
                (int)($ln['quantity'] ?? 1),
                (float)($ln['unit_price'] ?? 0),
                !empty($ln['variants']) ? json_encode($ln['variants'], JSON_UNESCAPED_UNICODE) : null,
                !empty($ln['addons'])   ? json_encode($ln['addons'],   JSON_UNESCAPED_UNICODE) : null,
                trim((string)($ln['instructions'] ?? '')) ?: null,
                (float)($ln['line_total'] ?? 0),
                10 * ($i + 1),
            ]);
        }
        $db->commit();

        log_activity($companyId, null, 'fnb_order_created_via_flow', 'fnb_order', $orderId, $orderNum);

        // Best-effort staff notification email — same recipients pattern
        // as the broadcast quota cron. Non-fatal on failure.
        try {
            fnb_notify_staff_on_new_order($companyId, $orderId, $orderNum, $custName, $total);
        } catch (Throwable $e) {
            error_log('[AiServe fnb_notify] ' . $e->getMessage());
        }

        return ['ok' => true, 'order_id' => $orderId, 'order_number' => $orderNum, 'total' => $total];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[AiServe fnb_create_order_from_flow_state] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'DB error: ' . $e->getMessage()];
    }
}

/**
 * Fire an email to workspace admins when a new order lands via a flow.
 * Reuses the alert_email + super_admin pattern from the broadcast quota
 * cron. mail() failures are best-effort — the order is already saved.
 */
function fnb_notify_staff_on_new_order(int $companyId, int $orderId, string $orderNum, string $custName, float $total): void
{
    $db = aiserve_db();
    $c = $db->prepare('SELECT name, alert_email FROM companies WHERE id = ?');
    $c->execute([$companyId]);
    $co = $c->fetch();
    if (!$co) return;

    $recipients = [];
    if (!empty($co['alert_email']) && filter_var((string)$co['alert_email'], FILTER_VALIDATE_EMAIL)) {
        $recipients[] = (string)$co['alert_email'];
    }
    $u = $db->prepare("SELECT email FROM users WHERE company_id = ? AND status = 'active' AND role = 'super_admin'");
    $u->execute([$companyId]);
    foreach ($u->fetchAll() as $r) {
        if (filter_var((string)$r['email'], FILTER_VALIDATE_EMAIL)) $recipients[] = (string)$r['email'];
    }
    $recipients = array_values(array_unique($recipients));
    if (!$recipients) return;

    $currency = platform_setting('pricing_currency', 'RM');
    $base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
        ? rtrim((string)APP_BASE_URL, '/')
        : ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''));
    $url = $base . '/admin/fnb_order_view.php?id=' . $orderId;

    $subject = "[{$co['name']}] New order $orderNum from " . $custName;
    $body =
        "A new WhatsApp order just came in.\n\n"
      . "Order:    $orderNum\n"
      . "Customer: " . ($custName ?: '(unknown)') . "\n"
      . "Total:    $currency " . number_format($total, 2) . "\n\n"
      . "Open the order: $url\n\n"
      . "— AiServe Inbox\n";

    $headers = [
        'From: AiServe Inbox <noreply@' . preg_replace('/^https?:\\/\\//', '', (string)platform_setting('operator_email', 'noreply@aiserve.my')) . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    foreach ($recipients as $to) {
        @mail($to, $subject, $body, implode("\r\n", $headers));
    }
}

/**
 * Formatted human-facing order number from a row's numeric id.
 *   id=1   -> "A10001"
 *   id=42  -> "A10042"
 *   id=500 -> "A10500"
 * Adds 10000 so the count of orders on a fresh workspace isn't visible.
 */
function fnb_order_number_from_id(int $id): string
{
    return 'A' . str_pad((string)($id + 10000), 5, '0', STR_PAD_LEFT);
}

/**
 * Compute the price of one line item given base + variant deltas +
 * addon deltas + quantity. Both variant / addon arrays accept either
 * ['price_delta' => 2.5] or the raw float (for flexibility).
 */
function fnb_line_total(float $unitPrice, array $variants, array $addons, int $qty): float
{
    $extra = 0.0;
    foreach ($variants as $v) {
        $extra += is_array($v) ? (float)($v['price_delta'] ?? 0) : (float)$v;
    }
    foreach ($addons as $a) {
        $extra += is_array($a) ? (float)($a['price_delta'] ?? 0) : (float)$a;
    }
    return round(($unitPrice + $extra) * $qty, 2);
}

/**
 * Colored status label used across the order UI. Reserves the palette
 * so a rebrand only touches this map.
 */
function fnb_status_label(string $status): string
{
    $map = [
        'new'        => ['New',         '#2563EB'],
        'confirmed'  => ['Confirmed',   '#9333EA'],
        'processing' => ['Processing',  '#F59E0B'],
        'completed'  => ['Completed',   '#16A34A'],
        'cancelled'  => ['Cancelled',   '#94A3B8'],
    ];
    if (!isset($map[$status])) return htmlspecialchars($status);
    [$label, $color] = $map[$status];
    return '<span style="display:inline-block; padding:2px 10px; border-radius:999px; '
         . 'background:' . $color . '22; color:' . $color . '; font-weight:600; font-size:12px;">'
         . htmlspecialchars($label) . '</span>';
}

/**
 * The forward-only status transition. Given the current status, returns
 * the next natural status + a button label, or null for terminal states.
 * cancelled is reachable from any non-terminal state via a separate button.
 */
function fnb_next_status(string $status): ?array
{
    return match ($status) {
        'new'        => ['confirmed',  'Confirm →'],
        'confirmed'  => ['processing', 'Start processing →'],
        'processing' => ['completed',  'Mark completed →'],
        default      => null,
    };
}

/**
 * Seed a working F&B ordering flow: welcome → ask type → menu → parse
 * cart loop → collect name + address → materialize order.
 *
 * When $goLive is true (widget quick-setup default), the flow ships as
 * status=active with a new_conversation trigger so the very first
 * message on any new channel — WhatsApp, web widget — fires it.
 *
 * When false (the historic "seed for editing" path from admin/flows.php),
 * ships as status=draft with a keyword trigger so the operator can
 * review + tweak before flipping live.
 *
 * Returns the new flow id. Wraps everything in a transaction — a
 * mid-seed failure leaves no orphans.
 */
function fnb_seed_starter_flow(PDO $db, int $companyId, int $userId, bool $goLive = true): int
{
    $status  = $goLive ? 'active'           : 'draft';
    $trigger = $goLive ? 'new_conversation' : 'keyword';
    $kw      = $goLive ? null               : 'order,menu,food,makan';

    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO flows (company_id, name, trigger_type, trigger_keywords, status, created_by_user_id)
             VALUES (?, "F&B order taking (starter)", ?, ?, ?, ?)'
        )->execute([$companyId, $trigger, $kw, $status, $userId]);
        $fid = (int)$db->lastInsertId();

        $nins = $db->prepare(
            'INSERT INTO flow_nodes (flow_id, node_type, label, config) VALUES (?, ?, ?, ?)'
        );
        $node = function (string $type, string $label, array $config = []) use ($nins, $fid, $db) {
            $nins->execute([$fid, $type, $label, json_encode($config, JSON_UNESCAPED_UNICODE)]);
            return (int)$db->lastInsertId();
        };

        $nWelcome    = $node('send_message',     'Welcome greeting',
            ['text' => "Welcome! 🍽️ How would you like your order?\n\n"
                     . "1. Delivery\n"
                     . "2. Self-pickup\n\n"
                     . "_Reply with 1 or 2 — or just tap the button below._"]);
        $nWaitType   = $node('wait_reply',       'Wait for order type',
            ['var_name' => 'order_type']);
        $nSendMenu   = $node('fnb_send_menu',    'Send menu');
        $nWaitOrder  = $node('wait_reply',       'Wait for order details',
            ['var_name' => 'raw_order']);
        $nCart       = $node('fnb_cart_add',     'AI: parse into cart');
        $nWaitDone   = $node('wait_reply',       'Wait for "done" or more items',
            ['var_name' => 'more_items']);
        $nBranchDone = $node('branch',           'Done or add more?');
        $nAskName    = $node('send_message',     'Ask for customer name',
            ['text' => "Got it. What name should we put on the order?"]);
        $nWaitName   = $node('wait_reply',       'Wait for name',
            ['var_name' => 'customer_name']);
        $nAskAddr    = $node('send_message',     'Ask for address / pickup time',
            ['text' => "Please share your *delivery address* (or *pickup time* if picking up)."]);
        $nWaitAddr   = $node('wait_reply',       'Wait for address',
            ['var_name' => 'delivery_address']);
        $nCreate     = $node('fnb_create_order', 'Create the order');
        $nEnd        = $node('end',              'End');

        $nextMap = [
            $nWelcome    => $nWaitType,
            $nWaitType   => $nSendMenu,
            $nSendMenu   => $nWaitOrder,
            $nWaitOrder  => $nCart,
            $nCart       => $nWaitDone,
            $nWaitDone   => $nBranchDone,
            $nAskName    => $nWaitName,
            $nWaitName   => $nAskAddr,
            $nAskAddr    => $nWaitAddr,
            $nCreate     => $nEnd,
        ];
        $upd = $db->prepare('UPDATE flow_nodes SET next_node_id = ? WHERE id = ?');
        foreach ($nextMap as $from => $to) $upd->execute([$to, $from]);

        $eIns = $db->prepare(
            'INSERT INTO flow_edges (flow_id, from_node_id, to_node_id, condition_type, condition_value, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $eIns->execute([$fid, $nBranchDone, $nAskName, 'keyword', 'done', 1]);
        $eIns->execute([$fid, $nBranchDone, $nCart,    'default', null,   2]);

        $db->prepare('UPDATE flows SET entry_node_id = ? WHERE id = ?')->execute([$nWelcome, $fid]);
        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Does this company have at least one active flow whose trigger can
 * plausibly fire on an inbound message (either new_conversation or a
 * keyword-configured flow)? Used by admin/webchat.php to warn the
 * operator that their widget messages will land in the inbox but no
 * bot will reply.
 */
function fnb_has_reachable_active_flow(PDO $db, int $companyId): bool
{
    $q = $db->prepare(
        'SELECT COUNT(*) FROM flows
         WHERE company_id = ? AND status = "active"
           AND (
               trigger_type = "new_conversation"
               OR (trigger_type = "keyword" AND trigger_keywords IS NOT NULL AND trigger_keywords <> "")
           )'
    );
    $q->execute([$companyId]);
    return (int)$q->fetchColumn() > 0;
}
