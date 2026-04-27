<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);

layout_start($current_user, 'AI Settings', 'ai_settings');
?>
<div class="card">
  <h2>AI reply assistant (placeholder)</h2>
  <p class="muted">
    AI features are planned for a future phase and will <strong>never</strong> auto-send a reply by default.
    The roadmap below will appear here as full settings once enabled.
  </p>

  <ul class="bullet">
    <li><strong>Suggested reply</strong> — agent sees a draft they can edit and approve</li>
    <li><strong>FAQ assistant</strong> — answer based on a knowledge base</li>
    <li><strong>Auto summary</strong> — summarize long conversations for handover</li>
    <li><strong>Sentiment detection</strong> — flag angry / positive customers</li>
    <li><strong>Lead scoring</strong> — rate likelihood of conversion</li>
    <li><strong>Auto tagging</strong> — categorize conversations automatically</li>
  </ul>

  <div class="alert alert-info">
    Wiring up an LLM provider (Claude, OpenAI, etc.) will be done in Phase 3.
    For now this page is a placeholder so the navigation, schema, and UI scaffolding are ready.
  </div>
</div>
<?php layout_end(); ?>
