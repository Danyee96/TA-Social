# TA Toolkit · Social Command

A multi-user social media content tracker built from the original browser-only dashboard. It has the same design, calendar, monthly list and audience cards. Data is now kept in a shared database, with logins, roles and permissions.

**Stack:** PHP 8.0+ · MySQL/MariaDB (or SQLite) · vanilla JavaScript. There is no build step, no Composer and no npm. You open the folder in VS Code, edit, and upload.

---

## 1. Deploy on Hostinger (about 10 minutes)

1. **Create the database.** In hPanel, go to **Databases → MySQL Databases** and create a database and a user. Note the database name, user and password. They look like `u123456789_social`.
2. **Upload the files.** Go to hPanel **Files → File Manager → public_html**, or a subfolder such as `public_html/social`. Upload the zip and **Extract** it. Make sure `index.php` sits directly inside the folder, not one level deeper.
3. **Set PHP 8.1 or newer.** Go to hPanel **Advanced → PHP Configuration**. 8.2 or 8.3 is recommended.
4. **Run the installer.** Open `https://yourdomain.com/install.php` (or `/social/install.php`). Choose *MySQL*, enter the database details, the workspace name, your timezone and your **Super Admin** login, then click **Install**.
5. **Sign in.** The installer writes `config.php` and locks itself (`storage/installed.lock`).
6. **Turn on SSL** in hPanel, which is free. Then set `'force_https' => true` in `config.php`, or uncomment the HTTPS lines in `.htaccess`.

If the installer can't write `config.php`, copy `config.sample.php` to `config.php`, fill it in with File Manager, and reload `install.php`.

### Updating later
Edit the files locally in VS Code, then upload and overwrite them. **Do not** overwrite `config.php` or the `storage/` folder. Bump `APP_VERSION` in `inc/bootstrap.php` so browsers load the new CSS/JS.

Tip: hPanel → **Advanced → Git** can pull straight from a GitHub repo. `.gitignore` already excludes `config.php` and the database file.

## 2. Run locally (VS Code)

```bash
php -S localhost:8000
```
Open http://localhost:8000/install.php and choose **SQLite**, which needs no database server. Delete `config.php` and the files in `storage/` (`app.sqlite`, `installed.lock`) to reinstall.

## 3. Moving data from the old dashboard

In the old HTML dashboard, click **Export backup ↓**. In the new app, go to **Backup & import → Choose backup file**. Posts, done states, owners, links, notes, the monthly list (with its links to calendar posts) and the audience history all come across. Owners whose name matches a team member are assigned to that person automatically.

---

## Roles & permissions

| Role | Level | Default access |
|---|---|---|
| **Super Admin** | 100 | Everything, always. Manages roles and permissions, restores backups. At least one must stay active. |
| **Admin** | 80 | Everything except editing roles and importing backups. Adds, edits, deactivates and removes users **below** Admin. |
| **Social Media Manager** | 60 | Plans the calendar, generates months, edits and assigns any post, clears days, updates audience counts, reports, activity log, exports. |
| **Content Creator** | 40 | Adds posts, edits their own and assigned posts, checks off their own or unassigned posts, comments. |
| **Viewer** | 10 | Read-only dashboard, calendar, reports and audience. |

* Permissions are edited per role under **Roles & permissions**, using 19 granular switches. You can also create custom roles (e.g. *Video Editor*).
* **Level rule:** a person can only manage users and roles with a *lower* level than their own.
* Deactivating a user, resetting their password or changing their role signs them out of every device at once.
* Removing a user keeps their posts. The posts become unassigned and the person's name is kept as the owner label.

