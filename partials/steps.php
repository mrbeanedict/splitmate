<?php
// Details → Scan → Review → Assign progress indicator, numbered 1–4. Pages set $step to 1 (Scan), 2 (Review) or
// 3 (Assign); Details comes before them and is always done (the bill exists). With $billId set, Details links back
// to the Create Bill form to change the bill's details.
$names = [0 => 'Details', 1 => 'Scan', 2 => 'Review', 3 => 'Assign'];
$detailsLink = !empty($billId) ? 'my-bills?edit=' . (int) $billId : null;
?>
<div class="steps">
<?php foreach ($names as $n => $label): $cls = $n < $step ? 'done' : ($n === $step ? 'current' : ''); ?>
  <?php if ($n > 0): ?><div class="step-line<?= $n <= $step ? ' done' : '' ?>"></div><?php endif; ?>
  <?php if ($n === 0 && $detailsLink): ?>
  <a href="<?= h($detailsLink) ?>" class="step step-link <?= $cls ?>" title="Change the bill name, members or who paid"><span class="dot">✓</span><?= $label ?></a>
  <?php else: ?>
  <div class="step <?= $cls ?>"><span class="dot"><?= $n < $step ? '✓' : $n + 1 ?></span><?= $label ?></div>
  <?php endif; ?>
<?php endforeach; ?>
</div>
