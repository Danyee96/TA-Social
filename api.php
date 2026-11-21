<?php
/**
 * JSON API.  Call as:  api.php?r=<route>   (POST JSON body for writes)
 * Every write needs the X-CSRF-Token header. Every route checks permissions.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/schema.php';
start_session();
security_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const STATUSES = ['planned' => 'Planned', 'in_progress' => 'In progress', 'ready' => 'Ready to post', 'published' => 'Published'];

/* ---------------- request helpers ---------------- */

final class ApiError extends Exception
{
    public int $status;
    public function __construct(string $m, int $status = 400) { parent::__construct($m); $this->status = $status; }
}

$BODY = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $BODY = json_decode($raw, true);
    if (!is_array($BODY)) $BODY = $_POST;
}

function in(string $k, $d = null) { global $BODY; return $BODY[$k] ?? $_GET[$k] ?? $d; }
function str_in(string $k, int $max = 255): string { return mb_substr(trim((string)in($k, '')), 0, $max); }
function int_in(string $k): ?int { $v = in($k); return ($v === null || $v === '') ? null : (int)$v; }
function fail(string $m, int $s = 400): void { throw new ApiError($m, $s); }
function need(string $perm): void { if (!can($perm)) fail('You don\'t have permission to do that.', 403); }
function me(): array { return current_user(); }
function myid(): int { return (int)current_user()['id']; }

function require_write(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('POST required', 405);
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), (string)$t)) fail('Session expired — reload the page.', 419);
}

function valid_link(string $l): bool
{
    return $l === '' || (bool)preg_match('#^https?://#i', $l) && filter_var($l, FILTER_VALIDATE_URL) !== false;
}

/* ---------------- shared data shaping ---------------- */

function platform_row(array $p): array
{
    return [
        'id' => (int)$p['id'], 'name' => $p['name'], 'color' => $p['color'], 'url' => $p['url'],
        'description' => $p['description'], 'metric_label' => $p['metric_label'], 'group_name' => $p['group_name'] ?: $p['name'],
        'short_name' => $p['short_name'] ?: mb_substr($p['name'], 0, 2), 'types' => json_decode($p['types'] ?: '[]', true) ?: [],
        'sort' => (int)$p['sort'], 'active' => (int)$p['active'] === 1,
    ];
}

function platforms_all(): array
{
    return array_map('platform_row', db_all('SELECT * FROM platforms ORDER BY sort, id'));
}

function platform_get(int $id): array
{
    $p = db_one('SELECT * FROM platforms WHERE id = ?', [$id]);
    if (!$p) fail('Unknown platform.');
    return platform_row($p);
}

const POST_COLS = 'p.id, p.title, p.post_date, p.post_time, p.platform_id, p.type, p.status, p.done, p.done_at, p.done_by, p.assignee_id, p.owner_text, p.link, p.notes, p.monthly_item_id, p.created_by, p.created_at, p.updated_at';

function post_row(array $p): array
{
    return [
        'id' => (int)$p['id'], 'title' => $p['title'], 'date' => $p['post_date'], 'time' => $p['post_time'],
        'platform_id' => (int)$p['platform_id'], 'type' => $p['type'], 'status' => $p['status'], 'done' => (int)$p['done'] === 1,
        'done_at' => $p['done_at'], 'done_by' => $p['done_by'] !== null ? (int)$p['done_by'] : null,
        'assignee_id' => $p['assignee_id'] !== null ? (int)$p['assignee_id'] : null, 'owner' => $p['owner_text'],
        'link' => $p['link'], 'notes' => (string)$p['notes'], 'monthly_item_id' => $p['monthly_item_id'] !== null ? (int)$p['monthly_item_id'] : null,
        'created_by' => $p['created_by'] !== null ? (int)$p['created_by'] : null, 'created_at' => $p['created_at'], 'updated_at' => $p['updated_at'],
        'comments' => (int)($p['comment_count'] ?? 0),
    ];
}

function posts_where(string $where, array $params, string $order = 'p.post_date, p.post_time, p.platform_id, p.id', string $limit = ''): array
{
    $rows = db_all('SELECT ' . POST_COLS . ', (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count
                    FROM posts p WHERE ' . $where . ' ORDER BY ' . $order . ' ' . $limit, $params);
    return array_map('post_row', $rows);
}

function post_get(int $id): array
{
    $r = posts_where('p.id = ?', [$id]);
    if (!$r) fail('That post no longer exists.', 404);
    return $r[0];
}

function can_edit_post(array $p): bool
{
    if (can('content.edit')) return true;
    return can('content.edit_own') && ($p['assignee_id'] === myid() || $p['created_by'] === myid());
}

function month_bounds(string $m): array
{
    $first = $m . '-01';
    $last = date('Y-m-t', strtotime($first));
    return [$first, $last];
}

function user_label(?int $id): string
{
    if (!$id) return '';
    return (string)(db_val('SELECT name FROM users WHERE id = ?', [$id]) ?? 'Deleted user');
}

/** Save a set of posts (+ comments + monthly links) so they can be restored with Undo. */
function push_undo(string $kind, string $label, array $postIds, array $daysOffAdded = []): int
{
    if (!$postIds && !$daysOffAdded) return 0;
    $in = db_in($postIds);
    $payload = [
        'posts' => $postIds ? db_all("SELECT * FROM posts WHERE id IN ($in)", $postIds) : [],
        'comments' => $postIds ? db_all("SELECT * FROM comments WHERE post_id IN ($in)", $postIds) : [],
        'links' => $postIds ? db_all("SELECT id, post_id FROM monthly_items WHERE post_id IN ($in)", $postIds) : [],
        'days_off_added' => $daysOffAdded,
    ];
    db_exec('DELETE FROM undo_buffer WHERE user_id = ?', [myid()]);
    return db_insert('undo_buffer', ['user_id' => myid(), 'kind' => $kind, 'label' => $label, 'payload' => json_encode($payload), 'created_at' => now()]);
}

function delete_posts(array $ids): void
{
    if (!$ids) return;
    $in = db_in($ids);
    db_exec("UPDATE monthly_items SET post_id = NULL WHERE post_id IN ($in)", $ids);
    db_exec("DELETE FROM comments WHERE post_id IN ($in)", $ids);
    db_exec("DELETE FROM posts WHERE id IN ($in)", $ids);
}

function undo_info(): ?array
{
    $u = db_one('SELECT id, kind, label, created_at FROM undo_buffer WHERE user_id = ? ORDER BY id DESC LIMIT 1', [myid()]);
    if (!$u) return null;
    if (strtotime($u['created_at']) < time() - 86400) return null; // undo kept for 24h
    return ['id' => (int)$u['id'], 'kind' => $u['kind'], 'label' => $u['label'], 'created_at' => $u['created_at']];
}

function days_off_between(string $a, string $b): array
{
    return array_map(fn($r) => ['date' => $r['off_date'], 'reason' => $r['reason']],
        db_all('SELECT off_date, reason FROM days_off WHERE off_date BETWEEN ? AND ? ORDER BY off_date', [$a, $b]));
}

function week_bounds(string $day): array
{
    $t = strtotime($day);
    $start = date('Y-m-d', strtotime('-' . (int)date('w', $t) . ' days', $t));
    return [$start, date('Y-m-d', strtotime($start . ' +6 days'))];
}

function team_list(): array
{
    return array_map(fn($u) => [
        'id' => (int)$u['id'], 'name' => $u['name'], 'title' => $u['title'], 'color' => $u['color'], 'active' => (int)$u['active'] === 1,
    ], db_all('SELECT id, name, title, color, active FROM users ORDER BY active DESC, name'));
}

/* ---------------- routes ---------------- */

$routes = [];

/* ===== session / boot ===== */

$routes['boot'] = function () {
    $u = me();
    return [
        'me' => ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'title' => $u['title'], 'color' => $u['color'],
            'role' => ['slug' => $u['role_slug'], 'name' => $u['role_name'], 'level' => (int)$u['role_rank']], 'permissions' => $u['permissions']],
        'platforms' => platforms_all(),
        'team' => team_list(),
        'statuses' => STATUSES,
        'workspace' => ['name' => setting('workspace_name', 'TA Toolkit'), 'tagline' => setting('tagline', 'Social Command')],
        'undo' => undo_info(),
        'version' => APP_VERSION,
        'server_now' => now(),
        'group_order' => json_decode((string)setting('group_order', '[]'), true) ?: [],
    ];
};

/* ===== overview ===== */

$routes['overview'] = function () {
    need('dashboard.view');
    $month = (string)in('month');
    $today = (string)in('today');
    if (!valid_month($month) || !valid_date($today)) fail('Bad date.');
    [$a, $b] = month_bounds($month);
    $week = date('Y-m-d', strtotime($today . ' +7 days'));
    $metrics = can('metrics.view') ? metrics_summary() : null;
    return [
        'month' => posts_where('p.post_date BETWEEN ? AND ?', [$a, $b]),
        'today' => posts_where('p.post_date = ?', [$today]),
        'pastdue_count' => (int)db_val('SELECT COUNT(*) FROM posts WHERE done = 0 AND post_date < ?', [$today]),
        'pastdue' => posts_where('p.done = 0 AND p.post_date < ?', [$today], 'p.post_date DESC, p.id', 'LIMIT 8'),
        'mine' => posts_where('p.done = 0 AND p.assignee_id = ? AND p.post_date <= ?', [myid(), $week], 'p.post_date, p.id', 'LIMIT 12'),
        'upcoming_count' => (int)db_val('SELECT COUNT(*) FROM posts WHERE done = 0 AND post_date > ? AND post_date <= ?', [$today, $week]),
        'metrics' => $metrics,
        'socials' => (can('metrics.view') || can('socials.manage')) ? social_dashboard_data() : [],
        'published_feed' => (can('metrics.view') || can('socials.manage')) ? social_published_feed(6) : [],
        'socials_configured' => can('socials.manage') && social_enabled(),
        'activity' => can('activity.view') ? activity_rows(8, 0, []) : [],
        'week' => (function () use ($today) {
            [$ws, $we] = week_bounds($today);
            return ['start' => $ws, 'end' => $we, 'posts' => posts_where('p.post_date BETWEEN ? AND ?', [$ws, $we]), 'days_off' => days_off_between($ws, $we)];
        })(),
    ];
};

