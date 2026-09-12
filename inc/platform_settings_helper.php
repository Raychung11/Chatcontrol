<?php
/**
 * Tiny shared helper for platform_settings admin forms.
 *
 * Both /admin/pricing.php and /admin/legal.php render the same shape of form
 * (each field has label/type/hint, may be optional, may have step/max) and the
 * same save behaviour (validate, upsert into platform_settings inside a
 * transaction, log to activity). This lets both pages stay short.
 */

/**
 * Validate POST against $fields and upsert each accepted value. Returns the
 * msg/err pair the caller renders. Does not echo anything.
 *
 * @param array<string, array{label:string,type:string,hint:string,max?:int,step?:string,optional?:bool}> $fields
 */
function platform_settings_save(PDO $db, array $fields, string $logAction, int $companyId, int $userId): array
{
    $values = [];
    foreach ($fields as $key => $meta) {
        $raw  = trim((string)($_POST[$key] ?? ''));
        $type = (string)($meta['type'] ?? 'text');
        // Password: blank input = keep the previously-stored value.
        if ($type === 'password' && $raw === '') continue;
        if ($type === 'number') {
            if ($raw === '' || !is_numeric($raw) || (float)$raw < 0) {
                return ['msg' => '', 'err' => $meta['label'] . ' must be a non-negative number.'];
            }
            $f = (float)$raw;
            $raw = (abs($f - round($f)) < 0.005) ? (string)(int)round($f) : (string)$f;
        }
        if ($type === 'email' && $raw !== '' && !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return ['msg' => '', 'err' => $meta['label'] . ' must be a valid email address.'];
        }
        if ($raw === '' && in_array($type, ['text', 'number', 'email'], true) && empty($meta['optional'])) {
            return ['msg' => '', 'err' => $meta['label'] . ' is required.'];
        }
        $values[$key] = $raw;
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO platform_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $db->beginTransaction();
        foreach ($values as $k => $v) $stmt->execute([$k, $v]);
        $db->commit();
        log_activity($companyId, $userId, $logAction, 'platform', 0, '');
        return ['msg' => 'Saved. Changes are live immediately.', 'err' => ''];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[AiServe] ' . $logAction . ' save failed: ' . $e->getMessage());
        return ['msg' => '', 'err' => 'Could not save — check the migration is in (sql/migration_phase14.sql).'];
    }
}

/**
 * Pull the full platform_settings map. Returns empty array (with a hint) if
 * the table doesn't exist yet.
 *
 * @return array{rows: array<string,string>, err: string}
 */
function platform_settings_load(PDO $db): array
{
    try {
        $rows = $db->query('SELECT `key`, `value` FROM platform_settings')->fetchAll();
        $map  = [];
        foreach ($rows as $r) $map[$r['key']] = (string)$r['value'];
        return ['rows' => $map, 'err' => ''];
    } catch (Throwable $e) {
        return ['rows' => [], 'err' => 'platform_settings table not found — run sql/migration_phase14.sql first.'];
    }
}

/**
 * Render one labelled input matching the field metadata. Echoes HTML.
 */
function render_platform_setting_field(string $key, array $meta, string $val): void
{
    $req  = empty($meta['optional']) ? 'required' : '';
    $type = (string)($meta['type'] ?? 'text');
    ?>
    <label>
      <?= e($meta['label']) ?>
      <?php if (!empty($meta['optional'])): ?><small class="muted">(optional)</small><?php endif; ?>
      <?php if ($type === 'textarea'): ?>
        <textarea name="<?= e($key) ?>" rows="<?= (int)($meta['rows'] ?? 3) ?>"><?= e($val) ?></textarea>
      <?php elseif ($type === 'number'): ?>
        <input type="number" name="<?= e($key) ?>" value="<?= e($val) ?>"
               step="<?= e($meta['step'] ?? '1') ?>" min="0" <?= $req ?>>
      <?php elseif ($type === 'password'): ?>
        <input type="password" name="<?= e($key) ?>"
               autocomplete="new-password"
               placeholder="<?= $val !== '' ? '•••••••• (' . strlen($val) . ' chars saved — leave blank to keep)' : 'not set' ?>"
               <?= $req ?>>
      <?php elseif ($type === 'email'): ?>
        <input type="email" name="<?= e($key) ?>" value="<?= e($val) ?>"
               <?= isset($meta['max']) ? 'maxlength="' . (int)$meta['max'] . '"' : '' ?> <?= $req ?>>
      <?php elseif ($type === 'select'): ?>
        <select name="<?= e($key) ?>" <?= $req ?>>
          <?php foreach ((array)($meta['options'] ?? []) as $optV => $optL): ?>
            <option value="<?= e((string)$optV) ?>" <?= (string)$val === (string)$optV ? 'selected' : '' ?>>
              <?= e((string)$optL) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="text" name="<?= e($key) ?>" value="<?= e($val) ?>"
               <?= isset($meta['max']) ? 'maxlength="' . (int)$meta['max'] . '"' : '' ?> <?= $req ?>>
      <?php endif; ?>
      <small class="muted"><?= e($meta['hint']) ?></small>
    </label>
    <?php
}
