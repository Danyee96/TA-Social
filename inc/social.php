<?php
declare(strict_types=1);

const SOCIAL_PLATFORMS = [
    'instagram' => ['label' => 'Instagram', 'actor' => 'apify~instagram-profile-scraper', 'metric' => 'Followers'],
    'youtube' => ['label' => 'YouTube', 'actor' => 'streamers~youtube-channel-scraper', 'metric' => 'Subscribers'],
    'tiktok' => ['label' => 'TikTok', 'actor' => 'clockworks~tiktok-profile-scraper', 'metric' => 'Followers'],
    'x' => ['label'=>'X (Twitter)', 'actor'=>'feedminer~x-profile-scraper', 'metric'=>'Followers'],
    'facebook' => ['label' => 'Facebook', 'actor' => 'apify~facebook-pages-scraper', 'metric' => 'Followers'],
];

const SOCIAL_FEED_PLATFORMS = ['instagram', 'x', 'youtube'];
const SOCIAL_FEED_BATCH = 10;

function social_feed_schema(bool $sqlite): string
{
    $id = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $keyType = $sqlite ? 'VARCHAR(40)' : 'VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin';
    return "CREATE TABLE IF NOT EXISTS social_published (
        account_id $id NOT NULL, external_id $keyType NOT NULL, caption VARCHAR(500) NOT NULL,
        url VARCHAR(255) NOT NULL, published_at DATETIME NOT NULL, has_time INTEGER NOT NULL,
        PRIMARY KEY (account_id, external_id), FOREIGN KEY (account_id) REFERENCES social_accounts(id) ON DELETE CASCADE
    )$engine";
}

function social_preview_schema(bool $sqlite): string
{
    $id = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $keyType = $sqlite ? 'VARCHAR(40)' : 'VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return "CREATE TABLE IF NOT EXISTS social_post_previews (
        account_id $id NOT NULL, external_id $keyType NOT NULL, detail TEXT NOT NULL,
        PRIMARY KEY (account_id, external_id),
        FOREIGN KEY (account_id, external_id) REFERENCES social_published(account_id, external_id) ON DELETE CASCADE
    )$engine";
}

function social_schema(bool $sqlite): array
{
    $id = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $auto = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $count = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return [
        "CREATE TABLE IF NOT EXISTS social_accounts (
            id $auto, platform VARCHAR(20) NOT NULL,
            handle VARCHAR(120) NOT NULL, identity_key CHAR(64) NOT NULL, profile_url VARCHAR(1300) NOT NULL, display_name VARCHAR(255) NULL,
            is_active INTEGER NOT NULL DEFAULT 1, sync_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            run_id VARCHAR(80) NULL, last_attempt DATETIME NULL, last_synced DATETIME NULL,
            next_sync DATETIME NULL, locked_until DATETIME NULL, last_error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL, UNIQUE (identity_key)
        )$engine",
        "CREATE TABLE IF NOT EXISTS social_snapshots (
            account_id $id NOT NULL, snapshot_date CHAR(10) NOT NULL, followers $count NULL,
            posts $count NULL, likes $count NULL, views $count NULL, captured_at DATETIME NOT NULL,
            PRIMARY KEY (account_id, snapshot_date), FOREIGN KEY (account_id) REFERENCES social_accounts(id) ON DELETE CASCADE
        )$engine",
        social_feed_schema($sqlite),
        social_preview_schema($sqlite),
    ];
}

function social_config(): array
{
    $config = cfg('apify', []);
    $config['token'] = trim((string)($config['token'] ?? '')) ?: trim((string)getenv('TA_SOCIAL_APIFY_TOKEN'));
    if ($config['token'] === '' && is_file(APP_ROOT . '/storage/apify.local.php')) {
        $config['token'] = (string)require APP_ROOT . '/storage/apify.local.php';
    }
    return $config;
}

function social_enabled(): bool { return trim((string)(social_config()['token'] ?? '')) !== ''; }
function social_interval(): int { return max(6, min(168, (int)(social_config()['refresh_hours'] ?? 24))) * 3600; }
function social_time(int $seconds): string { return date('Y-m-d H:i:s', time() + $seconds); }

