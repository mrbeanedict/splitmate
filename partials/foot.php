<?php
// Setlo AI chat button on the signed-in user pages that have the sidebar/tab bar ($nav is set), never in the admin area.
if (!empty($nav) && function_exists('session_area') && session_area() !== 'admin' && current_user()) {
    require __DIR__ . '/assistant.php';
}
?>
</body>
</html>