/* ===== posts ===== */

$routes['posts.list'] = function () {
    need('content.view');
    $from = (string)in('from'); $to = (string)in('to');
    if (!valid_date($from) || !valid_date($to)) fail('Bad date range.');
    return ['posts' => posts_where('p.post_date BETWEEN ? AND ?', [$from, $to]), 'days_off' => days_off_between($from, $to), 'undo' => undo_info()];
};

$routes['posts.search'] = function () {
    need('content.view');
    $q = str_in('q', 100);
    if (mb_strlen($q) < 2) return ['posts' => []];
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    return ['posts' => posts_where('(p.title LIKE ? OR p.notes LIKE ? OR p.type LIKE ? OR p.owner_text LIKE ?)', [$like, $like, $like, $like], 'p.post_date DESC, p.id', 'LIMIT 40')];
};

$routes['posts.mine'] = function () {
    need('content.view');
    $today = (string)in('today');
    if (!valid_date($today)) fail('Bad date.');
    $uid = int_in('user_id') ?? myid();
    if ($uid !== myid() && !can('reports.view')) $uid = myid();
    return [
        'overdue' => posts_where('p.done = 0 AND p.assignee_id = ? AND p.post_date < ?', [$uid, $today], 'p.post_date, p.id'),
        'upcoming' => posts_where('p.done = 0 AND p.assignee_id = ? AND p.post_date >= ?', [$uid, $today], 'p.post_date, p.id', 'LIMIT 200'),
        'recent_done' => posts_where('p.done = 1 AND p.assignee_id = ?', [$uid], 'p.done_at DESC, p.id DESC', 'LIMIT 20'),
    ];
};

$routes['posts.get'] = function () {
    need('content.view');
    $p = post_get((int)in('id'));
    $comments = db_all('SELECT c.id, c.body, c.created_at, c.user_id, u.name, u.color FROM comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.post_id = ? ORDER BY c.id', [$p['id']]);
    $history = db_all("SELECT a.summary, a.created_at, u.name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.entity = 'post' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 15", [$p['id']]);
    return ['post' => $p, 'can_edit' => can_edit_post($p), 'comments' => $comments, 'history' => $history];
};

$routes['posts.save'] = function () {
    require_write();
    $id = int_in('id');
    $old = $id ? post_get($id) : null;
    if ($old && !can_edit_post($old)) fail('You can only edit posts you created or are assigned to.', 403);
    if (!$old) need('content.create');

    $date = (string)in('date');
    if (!valid_date($date)) fail('Choose a valid date.');
    $plat = platform_get((int)in('platform_id'));
    $type = str_in('type', 120);
    if ($type === '') $type = $plat['types'][0] ?? 'Post';
    $link = str_in('link', 500);
    if (!valid_link($link)) fail('Links must start with http:// or https://');
    $time = str_in('time', 5);
    if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $time = '';
    $status = (string)in('status', 'planned');
    if (!isset(STATUSES[$status])) $status = 'planned';

    $assignee = int_in('assignee_id');
    if ($assignee && !db_val('SELECT id FROM users WHERE id = ?', [$assignee])) $assignee = null;
    // Without full edit rights you can't reassign someone else's post
    if ($old && !can('content.edit') && $assignee !== $old['assignee_id'] && $assignee !== myid() && $assignee !== null) $assignee = $old['assignee_id'];

    $done = $status === 'published';
    if ($old && $old['done'] !== $done && !can('content.complete')) fail('You don\'t have permission to mark posts published.', 403);
    if (!$old && $done && !can('content.complete')) $status = 'ready';
    $done = $status === 'published';

    $data = [
        'title' => str_in('title', 255), 'post_date' => $date, 'post_time' => $time, 'platform_id' => $plat['id'], 'type' => $type,
        'status' => $status, 'done' => $done ? 1 : 0, 'assignee_id' => $assignee, 'owner_text' => str_in('owner', 120),
        'link' => $link, 'notes' => mb_substr(trim((string)in('notes', '')), 0, 5000), 'updated_at' => now(),
    ];
    if (!$old || $old['done'] !== $done) {
        $data['done_at'] = $done ? now() : null;
        $data['done_by'] = $done ? myid() : null;
    }
    $label = ($data['title'] ?: $type) . ' (' . $plat['name'] . ', ' . $date . ')';
    if ($old) {
        db_update('posts', $data, 'id = ?', [$id]);
        $changes = [];
        if ($old['date'] !== $date) $changes[] = 'moved ' . $old['date'] . ' → ' . $date;
        if ($old['status'] !== $status) $changes[] = 'status → ' . STATUSES[$status];
        if ($old['assignee_id'] !== $assignee) $changes[] = 'assigned to ' . ($assignee ? user_label($assignee) : 'nobody');
        log_activity('post.update', 'post', $id, 'Edited ' . $label . ($changes ? ' · ' . implode(', ', $changes) : ''));
    } else {
        $data += ['created_by' => myid(), 'created_at' => now()];
        $id = db_insert('posts', $data);
        log_activity('post.create', 'post', $id, 'Added ' . $label . ($assignee ? ' · assigned to ' . user_label($assignee) : ''));
    }
    return ['post' => post_get($id)];
};

$routes['posts.toggle'] = function () {
    require_write();
    need('content.complete');
    $p = post_get((int)in('id'));
    // Without "edit any post", you can only check off your own or unassigned posts
    if (!can('content.edit') && $p['assignee_id'] !== null && $p['assignee_id'] !== myid()) {
        fail('This post is assigned to someone else.', 403);
    }
    $done = (bool)in('done');
    db_update('posts', [
        'done' => $done ? 1 : 0, 'status' => $done ? 'published' : ($p['status'] === 'published' ? 'ready' : $p['status']),
        'done_at' => $done ? now() : null, 'done_by' => $done ? myid() : null, 'updated_at' => now(),
    ], 'id = ?', [$p['id']]);
    $pl = platform_get($p['platform_id']);
    log_activity($done ? 'post.publish' : 'post.unpublish', 'post', $p['id'], ($done ? 'Checked off ' : 'Unchecked ') . ($p['title'] ?: $p['type']) . ' (' . $pl['name'] . ', ' . $p['date'] . ')');
    return ['post' => post_get($p['id'])];
};

$routes['posts.delete'] = function () {
    require_write();
    need('content.delete');
    $p = post_get((int)in('id'));
    $pl = platform_get($p['platform_id']);
    $label = 'Deleted ' . ($p['title'] ?: $p['type']) . ' (' . $pl['name'] . ', ' . $p['date'] . ')';
    db_tx(function () use ($p, $label) {
        push_undo('delete', $label, [$p['id']]);
        delete_posts([$p['id']]);
    });
    log_activity('post.delete', 'post', $p['id'], $label);
    return ['undo' => undo_info()];
};

