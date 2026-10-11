<?php
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if (!$user) {
    redirect('pages/login');
}
redirect($user['role'] === 'admin' ? 'pages/admin-dashboard' : 'pages/dashboard'); 