/** Canonicalize handles/URLs without ever requesting user-supplied URLs. */
function social_identity(string $platform, string $raw): array
{
    if (!isset(SOCIAL_PLATFORMS[$platform])) throw new InvalidArgumentException('Choose a supported platform.');
    $raw = trim($raw);
    if (preg_match('~^(?:www\.)?(instagram\.com|youtube\.com|tiktok\.com|facebook\.com|x\.com|twitter\.com)/~i', $raw)) $raw = 'https://' . $raw;
    $hosts = ['x'=>['x.com','www.x.com','twitter.com','www.twitter.com','mobile.twitter.com'], 'instagram'=>['instagram.com','www.instagram.com'], 'youtube'=>['youtube.com','www.youtube.com','m.youtube.com'], 'tiktok'=>['tiktok.com','www.tiktok.com'], 'facebook'=>['facebook.com','www.facebook.com','m.facebook.com']];
    $channelId = false;
    if (str_contains($raw, '://')) {
        $url = parse_url($raw);
        if (!$url || !in_array(strtolower($url['host'] ?? ''), $hosts[$platform], true) || !in_array($url['scheme'] ?? '', ['https', 'http'], true) || isset($url['user']) || isset($url['port'])) {
            throw new InvalidArgumentException('Enter a handle or a profile URL from the selected platform.');
        }
        $path = trim(rawurldecode($url['path'] ?? ''), '/');
        if ($platform === 'facebook' && $path === 'profile.php') {
            parse_str($url['query'] ?? '', $query);
            $raw = is_string($query['id'] ?? null) ? $query['id'] : '';
        } elseif ($platform === 'youtube' && preg_match('~^channel/(UC[A-Za-z0-9_-]{22})/?$~', $path, $m)) {
            $raw = $m[1]; $channelId = true;
        } else {
            if (str_contains($path, '/')) throw new InvalidArgumentException('Use the profile URL, rather than a post or video URL.');
            $raw = $path;
        }
    }
    $handle = ltrim($raw, '@');
    if ($platform === 'youtube' && preg_match('/^UC[A-Za-z0-9_-]{22}$/', $handle)) $channelId = true;
    $valid = $platform === 'youtube' ? preg_match('/^[\p{L}\p{N}_.\-·]{3,100}$/u', $handle) : preg_match('/^[a-zA-Z0-9_.-]{1,100}$/', $handle);
    if ($platform === 'x') $valid = preg_match('/^[a-zA-Z0-9_]{1,15}$/', $handle);
    $reserved = ['x'=>['home','explore','search','i','login','intent','settings','notifications','messages','compose','signup','tos','privacy'], 'instagram'=>['p','reel','reels','stories','explore'], 'youtube'=>['watch','shorts','playlist','feed','results'], 'tiktok'=>['video','discover','explore'], 'facebook'=>['watch','reel','reels','groups','share','login','profile.php']];
    if (!$valid || in_array(strtolower($handle), $reserved[$platform], true)) throw new InvalidArgumentException('Enter a valid account handle.');
    $handle = mb_strtolower($handle);
    // YouTube channel IDs are case-sensitive.
    if ($channelId) $handle = ltrim($raw, '@');
    $profile = match ($platform) {
        'x' => 'https://x.com/' . rawurlencode($handle),
        'instagram' => 'https://www.instagram.com/' . rawurlencode($handle) . '/',
        'youtube' => 'https://www.youtube.com/' . ($channelId ? 'channel/' : '@') . rawurlencode($handle),
        'tiktok' => 'https://www.tiktok.com/@' . rawurlencode($handle),
        'facebook' => ctype_digit($handle) ? 'https://www.facebook.com/profile.php?id=' . $handle : 'https://www.facebook.com/' . rawurlencode($handle),
    };
    return ['handle'=>$handle, 'profile_url'=>$profile];
}