## Everything from the original, kept
Overview cards (combined audience, month completion, today's checklist, past due) · platform cards with channel links, counts, "+N since prior update" and per-platform progress · "Consistency creates the brand" card · today's checklist · month calendar with week rows, "THIS WEEK" highlight, per-day counts (Complete / Past due / Due today / Scheduled / Day off) · click a day to expand it, grouped YouTube / X / IG + Facebook / TikTok · platform filter · generate daily slots (every day or Mon–Fri, duplicates skipped) · add, edit and delete posts with title, date, platform, content type, owner, link and notes · clear day with confirmation and **undo** · monthly content list with date assignment, checkbox, edit, remove, the default monthly checklist (live event, educational thread, product update, member spotlight, branding posts 1 and 2) · manual audience counts with history · export and import backups.

## From the walkthrough video (v1.1)
* **Each daily slot has its own days.** YouTube long form is **Mon–Fri**; the Short, IG/FB Reels, TikTok and both X posts run **every day**. You can edit the days per slot in *Platforms & templates* (S M T W T F S toggles). Generate still offers *Every day* / *Mon–Fri only* as overrides.
* **Days off are remembered.** *Clear day* asks for an optional reason ("Day off · birthday") and marks the day off, so pressing *Generate* again won't refill it. Days off show as 🌴 on the calendar, and *Remove day off* restores them.
* **Clear week** in every week header, for a cruise or a week away. It's one confirmation and one undo.
* **Monthly items with a usual day** (e.g. live event on the 1st, product update on the 28th) land on the calendar automatically when you click *Add monthly checklist*. Days past the end of a month are clamped to its last day.
* Monthly items show a **★ marker** on their calendar day and a *Monthly* badge in the day view. They count toward month completion just like daily posts.
* A **"This week" planner** on the Overview, for the Monday "what's coming this week" check. It shows 7 day tiles with progress, past due, days off, the monthly items due, and how many posts aren't assigned yet. Click a tile to open that day.
* The day view keeps the original column order **YouTube · X · IG / Facebook · TikTok**, and you can edit it.
* Existing installs upgrade themselves on the first page load after you upload the new files. No reinstall is needed.

## Added
* **Logins, roles and permissions.** There is a Super Admin, Admins manage the team, and custom roles are possible.
* **Assign posts to team members.** There are avatars on every post, plus a **My work** page with overdue, upcoming and recently published posts and a sidebar badge.
* **Status workflow:** Planned → In progress → Ready to post → Published. There is also an optional post time.
* **Comments on posts** and a per-post **change history**.
* **Drag and drop** a post onto another day to move it. There is **Duplicate**, **Mark all published** for a day, and undo for deletes as well as cleared days.
* Calendar filters by **assignee** and **status** (incl. *past due*), and a **global search** (press `/`).
* **Reports:** completion %, on-time rate, consistency streak, past due, breakdowns by platform, team member and content type, a daily completion heatmap and audience growth.
* **Audience page:** dated snapshots with notes, growth % per platform, sparklines and an editable history.
* **Platforms & templates:** add, rename, recolour, reorder or hide platforms. You can edit content types (renaming one updates existing posts) and the daily slot and monthly checklist templates without touching code.
* **Activity log** of every change, filterable by person and type.
* **CSV export** of any month, and JSON backup/restore (merge or replace).
* Generate slots **from a start date** (useful mid-month) and **assign them all** to one person.
* The view auto-refreshes every 60 seconds so the whole team stays in sync. Keyboard shortcuts: `/` search, `N` new post, `Esc` close.
* Security: bcrypt passwords, CSRF tokens, login rate limiting (8 tries per 15 minutes), HttpOnly/SameSite cookies, prepared SQL statements, escaped output, and protected `inc/`, `storage/` and `config.php`.

## File map
```
index.php          app shell (after login)
login.php / logout.php / install.php
api.php            all JSON endpoints: api.php?r=posts.save etc. Each checks permissions
inc/bootstrap.php  config, session, helpers, APP_VERSION
inc/db.php         PDO helpers (MySQL + SQLite)
inc/auth.php       login, sessions, can()
inc/permissions.php  permission list + default roles  ← add new permissions here
inc/schema.php     tables + default platforms/templates
assets/css/app.css original styles + additions
assets/js/app.js   whole front end, split into numbered sections
config.php         created by the installer (not in git)
storage/           SQLite file + install lock (not web-accessible)
```

Forgot the only Super Admin password? Open phpMyAdmin and run `UPDATE users SET password_hash = '<hash>' WHERE email = 'you@…';`. To get the hash, run `php -r "echo password_hash('NewPass123', PASSWORD_DEFAULT);"`.
