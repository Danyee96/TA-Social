<?php
/**
 * Permission catalogue + default roles.
 * Add a new permission here and it appears automatically in Roles & permissions.
 */
declare(strict_types=1);

const PERMISSIONS = [
    'Dashboard' => [
        'dashboard.view'   => 'View the overview dashboard',
        'reports.view'     => 'View reports & team performance',
    ],
    'Content' => [
        'content.view'     => 'View the calendar and all posts',
        'content.create'   => 'Add posts',
        'content.edit_own' => 'Edit posts they created or are assigned to',
        'content.edit'     => 'Edit any post (incl. reassigning)',
        'content.complete' => 'Check posts off as published',
        'content.delete'   => 'Delete posts and clear days',
        'content.generate' => 'Generate a month of daily slots',
        'monthly.manage'   => 'Manage the monthly content list',
        'comments.add'     => 'Comment on posts',
    ],
    'Audience' => [
        'metrics.view'     => 'View audience counts and growth',
        'metrics.update'   => 'Update audience counts',
    ],
    'Administration' => [
        'users.view'       => 'View the team list',
        'users.manage'     => 'Add, edit, deactivate and remove users',
        'roles.manage'     => 'Create roles and edit permissions',
        'platforms.manage' => 'Manage platforms, content types and daily slot templates',
        'activity.view'    => 'View the activity log',
        'backup.export'    => 'Export backups and CSV',
        'backup.import'    => 'Import / restore backups',
    ],
];

function all_permissions(): array
{
    $out = [];
    foreach (PERMISSIONS as $group) {
        foreach ($group as $k => $label) $out[] = $k;
    }
    return $out;
}

/**
 * Default roles. "rank" decides who can manage whom:
 * a user can only manage users/roles with a LOWER rank than their own.
 */
function default_roles(): array
{
    $all = all_permissions();
    return [
        ['slug' => 'superadmin', 'name' => 'Super Admin', 'rank' => 100, 'description' => 'Full control, including roles, permissions and restores. Cannot be restricted.', 'perms' => $all],
        ['slug' => 'admin', 'name' => 'Admin', 'rank' => 80, 'description' => 'Runs the workspace and manages the team (except Super Admins).',
            'perms' => array_values(array_diff($all, ['roles.manage', 'backup.import']))],
        ['slug' => 'manager', 'name' => 'Social Media Manager', 'rank' => 60, 'description' => 'Plans the calendar, assigns posts and updates audience counts.',
            'perms' => ['dashboard.view', 'reports.view', 'content.view', 'content.create', 'content.edit_own', 'content.edit', 'content.complete', 'content.delete', 'content.generate', 'monthly.manage', 'comments.add', 'metrics.view', 'metrics.update', 'users.view', 'activity.view', 'backup.export']],
        ['slug' => 'creator', 'name' => 'Content Creator', 'rank' => 40, 'description' => 'Works on assigned posts and checks them off when live.',
            'perms' => ['dashboard.view', 'content.view', 'content.create', 'content.edit_own', 'content.complete', 'comments.add', 'metrics.view']],
        ['slug' => 'viewer', 'name' => 'Viewer', 'rank' => 10, 'description' => 'Read-only access to the dashboard, calendar and reports.',
            'perms' => ['dashboard.view', 'reports.view', 'content.view', 'metrics.view']],
    ];
}
