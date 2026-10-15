<?php
// Spending is now the "Spending" tab of Financial Activity; old links and bookmarks land there.
require __DIR__ . '/../includes/bootstrap.php';
require_login();
redirect('pages/financial-activity' . (($_GET['tab'] ?? '') === 'history' ? '?tab=history' : ''));
