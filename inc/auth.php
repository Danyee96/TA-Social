<?php
/**
 * Authentication + permission checks.
 */
declare(strict_types=1);

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $id = (int)($_SESSION['uid'] ?? 0);
    if (!$id) return $cache = null;
    $u = db_one('SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.level AS role_rank
                 FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]);
    if (!$u || !(int)$u['active']) {
        $_SESSION = [];
        return $cache = null;
    }
    // Force logout if password changed / account edited after session started
    if (($u['session_version'] ?? 0) != ($_SESSION['sv'] ?? 0)) {
        $_SESSION = [];
        return $cache = null;
    }
    $u['permissions'] = user_permissions($u);
    unset($u['password_hash']);
    return $cache = $u;
}

function current_user_id(): ?int
{
    return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
}

function user_permissions(array $u): array
{
    if (($u['role_slug'] ?? '') === 'superadmin') return all_permissions();
    return array_column(db_all('SELECT permission FROM role_permissions WHERE role_id = ?', [(int)$u['role_id']]), 'permission');
}

function can(string $perm): bool
{
    $u = current_user();
    if (!$u) return false;
    return in_array($perm, $u['permissions'], true);
}

function is_superadmin(): bool
{
    $u = current_user();
    return $u && $u['role_slug'] === 'superadmin';
}

function too_many_attempts(string $email): bool
{
    $since = date('Y-m-d H:i:s', time() - 15 * 60);
    $n = (int)db_val('SELECT COUNT(*) FROM login_attempts WHERE (ip = ? OR email = ?) AND attempted_at > ?', [client_ip(), $email, $since]);
    return $n >= 8;
}

function attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));
    if (too_many_attempts($email)) {
        return [false, 'Too many attempts. Please wait 15 minutes and try again.'];
    }
    $u = db_one('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        db_insert('login_attempts', ['ip' => client_ip(), 'email' => $email, 'attempted_at' => now()]);
        return [false, 'Incorrect email or password.'];
    }
    if (!(int)$u['active']) {
        return [false, 'This account has been deactivated. Ask an admin to reactivate it.'];
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['sv'] = (int)$u['session_version'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    db_update('users', ['last_login_at' => now()], 'id = ?', [$u['id']]);
    db_exec('DELETE FROM login_attempts WHERE email = ?', [$email]);
    log_activity('auth.login', 'user', (int)$u['id'], $u['name'] . ' signed in');
    return [true, ''];
}

function logout(): void
{
    if (current_user_id()) log_activity('auth.logout', 'user', current_user_id(), 'Signed out');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
