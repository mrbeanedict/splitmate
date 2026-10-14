<?php
// Setlo AI: the floating mascot button and chat panel (assets/js/assistant.js). Included from partials/foot.php on
// signed-in user pages only, and only when the server has a Groq key (otherwise there is nothing to talk to).
require_once __DIR__ . '/../includes/groq.php';
if (!groq_available()) {
    return;
}
$mascot = url('assets/mascot/');
?>
<link rel="stylesheet" href="<?= h(url('assets/css/assistant.css')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/assistant.css') ?>" />
<div id="sai" class="sai" data-mascot="<?= h($mascot) ?>">
  <button type="button" class="sai-fab" aria-label="Open Setlo AI" aria-expanded="false" aria-controls="sai-panel">
    <img src="<?= h($mascot) ?>wave.png" alt="" width="45" height="45" />
    <span class="sai-fab-tip">Ask Setlo Buddy</span>
  </button>
  <section id="sai-panel" class="sai-panel" role="dialog" aria-label="Setlo AI chat" hidden>
    <header class="sai-head">
      <img src="<?= h($mascot) ?>think.png" alt="" width="42" height="42" />
      <div class="sai-title"><b>Setlo Buddy</b><small>Ask about your bills, spending, or how Setlo works</small></div>
      <button type="button" class="sai-icon-btn sai-clear" aria-label="Clear chat" title="Clear chat">
        <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>
      </button>
      <button type="button" class="sai-icon-btn sai-close" aria-label="Close Setlo AI" title="Close">
        <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </header>
    <div class="sai-log" role="log" aria-live="polite"></div>
    <div class="sai-chips" aria-label="Suggested questions"></div>
    <form class="sai-form" autocomplete="off">
      <textarea rows="1" maxlength="1000" placeholder="Ask anything about Setlo…" aria-label="Your message"></textarea>
      <button type="submit" class="sai-send" aria-label="Send">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
      </button>
    </form>
    <p class="sai-note">AI can make mistakes. Check important amounts on the page.</p>
  </section>
</div>
<script src="<?= h(url('assets/js/assistant.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/assistant.js') ?>" defer></script>
