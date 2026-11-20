<?php
/** Shared shell for the login / installer / password pages. */
declare(strict_types=1);

/** TA Toolkit identity shared by the public and signed-in screens. */
function brand_html(): string
{
    return '<img src="assets/brand/ta-mark.svg" width="56" height="32" alt="TA"> <span>Toolkit</span>';
}

function auth_page(string $title, string $body, string $workspace = 'TA Toolkit'): void
{
    security_headers();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . h($title) . ' · ' . h($workspace) . '</title><link rel="icon" href="assets/brand/ta-mark.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/app.css?v=' . APP_VERSION . '"></head>'
        . '<body class="authbody"><main class="authwrap"><div class="authcard">'
        . '<div class="brand">' . brand_html() . '</div><div class="eyebrow">Social Command</div>'
        . $body . '</div><p class="authfoot">Our content, in one place · Plan. Publish. Check it off.</p></main></body></html>';
}