$routes['posts.duplicate'] = function () {
    require_write();
    need('content.create');
    $p = post_get((int)in('id'));
    $date = (string)in('date', $p['date']);
    if (!valid_date($date)) fail('Choose a valid date.');
    $id = db_insert('posts', [
        'title' => $p['title'], 'post_date' => $date, 'post_time' => $p['time'], 'platform_id' => $p['platform_id'], 'type' => $p['type'],
        'status' => 'planned', 'done' => 0, 'assignee_id' => $p['assignee_id'], 'owner_text' => $p['owner'], 'link' => '',
        'notes' => $p['notes'], 'created_by' => myid(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    log_activity('post.create', 'post', $id, 'Duplicated ' . ($p['title'] ?: $p['type']) . ' to ' . $date);
    return ['post' => post_get($id)];
};

$routes['posts.move'] = function () {
    require_write();
    $p = post_get((int)in('id'));
    if (!can_edit_post($p)) fail('You can\'t move this post.', 403);
    $date = (string)in('date');
    if (!valid_date($date)) fail('Choose a valid date.');
    db_update('posts', ['post_date' => $date, 'updated_at' => now()], 'id = ?', [$p['id']]);
    log_activity('post.update', 'post', $p['id'], 'Moved ' . ($p['title'] ?: $p['type']) . ' ' . $p['date'] . ' → ' . $date);
    return ['post' => post_get($p['id'])];
};

/** Clear one or more days (a day off, a week away). Optionally remember them as days off so "Generate" skips them. */
$clearDays = function (array $dates, bool $dayOff, string $reason) {
    need('content.delete');
    $dates = array_values(array_unique(array_filter($dates, 'valid_date')));
    if (!$dates) fail('Pick a day to clear.');
    sort($dates);
    $ids = array_map('intval', array_column(db_all('SELECT id FROM posts WHERE post_date IN (' . db_in($dates) . ')', $dates), 'id'));
    $added = [];
    if ($dayOff) {
        foreach ($dates as $d) if (!db_val('SELECT off_date FROM days_off WHERE off_date = ?', [$d])) $added[] = $d;
    }
    if (!$ids && !$added) fail('Nothing to clear.');
    $span = count($dates) === 1 ? date('M j', strtotime($dates[0])) : date('M j', strtotime($dates[0])) . '–' . date('M j', strtotime(end($dates)));
    $label = 'Cleared ' . count($ids) . ' post' . (count($ids) === 1 ? '' : 's') . ' from ' . $span . ($added ? ' · marked as day off' : '');
    db_tx(function () use ($ids, $label, $added, $reason) {
        push_undo(count($added) > 1 || count($ids) > 30 ? 'clear_week' : 'clear_day', $label, $ids, $added);
        delete_posts($ids);
        foreach ($added as $d) db_insert('days_off', ['off_date' => $d, 'reason' => $reason, 'created_by' => myid(), 'created_at' => now()]);
    });
    log_activity('day.clear', 'day', null, $label . ($reason !== '' ? ' (' . $reason . ')' : ''));
    return ['undo' => undo_info(), 'count' => count($ids), 'days_off' => count($added)];
};

$routes['posts.clear_day'] = function () use ($clearDays) {
    require_write();
    return $clearDays([(string)in('date')], (bool)in('day_off', true), str_in('reason', 120));
};

$routes['posts.clear_days'] = function () use ($clearDays) {
    require_write();
    return $clearDays(array_map('strval', (array)in('dates', [])), (bool)in('day_off', true), str_in('reason', 120));
};

$routes['days_off.set'] = function () {
    require_write();
    need('content.generate');
    $date = (string)in('date');
    if (!valid_date($date)) fail('Bad date.');
    if (in('off')) {
        if (!db_val('SELECT off_date FROM days_off WHERE off_date = ?', [$date])) {
            db_insert('days_off', ['off_date' => $date, 'reason' => str_in('reason', 120), 'created_by' => myid(), 'created_at' => now()]);
        }
        log_activity('day.off', 'day', null, 'Marked ' . date('M j', strtotime($date)) . ' as a day off');
    } else {
        db_exec('DELETE FROM days_off WHERE off_date = ?', [$date]);
        log_activity('day.on', 'day', null, 'Removed day off on ' . date('M j', strtotime($date)) . ' — slots can be generated again');
    }
    return [];
};

$routes['posts.undo'] = function () {
    require_write();
    $u = db_one('SELECT * FROM undo_buffer WHERE user_id = ? ORDER BY id DESC LIMIT 1', [myid()]);
    if (!$u) fail('Nothing to undo.');
    $payload = json_decode($u['payload'], true) ?: [];
    $n = db_tx(function () use ($payload, $u) {
        $n = 0;
        foreach ($payload['posts'] ?? [] as $row) {
            if (db_val('SELECT id FROM posts WHERE id = ?', [$row['id']])) continue;
            db_insert('posts', $row);
            $n++;
        }
        foreach ($payload['comments'] ?? [] as $row) {
            if (!db_val('SELECT id FROM comments WHERE id = ?', [$row['id']])) db_insert('comments', $row);
        }
        foreach ($payload['links'] ?? [] as $l) {
            db_exec('UPDATE monthly_items SET post_id = ? WHERE id = ? AND post_id IS NULL', [$l['post_id'], $l['id']]);
        }
        foreach ($payload['days_off_added'] ?? [] as $d) db_exec('DELETE FROM days_off WHERE off_date = ?', [$d]);
        db_exec('DELETE FROM undo_buffer WHERE id = ?', [$u['id']]);
        return $n;
    });
    log_activity('post.restore', 'day', null, 'Undo: restored ' . $n . ' post' . ($n === 1 ? '' : 's') . ' (' . $u['label'] . ')');
    return ['restored' => $n];
};

$routes['posts.complete_day'] = function () {
    require_write();
    need('content.complete');
    $date = (string)in('date');
    if (!valid_date($date)) fail('Bad date.');
    $sql = 'SELECT id FROM posts WHERE post_date = ? AND done = 0';
    $params = [$date];
    if (!can('content.edit')) { $sql .= ' AND (assignee_id IS NULL OR assignee_id = ?)'; $params[] = myid(); }
    $ids = array_map('intval', array_column(db_all($sql, $params), 'id'));
    if ($ids) {
        db_exec('UPDATE posts SET done = 1, status = \'published\', done_at = ?, done_by = ?, updated_at = ? WHERE id IN (' . db_in($ids) . ')', array_merge([now(), myid(), now()], $ids));
        log_activity('day.complete', 'day', null, 'Marked ' . count($ids) . ' posts published on ' . date('M j', strtotime($date)));
    }
    return ['count' => count($ids)];
};

$routes['posts.generate'] = function () {
    require_write();
    need('content.generate');
    $month = (string)in('month');
    if (!valid_month($month)) fail('Bad month.');
    $slotIds = array_map('intval', (array)in('slot_ids', []));
    if (!$slotIds) fail('Pick at least one slot.');
    $slots = db_all('SELECT * FROM slot_templates WHERE id IN (' . db_in($slotIds) . ')', $slotIds);
    $schedule = (string)in('schedule', 'slots'); // slots = each slot's own days · all · weekdays
    $off = array_flip(array_column(days_off_between(...month_bounds($month)), 'date'));
    $offSkipped = 0;
    $assignee = int_in('assignee_id');
    $fromDate = (string)in('from', '');
    [$a, $b] = month_bounds($month);
    $existing = [];
    foreach (db_all('SELECT post_date, platform_id, type FROM posts WHERE post_date BETWEEN ? AND ?', [$a, $b]) as $r) {
        $existing[$r['post_date'] . '|' . $r['platform_id'] . '|' . $r['type']] = true;
    }
    $added = 0; $skipped = 0;
    db_tx(function () use ($a, $b, $slots, $schedule, $assignee, $fromDate, $off, &$existing, &$added, &$skipped, &$offSkipped) {
        for ($t = strtotime($a); $t <= strtotime($b); $t = strtotime('+1 day', $t)) {
            $date = date('Y-m-d', $t);
            if ($fromDate && valid_date($fromDate) && $date < $fromDate) continue;
            $dow = (int)date('w', $t);
            if ($schedule === 'weekdays' && ($dow === 0 || $dow === 6)) continue;
            if (isset($off[$date])) { $offSkipped++; continue; }
            foreach ($slots as $s) {
                if ($schedule === 'slots' && !in_array((string)$dow, explode(',', (string)$s['days']), true)) continue;
                $k = $date . '|' . $s['platform_id'] . '|' . $s['type'];
                if (isset($existing[$k])) { $skipped++; continue; }
                db_insert('posts', [
                    'title' => '', 'post_date' => $date, 'post_time' => '', 'platform_id' => (int)$s['platform_id'], 'type' => $s['type'],
                    'status' => 'planned', 'done' => 0, 'assignee_id' => $assignee, 'owner_text' => '', 'link' => '', 'notes' => '',
                    'created_by' => myid(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $existing[$k] = true;
                $added++;
            }
        }
    });
    log_activity('month.generate', 'month', null, 'Generated ' . $added . ' daily slots for ' . date('F Y', strtotime($a)) . ($skipped ? " ($skipped duplicates skipped)" : '') . ($offSkipped ? " ($offSkipped days off skipped)" : ''));
    return ['added' => $added, 'skipped' => $skipped, 'days_off' => $offSkipped];
};

/* ===== comments ===== */

$routes['comments.add'] = function () {
    require_write();
    need('comments.add');
    $p = post_get((int)in('post_id'));
    $body = mb_substr(trim((string)in('body', '')), 0, 3000);
    if ($body === '') fail('Write a comment first.');
    $id = db_insert('comments', ['post_id' => $p['id'], 'user_id' => myid(), 'body' => $body, 'created_at' => now()]);
    log_activity('comment.add', 'post', $p['id'], 'Commented on ' . ($p['title'] ?: $p['type']) . ' (' . $p['date'] . ')');
    return ['id' => $id];
};

$routes['comments.delete'] = function () {
    require_write();
    $c = db_one('SELECT * FROM comments WHERE id = ?', [(int)in('id')]);
    if (!$c) fail('Comment not found.', 404);
    if ((int)$c['user_id'] !== myid() && !can('content.edit')) fail('You can only delete your own comments.', 403);
    db_exec('DELETE FROM comments WHERE id = ?', [$c['id']]);
    return [];
};

/* ===== monthly content list ===== */

function monthly_rows(string $month): array
{
    $items = db_all('SELECT * FROM monthly_items WHERE month = ? ORDER BY sort, id', [$month]);
    $out = [];
    foreach ($items as $i) {
        $post = null;
        if ($i['post_id']) {
            $r = posts_where('p.id = ?', [(int)$i['post_id']]);
            $post = $r[0] ?? null;
        }
        $out[] = ['id' => (int)$i['id'], 'month' => $i['month'], 'platform_id' => (int)$i['platform_id'], 'type' => $i['type'], 'title' => $i['title'], 'post' => $post];
    }
    return $out;
}

$routes['monthly.list'] = function () {
    need('content.view');
    $m = (string)in('month');
    if (!valid_month($m)) fail('Bad month.');
    return ['items' => monthly_rows($m)];
};

$routes['monthly.add'] = function () {
    require_write();
    need('monthly.manage');
    $m = (string)in('month');
    if (!valid_month($m)) fail('Bad month.');
    $pl = platform_get((int)in('platform_id'));
    $title = str_in('title');
    if ($title === '') fail('Enter a topic / title.');
    $id = db_insert('monthly_items', ['month' => $m, 'platform_id' => $pl['id'], 'type' => str_in('type', 120) ?: ($pl['types'][0] ?? ''), 'title' => $title,
        'sort' => (int)db_val('SELECT COUNT(*) FROM monthly_items WHERE month = ?', [$m]), 'created_by' => myid(), 'created_at' => now()]);
    log_activity('monthly.add', 'monthly', $id, 'Added "' . $title . '" to the ' . date('F', strtotime($m . '-01')) . ' monthly list');
    return ['id' => $id];
};

/** Put a monthly-list item on the calendar (creates its post from any saved draft). */
function monthly_create_post(array $i, string $date): int
{
    $d = json_decode((string)($i['draft'] ?? ''), true) ?: [];
    $done = !empty($d['done']);
    $pid = db_insert('posts', [
        'title' => $i['title'], 'post_date' => $date, 'post_time' => $d['time'] ?? '', 'platform_id' => (int)$i['platform_id'], 'type' => $i['type'],
        'status' => $d['status'] ?? 'planned', 'done' => $done ? 1 : 0, 'done_at' => $d['done_at'] ?? null, 'done_by' => $d['done_by'] ?? null,
        'assignee_id' => $d['assignee_id'] ?? null, 'owner_text' => $d['owner'] ?? '', 'link' => $d['link'] ?? '', 'notes' => $d['notes'] ?? '',
        'monthly_item_id' => (int)$i['id'], 'created_by' => myid(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    db_update('monthly_items', ['post_id' => $pid], 'id = ?', [$i['id']]);
    return $pid;
}

$routes['monthly.defaults'] = function () {
    require_write();
    need('monthly.manage');
    $m = (string)in('month');
    if (!valid_month($m)) fail('Bad month.');
    $added = 0; $scheduled = 0;
    foreach (db_all('SELECT * FROM monthly_templates ORDER BY sort, id') as $t) {
        $dupe = db_val('SELECT id FROM monthly_items WHERE month = ? AND platform_id = ? AND type = ? AND title = ?', [$m, $t['platform_id'], $t['type'], $t['title']]);
        if ($dupe) continue;
        $iid = db_insert('monthly_items', ['month' => $m, 'platform_id' => $t['platform_id'], 'type' => $t['type'], 'title' => $t['title'],
            'sort' => 100 + (int)$t['sort'], 'created_by' => myid(), 'created_at' => now()]);
        $added++;
        // Template has a usual day (e.g. the 1st or the 28th) → schedule it straight away
        $dom = (int)($t['day_of_month'] ?? 0);
        if ($dom > 0 && can('content.create')) {
            $date = $m . '-' . str_pad((string)min($dom, (int)date('t', strtotime($m . '-01'))), 2, '0', STR_PAD_LEFT);
            monthly_create_post(db_one('SELECT * FROM monthly_items WHERE id = ?', [$iid]), $date);
            $scheduled++;
        }
    }
    if ($added) log_activity('monthly.defaults', 'monthly', null, "Added $added monthly checklist items for " . date('F Y', strtotime($m . '-01')));
    return ['added' => $added, 'scheduled' => $scheduled];
};

$routes['monthly.schedule'] = function () {
    require_write();
    $i = db_one('SELECT * FROM monthly_items WHERE id = ?', [(int)in('id')]);
    if (!$i) fail('Item not found.', 404);
    $date = (string)in('date', '');
    $post = $i['post_id'] ? (posts_where('p.id = ?', [(int)$i['post_id']])[0] ?? null) : null;
    if ($post ? !can_edit_post($post) : !can('content.create')) fail('You don\'t have permission to schedule this.', 403);

    if ($date === '') {
        // Unschedule: keep the post's details as a draft so nothing is lost
        if ($post) {
            db_update('monthly_items', ['title' => $post['title'] ?: $i['title'], 'type' => $post['type'], 'platform_id' => $post['platform_id'],
                'draft' => json_encode($post), 'post_id' => null], 'id = ?', [$i['id']]);
            db_exec('DELETE FROM comments WHERE post_id = ?', [$post['id']]);
            db_exec('DELETE FROM posts WHERE id = ?', [$post['id']]);
            log_activity('monthly.unschedule', 'monthly', (int)$i['id'], 'Unscheduled "' . $i['title'] . '"');
        }
        return [];
    }
    if (!valid_date($date) || substr($date, 0, 7) !== $i['month']) fail('Pick a date inside ' . date('F', strtotime($i['month'] . '-01')) . '.');
    if ($post) {
        db_update('posts', ['post_date' => $date, 'updated_at' => now()], 'id = ?', [$post['id']]);
        log_activity('post.update', 'post', $post['id'], 'Rescheduled "' . ($post['title'] ?: $i['title']) . '" to ' . $date);
        return ['post_id' => $post['id']];
    }
    $pid = monthly_create_post($i, $date);
    log_activity('monthly.schedule', 'post', $pid, 'Scheduled "' . $i['title'] . '" for ' . $date);
    return ['post_id' => $pid];
};

$routes['monthly.remove'] = function () {
    require_write();
    need('monthly.manage');
    $i = db_one('SELECT * FROM monthly_items WHERE id = ?', [(int)in('id')]);
    if (!$i) fail('Item not found.', 404);
    // Like the original: removing from the list keeps any scheduled post on the calendar
    db_exec('UPDATE posts SET monthly_item_id = NULL WHERE monthly_item_id = ?', [$i['id']]);
    db_exec('DELETE FROM monthly_items WHERE id = ?', [$i['id']]);
    log_activity('monthly.remove', 'monthly', (int)$i['id'], 'Removed "' . $i['title'] . '" from the monthly list');
    return [];
};

/* ===== audience metrics ===== */

function metrics_summary(): array
{
    $batches = db_all('SELECT b.id, b.snap_date, b.note, b.created_at, u.name AS by_name FROM metric_batches b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.snap_date DESC, b.id DESC LIMIT 120');
    $ids = array_map(fn($b) => (int)$b['id'], $batches);
    $vals = [];
    if ($ids) {
        foreach (db_all('SELECT * FROM metric_values WHERE batch_id IN (' . db_in($ids) . ')', $ids) as $v) {
            $vals[(int)$v['batch_id']][(int)$v['platform_id']] = $v['value'] === null ? null : (int)$v['value'];
        }
    }
    $history = array_map(fn($b) => ['id' => (int)$b['id'], 'date' => $b['snap_date'], 'note' => $b['note'], 'by' => $b['by_name'],
        'values' => (object)($vals[(int)$b['id']] ?? [])], $batches);
    $current = []; $previous = [];
    if ($history) {
        $current = (array)$history[0]['values'];
        foreach ($current as $pid => $v) {
            for ($i = 1; $i < count($history); $i++) {
                $pv = ((array)$history[$i]['values'])[$pid] ?? null;
                if ($pv !== null) { $previous[$pid] = $pv; break; }
            }
        }
    }
    return ['current' => (object)$current, 'previous' => (object)$previous, 'updated' => $history[0]['date'] ?? null, 'history' => $history];
}

$routes['metrics.get'] = function () {
    need('metrics.view');
    return metrics_summary();
};

$routes['metrics.save'] = function () {
    require_write();
    need('metrics.update');
    $date = (string)in('date');
    if (!valid_date($date)) fail('Choose a valid date.');
    $values = (array)in('values', []);
    $bid = db_tx(function () use ($date, $values) {
        $bid = db_insert('metric_batches', ['snap_date' => $date, 'note' => str_in('note'), 'created_by' => myid(), 'created_at' => now()]);
        foreach (platforms_all() as $p) {
            $raw = $values[$p['id']] ?? $values[(string)$p['id']] ?? null;
            $v = ($raw === null || $raw === '') ? null : max(0, (int)$raw);
            db_insert('metric_values', ['batch_id' => $bid, 'platform_id' => $p['id'], 'value' => $v]);
        }
        return $bid;
    });
    log_activity('metrics.update', 'metrics', $bid, 'Updated audience counts (' . $date . ')');
    return metrics_summary();
};

$routes['metrics.delete'] = function () {
    require_write();
    need('metrics.update');
    $id = (int)in('id');
    db_exec('DELETE FROM metric_values WHERE batch_id = ?', [$id]);
    db_exec('DELETE FROM metric_batches WHERE id = ?', [$id]);
    log_activity('metrics.delete', 'metrics', $id, 'Deleted an audience snapshot');
    return metrics_summary();
};

/* ===== connected social profiles ===== */

$routes['socials.list'] = function () {
    if (!can('metrics.view') && !can('socials.manage')) fail('No access.', 403);
    return [
        'accounts' => social_dashboard_data(),
        'published_feed' => social_published_feed(60),
        'today' => date('Y-m-d'),
        'today_published' => social_published_feed(null, date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))),
        'configured' => social_enabled(),
        'platforms' => array_map(fn($platform) => $platform['label'], SOCIAL_PLATFORMS),
    ];
};

$routes['socials.calendar'] = function () {
    if (!can('metrics.view') && !can('socials.manage')) fail('No access.', 403);
    $month = (string)in('month', date('Y-m'));
    if (!valid_month($month)) fail('Choose a valid month.');
    $from = $month . '-01';
    $until = (new DateTimeImmutable($from))->modify('first day of next month')->format('Y-m-d');
    return [
        'month' => $month,
        'today' => date('Y-m-d'),
        'items' => social_published_feed(null, $from, $until),
        'configured' => social_enabled(),
        'platforms' => array_map(fn($key) => SOCIAL_PLATFORMS[$key]['label'], array_combine(SOCIAL_FEED_PLATFORMS, SOCIAL_FEED_PLATFORMS)),
    ];
};

$routes['socials.connect'] = function () {
    require_write();
    if (!is_superadmin()) fail('Only the Super Admin can configure Apify.', 403);
    if (social_enabled()) fail('Apify is already configured.', 409);
    try {
        social_connect_provider(str_in('token', 250));
    } catch (RuntimeException $e) {
        fail($e->getMessage());
    }
    log_activity('social.configure', 'social', null, 'Connected Apify for social analytics');
    return [];
};

$routes['socials.add'] = function () {
    require_write();
    need('socials.manage');
    $platform = str_in('platform', 20);
    try {
        $identity = social_identity($platform, str_in('profile', 1500));
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage());
    }

    $key = hash('sha256', $platform . ':' . $identity['handle']);
    $existing = db_one('SELECT id, is_active, run_id FROM social_accounts WHERE identity_key=?', [$key]);
    if ($existing) {
        $id = (int)$existing['id'];
        $status = $existing['run_id'] ? 'syncing' : 'pending';
        if (!db_exec('UPDATE social_accounts SET is_active=1, sync_status=?, next_sync=NULL, last_error=NULL WHERE id=? AND is_active=0', [$status, $id])) {
            fail('This profile is already linked.', 409);
        }
    } else {
        try {
            $id = db_insert('social_accounts', $identity + ['platform'=>$platform, 'identity_key'=>$key, 'created_at'=>now()]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') fail('This profile is already linked.', 409);
            throw $e;
        }
    }
    log_activity('social.link', 'social', $id, 'Linked ' . SOCIAL_PLATFORMS[$platform]['label'] . ' · ' . $identity['handle']);
    if (social_enabled()) social_start($id);
    return ['id'=>$id];
};

$routes['socials.remove'] = function () {
    require_write();
    need('socials.manage');
    $id = (int)in('id');
    if (!db_exec("UPDATE social_accounts SET is_active=0, sync_status='archived'
        WHERE id=? AND is_active=1 AND (locked_until IS NULL OR locked_until<?)", [$id, now()])) {
        if (db_val('SELECT id FROM social_accounts WHERE id=? AND is_active=1', [$id])) {
            fail('A sync request is in progress. Try again shortly.', 409);
        }
        fail('Profile not found.', 404);
    }
    log_activity('social.remove', 'social', $id, 'Unlinked a social profile');
    return [];
};

$routes['socials.refresh'] = function () {
    require_write();
    need('socials.manage');
    if (!social_enabled()) fail('Configure the Apify token to sync stats.', 503);
    $id = (int)in('id');
    $account = db_one('SELECT run_id, last_attempt FROM social_accounts WHERE id=? AND is_active=1', [$id]);
    if (!$account) fail('Profile not found.', 404);
    if ($account['run_id']) {
        social_collect($id);
    } elseif ($account['last_attempt'] && strtotime($account['last_attempt']) > time()-3600) {
        fail('This profile can start one sync per hour.', 429);
    } else {
        social_start($id);
    }
    return [];
};

/** Process one due profile while admins are viewing analytics. Cron handles unattended sync. */
$routes['socials.poll'] = function () {
    require_write();
    need('socials.manage');
    return ['processed' => social_enabled() ? social_tick(1) : 0];
};

/* ===== reports ===== */

$routes['reports.get'] = function () {
    need('reports.view');
    $m = (string)in('month'); $today = (string)in('today');
    if (!valid_month($m) || !valid_date($today)) fail('Bad date.');
    [$a, $b] = month_bounds($m);
    $posts = posts_where('p.post_date BETWEEN ? AND ?', [$a, $b]);
    $by = fn() => ['total' => 0, 'done' => 0, 'overdue' => 0, 'on_time' => 0];
    $tot = $by(); $plat = []; $user = []; $type = []; $days = [];
    foreach ($posts as $p) {
        $overdue = !$p['done'] && $p['date'] < $today;
        $onTime = $p['done'] && $p['done_at'] && substr($p['done_at'], 0, 10) <= $p['date'];
        foreach ([&$tot, &$plat[$p['platform_id']], &$user[$p['assignee_id'] ?? 0], &$type[$p['platform_id'] . '|' . $p['type']], &$days[$p['date']]] as &$bucket) {
            if ($bucket === null) $bucket = $by();
            $bucket['total']++;
            if ($p['done']) $bucket['done']++;
            if ($overdue) $bucket['overdue']++;
            if ($onTime) $bucket['on_time']++;
        }
        unset($bucket);
    }
    // Streak: consecutive fully-complete scheduled days up to today (skips days off)
    $streak = 0;
    $rows = db_all('SELECT post_date, COUNT(*) AS n, SUM(done) AS d FROM posts WHERE post_date <= ? AND post_date >= ? GROUP BY post_date ORDER BY post_date DESC',
        [$today, date('Y-m-d', strtotime($today . ' -180 days'))]);
    foreach ($rows as $i => $r) {
        if ((int)$r['d'] === (int)$r['n']) $streak++;
        elseif ($i === 0 && $r['post_date'] === $today) continue; // today still in progress
        else break;
    }
    $types = [];
    foreach ($type as $k => $v) { [$pid, $t] = explode('|', $k, 2); $types[] = ['platform_id' => (int)$pid, 'type' => $t] + $v; }
    usort($types, fn($x, $y) => $y['total'] <=> $x['total']);
    $users = [];
    foreach ($user as $uid => $v) $users[] = ['user_id' => $uid ?: null] + $v;
    usort($users, fn($x, $y) => $y['total'] <=> $x['total']);
    $platforms = [];
    foreach ($plat as $pid => $v) $platforms[] = ['platform_id' => (int)$pid] + $v;
    ksort($days);
    return ['totals' => $tot, 'platforms' => $platforms, 'users' => $users, 'types' => $types, 'days' => (object)$days, 'streak' => $streak,
        'metrics' => can('metrics.view') ? metrics_summary() : null];
};

/* ===== users ===== */

$routes['users.list'] = function () {
    need('users.view');
    $rows = db_all('SELECT u.id, u.name, u.email, u.title, u.color, u.active, u.last_login_at, u.created_at, u.role_id, r.name AS role_name, r.level, r.slug
                    FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.active DESC, r.level DESC, u.name');
    $stats = [];
    foreach (db_all('SELECT assignee_id, COUNT(*) AS n, SUM(done) AS d FROM posts WHERE assignee_id IS NOT NULL GROUP BY assignee_id') as $s) {
        $stats[(int)$s['assignee_id']] = ['total' => (int)$s['n'], 'done' => (int)$s['d']];
    }
    $showEmail = can('users.manage');
    return ['users' => array_map(fn($u) => [
        'id' => (int)$u['id'], 'name' => $u['name'], 'email' => $showEmail || (int)$u['id'] === myid() ? $u['email'] : '', 'title' => $u['title'],
        'color' => $u['color'], 'active' => (int)$u['active'] === 1, 'last_login_at' => $u['last_login_at'], 'created_at' => $u['created_at'],
        'role_id' => (int)$u['role_id'], 'role_name' => $u['role_name'], 'role_level' => (int)$u['level'], 'role_slug' => $u['slug'],
        'stats' => $stats[(int)$u['id']] ?? ['total' => 0, 'done' => 0],
    ], $rows), 'roles' => roles_rows(false)];
};

function actor_level(): int { return (int)me()['role_rank']; }

function can_manage_level(int $level): bool
{
    return is_superadmin() || $level < actor_level();
}

$routes['users.save'] = function () {
    require_write();
    need('users.manage');
    $id = int_in('id');
    $old = $id ? db_one('SELECT u.*, r.level, r.slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]) : null;
    if ($id && !$old) fail('User not found.', 404);
    if ($old && !can_manage_level((int)$old['level']) && (int)$old['id'] !== myid()) fail('You can\'t edit someone at or above your own role.', 403);

    $name = str_in('name', 120);
    $email = strtolower(str_in('email', 190));
    if ($name === '') fail('Enter a name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email.');
    if (db_val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id ?? 0])) fail('Another user already uses that email.');
    $role = db_one('SELECT * FROM roles WHERE id = ?', [(int)in('role_id')]);
    if (!$role) fail('Choose a role.');
    $isSelf = $old && (int)$old['id'] === myid();
    if ($isSelf && (int)$role['id'] !== (int)$old['role_id']) fail('You can\'t change your own role.');
    if (!$isSelf && !can_manage_level((int)$role['level'])) fail('You can only assign roles below your own.', 403);
    $active = (bool)in('active', true);
    if ($isSelf && !$active) fail('You can\'t deactivate yourself.');
    $pass = (string)in('password', '');
    if (!$old && strlen($pass) < 8) fail('Set a password of at least 8 characters.');
    if ($pass !== '' && strlen($pass) < 8) fail('Password must be at least 8 characters.');
    $color = preg_match('/^#[0-9a-f]{6}$/i', (string)in('color')) ? (string)in('color') : '#00d8c6';

    $data = ['name' => $name, 'email' => $email, 'role_id' => (int)$role['id'], 'title' => str_in('title', 120), 'color' => $color, 'active' => $active ? 1 : 0, 'updated_at' => now()];
    if ($pass !== '') $data['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);

    if ($old) {
        // Keep at least one active Super Admin
        if ($old['slug'] === 'superadmin' && ($role['slug'] !== 'superadmin' || !$active)) {
            if ((int)db_val("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'superadmin' AND u.active = 1 AND u.id <> ?", [$id]) < 1) {
                fail('There must always be at least one active Super Admin.');
            }
        }
        $forceLogout = ($pass !== '' || !$active || (int)$role['id'] !== (int)$old['role_id']) && !$isSelf;
        if ($forceLogout) $data['session_version'] = (int)$old['session_version'] + 1;
        db_update('users', $data, 'id = ?', [$id]);
        log_activity('user.update', 'user', $id, 'Updated ' . $name . ' (' . $role['name'] . ($active ? '' : ', deactivated') . ($pass !== '' ? ', password reset' : '') . ')');
    } else {
        $data += ['created_by' => myid(), 'created_at' => now(), 'session_version' => 1];
        $id = db_insert('users', $data);
        log_activity('user.create', 'user', $id, 'Added ' . $name . ' as ' . $role['name']);
    }
    return ['id' => $id];
};

$routes['users.delete'] = function () {
    require_write();
    need('users.manage');
    $u = db_one('SELECT u.*, r.level, r.slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [(int)in('id')]);
    if (!$u) fail('User not found.', 404);
    if ((int)$u['id'] === myid()) fail('You can\'t remove yourself.');
    if (!can_manage_level((int)$u['level'])) fail('You can\'t remove someone at or above your own role.', 403);
    if ($u['slug'] === 'superadmin' && (int)db_val("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'superadmin' AND u.active = 1 AND u.id <> ?", [$u['id']]) < 1) {
        fail('There must always be at least one active Super Admin.');
    }
    db_tx(function () use ($u) {
        // Their posts stay on the calendar — the owner name is kept as text
        db_exec('UPDATE posts SET owner_text = CASE WHEN owner_text = \'\' THEN ? ELSE owner_text END, assignee_id = NULL WHERE assignee_id = ?', [$u['name'], $u['id']]);
        db_exec('DELETE FROM undo_buffer WHERE user_id = ?', [$u['id']]);
        db_exec('DELETE FROM users WHERE id = ?', [$u['id']]);
    });
    log_activity('user.delete', 'user', (int)$u['id'], 'Removed ' . $u['name'] . ' (' . $u['email'] . ')');
    return [];
};

/* ===== roles & permissions ===== */

function roles_rows(bool $withPerms = true): array
{
    $roles = db_all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS users FROM roles r ORDER BY r.level DESC, r.id');
    $out = [];
    foreach ($roles as $r) {
        $row = ['id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'description' => $r['description'], 'level' => (int)$r['level'],
            'is_system' => (int)$r['is_system'] === 1, 'users' => (int)$r['users'], 'assignable' => can_manage_level((int)$r['level'])];
        if ($withPerms) {
            $row['permissions'] = $r['slug'] === 'superadmin' ? all_permissions() : array_column(db_all('SELECT permission FROM role_permissions WHERE role_id = ?', [$r['id']]), 'permission');
        }
        $out[] = $row;
    }
    return $out;
}

$routes['roles.list'] = function () {
    if (!can('roles.manage') && !can('users.manage')) fail('No access.', 403);
    return ['roles' => roles_rows(), 'catalogue' => PERMISSIONS];
};

$routes['roles.save'] = function () {
    require_write();
    need('roles.manage');
    $id = int_in('id');
    $old = $id ? db_one('SELECT * FROM roles WHERE id = ?', [$id]) : null;
    if ($id && !$old) fail('Role not found.', 404);
    if ($old && $old['slug'] === 'superadmin') fail('The Super Admin role always has every permission.');
    if ($old && !can_manage_level((int)$old['level'])) fail('You can only edit roles below your own.', 403);
    $name = str_in('name', 80);
    if ($name === '') fail('Enter a role name.');
    $level = max(1, min(99, (int)in('level', 30)));
    if (!can_manage_level($level)) fail('Role level must be below your own (' . actor_level() . ').');
    $perms = array_values(array_intersect((array)in('permissions', []), all_permissions()));
    if (!is_superadmin()) $perms = array_values(array_intersect($perms, me()['permissions']));

    $rid = db_tx(function () use ($old, $id, $name, $level, $perms) {
        if ($old) {
            db_update('roles', ['name' => $name, 'description' => str_in('description'), 'level' => $level], 'id = ?', [$id]);
            $rid = $id;
        } else {
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'role';
            $base = $slug; $n = 2;
            while (db_val('SELECT id FROM roles WHERE slug = ?', [$slug])) $slug = $base . '-' . $n++;
            $rid = db_insert('roles', ['slug' => $slug, 'name' => $name, 'description' => str_in('description'), 'level' => $level, 'is_system' => 0, 'created_at' => now()]);
        }
        db_exec('DELETE FROM role_permissions WHERE role_id = ?', [$rid]);
        foreach ($perms as $p) db_insert('role_permissions', ['role_id' => $rid, 'permission' => $p]);
        return $rid;
    });
    log_activity($old ? 'role.update' : 'role.create', 'role', $rid, ($old ? 'Updated' : 'Created') . ' role ' . $name . ' (' . count($perms) . ' permissions)');
    return ['id' => $rid];
};

$routes['roles.delete'] = function () {
    require_write();
    need('roles.manage');
    $r = db_one('SELECT * FROM roles WHERE id = ?', [(int)in('id')]);
    if (!$r) fail('Role not found.', 404);
    if ((int)$r['is_system']) fail('Built-in roles can\'t be deleted — edit their permissions instead.');
    if (!can_manage_level((int)$r['level'])) fail('You can only delete roles below your own.', 403);
    if ((int)db_val('SELECT COUNT(*) FROM users WHERE role_id = ?', [$r['id']])) fail('Move the users in this role to another role first.');
    db_exec('DELETE FROM role_permissions WHERE role_id = ?', [$r['id']]);
    db_exec('DELETE FROM roles WHERE id = ?', [$r['id']]);
    log_activity('role.delete', 'role', (int)$r['id'], 'Deleted role ' . $r['name']);
    return [];
};

/* ===== platforms & templates ===== */

$routes['platforms.save'] = function () {
    require_write();
    need('platforms.manage');
    $id = int_in('id');
    $name = str_in('name', 60);
    if ($name === '') fail('Enter a platform name.');
    if (db_val('SELECT id FROM platforms WHERE name = ? AND id <> ?', [$name, $id ?? 0])) fail('A platform with that name already exists.');
    $url = str_in('url', 255);
    if (!valid_link($url)) fail('Channel link must start with https://');
    $types = array_values(array_unique(array_filter(array_map(fn($t) => mb_substr(trim((string)$t), 0, 120), (array)in('types', [])))));
    if (!$types) fail('Add at least one content type.');
    $data = [
        'name' => $name, 'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)in('color')) ? (string)in('color') : '#00d8c6', 'url' => $url,
        'description' => str_in('description'), 'metric_label' => str_in('metric_label', 40) ?: 'followers', 'group_name' => str_in('group_name', 60) ?: $name,
        'short_name' => str_in('short_name', 10) ?: mb_strtoupper(mb_substr($name, 0, 2)), 'types' => json_encode($types, JSON_UNESCAPED_UNICODE),
        'active' => in('active', true) ? 1 : 0,
    ];
    if ($id) {
        $old = platform_get($id);
        db_update('platforms', $data, 'id = ?', [$id]);
        // Renamed types: keep posts consistent when a type was renamed in place
        $renames = (array)in('renames', []);
        foreach ($renames as $from => $to) {
            if (in_array($from, $old['types'], true) && in_array($to, $types, true)) {
                db_exec('UPDATE posts SET type = ? WHERE platform_id = ? AND type = ?', [$to, $id, $from]);
                db_exec('UPDATE slot_templates SET type = ? WHERE platform_id = ? AND type = ?', [$to, $id, $from]);
                db_exec('UPDATE monthly_templates SET type = ? WHERE platform_id = ? AND type = ?', [$to, $id, $from]);
            }
        }
        log_activity('platform.update', 'platform', $id, 'Updated platform ' . $name);
    } else {
        $data['sort'] = (int)db_val('SELECT COALESCE(MAX(sort),0)+1 FROM platforms');
        $id = db_insert('platforms', $data);
        log_activity('platform.create', 'platform', $id, 'Added platform ' . $name);
    }
    return ['platforms' => platforms_all()];
};

$routes['platforms.delete'] = function () {
    require_write();
    need('platforms.manage');
    $p = platform_get((int)in('id'));
    if ((int)db_val('SELECT COUNT(*) FROM posts WHERE platform_id = ?', [$p['id']])) fail('This platform has posts. Deactivate it instead so history is kept.');
    db_exec('DELETE FROM slot_templates WHERE platform_id = ?', [$p['id']]);
    db_exec('DELETE FROM monthly_templates WHERE platform_id = ?', [$p['id']]);
    db_exec('DELETE FROM monthly_items WHERE platform_id = ?', [$p['id']]);
    db_exec('DELETE FROM metric_values WHERE platform_id = ?', [$p['id']]);
    db_exec('DELETE FROM platforms WHERE id = ?', [$p['id']]);
    log_activity('platform.delete', 'platform', $p['id'], 'Deleted platform ' . $p['name']);
    return ['platforms' => platforms_all()];
};

$routes['platforms.reorder'] = function () {
    require_write();
    need('platforms.manage');
    foreach (array_values((array)in('ids', [])) as $i => $pid) db_exec('UPDATE platforms SET sort = ? WHERE id = ?', [$i, (int)$pid]);
    return ['platforms' => platforms_all()];
};

$routes['templates.get'] = function () {
    need('content.view');
    return [
        'slots' => array_map(fn($s) => ['id' => (int)$s['id'], 'platform_id' => (int)$s['platform_id'], 'type' => $s['type'], 'active' => (int)$s['active'] === 1,
            'days' => array_map('intval', array_filter(explode(',', (string)$s['days']), 'strlen'))],
            db_all('SELECT * FROM slot_templates ORDER BY sort, id')),
        'monthly' => array_map(fn($s) => ['id' => (int)$s['id'], 'platform_id' => (int)$s['platform_id'], 'type' => $s['type'], 'title' => $s['title'],
            'day_of_month' => $s['day_of_month'] !== null ? (int)$s['day_of_month'] : null],
            db_all('SELECT * FROM monthly_templates ORDER BY sort, id')),
    ];
};

$routes['templates.save'] = function () {
    require_write();
    need('platforms.manage');
    $valid = array_column(platforms_all(), null, 'id');
    db_tx(function () use ($valid) {
        db_exec('DELETE FROM slot_templates');
        foreach (array_values((array)in('slots', [])) as $i => $s) {
            $pid = (int)($s['platform_id'] ?? 0); $type = mb_substr(trim((string)($s['type'] ?? '')), 0, 120);
            if (!isset($valid[$pid]) || $type === '') continue;
            $days = array_values(array_unique(array_filter(array_map('intval', (array)($s['days'] ?? [0, 1, 2, 3, 4, 5, 6])), fn($d) => $d >= 0 && $d <= 6)));
            sort($days);
            if (!$days) continue;
            db_insert('slot_templates', ['platform_id' => $pid, 'type' => $type, 'days' => implode(',', $days), 'sort' => $i, 'active' => !empty($s['active']) ? 1 : 0]);
        }
        db_exec('DELETE FROM monthly_templates');
        foreach (array_values((array)in('monthly', [])) as $i => $s) {
            $pid = (int)($s['platform_id'] ?? 0); $type = mb_substr(trim((string)($s['type'] ?? '')), 0, 120); $title = mb_substr(trim((string)($s['title'] ?? '')), 0, 255);
            if (!isset($valid[$pid]) || $type === '' || $title === '') continue;
            $dom = (int)($s['day_of_month'] ?? 0);
            db_insert('monthly_templates', ['platform_id' => $pid, 'type' => $type, 'title' => $title, 'day_of_month' => ($dom >= 1 && $dom <= 31) ? $dom : null, 'sort' => $i]);
        }
    });
    $go = array_values(array_filter(array_map(fn($g) => mb_substr(trim((string)$g), 0, 60), (array)in('group_order', []))));
    if ($go) set_setting('group_order', json_encode($go, JSON_UNESCAPED_UNICODE));
    log_activity('templates.update', 'templates', null, 'Updated daily slot & monthly checklist templates');
    return [];
};

$routes['settings.save'] = function () {
    require_write();
    need('platforms.manage');
    $n = str_in('workspace_name', 60);
    if ($n === '') fail('Enter a workspace name.');
    set_setting('workspace_name', $n);
    log_activity('settings.update', 'settings', null, 'Renamed workspace to ' . $n);
    return [];
};

/* ===== activity log ===== */

function activity_rows(int $limit, int $offset, array $f): array
{
    $where = '1=1'; $params = [];
    if (!empty($f['user_id'])) { $where .= ' AND a.user_id = ?'; $params[] = (int)$f['user_id']; }
    if (!empty($f['q'])) { $where .= ' AND a.summary LIKE ?'; $params[] = '%' . $f['q'] . '%'; }
    if (!empty($f['kind'])) { $where .= ' AND a.action LIKE ?'; $params[] = $f['kind'] . '.%'; }
    return array_map(fn($r) => ['id' => (int)$r['id'], 'action' => $r['action'], 'summary' => $r['summary'], 'created_at' => $r['created_at'],
        'user' => $r['name'] ?? 'Deleted user', 'color' => $r['color'] ?? '#607a86', 'entity' => $r['entity'], 'entity_id' => $r['entity_id'] !== null ? (int)$r['entity_id'] : null],
        db_all("SELECT a.*, u.name, u.color FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE $where ORDER BY a.id DESC LIMIT $limit OFFSET $offset", $params));
}

$routes['activity.list'] = function () {
    need('activity.view');
    $page = max(0, (int)in('page', 0));
    $rows = activity_rows(51, $page * 50, ['user_id' => int_in('user_id'), 'q' => str_in('q', 80), 'kind' => preg_replace('/[^a-z]/', '', (string)in('kind', ''))]);
    return ['rows' => array_slice($rows, 0, 50), 'more' => count($rows) > 50];
};

/* ===== profile ===== */

$routes['profile.save'] = function () {
    require_write();
    $u = db_one('SELECT * FROM users WHERE id = ?', [myid()]);
    $name = str_in('name', 120);
    $email = strtolower(str_in('email', 190));
    if ($name === '') fail('Enter your name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email.');
    if (db_val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, myid()])) fail('Another user already uses that email.');
    $data = ['name' => $name, 'email' => $email, 'title' => str_in('title', 120), 'updated_at' => now(),
        'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)in('color')) ? (string)in('color') : $u['color']];
    $new = (string)in('new_password', '');
    if ($new !== '' || $email !== $u['email']) {
        if (!password_verify((string)in('current_password', ''), $u['password_hash'])) fail('Your current password is incorrect.');
    }
    if ($new !== '') {
        if (strlen($new) < 8) fail('New password must be at least 8 characters.');
        $data['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        $data['session_version'] = (int)$u['session_version'] + 1;
        $_SESSION['sv'] = $data['session_version']; // keep this session, log out others
    }
    db_update('users', $data, 'id = ?', [myid()]);
    log_activity('profile.update', 'user', myid(), 'Updated own profile' . ($new !== '' ? ' and password' : ''));
    return [];
};

/* ===== backup / import / csv ===== */

$routes['backup.export'] = function () {
    need('backup.export');
    $pl = array_column(platforms_all(), 'name', 'id');
    $emails = array_column(db_all('SELECT id, email FROM users'), 'email', 'id');
    $posts = array_map(fn($p) => ['id' => $p['id'], 'date' => $p['date'], 'time' => $p['time'], 'platform' => $pl[$p['platform_id']] ?? '', 'type' => $p['type'],
        'title' => $p['title'], 'status' => $p['status'], 'done' => $p['done'], 'done_at' => $p['done_at'], 'assignee' => $emails[$p['assignee_id']] ?? '',
        'owner' => $p['owner'], 'link' => $p['link'], 'notes' => $p['notes'], 'monthly_item_id' => $p['monthly_item_id']], posts_where('1=1', []));
    $monthly = array_map(fn($i) => ['id' => (int)$i['id'], 'month' => $i['month'], 'platform' => $pl[$i['platform_id']] ?? '', 'type' => $i['type'], 'title' => $i['title'],
        'post_id' => $i['post_id'] !== null ? (int)$i['post_id'] : null, 'draft' => $i['draft'] ? json_decode($i['draft'], true) : null], db_all('SELECT * FROM monthly_items ORDER BY month, sort, id'));
    $metrics = metrics_summary();
    $history = array_map(fn($h) => ['date' => $h['date'], 'note' => $h['note'], 'metrics' => (object)array_combine(
        array_map(fn($k) => $pl[$k] ?? (string)$k, array_keys((array)$h['values'])), array_values((array)$h['values']))], array_reverse($metrics['history']));
    $comments = array_map(fn($c) => ['post_id' => (int)$c['post_id'], 'author' => $emails[$c['user_id']] ?? '', 'body' => $c['body'], 'created_at' => $c['created_at']], db_all('SELECT * FROM comments ORDER BY id'));
    log_activity('backup.export', 'backup', null, 'Exported a full backup');
    return ['backup' => [
        'app' => 'ta-social-command', 'format' => 2, 'exported_at' => date('c'), 'workspace' => setting('workspace_name'),
        'platforms' => array_map(fn($p) => array_diff_key($p, ['id' => 1]), platforms_all()),
        'slot_templates' => array_map(fn($s) => ['platform' => $pl[$s['platform_id']] ?? '', 'type' => $s['type'], 'days' => $s['days'], 'active' => (int)$s['active'] === 1], db_all('SELECT * FROM slot_templates ORDER BY sort')),
        'monthly_templates' => array_map(fn($s) => ['platform' => $pl[$s['platform_id']] ?? '', 'type' => $s['type'], 'title' => $s['title'], 'day_of_month' => $s['day_of_month']], db_all('SELECT * FROM monthly_templates ORDER BY sort')),
        'days_off' => days_off_between('1900-01-01', '2999-12-31'),
        'posts' => $posts, 'monthly_items' => $monthly, 'metric_history' => $history, 'comments' => $comments,
    ]];
};

$routes['backup.csv'] = function () {
    need('backup.export');
    $m = (string)in('month');
    if (!valid_month($m)) fail('Bad month.');
    [$a, $b] = month_bounds($m);
    $pl = array_column(platforms_all(), 'name', 'id');
    $names = array_column(db_all('SELECT id, name FROM users'), 'name', 'id');
    $rows = [['Date', 'Time', 'Platform', 'Content type', 'Title', 'Status', 'Published', 'Published at', 'Assignee', 'Owner', 'Link', 'Notes']];
    foreach (posts_where('p.post_date BETWEEN ? AND ?', [$a, $b]) as $p) {
        $rows[] = [$p['date'], $p['time'], $pl[$p['platform_id']] ?? '', $p['type'], $p['title'], STATUSES[$p['status']] ?? $p['status'], $p['done'] ? 'Yes' : 'No',
            $p['done_at'] ?? '', $names[$p['assignee_id']] ?? '', $p['owner'], $p['link'], $p['notes']];
    }
    return ['rows' => $rows];
};

$routes['backup.import'] = function () {
    require_write();
    need('backup.import');
    $data = in('data');
    $mode = in('mode') === 'replace' ? 'replace' : 'merge';
    if (!is_array($data)) fail('That file isn\'t a valid backup.');

    // Normalise: original browser dashboard export (tasks/metrics/history) → new format
    if (isset($data['tasks']) && is_array($data['tasks'])) {
        $posts = []; $monthly = [];
        foreach ($data['tasks'] as $t) {
            $posts[] = ['id' => $t['id'] ?? null, 'date' => $t['date'] ?? '', 'platform' => $t['platform'] ?? '', 'type' => $t['type'] ?? '', 'title' => $t['title'] ?? '',
                'done' => !empty($t['done']), 'owner' => $t['owner'] ?? '', 'link' => $t['link'] ?? '', 'notes' => $t['notes'] ?? ''];
        }
        foreach ($data['monthlyContent'] ?? [] as $i) {
            $monthly[] = ['id' => $i['id'] ?? null, 'month' => $i['month'] ?? '', 'platform' => $i['platform'] ?? '', 'type' => $i['type'] ?? '', 'title' => $i['title'] ?? '',
                'post_id' => $i['taskId'] ?? null, 'draft' => $i['draft'] ?? null];
        }
        $history = $data['history'] ?? [];
        if (!$history && !empty($data['metrics'])) $history = [['date' => $data['updated'] ?? date('Y-m-d'), 'metrics' => $data['metrics']]];
        $data = ['posts' => $posts, 'monthly_items' => $monthly, 'metric_history' => $history, 'comments' => []];
    }
    if (!isset($data['posts']) || !is_array($data['posts'])) fail('That file isn\'t a valid Social Command backup.');

    $result = db_tx(function () use ($data, $mode) {
        if ($mode === 'replace') {
            foreach (['comments', 'posts', 'monthly_items', 'metric_values', 'metric_batches', 'undo_buffer', 'days_off'] as $t) db_exec("DELETE FROM $t");
        }
        // Platforms by name (create missing ones so nothing is lost)
        $pmap = array_column(platforms_all(), 'id', 'name');
        foreach ($data['platforms'] ?? [] as $p) {
            if (!empty($p['name']) && !isset($pmap[$p['name']])) {
                $pmap[$p['name']] = db_insert('platforms', ['name' => mb_substr($p['name'], 0, 60), 'color' => $p['color'] ?? '#00d8c6', 'url' => $p['url'] ?? '', 'description' => $p['description'] ?? '',
                    'metric_label' => $p['metric_label'] ?? 'followers', 'group_name' => $p['group_name'] ?? $p['name'], 'short_name' => $p['short_name'] ?? '',
                    'types' => json_encode($p['types'] ?? ['Post']), 'sort' => 50, 'active' => 1]);
            }
        }
        $platformId = function (string $name) use (&$pmap) {
            if ($name === '') return null;
            if (!isset($pmap[$name])) {
                $pmap[$name] = db_insert('platforms', ['name' => mb_substr($name, 0, 60), 'color' => '#00d8c6', 'url' => '', 'description' => '', 'metric_label' => 'followers',
                    'group_name' => $name, 'short_name' => '', 'types' => json_encode(['Post']), 'sort' => 50, 'active' => 1]);
            }
            return $pmap[$name];
        };
        $users = db_all('SELECT id, name, email FROM users');
        $byEmail = array_column($users, 'id', 'email');
        $byName = [];
        foreach ($users as $u) $byName[strtolower($u['name'])] = (int)$u['id'];

        $idmap = []; $added = 0; $skipped = 0;
        foreach ($data['posts'] as $p) {
            $date = (string)($p['date'] ?? '');
            $pid = $platformId((string)($p['platform'] ?? ''));
            if (!valid_date($date) || !$pid) { $skipped++; continue; }
            $type = mb_substr((string)($p['type'] ?? ''), 0, 120);
            $title = mb_substr((string)($p['title'] ?? ''), 0, 255);
            if ($mode === 'merge' && db_val('SELECT id FROM posts WHERE post_date = ? AND platform_id = ? AND type = ? AND title = ?', [$date, $pid, $type, $title])) { $skipped++; continue; }
            $owner = (string)($p['owner'] ?? '');
            $assignee = $byEmail[$p['assignee'] ?? ''] ?? ($byName[strtolower($owner)] ?? null);
            $link = (string)($p['link'] ?? '');
            $done = !empty($p['done']);
            $newId = db_insert('posts', [
                'title' => $title, 'post_date' => $date, 'post_time' => (string)($p['time'] ?? ''), 'platform_id' => $pid, 'type' => $type,
                'status' => isset(STATUSES[$p['status'] ?? '']) ? $p['status'] : ($done ? 'published' : 'planned'), 'done' => $done ? 1 : 0,
                'done_at' => $done ? ($p['done_at'] ?? now()) : null, 'assignee_id' => $assignee, 'owner_text' => mb_substr($owner, 0, 120),
                'link' => valid_link($link) ? mb_substr($link, 0, 500) : '', 'notes' => mb_substr((string)($p['notes'] ?? ''), 0, 5000),
                'created_by' => myid(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (isset($p['id'])) $idmap[(string)$p['id']] = $newId;
            $added++;
        }
        $mAdded = 0;
        foreach ($data['monthly_items'] ?? [] as $i) {
            $pid = $platformId((string)($i['platform'] ?? ''));
            if (!$pid || !valid_month((string)($i['month'] ?? ''))) continue;
            $postId = isset($i['post_id']) ? ($idmap[(string)$i['post_id']] ?? null) : null;
            if ($mode === 'merge' && db_val('SELECT id FROM monthly_items WHERE month = ? AND platform_id = ? AND type = ? AND title = ?', [$i['month'], $pid, $i['type'] ?? '', $i['title'] ?? ''])) continue;
            $mid = db_insert('monthly_items', ['month' => $i['month'], 'platform_id' => $pid, 'type' => mb_substr((string)($i['type'] ?? ''), 0, 120), 'title' => mb_substr((string)($i['title'] ?? ''), 0, 255),
                'post_id' => $postId, 'draft' => !empty($i['draft']) ? json_encode($i['draft']) : null, 'sort' => $mAdded, 'created_by' => myid(), 'created_at' => now()]);
            if ($postId) db_exec('UPDATE posts SET monthly_item_id = ? WHERE id = ?', [$mid, $postId]);
            $mAdded++;
        }
        $snaps = 0;
        foreach ($data['metric_history'] ?? [] as $h) {
            if (!valid_date((string)($h['date'] ?? ''))) continue;
            $bid = db_insert('metric_batches', ['snap_date' => $h['date'], 'note' => mb_substr((string)($h['note'] ?? 'Imported'), 0, 255), 'created_by' => myid(), 'created_at' => now()]);
            foreach ((array)($h['metrics'] ?? []) as $name => $val) {
                $pid = $platformId((string)$name);
                if ($pid) db_insert('metric_values', ['batch_id' => $bid, 'platform_id' => $pid, 'value' => ($val === null || $val === '') ? null : (int)$val]);
            }
            $snaps++;
        }
        foreach ($data['comments'] ?? [] as $c) {
            $postId = $idmap[(string)($c['post_id'] ?? '')] ?? null;
            if ($postId && trim((string)($c['body'] ?? '')) !== '') {
                db_insert('comments', ['post_id' => $postId, 'user_id' => $byEmail[$c['author'] ?? ''] ?? null, 'body' => mb_substr((string)$c['body'], 0, 3000), 'created_at' => $c['created_at'] ?? now()]);
            }
        }
        foreach ($data['days_off'] ?? [] as $o) {
            $d = (string)($o['date'] ?? '');
            if (valid_date($d) && !db_val('SELECT off_date FROM days_off WHERE off_date = ?', [$d])) {
                db_insert('days_off', ['off_date' => $d, 'reason' => mb_substr((string)($o['reason'] ?? ''), 0, 120), 'created_by' => myid(), 'created_at' => now()]);
            }
        }
        return ['posts' => $added, 'skipped' => $skipped, 'monthly' => $mAdded, 'snapshots' => $snaps];
    });
    log_activity('backup.import', 'backup', null, 'Imported backup (' . $mode . '): ' . $result['posts'] . ' posts, ' . $result['monthly'] . ' monthly items, ' . $result['snapshots'] . ' audience snapshots');
    return $result;
};

/* ---------------- dispatch ---------------- */

try {
    if ($CONFIG === null) fail('Not installed.', 503);
    migrate();
    $route = (string)($_GET['r'] ?? '');
    if (!isset($routes[$route])) fail('Unknown endpoint.', 404);
    if (!current_user()) fail('Please sign in again.', 401);
    $out = $routes[$route]();
    echo json_encode(['ok' => true, 'data' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (ApiError $e) {
    http_response_code($e->status);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[social-command] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'error' => cfg('app.debug') ? $e->getMessage() : 'Something went wrong on the server. Please try again.']);
}
