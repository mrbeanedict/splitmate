<?php
// Navigation: bottom tab bar on phones, sectioned left sidebar on laptops (see .nav2 in src/input.css), and a
// slide-in menu on phones with the same sections. Set $nav to one of the keys below before including.
//
// Each link: key => [href, sidebar label, bottom-bar label or null, icon paths]. Links with a bottom-bar label are
// the phone tabs (Home, Scan, Settle, then Menu); the rest are sidebar/menu only.
$sections = [
    '' => [
        'dashboard' => ['dashboard', 'Dashboard', 'Home', '<path stroke-linecap="round" stroke-linejoin="round" d="M3 11l9-7 9 7M5 9.5V20h5v-5h4v5h5V9.5"/>'],
        'scan'      => ['scan-receipt', 'Scan a receipt', 'Scan', '<path stroke-linecap="round" stroke-linejoin="round" d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M8 12h8"/>'],
    ],
    // Collapsible groups (heading => [icon, tone, open by default]). Group Finance starts open.
    'Group Finance' => [
        'bills'  => ['my-bills', 'My bills', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 8h6M9 12h6"/>'],
        'groups' => ['groups', 'Groups', null, '<circle cx="9" cy="8" r="3.2"/><circle cx="17" cy="9" r="2.6"/><path stroke-linecap="round" d="M3 19c.8-3 3.2-4.6 6-4.6s5.2 1.6 6 4.6M15.5 14.6c2.6-.3 4.6 1.2 5.5 4.4"/>'],
        'settle' => ['my-settlements', 'Settle up', 'Settle', '<rect x="3" y="6" width="18" height="14" rx="2.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 13h2M3 10h14a2 2 0 012 2"/>'],
    ],
    'Personal Finance' => [
        'purchases' => ['purchases', 'Personal expenses', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l1 13H5L6 7zM9 7a3 3 0 016 0"/>'],
        'utang'     => ['utang', 'Utang', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M7 10h10M7 14h6M17 3l4 4-4 4M21 7H9a6 6 0 00-6 6v0a6 6 0 006 6h3"/>'],
    ],
    // Standalone pages
    'More' => [
        'stats'     => ['stats', 'Spending overview', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>'],
        'history'   => ['stats?tab=history', 'Settlement history', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 109-9 9 9 0 00-6.4 2.6L3 8M3 3v5h5M12 7v5l3 2"/>'],
        'community' => ['community', 'Community', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-1v3l-3-3h-5a2 2 0 01-2-2v-1M15 4H5a2 2 0 00-2 2v6a2 2 0 002 2h1v3l3-3h6a2 2 0 002-2V6a2 2 0 00-2-2z"/>'],
        'help'      => ['help', 'Help & Support', null, '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M9.5 9.5a2.5 2.5 0 115 0c0 1.5-2.5 2-2.5 3.5M12 17h.01"/>'],
    ],
    'Account' => [
        'profile'       => ['profile', 'My profile', null, '<circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5"/>'],
        'payments'      => ['payment-methods', 'Payment methods', null, '<rect x="3" y="6" width="18" height="13" rx="2.5"/><path stroke-linecap="round" d="M3 10h18M7 15h3"/>'],
        'settings'      => ['account-settings', 'Account settings', null, '<circle cx="12" cy="12" r="3"/><path stroke-linecap="round" d="M12 3v2.5M12 18.5V21M3 12h2.5M18.5 12H21M5.6 5.6l1.8 1.8M16.6 16.6l1.8 1.8M5.6 18.4l1.8-1.8M16.6 7.4l1.8-1.8"/>'],
        'notifications' => ['notifications', 'Notifications', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>'],
    ],
];
// Group metadata: [css tone, open by default, icon]
$groups = [
    'Group Finance'    => ['group', true,  '<circle cx="9" cy="8" r="3.2"/><circle cx="17" cy="9" r="2.6"/><path stroke-linecap="round" d="M3 19c.8-3 3.2-4.6 6-4.6s5.2 1.6 6 4.6"/>'],
    'Account'          => ['account', false, '<circle cx="12" cy="12" r="3"/><path stroke-linecap="round" d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/>'],
    'Personal Finance' => ['personal', false, '<circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5"/>'],
];
// "Settlement history" shares the stats page, so tell the two apart by ?tab=
$onHistory = ($_GET['tab'] ?? '') === 'history';
$icon = fn (string $paths) => '<svg class="h-[22px] w-[22px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">' . $paths . '</svg>';
$burger = '<svg class="h-[22px] w-[22px] shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>';
$signOut = $icon('<path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>');
$isActive = fn (string $key) => $key === 'history' ? (($nav ?? '') === 'stats' && $onHistory) : (($nav ?? '') === 'stats' && $key === 'stats' ? !$onHistory : ($nav ?? '') === $key);
$chev = '<svg class="nav-chev h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>';
$groupOpen = function (string $heading, array $links) use ($groups, $isActive): bool {
    if (!isset($groups[$heading])) return true;
    foreach ($links as $key => $_) if ($isActive($key)) return true;
    return $groups[$heading][1];
};
?>
<nav class="nav2">
  <!-- Laptop: logo + burger (shrinks the sidebar to icons) -->
  <div class="nav2-head">
    <a href="dashboard" class="nav2-logo" aria-label="Setlo home">
      <img src="<?= h(url('assets/icons/icon-192.png')) ?>" alt="" class="h-8 w-8 rounded-lg" /> setlo
    </a>
    <button type="button" class="nav-burger" data-nav-toggle="lg" aria-label="Show or hide the sidebar" title="Show or hide the sidebar"><?= $burger ?></button>
  </div>
<?php foreach ($sections as $heading => $links): ?>
<?php if (isset($groups[$heading])): [$tone, , $gIcon] = $groups[$heading]; $open = $groupOpen($heading, $links); ?>
  <div class="nav-group nav-group-<?= $tone ?><?= $open ? ' open' : '' ?>" data-nav-group="<?= $tone ?>">
    <button type="button" class="nav-group-head" data-group-toggle aria-expanded="<?= $open ? 'true' : 'false' ?>"><?= $icon($gIcon) ?><span class="nav-label"><?= h($heading) ?></span><?= $chev ?></button>
    <div class="nav-group-body">
<?php foreach ($links as $key => [$href, $label, $tab, $paths]): ?>
      <a href="<?= $href ?>" data-tour="nav-<?= $key ?>" title="<?= h($label) ?>" class="<?= $tab ? '' : '!hidden lg:!flex' ?><?= $isActive($key) ? ' active' : '' ?>"<?= $isActive($key) ? ' aria-current="page"' : '' ?>><?= $icon($paths) ?><span class="nav-label"><span class="lg:hidden"><?= h($tab ?? $label) ?></span><span class="hidden lg:inline"><?= h($label) ?></span></span></a>
<?php endforeach; ?>
    </div>
  </div>
<?php else: ?>
<?php if ($heading === 'More'): ?>
  <hr class="nav-rule" />
<?php endif; ?>
<?php foreach ($links as $key => [$href, $label, $tab, $paths]): ?>
  <a href="<?= $href ?>" data-tour="nav-<?= $key ?>" title="<?= h($label) ?>" class="<?= $tab ? '' : '!hidden lg:!flex' ?><?= $isActive($key) ? ' active' : '' ?>"<?= $isActive($key) ? ' aria-current="page"' : '' ?>><?= $icon($paths) ?><span class="nav-label"><span class="lg:hidden"><?= h($tab ?? $label) ?></span><span class="hidden lg:inline"><?= h($label) ?></span></span></a>
<?php endforeach; ?>
<?php endif; ?>
<?php endforeach; ?>
  <!-- Phones: 4th tab opens the slide-in menu -->
  <button type="button" class="lg:!hidden" data-nav-toggle="lg" aria-label="Open menu"><?= $burger ?>Menu</button>
  <form method="post" action="logout" class="nav-signout hidden lg:mt-auto lg:block lg:border-t lg:border-[#e8eeee] lg:pt-3">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>" />
    <button class="!text-rose-600" title="Sign out"><?= $signOut ?><span class="nav-label">Sign out</span></button>
  </form>
</nav>

<!-- Phones: slide-in menu, same sections as the laptop sidebar -->
<div class="nav-backdrop" data-nav-close></div>
<aside class="nav-drawer" aria-label="Menu">
  <div class="mb-2 flex items-center justify-between px-1">
    <a href="dashboard" class="flex items-center gap-2 px-1 text-lg font-extrabold text-ink">
      <img src="<?= h(url('assets/icons/icon-192.png')) ?>" alt="" class="h-8 w-8 rounded-lg" /> setlo
    </a>
    <button type="button" class="nav-burger" data-nav-close aria-label="Close menu">
      <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
<?php foreach ($sections as $heading => $links): ?>
<?php if (isset($groups[$heading])): [$tone, , $gIcon] = $groups[$heading]; $open = $groupOpen($heading, $links); ?>
  <div class="nav-group nav-group-<?= $tone ?><?= $open ? ' open' : '' ?>" data-nav-group="<?= $tone ?>">
    <button type="button" class="nav-group-head" data-group-toggle aria-expanded="<?= $open ? 'true' : 'false' ?>"><?= $icon($gIcon) ?><span class="nav-label"><?= h($heading) ?></span><?= $chev ?></button>
    <div class="nav-group-body">
<?php foreach ($links as $key => [$href, $label, $tab, $paths]): ?>
      <a href="<?= $href ?>"<?= $isActive($key) ? ' class="active" aria-current="page"' : '' ?>><?= $icon($paths) ?><?= h($label) ?></a>
<?php endforeach; ?>
    </div>
  </div>
<?php else: ?>
<?php if ($heading === 'More'): ?>
  <hr class="nav-rule" />
<?php endif; ?>
<?php foreach ($links as $key => [$href, $label, $tab, $paths]): ?>
  <a href="<?= $href ?>"<?= $isActive($key) ? ' class="active" aria-current="page"' : '' ?>><?= $icon($paths) ?><?= h($label) ?></a>
<?php endforeach; ?>
<?php endif; ?>
<?php endforeach; ?>
  <form method="post" action="logout" class="nav-signout mt-auto border-t border-[#e8eeee] pt-3">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>" />
    <button class="nav-drawer-out"><?= $signOut ?>Sign out</button>
  </form>
</aside>
