<?php
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if (!$user) {
    // Signed-out visitors get the landing page (about, guidelines, tutorials, reviews)
    require __DIR__ . '/pages/landing.php';
    exit;
}
redirect($user['role'] === 'admin' ? 'pages/admin-dashboard' : 'pages/dashboard');