/** All provider traffic stays on a single fixed HTTPS host; tokens never enter URLs/logs. */
function apify_request(string $method, string $path, ?array $body = null, array $query = [], ?string $token = null): array
{
    $token ??= social_config()['token'];
    if ($token === '') throw new RuntimeException('Automatic stats are not configured. Ask your admin to connect Apify.');
    if (!preg_match('~^/(users/me|acts/[A-Za-z0-9_\~.-]+/runs|actor-runs/[A-Za-z0-9_-]+(?:/abort)?|datasets/[A-Za-z0-9_-]+/items)$~', $path)) throw new RuntimeException('Invalid provider request.');
    $url = 'https://api.apify.com/v2' . $path . ($query ? '?' . http_build_query($query) : '');
    // Injectable transport for isolated integration tests.
    if (isset($GLOBALS['social_test_transport'])) {
        [$code, $response] = ($GLOBALS['social_test_transport'])($method, $path, $body, $query);
    } else {
        if (!function_exists('curl_init')) throw new RuntimeException('Enable the PHP cURL extension to sync social stats.');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_TIMEOUT=>20,
            CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false) throw new RuntimeException('Provider connection failed. The next scheduled sync will retry.');
        $response = json_decode($raw, true);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException(match ((int)$code) {
            401, 403 => 'Apify access was refused. Ask your admin to check the token and actor access.',
            402 => 'Apify credits or actor subscription required. Ask your admin to check billing.',
            404 => 'The stats provider is unavailable. Ask your admin to check the integration.',
            429 => 'The stats provider is busy. The next scheduled sync will retry.',
            default => 'The stats provider could not complete this request. Please try later.',
        });
    }
    if (!is_array($response)) throw new RuntimeException('The stats provider returned an invalid response.');
    return $response;
}

function social_connect_provider(string $token): void
{
    if (!preg_match('/^apify_api_[A-Za-z0-9_-]{10,200}$/D', $token)) {
        throw new RuntimeException('Enter a valid Apify API token.');
    }
    $account = apify_request('GET', '/users/me', null, [], $token);
    if (empty($account['data']['id'])) throw new RuntimeException('Apify could not verify this token.');

    $dir = APP_ROOT . '/storage';
    $file = $dir . '/apify.local.php';
    $temp = $dir . '/apify-' . bin2hex(random_bytes(12)) . '.php';
    $stream = @fopen($temp, 'x');
    if ($stream === false) throw new RuntimeException('The server could not save the Apify token. Check storage permissions.');
    try {
        $contents = '<?php return ' . var_export($token, true) . ';' . PHP_EOL;
        if (!@chmod($temp, 0600) || @fwrite($stream, $contents) !== strlen($contents) || !@fflush($stream) || !@rename($temp, $file)) {
            throw new RuntimeException('The server could not save the Apify token. Check storage permissions.');
        }
    } finally {
        fclose($stream);
        if (is_file($temp)) @unlink($temp);
    }
}

function social_actor_input(array $account): array
{
    return match ($account['platform']) {
        'x' => ['handles'=>[$account['handle']], 'includeTweets'=>true, 'maxTweetsPerProfile'=>SOCIAL_FEED_BATCH, 'excludeRetweets'=>true],
        'instagram' => ['usernames'=>[$account['handle']]],
        'tiktok' => ['profiles'=>[$account['handle']], 'resultsPerPage'=>1, 'shouldDownloadVideos'=>false, 'shouldDownloadCovers'=>false, 'shouldDownloadSlideshowImages'=>false, 'shouldDownloadSubtitles'=>false],
        'youtube' => ['startUrls'=>[['url'=>$account['profile_url']]], 'maxResults'=>6, 'maxResultsShorts'=>4, 'maxResultStreams'=>0, 'sortVideosBy'=>'NEWEST'],
        'facebook' => ['startUrls'=>[['url'=>$account['profile_url']]]],
    };
}

/** Unknown, hidden or malformed values stay NULL, never a misleading zero. */
function social_count(mixed $raw): ?int
{
    if (is_int($raw)) return $raw >= 0 ? $raw : null;
    if (!is_string($raw) && !is_float($raw)) return null;
    $value = str_replace([',', ' ', "\xc2\xa0"], '', trim((string)$raw));
    if (!preg_match('/^(\d+(?:\.\d+)?)([kmb])?$/i', $value, $m)) return null;
    $n = (float)$m[1] * match (strtolower($m[2] ?? '')) { 'k'=>1000, 'm'=>1000000, 'b'=>1000000000, default=>1 };
    return is_finite($n) && $n < PHP_INT_MAX ? (int)round($n) : null;
}

