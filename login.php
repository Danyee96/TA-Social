<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
start_session();

if (current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
$email = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = (string)($_POST['email'] ?? '');
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        [$ok, $error] = attempt_login($email, (string)($_POST['password'] ?? ''));
        if ($ok) {
            header('Location: index.php');
            exit;
        }
    }
}

$ws = setting('workspace_name', 'TA Toolkit');
auth_page('Sign in', '
<h1>Welcome back.</h1><p class="muted">Sign in to plan, publish and check it off.</p>
' . ($error ? '<div class="formerror">' . h($error) . '</div>' : '') . '
<form method="post">
  <input type="hidden" name="csrf" value="' . h(csrf_token()) . '">
  <label for="email">Email</label><input id="email" name="email" type="email" required autofocus autocomplete="username" value="' . h($email) . '">
  <label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password">
  <div class="actions"><button class="primary" style="width:100%">Sign in →</button></div>
</form>
<p class="muted small" style="margin-top:18px">Forgot your password? Ask an Admin to reset it from the Team page.</p>', $ws);
