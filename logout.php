<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
start_session();
// Logout via POST with CSRF (link in app submits a small form)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
    logout();
}
header('Location: login.php');
exit;