function social_parse(array $account, array $rows): array
{
    foreach ($rows as $row) {
        if (!is_array($row) || !empty($row['error']) || !empty($row['errorCode']) || !empty($row['demo'])) continue;
        $p = $account['platform'];
        $data = match ($p) { 'tiktok'=>$row['authorMeta'] ?? $row, 'youtube'=>$row['aboutChannelInfo'] ?? $row, default=>$row };
        $identity = match ($p) {
            'x'=>$data['handle'] ?? $data['userName'] ?? '',
            'instagram'=>$data['username'] ?? '', 'tiktok'=>$data['name'] ?? $data['uniqueId'] ?? '',
            'youtube'=>str_contains($account['profile_url'], '/channel/') ? ($data['channelId'] ?? $row['channelId'] ?? '') : ($data['channelUsername'] ?? $row['channelUsername'] ?? ''),
            'facebook'=>ctype_digit($account['handle']) ? (string)($data['pageId'] ?? $data['facebookId'] ?? '') : ($data['pageName'] ?? ''),
        };
        $identity = (string)$identity;
        $isChannelId = $p === 'youtube' && str_contains($account['profile_url'], '/channel/');
        if (!social_handle_matches($account, $identity, $isChannelId)) continue;
        $followers = social_count(match ($p) {
            'x'=>$data['followersCount'] ?? $data['followers'] ?? null,
            'instagram'=>$data['followersCount'] ?? null, 'tiktok'=>$data['fans'] ?? $data['followerCount'] ?? null,
            'youtube'=>$data['numberOfSubscribers'] ?? $row['numberOfSubscribers'] ?? null, 'facebook'=>$data['followers'] ?? null,
        });
        if ($followers === null) continue;
        return [
            'followers'=>$followers,
            'posts'=>social_count(match ($p) { 'x'=>$data['tweetsCount'] ?? $data['statusesCount'] ?? null, 'instagram'=>$data['postsCount'] ?? null, 'tiktok'=>$data['video'] ?? $data['videoCount'] ?? null, 'youtube'=>$data['channelTotalVideos'] ?? null, default=>null }),
            'likes'=>social_count(match ($p) { 'tiktok'=>$data['heart'] ?? $data['heartCount'] ?? null, 'facebook'=>$data['likes'] ?? null, default=>null }),
            'views'=>$p === 'youtube' ? social_count($data['channelTotalViews'] ?? null) : null,
            'display_name'=>mb_substr((string)match ($p) { 'x'=>$data['name'] ?? $identity, 'instagram'=>$data['fullName'] ?? $identity, 'tiktok'=>$data['nickName'] ?? $identity, 'youtube'=>$data['channelName'] ?? $row['channelName'] ?? $identity, 'facebook'=>$data['title'] ?? $identity }, 0, 255),
        ];
    }
    throw new RuntimeException('No public follower count was returned for this account. Check the handle and profile visibility.');
}

function social_handle_matches(array $account, string $identity, bool $caseSensitive = false): bool
{
    $identity = ltrim($identity, '@');
    return $caseSensitive ? $identity === $account['handle'] : mb_strtolower($identity) === mb_strtolower($account['handle']);
}

function social_claim(int $id): ?array
{
    $changed = db_exec('UPDATE social_accounts SET locked_until=? WHERE id=? AND is_active=1 AND sync_status<>\'archived\' AND (locked_until IS NULL OR locked_until<?)', [social_time(90), $id, now()]);
    return $changed ? db_one('SELECT * FROM social_accounts WHERE id=?', [$id]) : null;
}

function social_fail(int $id, string $message, bool $clearRun = true): void
{
    db_exec('UPDATE social_accounts SET sync_status=?, last_error=?, next_sync=?, locked_until=NULL' . ($clearRun ? ', run_id=NULL' : '') . ' WHERE id=? AND sync_status<>\'archived\'',
        ['error', mb_substr($message, 0, 255), social_time(3600), $id]);
}

