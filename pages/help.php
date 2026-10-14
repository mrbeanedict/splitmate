<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Help & Support';
$back = 'dashboard.php';
$nav = 'help';
$email = config('SUPPORT_EMAIL');
$faqs = [
    ['How do I split a bill?', 'Scan a receipt or start a bill from My bills, add who was there, assign the items, and Setlo works out what each person owes.'],
    ['How do I pay someone back?', 'Open Settle up, choose the person, and pay by their preferred method. They confirm it when the money arrives. Partial payments are fine.'],
    ['What is the difference between My Debts & Repayments and Utang?', 'My Debts & Repayments is where you record and manage personal loans. Utang is the overview: those loans plus change credits from group payments, without counting anything twice.'],
    ['Why is a record "awaiting confirmation"?', 'Records count once the other person confirms them. They can confirm it or say it isn’t right.'],
    ['A scanned receipt got something wrong.', 'Receipts are read automatically and can contain mistakes. Fix the items and amounts on the review screen before you continue.'],
    ['I forgot my password.', 'On the sign-in page choose “Forgot password?” and we’ll email you a 6-digit code. You can change it any time under Account Settings.'],
];
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>
  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="text-[21px] font-extrabold tracking-tight">Help &amp; Support</h1>
        <p class="text-[12px] font-medium text-brand-50/90">FAQs, contact details and issue reporting</p>
      </div>
    </div>
  </div>

  <div class="flex-1 space-y-5 px-5 pb-8 pt-4">
    <section>
      <p class="section-label">FAQS</p>
      <div class="tile divide-y divide-slate-100 overflow-hidden">
<?php foreach ($faqs as [$q, $a]): ?>
        <details class="group px-4 py-3">
          <summary class="cursor-pointer list-none text-[14px] font-bold text-ink"><?= h($q) ?></summary>
          <p class="mt-2 text-[13px] leading-relaxed text-slate-500"><?= h($a) ?></p>
        </details>
<?php endforeach; ?>
      </div>
    </section>

    <section>
      <p class="section-label">CONTACT &amp; REPORT AN ISSUE</p>
      <div class="tile space-y-2 p-4 text-[13px] text-slate-600">
<?php if ($email): ?>
        <p>Email us at <a class="font-bold text-brand-700" href="mailto:<?= h($email) ?>?subject=<?= rawurlencode('Setlo support: ' . $user['full_name']) ?>"><?= h($email) ?></a>. Tell us what you were doing, what you expected, and what happened instead. Screenshots help.</p>
<?php else: ?>
        <p>Found a problem? Tell the Setlo app manager what you were doing, what you expected, and what happened instead. Screenshots help.</p>
<?php endif; ?>
        <p>You can also leave feedback through <a class="font-bold text-brand-700" href="reviews">Rate Setlo</a>.</p>
      </div>
    </section>

    <section>
      <p class="section-label">MORE</p>
      <div class="tile divide-y divide-slate-100 overflow-hidden text-[14px] font-bold">
        <a href="dashboard?tour=1" class="block px-4 py-3 text-ink">Replay the tour</a>
        <a href="conditions_policy" class="block px-4 py-3 text-ink">Terms &amp; Privacy</a>
      </div>
    </section>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>
<script>Setlo.mount({});</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
