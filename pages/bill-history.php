<?php
// Bill History is now the "Settlement history" tab of Financial Activity; old links and bookmarks land there.
require __DIR__ . '/../includes/bootstrap.php';
require_login();
redirect('pages/financial-activity?tab=history');
