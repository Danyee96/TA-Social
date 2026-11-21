<?php
/**
 * Database schema + seed data. Used by install.php.
 * Portable between MySQL/MariaDB and SQLite.
 */
declare(strict_types=1);

require_once __DIR__ . '/social.php';

const SCHEMA_VERSION = 4;

/** Upgrades an existing install in place (run automatically on first request after an update). */
function migrate(): void
{
    $v = (int)setting('schema_version', '1');
    if ($v >= SCHEMA_VERSION) return;
    $d = db_driver();
    $int = $d === 'sqlite' ? 'INTEGER' : 'INT';
    if ($v < 2) {
        $tail = $d === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        db()->exec("ALTER TABLE slot_templates ADD COLUMN days VARCHAR(20) NOT NULL DEFAULT '0,1,2,3,4,5,6'");
        db()->exec("ALTER TABLE monthly_templates ADD COLUMN day_of_month $int NULL");
        db()->exec("CREATE TABLE days_off (off_date DATE NOT NULL PRIMARY KEY, reason VARCHAR(120) NOT NULL DEFAULT '', created_by $int NULL, created_at DATETIME NOT NULL)$tail");
        db_exec("UPDATE slot_templates SET days = '1,2,3,4,5' WHERE type = 'Long form · Chart breakdown'");
        if (setting('group_order') === null) set_setting('group_order', json_encode(['YouTube', 'X', 'IG / Facebook', 'TikTok']));
    }
    if ($v < 3) {
        foreach (social_schema($d === 'sqlite') as $sql) db()->exec($sql);
        $insert = $d === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        db_exec("$insert INTO role_permissions (role_id, permission) SELECT id, ? FROM roles WHERE slug = 'admin'", ['socials.manage']);
    }
    db_tx(function () {
        // Give profiles without a snapshot one fresh attempt with the new X provider.
        $changed = db_exec("UPDATE settings SET svalue=? WHERE skey='schema_version' AND svalue IN ('1','2','3')",
            [(string)SCHEMA_VERSION]);
        if ($changed) {
            db_exec("UPDATE social_accounts SET sync_status='pending', last_attempt=NULL, next_sync=NULL, last_error=NULL
                WHERE platform='x' AND last_synced IS NULL AND run_id IS NULL
                AND sync_status IN ('pending','error') AND (locked_until IS NULL OR locked_until<?)", [now()]);
        } elseif (setting('schema_version') === null) {
            set_setting('schema_version', (string)SCHEMA_VERSION);
        }
    });
}

function schema_sql(string $driver): array
{
    $id   = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $tail = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $int  = $driver === 'sqlite' ? 'INTEGER' : 'INT';
    $big  = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
    $text = $driver === 'sqlite' ? 'TEXT' : 'MEDIUMTEXT';

    return [
        "CREATE TABLE roles (
            id $id,
            slug VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            level $int NOT NULL DEFAULT 10,
            is_system $int NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        )$tail",
        "CREATE TABLE role_permissions (
            role_id $int NOT NULL,
            permission VARCHAR(60) NOT NULL,
            PRIMARY KEY (role_id, permission)
        )$tail",
        "CREATE TABLE users (
            id $id,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role_id $int NOT NULL,
            title VARCHAR(120) NOT NULL DEFAULT '',
            color VARCHAR(9) NOT NULL DEFAULT '#00d8c6',
            active $int NOT NULL DEFAULT 1,
            session_version $int NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_by $int NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )$tail",
        "CREATE TABLE platforms (
            id $id,
            name VARCHAR(60) NOT NULL UNIQUE,
            color VARCHAR(9) NOT NULL DEFAULT '#00d8c6',
            url VARCHAR(255) NOT NULL DEFAULT '',
            description VARCHAR(255) NOT NULL DEFAULT '',
            metric_label VARCHAR(40) NOT NULL DEFAULT 'followers',
            group_name VARCHAR(60) NOT NULL DEFAULT '',
            short_name VARCHAR(10) NOT NULL DEFAULT '',
            types $text NOT NULL,
            sort $int NOT NULL DEFAULT 0,
            active $int NOT NULL DEFAULT 1
        )$tail",
        "CREATE TABLE posts (
            id $id,
            title VARCHAR(255) NOT NULL DEFAULT '',
            post_date DATE NOT NULL,
            post_time VARCHAR(5) NOT NULL DEFAULT '',
            platform_id $int NOT NULL,
            type VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'planned',
            done $int NOT NULL DEFAULT 0,
            done_at DATETIME NULL,
            done_by $int NULL,
            assignee_id $int NULL,
            owner_text VARCHAR(120) NOT NULL DEFAULT '',
            link VARCHAR(500) NOT NULL DEFAULT '',
            notes $text NULL,
            monthly_item_id $int NULL,
            created_by $int NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )$tail",
        "CREATE INDEX idx_posts_date ON posts (post_date)",
        "CREATE INDEX idx_posts_platform ON posts (platform_id)",
        "CREATE INDEX idx_posts_assignee ON posts (assignee_id)",
        "CREATE TABLE monthly_items (
            id $id,
            month VARCHAR(7) NOT NULL,
            platform_id $int NOT NULL,
            type VARCHAR(120) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            post_id $int NULL,
            draft $text NULL,
            sort $int NOT NULL DEFAULT 0,
            created_by $int NULL,
            created_at DATETIME NOT NULL
        )$tail",
        "CREATE INDEX idx_monthly_month ON monthly_items (month)",
        "CREATE TABLE slot_templates (
            id $id,
            platform_id $int NOT NULL,
            type VARCHAR(120) NOT NULL,
            days VARCHAR(20) NOT NULL DEFAULT '0,1,2,3,4,5,6',
            sort $int NOT NULL DEFAULT 0,
            active $int NOT NULL DEFAULT 1
        )$tail",
        "CREATE TABLE monthly_templates (
            id $id,
            platform_id $int NOT NULL,
            type VARCHAR(120) NOT NULL,
            title VARCHAR(255) NOT NULL,
            day_of_month $int NULL,
            sort $int NOT NULL DEFAULT 0
        )$tail",
        "CREATE TABLE days_off (
            off_date DATE NOT NULL PRIMARY KEY,
            reason VARCHAR(120) NOT NULL DEFAULT '',
            created_by $int NULL,
            created_at DATETIME NOT NULL
        )$tail",
        "CREATE TABLE metric_batches (
            id $id,
            snap_date DATE NOT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_by $int NULL,
            created_at DATETIME NOT NULL
        )$tail",
        "CREATE TABLE metric_values (
            batch_id $int NOT NULL,
            platform_id $int NOT NULL,
            value $big NULL,
            PRIMARY KEY (batch_id, platform_id)
        )$tail",
        "CREATE TABLE comments (
            id $id,
            post_id $int NOT NULL,
            user_id $int NULL,
            body $text NOT NULL,
            created_at DATETIME NOT NULL
        )$tail",
        "CREATE INDEX idx_comments_post ON comments (post_id)",
        "CREATE TABLE activity_log (
            id $id,
            user_id $int NULL,
            action VARCHAR(60) NOT NULL,
            entity VARCHAR(30) NOT NULL DEFAULT '',
            entity_id $int NULL,
            summary VARCHAR(255) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        )$tail",
        "CREATE INDEX idx_activity_created ON activity_log (created_at)",
        "CREATE TABLE undo_buffer (
            id $id,
            user_id $int NULL,
            kind VARCHAR(30) NOT NULL,
            label VARCHAR(255) NOT NULL DEFAULT '',
            payload $text NOT NULL,
            created_at DATETIME NOT NULL
        )$tail",
        "CREATE TABLE login_attempts (
            id $id,
            ip VARCHAR(45) NOT NULL,
            email VARCHAR(190) NOT NULL DEFAULT '',
            attempted_at DATETIME NOT NULL
        )$tail",
        "CREATE TABLE settings (
            skey VARCHAR(60) NOT NULL PRIMARY KEY,
            svalue $text NULL
        )$tail",
        ...social_schema($driver === 'sqlite'),
    ];
}

