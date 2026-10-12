<?php
// My own page backdrop photo (users.bg_image). Only its owner ever sees it, behind their own pages.
require __DIR__ . '/../includes/api.php';
require __DIR__ . '/../includes/uploads.php';

$me = api_user();
serve_upload('backgrounds', q('SELECT bg_image FROM users WHERE id = ?', [$me['id']])->fetchColumn() ?: null);