/** One bounded provider call to start a background run; no long-running web requests. */
function social_start(int $id): void
{
    $account = social_claim($id);
    if (!$account) return;
    try {
        if ($account['run_id']) return;
        // An hourly minimum also protects manual refreshes from duplicate charges.
        if ($account['last_attempt'] && strtotime($account['last_attempt']) > time() - 3600) return;
        db_exec('UPDATE social_accounts SET last_attempt=?, next_sync=? WHERE id=?', [now(), social_time(social_interval()), $id]);
        $data = apify_request('POST', '/acts/' . SOCIAL_PLATFORMS[$account['platform']]['actor'] . '/runs', social_actor_input($account), [
            'timeout'=>180, 'maxItems'=>$account['platform'] === 'youtube' ? SOCIAL_FEED_BATCH : 1,
            'maxTotalChargeUsd'=>max(0.01, min(1, (float)(social_config()['max_run_cost_usd'] ?? 0.05))),
        ])['data'] ?? [];
        if (empty($data['id']) || !preg_match('/^[a-zA-Z0-9_-]+$/', $data['id'])) throw new RuntimeException('The provider did not return a valid sync job.');
        db_exec('UPDATE social_accounts SET run_id=?, sync_status=?, last_error=NULL WHERE id=? AND sync_status<>\'archived\'', [$data['id'], 'syncing', $id]);
    } catch (Throwable $e) {
        social_fail($id, $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Could not start stats sync. Please retry later.');
    } finally { db_exec('UPDATE social_accounts SET locked_until=NULL WHERE id=?', [$id]); }
}

/** Poll an existing run; never starts a second paid run. */
function social_collect(int $id): void
{
    $account = social_claim($id);
    if (!$account) return;
    $terminal = false;
    try {
        if (!$account['run_id']) return;
        $run = apify_request('GET', '/actor-runs/' . $account['run_id'])['data'] ?? [];
        $status = $run['status'] ?? '';
        if (in_array($status, ['READY','RUNNING','TIMING-OUT','ABORTING'], true)) return;
        if ($status !== 'SUCCEEDED') {
            $terminal = in_array($status, ['FAILED','TIMED-OUT','ABORTED'], true);
            throw new RuntimeException('The profile sync failed or timed out. A scheduled retry will follow.');
        }
        $dataset = (string)($run['defaultDatasetId'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $dataset)) {
            $terminal = true;
            throw new RuntimeException('The provider did not return stats.');
        }
        $rows = apify_request('GET', '/datasets/' . $dataset . '/items', null, ['clean'=>'true','limit'=>SOCIAL_FEED_BATCH]);
        $published = social_feed_parse($account, $rows);
        if ($account['platform'] === 'youtube') {
            foreach (social_youtube_feed($account, $rows) as $key=>$item) {
                $item['preview'] = array_replace($item['preview'], $published[$key]['preview'] ?? []);
                $published[$key] = $item;
            }
        }
        try {
            $stats = social_parse($account, $rows);
        } catch (RuntimeException $e) {
            if ($published) db_tx(fn() => social_feed_save($id, $published));
            $terminal = true;
            throw $e;
        }
        db()->beginTransaction();
        $name = $stats['display_name']; unset($stats['display_name']);
        $applied=db_exec('UPDATE social_accounts SET display_name=?, last_synced=?, sync_status=?, run_id=NULL, last_error=NULL, next_sync=? WHERE id=? AND run_id=? AND sync_status<>\'archived\'', [$name, now(), 'ready', social_time(social_interval()), $id, $account['run_id']]);
        if (!$applied) { db()->rollBack(); return; }
        $day = date('Y-m-d');
        $stats['captured_at'] = now();
        if (db_val('SELECT account_id FROM social_snapshots WHERE account_id=? AND snapshot_date=?', [$id, $day])) {
            db_update('social_snapshots', $stats, 'account_id=? AND snapshot_date=?', [$id, $day]);
        } else {
            db_insert('social_snapshots', $stats + ['account_id'=>$id, 'snapshot_date'=>$day]);
        }
        social_feed_save($id, $published);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        // Keep a succeeded run on transient network errors, so a retry can collect it without charging again.
        social_fail($id, $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Could not save stats. Please retry later.', $terminal);
    } finally { db_exec('UPDATE social_accounts SET locked_until=NULL WHERE id=?', [$id]); }
}

function social_due(int $limit, bool $runs): array
{
    $condition = $runs
        ? "run_id IS NOT NULL AND (sync_status='syncing' OR next_sync IS NULL OR next_sync<=?)"
        : 'run_id IS NULL AND (next_sync IS NULL OR next_sync<=?) AND (last_attempt IS NULL OR last_attempt<=?)';
    $params = [now(), now()];
    if (!$runs) $params[] = social_time(-3600);
    return db_all("SELECT id FROM social_accounts WHERE is_active=1 AND sync_status<>'archived'
        AND (locked_until IS NULL OR locked_until<?) AND ($condition)
        ORDER BY next_sync, id LIMIT " . max(1, min(100, $limit)), $params);
}

function social_tick(int $limit = 20): int
{
    if (!social_enabled()) throw new RuntimeException('Apify token is not configured.');
    $runs = social_due($limit, true);
    $remaining = $limit - count($runs);
    $starts = $remaining > 0 ? social_due($remaining, false) : [];
    foreach ($runs as $row) social_collect((int)$row['id']);
    foreach ($starts as $row) social_start((int)$row['id']);
    return count($runs) + count($starts);
}

/** Public analytics projection. Internal provider job IDs stay server-side. */
function social_dashboard_data(): array
{
    $accounts = db_all("SELECT * FROM social_accounts WHERE is_active=1 AND sync_status<>'archived' ORDER BY platform, id");
    if (!$accounts) return [];
    $days = db_all('SELECT s.* FROM social_snapshots s JOIN social_accounts a ON a.id=s.account_id
        WHERE a.is_active=1 AND s.snapshot_date>=? ORDER BY s.snapshot_date', [date('Y-m-d', strtotime('-30 days'))]);
    $history = [];
    foreach ($days as $day) $history[$day['account_id']][] = $day;

    return array_map(function (array $a) use ($history) {
        $days = $history[$a['id']] ?? [];
        $latest = $days ? end($days) : db_one('SELECT * FROM social_snapshots WHERE account_id=? ORDER BY snapshot_date DESC LIMIT 1', [$a['id']]);
        $data = [
            'id'=>(int)$a['id'], 'platform'=>$a['platform'], 'label'=>SOCIAL_PLATFORMS[$a['platform']]['label'],
            'metric'=>SOCIAL_PLATFORMS[$a['platform']]['metric'], 'handle'=>$a['handle'], 'url'=>$a['profile_url'],
            'name'=>$a['display_name'], 'status'=>$a['sync_status'], 'last_synced'=>$a['last_synced'],
            'stale'=>$a['last_synced'] && strtotime($a['last_synced']) < time()-social_interval(),
            'error'=>$a['last_error'],
            'can_refresh'=>$a['run_id'] ? $a['sync_status'] === 'error' : (!$a['last_attempt'] || strtotime($a['last_attempt']) <= time()-3600),
            'growth'=>count($days) >= 2 ? (int)$latest['followers'] - (int)$days[0]['followers'] : null,
            'history'=>array_map(fn($day)=>['date'=>$day['snapshot_date'], 'followers'=>(int)$day['followers']], $days),
        ];
        foreach (['followers', 'posts', 'likes', 'views'] as $metric) {
            $data[$metric] = isset($latest[$metric]) ? (int)$latest[$metric] : null;
        }
        return $data;
    }, $accounts);
}

/** Normalize provider records while keeping platform-native post IDs and publication dates. */
function social_feed_parse(array $account, array $rows): array
{
    $platform = $account['platform'];
    if (!in_array($platform, SOCIAL_FEED_PLATFORMS, true)) return [];
    $items = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !empty($row['demo']) || !empty($row['error']) || !empty($row['errorCode'])) continue;
        $channelId = $platform === 'youtube' && str_contains($account['profile_url'], '/channel/');
        $identity = match ($platform) {
            'instagram' => $row['username'] ?? '',
            'x' => $row['handle'] ?? '',
            'youtube' => $channelId
                ? ($row['channelId'] ?? $row['aboutChannelInfo']['channelId'] ?? basename((string)parse_url($row['channelUrl'] ?? '', PHP_URL_PATH)))
                : ($row['channelUsername'] ?? $row['aboutChannelInfo']['channelUsername'] ?? ''),
        };
        if (!social_handle_matches($account, (string)$identity, $channelId)) continue;
        $posts = match ($platform) {
            'instagram' => $row['latestPosts'] ?? [],
            'x' => $row['tweets'] ?? $row['latestTweets'] ?? [],
            'youtube' => [$row],
        };
        if (!is_array($posts)) continue;
        foreach (array_slice($posts, 0, SOCIAL_FEED_BATCH) as $post) {
            if (!is_array($post) || !empty($post['isRetweet']) || !empty($post['isUpcoming'])) continue;
            if (isset($post['authorHandle']) && !social_handle_matches($account, (string)$post['authorHandle'])) continue;
            $id = (string)($platform === 'instagram' ? ($post['shortCode'] ?? '') : ($post['id'] ?? ''));
            $valid = match ($platform) {
                'instagram' => preg_match('/^[A-Za-z0-9_-]{3,30}$/D', $id),
                'x' => preg_match('/^[0-9]{1,25}$/D', $id),
                'youtube' => preg_match('/^[A-Za-z0-9_-]{11}$/D', $id),
            };
            if (!$valid) continue;

            $rawDate = match ($platform) {
                'instagram' => $post['timestamp'] ?? null,
                'x' => $post['createdAt'] ?? null,
                'youtube' => $post['publishedAt'] ?? $post['date'] ?? null,
            };
            if (!is_string($rawDate) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d))?$/D', $rawDate)
                || !valid_date(substr($rawDate, 0, 10))) continue;
            $hasTime = strlen($rawDate) > 10;
            $date = new DateTimeImmutable($rawDate, new DateTimeZone('UTC'));
            if ($date->getTimestamp() > time()) continue;

            $url = match ($platform) {
                'instagram' => 'https://www.instagram.com/p/' . $id . '/',
                'x' => $account['profile_url'] . '/status/' . $id,
                'youtube' => 'https://www.youtube.com/watch?v=' . $id,
            };
            $caption = match ($platform) {
                'instagram' => $post['caption'] ?? '',
                'x' => $post['text'] ?? '',
                'youtube' => $post['title'] ?? '',
            };
            $items[$id] = [
                'external_id' => $id,
                'caption' => mb_substr(trim((string)$caption), 0, 500),
                'url' => $url,
                'published_at' => $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'has_time' => (int)$hasTime,
                'preview' => social_post_preview($platform, $post),
            ];
        }
    }
    return $items;
}