/** Seed roles, platforms and templates — exactly as in the original dashboard. */
function seed_defaults(): void
{
    $t = now();
    foreach (default_roles() as $r) {
        $rid = db_insert('roles', ['slug' => $r['slug'], 'name' => $r['name'], 'description' => $r['description'], 'level' => $r['rank'], 'is_system' => 1, 'created_at' => $t]);
        foreach ($r['perms'] as $p) db_insert('role_permissions', ['role_id' => $rid, 'permission' => $p]);
    }

    $platforms = [
        ['YouTube', '#ff6969', 'https://www.youtube.com/@TheTAToolkit', 'Full breakdowns, education, strategy, Shorts & live events', 'subscribers', 'YouTube', 'YT',
            ['Long form · Chart breakdown', 'Long form · Education', 'Long form · Strategy', 'Short form · Chart analysis', 'Community post', 'Live event']],
        ['Instagram', '#d58fff', 'https://www.instagram.com/thetatoolkit/', 'Analysis Reels, education, product branding & member stories', 'followers', 'IG / Facebook', 'IG',
            ['Reel · Chart analysis', 'Education carousel', 'Product branding', 'Member spotlight']],
        ['TikTok', '#00e1cd', 'https://www.tiktok.com/@thetatoolkit', 'Stock & crypto chart analysis shorts', 'followers', 'TikTok', 'TT',
            ['Short form · Chart analysis']],
        ['X', '#ced9e0', 'https://x.com/TheTAToolkit', 'Daily market analysis, educational threads & product updates', 'followers', 'X', 'X',
            ['Market analysis · Post 1', 'Market analysis · Post 2', 'Educational thread', 'Product update']],
        ['Facebook', '#77adff', '', 'Instagram content adapted for Facebook', 'followers', 'IG / Facebook', 'FB',
            ['Reel · Chart analysis', 'Education carousel']],
    ];
    $ids = [];
    foreach ($platforms as $i => $p) {
        $ids[$p[0]] = db_insert('platforms', [
            'name' => $p[0], 'color' => $p[1], 'url' => $p[2], 'description' => $p[3], 'metric_label' => $p[4],
            'group_name' => $p[5], 'short_name' => $p[6], 'types' => json_encode($p[7], JSON_UNESCAPED_UNICODE), 'sort' => $i, 'active' => 1,
        ]);
    }

    // Daily slot defaults (original "Build this month's checklist")
    $slots = [
        ['YouTube', 'Long form · Chart breakdown'], ['YouTube', 'Short form · Chart analysis'],
        ['Instagram', 'Reel · Chart analysis'], ['TikTok', 'Short form · Chart analysis'],
        ['Facebook', 'Reel · Chart analysis'], ['X', 'Market analysis · Post 1'], ['X', 'Market analysis · Post 2'],
    ];
    // As described in the walkthrough video: YouTube long form Mon–Fri, everything else every day
    foreach ($slots as $i => [$p, $type]) {
        $days = $type === 'Long form · Chart breakdown' ? '1,2,3,4,5' : '0,1,2,3,4,5,6';
        db_insert('slot_templates', ['platform_id' => $ids[$p], 'type' => $type, 'days' => $days, 'sort' => $i, 'active' => 1]);
    }

    // Monthly checklist defaults (original "Add monthly checklist", cadence v3)
    $monthly = [
        ['YouTube', 'Live event', 'Public community live event'],
        ['X', 'Educational thread', 'Educational thread'],
        ['X', 'Product update', 'Toolkit product update'],
        ['Instagram', 'Member spotlight', 'Community member spotlight'],
        ['Instagram', 'Product branding', 'Toolkit branding post 1'],
        ['Instagram', 'Product branding', 'Toolkit branding post 2'],
    ];
    foreach ($monthly as $i => [$p, $type, $title]) db_insert('monthly_templates', ['platform_id' => $ids[$p], 'type' => $type, 'title' => $title, 'sort' => $i]);

    set_setting('workspace_name', 'TA Toolkit');
    set_setting('tagline', 'Social Command');
    set_setting('week_start', '0');
    set_setting('installed_at', $t);
    // Day-view column order shown in the original dashboard
    set_setting('group_order', json_encode(['YouTube', 'X', 'IG / Facebook', 'TikTok']));
    set_setting('schema_version', (string)SCHEMA_VERSION);
}
