<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
start_session();
security_headers();

$me = current_user();
if (!$me) {
    header('Location: login.php');
    exit;
}
$ws = setting('workspace_name', 'TA Toolkit');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<title><?= h($ws) ?> · Social Command</title>
<link rel="icon" href="assets/brand/ta-mark.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
</head>
<body>
<aside id="sidebar">
  <div class="brand"><?= brand_html() ?></div>
  <div class="eyebrow">Social Command</div>
  <nav class="nav" id="nav" aria-label="Main"></nav>
  <div class="asidefoot">
    <div class="me" id="mecard"></div>
    <span class="accent">●</span> Our content, in one place<br>Plan. Publish. Check it off.
    <form method="post" action="logout.php" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button class="wide">Sign out</button>
    </form>
  </div>
</aside>
<button id="menutoggle" class="menutoggle" aria-label="Open menu">☰</button>
<main>
  <header>
    <div>
      <div class="eyebrow" id="crumb"><?= h($ws) ?> / Marketing Operations</div>
      <h1 id="heading">Our brand. In motion.</h1>
      <div class="muted" id="subtitle">A clear view of our audience and publishing rhythm.</div>
    </div>
    <div class="row">
      <input id="globalsearch" class="search" type="search" placeholder="Search posts…  ( / )" aria-label="Search posts">
      <span class="pill" id="savestatus">Synced</span>
      <button class="primary" id="add">+ Add content</button>
    </div>
  </header>
  <section id="view" aria-live="polite"><div class="empty">Loading…</div></section>
  <div class="footnote">Everything is saved to the shared workspace database. The whole team sees the same calendar.</div>
</main>
<div id="dialogs"></div>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script src="assets/js/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