function social_post_preview(string $platform, array $post): array
{
    $image = match ($platform) {
        'instagram' => $post['displayUrl'] ?? '',
        'x' => $post['media'][0]['url'] ?? '',
        default => '',
    };
    $preview = [];
    if (is_string($image) && filter_var($image, FILTER_VALIDATE_URL)) {
        $url = parse_url($image);
        $host = strtolower($url['host'] ?? '');
        if (($url['scheme'] ?? '') === 'https' && !isset($url['user']) && !isset($url['port']) &&
            ($host === 'pbs.twimg.com' || str_ends_with($host, '.cdninstagram.com') || str_ends_with($host, '.fbcdn.net'))) {
            $preview['thumbnail'] = $image;
        }
    }
    $metrics = match ($platform) {
        'instagram' => ['likes'=>$post['likesCount'] ?? null, 'comments'=>$post['commentsCount'] ?? null, 'views'=>$post['videoViewCount'] ?? null],
        'x' => ['likes'=>$post['likes'] ?? $post['likeCount'] ?? null, 'replies'=>$post['replies'] ?? $post['replyCount'] ?? null,
            'reposts'=>$post['retweets'] ?? $post['retweetCount'] ?? null, 'views'=>$post['views'] ?? $post['viewCount'] ?? null],
        'youtube' => ['views'=>$post['viewCount'] ?? null, 'likes'=>$post['likes'] ?? null, 'comments'=>$post['commentsCount'] ?? null],
    };
    foreach ($metrics as $key=>$value) {
        $count = social_count($value);
        if ($count !== null) $preview[$key] = $count;
    }
    return $preview;
}

/** YouTube's own channel feed supplies timestamps when the channel actor only has a listing date. */
function social_youtube_feed(array $account, array $rows): array
{
    $channel = str_contains($account['profile_url'], '/channel/') ? $account['handle'] : '';
    foreach ($rows as $row) {
        if ($channel !== '') break;
        if (!is_array($row) || !social_handle_matches($account, (string)($row['channelUsername'] ?? ''))) continue;
        $channel = (string)($row['channelId'] ?? '');
    }
    if (!preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $channel) || !function_exists('simplexml_load_string') || !function_exists('curl_init')) return [];

    $curl = curl_init('https://www.youtube.com/feeds/videos.xml?channel_id=' . $channel);
    $xml = '';
    curl_setopt_array($curl, [
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$xml) {
            if (strlen($xml) + strlen($chunk) > 512000) return 0;
            $xml .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl) && curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200;
    curl_close($curl);
    if (!$ok) return [];
    $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    if ($feed === false) return [];

    $entries = [];
    foreach ($feed->entry as $entry) {
        $yt = $entry->children('http://www.youtube.com/xml/schemas/2015');
        if ((string)$yt->channelId !== $channel) continue;
        $entries[] = [
            'id'=>(string)$yt->videoId, 'channelId'=>$channel, 'channelUsername'=>$account['handle'],
            'title'=>(string)$entry->title, 'publishedAt'=>(string)$entry->published,
            'viewCount'=>(string)($entry->children('http://search.yahoo.com/mrss/')->group->community->statistics['views'] ?? ''),
        ];
    }
    return social_feed_parse($account, array_slice($entries, 0, SOCIAL_FEED_BATCH));
}

/** Called inside the social sync transaction while the account is claimed. */
function social_feed_save(int $accountId, array $items): void
{
    foreach ($items as $item) {
        $id = $item['external_id'];
        $preview = $item['preview'] ?? [];
        unset($item['preview']);
        if (db_val('SELECT external_id FROM social_published WHERE account_id=? AND external_id=?', [$accountId, $id]) !== null) {
            db_update('social_published', $item, 'account_id=? AND external_id=?', [$accountId, $id]);
        } else {
            db_insert('social_published', $item + ['account_id'=>$accountId]);
        }
        if ($preview) {
            $previous = db_val('SELECT detail FROM social_post_previews WHERE account_id=? AND external_id=?', [$accountId, $id]);
            $detail = json_encode(array_replace(json_decode($previous ?? '{}', true) ?: [], $preview), JSON_THROW_ON_ERROR);
            if ($previous === null) {
                db_insert('social_post_previews', ['account_id'=>$accountId, 'external_id'=>$id, 'detail'=>$detail]);
            } else {
                db_update('social_post_previews', ['detail'=>$detail], 'account_id=? AND external_id=?', [$accountId, $id]);
            }
        }
    }
}

function social_published_feed(?int $limit, ?string $from = null, ?string $until = null): array
{
    $utc = new DateTimeZone('UTC');
    $workspace = new DateTimeZone(date_default_timezone_get());
    $range = '';
    $params = [];
    if ($from !== null && $until !== null) {
        $range = ' AND ((p.has_time=0 AND p.published_at>=? AND p.published_at<?)
            OR (p.has_time=1 AND p.published_at>=? AND p.published_at<?))';
        $params = [$from . ' 00:00:00', $until . ' 00:00:00',
            (new DateTimeImmutable($from, $workspace))->setTimezone($utc)->format('Y-m-d H:i:s'),
            (new DateTimeImmutable($until, $workspace))->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
    $suffix = $limit === null ? '' : ' LIMIT ' . max(1, min(100, $limit));
    $rows = db_all("SELECT p.external_id, p.caption, p.url, p.published_at, p.has_time, a.platform, a.handle, v.detail
        FROM social_published p JOIN social_accounts a ON a.id=p.account_id
        LEFT JOIN social_post_previews v ON v.account_id=p.account_id AND v.external_id=p.external_id WHERE a.is_active=1
        AND NOT EXISTS (
            SELECT 1 FROM social_accounts other_account JOIN social_published other_post ON other_post.account_id=other_account.id
            WHERE other_account.is_active=1 AND other_account.platform=a.platform AND other_account.id<a.id AND other_post.external_id=p.external_id
        )
        $range ORDER BY p.published_at DESC, p.account_id, p.external_id" . $suffix, $params);
    foreach ($rows as &$row) {
        $row['label'] = SOCIAL_PLATFORMS[$row['platform']]['label'];
        $date = new DateTimeImmutable($row['published_at'], $utc);
        $row['workspace_date'] = ($row['has_time'] ? $date->setTimezone($workspace) : $date)->format('Y-m-d');
        $row['preview'] = json_decode($row['detail'] ?? '{}', true) ?: [];
        if ($row['platform'] === 'youtube') {
            $row['preview']['thumbnail'] = 'https://i.ytimg.com/vi/' . $row['external_id'] . '/hqdefault.jpg';
        }
        unset($row['detail']);
        $row['published_at'] = $row['has_time'] ? $date->format(DATE_ATOM) : $date->format('Y-m-d');
        $row['published_label'] = $row['has_time'] ? $date->setTimezone($workspace)->format('M j, Y · g:i A T') : $date->format('M j, Y');
    }
    return $rows;
}
