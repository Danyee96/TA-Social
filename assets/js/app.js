/* =====================================================================
   TA Toolkit · Social Command — front-end app (vanilla JS, no build step)
   Sections:  1 core · 2 dialogs/toasts · 3 navigation · 4 overview
              5 calendar + monthly list · 6 post dialog · 7 my work
              8 reports · 9 audience · 10 team · 11 roles · 12 platforms
              13 activity · 14 backup · 15 profile · 16 search + boot
   ===================================================================== */
'use strict';

/* ---------- 1. core ---------- */
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const CSRF = document.querySelector('meta[name=csrf]').content;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
function localDate(d) { return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; }
let today = localDate(new Date());

const S = {
  me: null, platforms: [], team: [], statuses: {}, undo: null, workspace: {},
  page: 'overview', current: new Date(new Date().getFullYear(), new Date().getMonth(), 1),
  expanded: null, filter: 'All', assigneeFilter: 'all', statusFilter: 'all',
  posts: [], monthly: [], daysOff: {}, groupOrder: [], templates: null, metrics: null, busy: 0,
};

const can = p => S.me.permissions.includes(p);
const isMine = p => p.assignee_id === S.me.id || p.created_by === S.me.id;
const canEditPost = p => can('content.edit') || (can('content.edit_own') && isMine(p));
const canToggle = p => can('content.complete') && (can('content.edit') || !p.assignee_id || p.assignee_id === S.me.id);
const plat = id => S.platforms.find(p => p.id === Number(id)) || { id, name: 'Unknown', color: '#607a86', types: [], group_name: 'Other', metric_label: 'followers', description: '', url: '' };
const member = id => S.team.find(u => u.id === Number(id));
const activePlatforms = () => S.platforms.filter(p => p.active);
const monthKey = () => localDate(S.current).slice(0, 7);
const lastDay = () => localDate(new Date(S.current.getFullYear(), S.current.getMonth() + 1, 0));
const count = ts => ts.filter(t => t.done).length;
const pct = ts => ts.length ? Math.round(count(ts) / ts.length * 100) : 0;
const pretty = (d, opts = { month: 'short', day: 'numeric' }) => new Date(d + 'T12:00:00').toLocaleDateString('en-US', opts);
const period = () => S.current.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
const progress = (ts, p) => `<div class="progress"><i style="width:${p ?? pct(ts)}%"></i></div>`;
const metricNumber = n => n === undefined || n === null || n === '' ? '—' : Number(n).toLocaleString();
const initials = n => String(n || '?').split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase();
const avatar = (u, cls = '') => u ? `<span class="avatar ${cls}" style="--c:${esc(u.color)}" title="${esc(u.name)}">${esc(initials(u.name))}</span>` : '';
const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const daysLabel = days => {
  const d = [...(days || [])].sort().join(',');
  if (d === '0,1,2,3,4,5,6') return 'Every day';
  if (d === '1,2,3,4,5') return 'Mon–Fri';
  if (d === '0,6') return 'Weekends';
  return (days || []).slice().sort().map(i => DOW[i]).join(', ') || 'No days';
};
const statusChip = s => `<span class="status ${esc(s)}">${esc(S.statuses[s] || s)}</span>`;
let CLOCK_SKEW = 0; // server timestamps are in the server timezone; correct for the browser's
const ago = ts => {
  if (!ts) return 'never';
  const d = Math.max(0, (Date.now() - CLOCK_SKEW - new Date(ts.replace(' ', 'T')).getTime()) / 1000);
  if (d < 60) return 'just now'; if (d < 3600) return Math.floor(d / 60) + 'm ago';
  if (d < 86400) return Math.floor(d / 3600) + 'h ago'; if (d < 86400 * 7) return Math.floor(d / 86400) + 'd ago';
  return pretty(ts.slice(0, 10), { month: 'short', day: 'numeric', year: 'numeric' });
};

function setStatus(t) { const el = $('#savestatus'); if (el) el.textContent = t; }

async function call(route, body, params) {
  const qs = params ? '&' + new URLSearchParams(params).toString() : '';
  const opt = body !== undefined ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body) } : {};
  S.busy++; if (body !== undefined) setStatus('Saving…');
  try {
    const res = await fetch('api.php?r=' + route + qs, { credentials: 'same-origin', ...opt });
    let json; try { json = await res.json(); } catch { throw new Error('Server returned an invalid response.'); }
    if (res.status === 401) { location.href = 'login.php'; throw new Error('Signed out'); }
    if (!json.ok) throw new Error(json.error || 'Request failed');
    setStatus('Synced');
    return json.data;
  } catch (e) {
    setStatus(navigator.onLine ? 'Synced' : 'Offline');
    throw e;
  } finally { S.busy--; }
}
const get = (r, params) => call(r, undefined, params);
const post = (r, body = {}) => call(r, body);
/** Run a write; show its error as a toast and return null on failure. */
async function act(r, body, okMsg) {
  try { const d = await post(r, body); if (okMsg) toast(okMsg); return d ?? {}; }
  catch (e) { toast(e.message, { error: true }); return null; }
}

/* ---------- 2. dialogs + toasts ---------- */
function openDialog(html, { cls = '', onMount } = {}) {
  const d = document.createElement('dialog');
  d.className = cls; d.innerHTML = html;
  $('#dialogs').appendChild(d);
  d.addEventListener('close', () => setTimeout(() => d.remove(), 50));
  d.addEventListener('click', e => { if (e.target === d) d.close(); });
  $$('[data-close]', d).forEach(b => b.onclick = () => d.close());
  d.showModal();
  onMount && onMount(d);
  return d;
}
function confirmBox(title, text, ok = 'Confirm', danger = true) {
  return new Promise(resolve => {
    let answered = false;
    const d = openDialog(`<h2>${esc(title)}</h2><p class="muted" style="line-height:1.7">${text}</p>
      <div class="actions"><button type="button" data-close>Cancel</button><button type="button" class="primary" id="cb-ok">${esc(ok)}</button></div>`);
    $('#cb-ok', d).onclick = () => { answered = true; d.close(); resolve(true); };
    d.addEventListener('close', () => { if (!answered) resolve(false); });
  });
}
let toastTimer;
function toast(msg, { error = false, action, actionLabel } = {}) {
  const t = $('#toast');
  t.className = 'toast show' + (error ? ' err' : '');
  t.innerHTML = `<span>${esc(msg)}</span>` + (action ? `<button type="button">${esc(actionLabel || 'Undo')}</button>` : '');
  if (action) $('button', t).onclick = () => { t.className = 'toast'; action(); };
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.className = 'toast', action ? 7000 : 3200);
}

/* ---------- 3. navigation ---------- */
const PAGES = {
  overview: { label: 'Overview', ic: '◈', perm: 'dashboard.view', title: 'Our brand. In motion.', sub: 'A clear view of our audience and publishing rhythm.' },
  tracking: { label: 'Content tracking', ic: '▦', perm: 'content.view', title: 'Our content. Our calendar.', sub: 'Every platform, every post, one checklist.' },
  mine: { label: 'My work', ic: '✓', perm: 'content.view', title: 'My work.', sub: 'Everything assigned to you — overdue first.' },
  reports: { label: 'Reports', ic: '◔', perm: 'reports.view', title: 'How we\'re doing.', sub: 'Completion, consistency and team performance by month.' },
  socials: { label: 'Overview', ic: '◎', perm: ['metrics.view', 'socials.manage'], title: 'Social Overview', sub: 'Today’s published posts and connected profile analytics.' },
  'social-feed': { label: 'Published Feed', ic: '▦', perm: ['metrics.view', 'socials.manage'], title: 'Published Feed', sub: 'Browse imported posts by publication date.' },
  audience: { label: 'Audience', ic: '↗', perm: 'metrics.view', title: 'Our audience.', sub: 'Manual follower snapshots and growth over time.' },
  sec: { section: 'Workspace' },
  team: { label: 'Team', ic: '☺', perm: 'users.view', title: 'Our team.', sub: 'Who has access, and what they can do.' },
  roles: { label: 'Roles & permissions', ic: '⚿', perm: 'roles.manage', title: 'Roles & permissions.', sub: 'Decide exactly what each role can see and change.' },
  platforms: { label: 'Platforms & templates', ic: '⚙', perm: 'platforms.manage', title: 'Platforms & templates.', sub: 'Channels, content types, daily slots and monthly checklist.' },
  activity: { label: 'Activity log', ic: '☰', perm: 'activity.view', title: 'Activity log.', sub: 'Every change, who made it and when.' },
  data: { label: 'Backup & import', ic: '⇅', perm: ['backup.export', 'backup.import'], title: 'Backup & import.', sub: 'Export everything, or bring in a backup from the old browser dashboard.' },
  profile: { label: 'My profile', ic: '◎', perm: null, title: 'My profile.', sub: 'Your details and password.' },
};
const allowed = k => { const p = PAGES[k]?.perm; return p === null || (Array.isArray(p) ? p.some(can) : can(p)); };
const settingsPages = ['team', 'roles', 'platforms', 'data', 'profile'];

function renderNav() {
  let html = '';
  for (const [k, v] of Object.entries(PAGES)) {
    if (v.section) { html += `<div class="navsec">${v.section}</div>`; continue; }
    if (k === settingsPages[0]) {
      const active = settingsPages.includes(S.page);
      html += `<button data-page="settings" ${active ? 'class="active" aria-current="page"' : ''}><span class="ic">⚙</span>Settings</button>`;
    }
    if (settingsPages.includes(k) || !allowed(k)) continue;
    if (k === 'socials') html += '<div class="navsec social-nav-heading">Socials</div>';
    html += `<button data-page="${k}" class="${['socials','social-feed'].includes(k) ? 'social-nav-child' : ''} ${S.page === k ? 'active' : ''}" ${S.page === k ? 'aria-current="page"' : ''}><span class="ic">${v.ic}</span>${v.label}${k === 'mine' && S.myOverdue ? `<span class="count">${S.myOverdue}</span>` : ''}</button>`;
  }
  $('#nav').innerHTML = html;
  $$('#nav [data-page]').forEach(b => b.onclick = () => go(b.dataset.page));
  $('#add').classList.toggle('hidden', !can('content.create'));
}

function go(page, push = true) {
  if (page === 'settings') page = settingsPages.find(allowed);
  if (!PAGES[page] || PAGES[page].section || !allowed(page)) page = allowed('overview') ? 'overview' : 'tracking';
  S.page = page;
  if (push && location.hash !== '#/' + page) history.pushState(null, '', '#/' + page);
  $('#sidebar').classList.remove('open');
  return render();
}
window.addEventListener('popstate', () => go(location.hash.replace('#/', '') || 'overview', false));

async function render() {
  const v = PAGES[S.page];
  const inSettings = settingsPages.includes(S.page);
  $('#heading').textContent = inSettings ? 'Settings' : v.title;
  $('#subtitle').textContent = v.sub;
  document.title = `${v.label} · ${S.workspace.name} · Social Command`;
  renderNav();
  const settingsNav = $('#settings-nav');
  settingsNav.hidden = !inSettings;
  settingsNav.innerHTML = inSettings ? settingsPages.filter(allowed).map(page =>
    `<button data-settings-page="${page}" ${S.page === page ? 'aria-current="page"' : ''}>${esc(PAGES[page].label)}</button>`
  ).join('') : '';
  $$('[data-settings-page]', settingsNav).forEach(button => button.onclick = () => go(button.dataset.settingsPage));
  try {
    await ({ overview: renderOverview, tracking: renderTracking, mine: renderMine, reports: renderReports, socials: renderSocials, 'social-feed': renderPublishedCalendar, audience: renderAudience, team: renderTeam,
      roles: renderRoles, platforms: renderPlatforms, activity: renderActivity, data: renderData, profile: renderProfile })[S.page]();
  } catch (e) {
    $('#view').innerHTML = `<div class="notice warn">Couldn't load this page: ${esc(e.message)} <button onclick="render()">Retry</button></div>`;
  }
}
const refresh = () => render();

/* shared task line (original checklist row + assignee/status) */
function taskHTML(t, { showDate = false } = {}) {
  const p = plat(t.platform_id), u = member(t.assignee_id);
  const overdue = !t.done && t.date < today;
  return `<div class="task ${t.done ? 'done' : ''}" ${canEditPost(t) ? `draggable="true" data-drag="${t.id}"` : ''}>
    <input type="checkbox" data-check="${t.id}" ${t.done ? 'checked' : ''} ${canToggle(t) ? '' : 'disabled'} aria-label="Mark ${esc(p.name + ' ' + (t.title || t.type))} published">
    <div class="info"><div class="tasktitle">${esc(t.title || t.type)}</div>
      <div class="taskmeta">${showDate ? `<b>${pretty(t.date, { weekday: 'short', month: 'short', day: 'numeric' })}</b> · ` : ''}${esc(p.name)} · ${esc(t.type)}${t.time ? ' · ' + esc(t.time) : ''}${u ? ' · ' + esc(u.name) : ''}${t.owner ? ' · ' + esc(t.owner) : ''}${overdue ? ' · <span class="overdue">Past due</span>' : ''}${t.status !== 'planned' && !t.done ? ' ' + statusChip(t.status) : ''}${t.comments ? ` · 💬 ${t.comments}` : ''}${t.monthly_item_id ? ' <span class="badge amber">Monthly</span>' : ''}</div>
      ${t.notes ? `<div class="taskmeta">${esc(t.notes)}</div>` : ''}
      ${t.link ? `<a style="display:inline-block;margin-top:5px;font-size:12px" href="${esc(t.link)}" target="_blank" rel="noopener">Open post / working link ↗</a>` : ''}</div>
    ${u ? avatar(u, 'sm') : ''}
    <button class="iconbtn" data-edit="${t.id}" aria-label="${canEditPost(t) ? 'Edit' : 'View'} post">${canEditPost(t) ? 'Edit' : 'View'}</button></div>`;
}
function bindTasks(root, after = refresh) {
  $$('[data-check]', root).forEach(b => b.onchange = async () => {
    const r = await act('posts.toggle', { id: Number(b.dataset.check), done: b.checked });
    if (!r) b.checked = !b.checked; else after();
  });
  $$('[data-edit]', root).forEach(b => b.onclick = () => openPost(Number(b.dataset.edit)));
  $$('[data-drag]', root).forEach(el => el.ondragstart = e => { e.dataTransfer.setData('text/post', el.dataset.drag); e.dataTransfer.effectAllowed = 'move'; });
}

/* ---------- 4. overview ---------- */
async function renderOverview() {
  const d = await get('overview', { month: monthKey(), today });
  const ts = d.month, tod = d.today, m = d.metrics;
  S.myOverdue = d.mine.filter(t => t.date < today).length; renderNav();
  const known = m ? Object.values(m.current).filter(n => n !== null && n !== '') : [];
  const plats = S.platforms.filter(p => p.active || ts.some(t => t.platform_id === p.id));
  $('#view').innerHTML = `
  <div class="row between" style="margin-bottom:18px"><h2>Our publishing performance <span class="weeklabel">${period()}</span></h2>
    <div class="row"><button id="pm" aria-label="Previous month">←</button><button id="nm" aria-label="Next month">→</button>${can('content.view') ? '<button id="jump">Open calendar →</button>' : ''}</div></div>
  <div class="cards">
    <div class="card"><div class="muted">Combined manual audience</div><div class="big">${known.length ? known.reduce((a, b) => a + Number(b), 0).toLocaleString() : '—'}</div><div class="statline">${known.length} / ${activePlatforms().length} platforms updated · not unique people</div></div>
    <div class="card"><div class="muted">Month completion</div><div class="big accent">${pct(ts)}%</div><div class="statline">${count(ts)} of ${ts.length} posts published</div>${progress(ts)}</div>
    <div class="card"><div class="muted">Today’s checklist</div><div class="big">${count(tod)} <span class="muted" style="font-size:20px">/ ${tod.length}</span></div><div class="statline">${tod.length - count(tod)} posts left today · ${d.upcoming_count} open in the next 7 days</div></div>
    <div class="card"><div class="muted">Past due</div><div class="big ${d.pastdue_count ? 'overdue' : ''}">${d.pastdue_count}</div><div class="statline">Uncompleted posts before today</div></div>
  </div>
  ${weekPlanHTML(d.week)}
  ${canViewSocials() ? `<section class="section" ${d.socials_configured && d.socials.length ? 'data-social-poll' : ''}><div class="row between"><h2>Connected social analytics</h2><button id="go-socials">${can('socials.manage') ? 'Manage profiles' : 'View Socials'} →</button></div>
    ${can('socials.manage') && !d.socials_configured ? '<p class="notice">Connect Apify in Socials to load analytics for your linked profiles.</p>' : ''}
    <div id="dashboard-social-cards">${d.socials.length ? socialCards(d.socials) : '<p class="muted">No social profiles linked yet.</p>'}</div></section>` : ''}
  ${canViewSocials() ? `<section class="section card"><div class="row between"><h2>Recent uploads</h2><button id="go-published">View published feed →</button></div>
    <div id="dashboard-published-feed">${publishedFeedHTML(d.published_feed)}</div></section>` : ''}
  <div class="section row between" style="margin-bottom:16px"><h2>Publishing platforms and manual counts</h2>${can('metrics.update') ? '<button id="metrics">Update audience counts</button>' : ''}</div>
  <div class="platforms">${plats.map(p => {
    const a = ts.filter(t => t.platform_id === p.id), now = m ? m.current[p.id] : null, prev = m ? m.previous[p.id] : null;
    const delta = prev != null && now != null ? Number(now) - Number(prev) : null;
    return `<div class="card platform" style="--p:${esc(p.color)}"><div class="row between"><h3>${esc(p.name)}</h3>${p.url ? `<a href="${esc(p.url)}" target="_blank" rel="noopener">View channel ↗</a>` : '<span class="pill">Channel link not set</span>'}</div>
      <div class="big">${m ? metricNumber(now) : '—'}</div><div class="muted">${esc(p.metric_label)} ${delta !== null ? `<span class="${delta >= 0 ? 'accent' : 'overdue'}">· ${delta >= 0 ? '+' : ''}${delta.toLocaleString()} since prior update</span>` : ''}</div>
      <p class="muted" style="min-height:38px;line-height:1.6">${esc(p.description)}</p>
      <div class="row between"><span class="pill">${count(a)} / ${a.length} published</span><span class="muted">${pct(a)}%</span></div>${progress(a)}</div>`;
  }).join('')}
    <div class="card"><div class="eyebrow">Our operating rhythm</div><h2 style="margin-top:12px">Consistency creates the brand.</h2>
      <p class="muted" style="line-height:1.8">Plan the month, assign an owner, then check each post off when it goes live.</p>${can('content.generate') ? '<button id="build">Build a monthly checklist →</button>' : ''}</div>
  </div>
  <div class="section card"><div class="row between"><h2>Today · ${pretty(today)}</h2>${can('content.view') ? '<button id="todaybutton">Open today →</button>' : ''}</div>
    <div class="checklist" id="todaylist">${tod.length ? tod.map(t => taskHTML(t)).join('') : '<div class="empty">No posts scheduled today. Add a post or generate our monthly checklist.</div>'}</div></div>
  <div class="section two">
    <div class="card"><div class="row between"><h2>My assignments</h2><button id="gomine">My work →</button></div>
      <div class="checklist" id="minelist">${d.mine.length ? d.mine.map(t => taskHTML(t, { showDate: true })).join('') : '<div class="empty">Nothing assigned to you in the next 7 days.</div>'}</div></div>
    <div class="card"><div class="row between"><h2>Past due</h2><span class="pill">${d.pastdue_count} open</span></div>
      <div class="checklist" id="duelist">${d.pastdue.length ? d.pastdue.map(t => taskHTML(t, { showDate: true })).join('') : '<div class="empty">Nothing past due. Nice work.</div>'}</div></div>
  </div>
  ${d.activity.length ? `<div class="section card"><div class="row between"><h2>Recent activity</h2><button id="goact">Full log →</button></div><div class="list" style="margin-top:10px">${d.activity.map(activityLine).join('')}</div></div>` : ''}
  `;
  $('#pm').onclick = () => { S.current.setMonth(S.current.getMonth() - 1); render(); };
  $('#nm').onclick = () => { S.current.setMonth(S.current.getMonth() + 1); render(); };
  $('#jump') && ($('#jump').onclick = () => go('tracking'));
  $('#metrics') && ($('#metrics').onclick = openMetrics);
  $('#go-socials') && ($('#go-socials').onclick = () => go('socials'));
  $('#go-published') && ($('#go-published').onclick = () => go('social-feed'));
  $('#build') && ($('#build').onclick = () => { go('tracking'); openPlan(); });
  $('#todaybutton') && ($('#todaybutton').onclick = () => { S.current = new Date(today + 'T12:00:00'); S.current.setDate(1); S.expanded = today; go('tracking'); });
  $('#gomine').onclick = () => go('mine');
  $$('[data-weekday]').forEach(b => b.onclick = () => { S.current = new Date(b.dataset.weekday + 'T12:00:00'); S.current.setDate(1); S.expanded = b.dataset.weekday; go('tracking'); });
  $('#goact') && ($('#goact').onclick = () => go('activity'));
  bindTasks($('#view'));
}

/** Weekly planning view — the "start of every week, what do we have coming" check from the walkthrough. */
function weekPlanHTML(w) {
  if (!w) return '';
  const off = Object.fromEntries(w.days_off.map(o => [o.date, o.reason]));
  const days = []; for (let i = 0; i < 7; i++) { const d = new Date(w.start + 'T12:00:00'); d.setDate(d.getDate() + i); days.push(localDate(d)); }
  const all = w.posts;
  const unassigned = all.filter(t => !t.done && !t.assignee_id).length;
  return `<div class="section card"><div class="row between"><div><h2>This week <span class="weeklabel">${pretty(w.start)} – ${pretty(w.end)}</span></h2><p class="muted small" style="margin:6px 0 0">${count(all)} of ${all.length} published · ${all.filter(t => !t.done && t.date < today).length} past due${unassigned ? ` · ${unassigned} not assigned yet` : ''}</p></div>${can('content.view') ? '<button id="jump2" onclick="go(\'tracking\')">Open calendar →</button>' : ''}</div>
    <div class="weekplan">${days.map(d => {
      const a = all.filter(t => t.date === d), mon = a.filter(t => t.monthly_item_id), isOff = off[d] !== undefined && !a.length;
      const state = !a.length ? (isOff ? 'off' : 'empty') : count(a) === a.length ? 'done' : d < today ? 'late' : d === today ? 'today' : 'todo';
      return `<button class="wp ${state} ${d === today ? 'istoday' : ''}" data-weekday="${d}"><span class="wpd">${DOW[new Date(d + 'T12:00:00').getDay()]} <b>${Number(d.slice(-2))}</b></span>
        <span class="wpc">${a.length ? `${count(a)}/${a.length}` : isOff ? '🌴' : '—'}</span><span class="wps">${isOff ? esc(off[d] || 'Day off') : !a.length ? 'Nothing planned' : state === 'done' ? 'Complete' : state === 'late' ? 'Past due' : state === 'today' ? 'Due today' : 'Scheduled'}</span>
        ${mon.map(t => `<span class="wpm" style="--p:${esc(plat(t.platform_id).color)}">★ ${esc(t.title || t.type)}</span>`).join('')}</button>`;
    }).join('')}</div></div>`;
}

function activityLine(a) {
  return `<div class="li"><span class="avatar sm" style="--c:${esc(a.color)}">${esc(initials(a.user))}</span><div class="grow"><div class="ellipsis"><b>${esc(a.user)}</b> <span class="muted">·</span> ${esc(a.summary)}</div></div>
    <span class="muted small" title="${esc(a.created_at)}">${ago(a.created_at)}</span>${a.entity === 'post' && a.entity_id ? `<button class="iconbtn" data-openpost="${a.entity_id}">Open</button>` : ''}</div>`;
}
document.addEventListener('click', e => { const b = e.target.closest('[data-openpost]'); if (b) openPost(Number(b.dataset.openpost)); });

/* ---------- 5. calendar + monthly list ---------- */
function weekStart(d) { const n = new Date(d); n.setDate(n.getDate() - n.getDay()); return localDate(n); }
function weeks() {
  let d = new Date(S.current); d.setDate(1); d = new Date(weekStart(d) + 'T12:00:00');
  const out = [];
  while (d.getMonth() === S.current.getMonth() || d <= new Date(S.current.getFullYear(), S.current.getMonth() + 1, 0)) {
    const dates = []; for (let i = 0; i < 7; i++) { dates.push(localDate(d)); d.setDate(d.getDate() + 1); }
    out.push(dates); if (out.length === 6) break;
  }
  return out;
}
function filtered(ts) {
  return ts.filter(t => (S.filter === 'All' || t.platform_id === Number(S.filter))
    && (S.assigneeFilter === 'all' || (S.assigneeFilter === 'none' ? !t.assignee_id : t.assignee_id === Number(S.assigneeFilter)))
    && (S.statusFilter === 'all' || (S.statusFilter === 'open' ? !t.done : S.statusFilter === 'overdue' ? (!t.done && t.date < today) : t.status === S.statusFilter)));
}
function platformGroups() {
  const groups = [];
  const rank = n => { const i = S.groupOrder.indexOf(n); return i < 0 ? 99 : i; };
  for (const p of S.platforms) {
    if (!p.active && !S.posts.some(t => t.platform_id === p.id)) continue;
    let g = groups.find(g => g.name === p.group_name);
    if (!g) groups.push(g = { name: p.group_name, members: [], color: p.color });
    g.members.push(p.id);
  }
  return groups.sort((a, b) => rank(a.name) - rank(b.name));
}

async function loadMonth() {
  const [a, m] = await Promise.all([get('posts.list', { from: monthKey() + '-01', to: lastDay() }), get('monthly.list', { month: monthKey() })]);
  S.posts = a.posts; S.undo = a.undo; S.monthly = m.items;
  S.daysOff = Object.fromEntries((a.days_off || []).map(o => [o.date, o.reason]));
}

async function renderTracking() {
  await loadMonth();
  const ts = filtered(S.posts);
  const filtersOn = S.filter !== 'All' || S.assigneeFilter !== 'all' || S.statusFilter !== 'all';
  $('#view').innerHTML = `
  <div class="row between"><div class="row"><button id="prev" aria-label="Previous month">←</button><span class="monthlabel">${period()}</span><button id="next" aria-label="Next month">→</button><button id="now">Today</button></div>
    <div class="row">${can('backup.export') ? '<button id="csv">Export CSV ↓</button>' : ''}${can('content.generate') ? '<button id="generate">＋ Generate daily slots</button>' : ''}</div></div>
  <div class="toolbar"><div class="row"><button id="collapseall">Close day</button><span class="muted">Click a day to see its posts.${can('content.edit') || can('content.edit_own') ? ' Drag a post onto another day to move it.' : ''}</span></div>
    <div class="row filters"><span class="muted">${count(ts)} / ${ts.length} complete · ${pct(ts)}%</span>
      <select id="filter" aria-label="Filter by platform"><option value="All">All platforms</option>${S.platforms.map(p => `<option value="${p.id}" ${String(S.filter) === String(p.id) ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select>
      <select id="afilter" aria-label="Filter by assignee"><option value="all">Everyone</option><option value="${S.me.id}" ${String(S.assigneeFilter) === String(S.me.id) ? 'selected' : ''}>Assigned to me</option><option value="none" ${S.assigneeFilter === 'none' ? 'selected' : ''}>Unassigned</option>${S.team.filter(u => u.id !== S.me.id).map(u => `<option value="${u.id}" ${String(S.assigneeFilter) === String(u.id) ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>
      <select id="sfilter" aria-label="Filter by status"><option value="all">Any status</option><option value="open" ${S.statusFilter === 'open' ? 'selected' : ''}>Not published</option><option value="overdue" ${S.statusFilter === 'overdue' ? 'selected' : ''}>Past due</option>${Object.entries(S.statuses).map(([k, v]) => `<option value="${k}" ${S.statusFilter === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select>
      ${filtersOn ? '<button id="clearf" class="iconbtn">Reset filters</button>' : ''}</div></div>
  ${S.undo ? `<div class="notice row between" style="margin-bottom:18px"><span>${esc(S.undo.label)}.</span><button id="undoclear">${S.undo.kind === 'clear_day' ? 'Undo clear day' : S.undo.kind === 'clear_week' ? 'Undo clear' : 'Undo delete'}</button></div>` : ''}
  ${monthHTML(ts)}${monthlyHTML()}
  ${!S.posts.length ? '<div class="notice">Generate daily slots to create our recurring checklist, or add individual posts. Click any day to plan its posts.</div>' : ''}`;
  $('#prev').onclick = () => { S.current.setMonth(S.current.getMonth() - 1); S.expanded = null; render(); };
  $('#next').onclick = () => { S.current.setMonth(S.current.getMonth() + 1); S.expanded = null; render(); };
  $('#now').onclick = () => { S.current = new Date(today + 'T12:00:00'); S.current.setDate(1); S.expanded = today; render(); };
  $('#generate') && ($('#generate').onclick = openPlan);
  $('#csv') && ($('#csv').onclick = exportCSV);
  $('#undoclear') && ($('#undoclear').onclick = doUndo);
  $('#filter').onchange = e => { S.filter = e.target.value; render(); };
  $('#afilter').onchange = e => { S.assigneeFilter = e.target.value; render(); };
  $('#sfilter').onchange = e => { S.statusFilter = e.target.value; render(); };
  $('#clearf') && ($('#clearf').onclick = () => { S.filter = 'All'; S.assigneeFilter = 'all'; S.statusFilter = 'all'; render(); });
  $('#collapseall').onclick = () => { S.expanded = null; render(); };
  $$('[data-day]').forEach(b => {
    b.onclick = () => { S.expanded = S.expanded === b.dataset.day ? null : b.dataset.day; render(); };
    b.ondragover = e => { if (e.dataTransfer.types.includes('text/post')) { e.preventDefault(); b.classList.add('dragover'); } };
    b.ondragleave = () => b.classList.remove('dragover');
    b.ondrop = async e => {
      e.preventDefault(); b.classList.remove('dragover');
      const id = Number(e.dataTransfer.getData('text/post'));
      if (id && await act('posts.move', { id, date: b.dataset.day }, 'Moved to ' + pretty(b.dataset.day))) { S.expanded = b.dataset.day; render(); }
    };
  });
  $$('[data-addday]').forEach(b => b.onclick = () => openPost(null, b.dataset.addday));
  $$('[data-clearday]').forEach(b => b.onclick = e => { e.stopPropagation(); clearDates([b.dataset.clearday]); });
  $$('[data-clearweek]').forEach(b => b.onclick = e => { e.stopPropagation(); clearDates(weeks()[Number(b.dataset.clearweek)].filter(d => d.slice(0, 7) === monthKey())); });
  $$('[data-markoff]').forEach(b => b.onclick = async () => { if (await act('days_off.set', { date: b.dataset.markoff, off: true }, 'Marked as a day off')) render(); });
  $$('[data-unoff]').forEach(b => b.onclick = async () => { if (await act('days_off.set', { date: b.dataset.unoff, off: false }, 'Day off removed — you can generate slots for it again')) render(); });
  $$('[data-completeday]').forEach(b => b.onclick = async () => { const r = await act('posts.complete_day', { date: b.dataset.completeday }); if (r) { toast(r.count + ' posts marked published'); render(); } });
  bindTasks($('#view'));
  bindMonthly();
}

function monthHTML(ts) {
  return `<div class="calendar"><div class="dow">${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(d => `<div>${d}</div>`).join('')}</div>${weeks().map((ds, i) => {
    const all = ts.filter(t => ds.includes(t.date)), open = ds.includes(S.expanded);
    return `<section class="calendarweek ${ds.includes(today) ? 'currentweek' : 'quietweek'}"><div class="calendarweekhead"><span><b>Week ${i + 1}</b> <span class="weeklabel">${pretty(ds[0])} – ${pretty(ds[6])}</span></span><span class="row" style="gap:12px">${count(all)} / ${all.length} published${can('content.delete') && ds.some(d => d.slice(0, 7) === monthKey() && S.posts.some(t => t.date === d)) ? `<button class="iconbtn weekclear" data-clearweek="${i}" title="Clear every day of this week in ${esc(period())} (e.g. a week away)">Clear week</button>` : ''}</span></div>
    <div class="calgrid">${ds.map(d => {
      const a = ts.filter(t => t.date === d), inMonth = d.slice(0, 7) === monthKey();
      return `<div ${inMonth ? `role="button" tabindex="0" data-day="${d}" aria-expanded="${S.expanded === d}" aria-label="${pretty(d, { weekday: 'long', month: 'long', day: 'numeric' })}: ${count(a)} of ${a.length} published" onkeydown="if(event.target===this&&(event.key==='Enter'||event.key===' ')){event.preventDefault();this.click()}"` : ''} class="day compactday ${!inMonth ? 'other' : ''} ${d === today ? 'today' : ''} ${S.expanded === d ? 'selectedday' : ''}">
        <div class="daynum">${Number(d.slice(-2))}${d === today ? '<small class="accent">Today</small>' : ''}</div>
        ${inMonth ? `<div class="dailycount ${a.length && count(a) === a.length ? 'complete' : ''} ${!a.length && S.daysOff[d] !== undefined ? 'isoff' : ''}" ${S.daysOff[d] ? `title="${esc(S.daysOff[d])}"` : ''}>${a.length ? `<strong>${count(a)}</strong><span> / ${a.length}</span>` : '<span class="offdash">—</span>'}<small>${a.length ? (count(a) === a.length ? 'Complete' : d < today ? 'Past due' : d === today ? 'Due today' : 'Scheduled') : (S.daysOff[d] !== undefined ? (S.daysOff[d] ? esc(S.daysOff[d].slice(0, 14)) : 'Day off') : 'Day off')}</small></div>${a.some(t => t.monthly_item_id) ? `<span class="monthlymark" title="${a.filter(t => t.monthly_item_id).map(t => esc(t.title || t.type)).join(', ')}">★ ${a.filter(t => t.monthly_item_id).length}</span>` : ''}${a.length ? progress(a) : ''}` : ''}</div>`;
    }).join('')}</div>
    ${open ? `<div class="expandedweek">${ds.filter(d => d === S.expanded && d.slice(0, 7) === monthKey()).map(d => {
      const b = ts.filter(t => t.date === d), dayAll = S.posts.filter(t => t.date === d);
      return `<div class="daily"><div class="row between"><h3>${pretty(d, { weekday: 'long', month: 'short', day: 'numeric' })} ${d === today ? '<span class="pill accent">Today</span>' : ''}</h3>
        <div class="row dayactions"><span class="muted">${count(b)} / ${b.length} published</span>
          ${can('content.create') ? `<button class="iconbtn" data-addday="${d}">+ Add post</button>` : ''}
          ${can('content.complete') && b.some(t => !t.done) ? `<button class="iconbtn" data-completeday="${d}">✓ Mark all published</button>` : ''}
          ${can('content.generate') && S.daysOff[d] === undefined && !dayAll.length ? `<button class="iconbtn" data-markoff="${d}">Mark as day off</button>` : ''}
          ${can('content.delete') ? `<button class="iconbtn" style="color:var(--red)" data-clearday="${d}" ${dayAll.length ? '' : 'disabled'}>Clear day</button>` : ''}</div></div>
        ${S.daysOff[d] !== undefined ? `<div class="notice row between" style="margin:12px 0 0"><span>🌴 Day off${S.daysOff[d] ? ' · ' + esc(S.daysOff[d]) : ''} — “Generate daily slots” skips this day.</span>${can('content.generate') ? `<button class="iconbtn" data-unoff="${d}">Remove day off</button>` : ''}</div>` : ''}
        <div class="dayplatforms">${platformGroups().filter(g => S.filter === 'All' || g.members.includes(Number(S.filter))).map(g => {
          const group = b.filter(t => g.members.includes(t.platform_id));
          return `<div class="platformchecklist"><div class="platformheading" style="--p:${esc(g.color)}">${esc(g.name)}</div>${group.length ? group.map(t => taskHTML(t)).join('') : '<p class="muted">Nothing scheduled.</p>'}</div>`;
        }).join('')}</div></div>`;
    }).join('')}</div>` : ''}</section>`;
  }).join('')}</div>`;
}

/** Clear one day or a whole week (day off, holiday, a week away) — with reason + day-off memory + undo. */
function clearDates(dates) {
  const posts = S.posts.filter(t => dates.includes(t.date));
  const one = dates.length === 1;
  const when = one ? pretty(dates[0], { weekday: 'long', month: 'long', day: 'numeric' }) : `${pretty(dates[0])} – ${pretty(dates[dates.length - 1])}`;
  const d = openDialog(`<form id="cdf"><h2>${one ? 'Clear this day?' : 'Clear this week?'}</h2>
    <p class="muted" style="line-height:1.7">Remove all ${posts.length} posts from ${esc(when)} across every platform?${posts.some(t => t.done) ? ` <b>${count(posts)} are already checked off.</b>` : ''} This only clears the tracker; actual social posts stay published. This can be undone afterward.</p>
    <label>Reason (optional)</label><input id="cdr" maxlength="120" placeholder="${one ? 'e.g. Day off · birthday' : 'e.g. Cruise · team away'}">
    <label class="check"><input type="checkbox" id="cdo" checked> Remember as ${one ? 'a day off' : 'days off'} — “Generate daily slots” won’t refill ${one ? 'it' : 'them'}</label>
    <div class="actions"><button type="button" data-close>Keep content</button><button class="primary">Yes, clear ${one ? 'day' : 'week'}</button></div></form>`);
  $('#cdf', d).onsubmit = async e => {
    e.preventDefault();
    const r = await act('posts.clear_days', { dates, day_off: $('#cdo', d).checked, reason: $('#cdr', d).value });
    if (r) { d.close(); toast(`Cleared ${r.count} posts${r.days_off ? ` · ${r.days_off} day${r.days_off === 1 ? '' : 's'} off` : ''}`, { action: doUndo }); render(); }
  };
}
async function doUndo() {
  const r = await act('posts.undo', {});
  if (r) { toast(`Restored ${r.restored} post${r.restored === 1 ? '' : 's'}`); S.undo = null; render(); }
}

function monthlyHTML() {
  const items = S.monthly, done = items.filter(i => i.post?.done).length;
  return `<section class="section card" id="monthly-content"><div class="row between"><div><div class="eyebrow">Plan it once · Schedule when ready</div><h2 style="margin-top:9px">Monthly content list</h2><p class="muted">Choose a date to put an item on the calendar. Both views track the same post.</p></div>
    <div class="row"><span class="pill">${done} / ${items.length} complete</span>${can('monthly.manage') ? '<button id="monthlydefaults">Add monthly checklist</button><button class="primary" id="monthlyadd">+ Add monthly item</button>' : ''}</div></div>
  ${items.length ? items.map(i => {
    const t = i.post, p = plat(t?.platform_id || i.platform_id), canSched = t ? canEditPost(t) : can('content.create');
    return `<div class="monthlyrow"><input type="checkbox" data-monthcheck="${t?.id || ''}" ${t?.done ? 'checked' : ''} ${t && canToggle(t) ? '' : 'disabled'} aria-label="Mark ${esc(i.title)} complete">
      <div class="monthlyinfo"><b class="${t?.done ? 'accent' : ''}">${esc(t?.title || i.title)}</b><div class="taskmeta"><span class="tag" style="--p:${esc(p.color)}"><i class="dot"></i>${esc(p.name)}</span> · ${esc(t?.type || i.type)}${t && member(t.assignee_id) ? ' · ' + esc(member(t.assignee_id).name) : ''}</div>
        <div class="taskmeta">${t ? (t.done ? '✓ Published' : 'Scheduled · ' + pretty(t.date)) : 'Not scheduled yet'}</div></div>
      <div><label style="margin:0 0 5px">Assign date</label><input type="date" data-monthdate="${i.id}" value="${t?.date || ''}" min="${monthKey()}-01" max="${lastDay()}" ${canSched ? '' : 'disabled'} aria-label="Date for ${esc(i.title)}"></div>
      ${t ? `<button class="iconbtn" data-edit="${t.id}">${canEditPost(t) ? 'Edit post' : 'View post'}</button>` : ''}
      ${can('monthly.manage') ? `<button class="iconbtn" data-monthremove="${i.id}" aria-label="Remove ${esc(i.title)} from monthly list">Remove</button>` : ''}</div>`;
  }).join('') : '<div class="empty">Add our monthly live event, educational thread, product update, member spotlight, or branding post. Assign the dates when we’re ready.</div>'}</section>`;
}
function bindMonthly() {
  $('#monthlyadd') && ($('#monthlyadd').onclick = openMonthlyAdd);
  $('#monthlydefaults') && ($('#monthlydefaults').onclick = async () => { const r = await act('monthly.defaults', { month: monthKey() }); if (r) { toast(r.added ? `Added ${r.added} monthly items${r.scheduled ? ` · ${r.scheduled} put on their usual day` : ''}` : 'Monthly checklist already added'); render(); } });
  $$('[data-monthdate]').forEach(e => e.onchange = async () => { if (await act('monthly.schedule', { id: Number(e.dataset.monthdate), date: e.value }, e.value ? 'Scheduled for ' + pretty(e.value) : 'Unscheduled')) render(); });
  $$('[data-monthcheck]').forEach(e => e.onchange = async () => { if (!e.dataset.monthcheck) return; const r = await act('posts.toggle', { id: Number(e.dataset.monthcheck), done: e.checked }); if (!r) e.checked = !e.checked; else render(); });
  $$('[data-monthremove]').forEach(e => e.onclick = async () => { if (await act('monthly.remove', { id: Number(e.dataset.monthremove) }, 'Removed from the monthly list')) render(); });
}
function typeOptions(pid, sel) { return plat(pid).types.map(t => `<option ${t === sel ? 'selected' : ''}>${esc(t)}</option>`).join(''); }
function platformOptions(sel, includeInactive = false) { return S.platforms.filter(p => p.active || includeInactive || p.id === sel).map(p => `<option value="${p.id}" ${p.id === sel ? 'selected' : ''}>${esc(p.name)}</option>`).join(''); }
function openMonthlyAdd() {
  const first = activePlatforms()[0]?.id;
  const d = openDialog(`<form id="mf"><h2>Add monthly content</h2>
    <label for="mt">Topic / title</label><input id="mt" required placeholder="e.g. Meet the Price Meter carousel">
    <label for="mp">Platform</label><select id="mp">${platformOptions(first)}</select>
    <label for="mty">Content type</label><select id="mty">${typeOptions(first)}</select>
    <p class="muted">This stays in the monthly list until we assign a date.</p>
    <div class="actions"><button type="button" data-close>Cancel</button><button class="primary">Add to list</button></div></form>`);
  $('#mp', d).onchange = () => $('#mty', d).innerHTML = typeOptions(Number($('#mp', d).value));
  $('#mf', d).onsubmit = async e => { e.preventDefault(); if (await act('monthly.add', { month: monthKey(), title: $('#mt', d).value, platform_id: Number($('#mp', d).value), type: $('#mty', d).value }, 'Added to the monthly list')) { d.close(); render(); } };
}

async function openPlan() {
  const tpl = await get('templates.get');
  const isThisMonth = today.slice(0, 7) === monthKey();
  const d = openDialog(`<form id="pf"><h2>Build ${esc(period())}’s checklist</h2>
    <p class="muted">Choose what repeats each day. This adds blank planning slots, not finished posts. Existing posts are kept; duplicate slots are skipped.</p>
    ${tpl.slots.length ? tpl.slots.map(s => `<label class="check"><input type="checkbox" data-slot="${s.id}" ${s.active ? 'checked' : ''}><span class="grow"><span class="tag" style="--p:${esc(plat(s.platform_id).color)}"><i class="dot"></i></span> ${esc(plat(s.platform_id).name)} · ${esc(s.type)}</span><span class="badge">${daysLabel(s.days)}</span></label>`).join('') : '<div class="notice">No daily slot templates yet. Add them under Platforms & templates.</div>'}
    <div class="formgrid"><div><label>Schedule</label><select id="days"><option value="slots">Each slot’s own days (recommended)</option><option value="all">Every day</option><option value="weekdays">Monday–Friday only</option></select></div>
      <div><label>Starting from</label><input type="date" id="from" value="${isThisMonth ? today : monthKey() + '-01'}" min="${monthKey()}-01" max="${lastDay()}"></div></div>
    ${Object.keys(S.daysOff).length ? `<p class="muted small">🌴 ${Object.keys(S.daysOff).length} day${Object.keys(S.daysOff).length === 1 ? '' : 's'} off this month will be skipped.</p>` : ''}
    <label>Assign all slots to (optional)</label><select id="assignall"><option value="">Nobody yet</option>${S.team.filter(u => u.active).map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('')}</select>
    <div class="actions"><button type="button" data-close>Cancel</button><button class="primary">Generate month</button></div></form>`);
  $('#pf', d).onsubmit = async e => {
    e.preventDefault();
    const ids = $$('[data-slot]:checked', d).map(i => Number(i.dataset.slot));
    if (!ids.length) return toast('Pick at least one slot', { error: true });
    const r = await act('posts.generate', { month: monthKey(), slot_ids: ids, schedule: $('#days', d).value, from: $('#from', d).value, assignee_id: $('#assignall', d).value || null });
    if (r) { d.close(); toast(`Added ${r.added} slots${r.skipped ? ` · ${r.skipped} duplicates skipped` : ''}${r.days_off ? ` · ${r.days_off} days off skipped` : ''}`); if (S.page !== 'tracking') go('tracking'); else render(); }
  };
}

async function exportCSV() {
  try {
    const r = await get('backup.csv', { month: monthKey() });
    const csv = r.rows.map(row => row.map(c => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\r\n');
    download(new Blob(['﻿' + csv], { type: 'text/csv' }), `social-content-${monthKey()}.csv`);
  } catch (e) { toast(e.message, { error: true }); }
}
function download(blob, name) {
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

/* ---------- 6. post dialog (add / edit / view + comments) ---------- */
async function openPost(id, date) {
  let p = null, info = { can_edit: can('content.create'), comments: [], history: [] };
  if (id) { try { info = await get('posts.get', { id }); p = info.post; } catch (e) { return toast(e.message, { error: true }); } }
  const editable = p ? info.can_edit : can('content.create');
  const ro = editable ? '' : 'disabled';
  const pid = p?.platform_id || activePlatforms()[0]?.id;
  const defDate = p?.date || date || (today.startsWith(monthKey()) ? today : monthKey() + '-01');
  const assignOpts = `<option value="">Unassigned</option>${S.team.filter(u => u.active || u.id === p?.assignee_id).map(u => `<option value="${u.id}" ${u.id === (p ? p.assignee_id : (can('content.edit') ? null : S.me.id)) ? 'selected' : ''}>${esc(u.name)}${u.id === S.me.id ? ' (me)' : ''}</option>`).join('')}`;
  const d = openDialog(`<form id="tf"><div class="dlghead"><h2>${p ? (editable ? 'Edit content' : 'Post details') : 'Add content'}</h2><button type="button" class="iconbtn" data-close aria-label="Close">✕</button></div>
    <label>Post title / topic</label><input id="title" ${ro} value="${esc(p?.title)}" placeholder="e.g. NVIDIA: testing weekly support">
    <div class="formgrid">
      <div><label>Date</label><input id="date" type="date" required ${ro} value="${defDate}"></div>
      <div><label>Time (optional)</label><input id="time" type="time" ${ro} value="${esc(p?.time)}"></div>
      <div><label>Platform</label><select id="platform" ${ro}>${platformOptions(pid)}</select></div>
      <div><label>Content type</label><select id="type" ${ro}>${typeOptions(pid, p?.type)}</select></div>
      <div><label>Status</label><select id="status" ${ro}>${Object.entries(S.statuses).map(([k, v]) => `<option value="${k}" ${(p?.status || 'planned') === k ? 'selected' : ''} ${k === 'published' && !can('content.complete') ? 'disabled' : ''}>${esc(v)}</option>`).join('')}</select></div>
      <div><label>Assigned to</label><select id="assignee" ${ro} ${p && !can('content.edit') ? 'disabled' : ''}>${assignOpts}</select></div>
    </div>
    <label>Owner / label (optional)</label><input id="owner" ${ro} value="${esc(p?.owner)}" placeholder="Tyler / Bullseye / Team">
    <label>Published link / working link</label><input id="link" type="url" ${ro} value="${esc(p?.link)}" placeholder="https://">
    <label>Notes</label><textarea id="notes" ${ro} placeholder="Hook, asset, thumbnail idea, next step…">${esc(p?.notes)}</textarea>
    ${p ? `<p class="muted small">Created ${ago(p.created_at)}${p.done && p.done_at ? ` · Published ${ago(p.done_at)}${member(p.done_by) ? ' by ' + esc(member(p.done_by).name) : ''}` : ''}</p>` : ''}
    <div class="actions">
      ${p && can('content.delete') ? '<button type="button" id="del" class="danger">Delete post</button>' : ''}
      ${p && can('content.create') ? '<button type="button" id="dup">Duplicate</button>' : ''}
      <span style="flex:1"></span>
      <button type="button" data-close>${editable ? 'Cancel' : 'Close'}</button>${editable ? '<button class="primary">Save content</button>' : ''}</div></form>
    ${p ? `<div class="section"><h3>Comments</h3><div id="comments">${commentsHTML(info.comments)}</div>
      ${can('comments.add') ? `<form id="cf" class="row" style="margin-top:12px;flex-wrap:nowrap"><input id="cbody" placeholder="Write a comment, feedback or next step…" maxlength="3000"><button class="primary">Post</button></form>` : ''}</div>
      ${info.history.length ? `<div class="section"><h3>History</h3><div class="timeline" style="margin-top:8px">${info.history.map(h => `<div><b>${esc(h.name || 'Deleted user')}</b> · ${esc(h.summary)} · <span title="${esc(h.created_at)}">${ago(h.created_at)}</span></div>`).join('')}</div></div>` : ''}` : ''}`, { cls: 'wide' });
  $('#platform', d).onchange = () => $('#type', d).innerHTML = typeOptions(Number($('#platform', d).value));
  $('#tf', d).onsubmit = async e => {
    e.preventDefault();
    if (!editable) return d.close();
    const body = { id: p?.id, title: $('#title', d).value, date: $('#date', d).value, time: $('#time', d).value, platform_id: Number($('#platform', d).value),
      type: $('#type', d).value, status: $('#status', d).value, assignee_id: $('#assignee', d).value ? Number($('#assignee', d).value) : null,
      owner: $('#owner', d).value, link: $('#link', d).value.trim(), notes: $('#notes', d).value };
    if (p && !can('content.edit')) body.assignee_id = p.assignee_id;
    if (await act('posts.save', body, p ? 'Saved' : 'Post added')) { d.close(); refresh(); }
  };
  $('#del', d) && ($('#del', d).onclick = async () => { const r = await act('posts.delete', { id: p.id }); if (r) { d.close(); toast('Post deleted', { action: doUndo }); refresh(); } });
  $('#dup', d) && ($('#dup', d).onclick = async () => {
    const nd = prompt('Duplicate to which date? (YYYY-MM-DD)', p.date);
    if (nd && await act('posts.duplicate', { id: p.id, date: nd }, 'Duplicated to ' + nd)) { d.close(); refresh(); }
  });
  $('#cf', d) && ($('#cf', d).onsubmit = async e => {
    e.preventDefault();
    const body = $('#cbody', d).value.trim(); if (!body) return;
    if (await act('comments.add', { post_id: p.id, body })) {
      const fresh = await get('posts.get', { id: p.id }); $('#comments', d).innerHTML = commentsHTML(fresh.comments); $('#cbody', d).value = ''; bindComments(d, p.id);
    }
  });
  bindComments(d, p?.id);
}
function commentsHTML(list) {
  return list.length ? list.map(c => `<div class="comment"><div class="meta"><span class="avatar sm" style="--c:${esc(c.color || '#607a86')}">${esc(initials(c.name))}</span><b>${esc(c.name || 'Deleted user')}</b> · ${ago(c.created_at)}
    ${Number(c.user_id) === S.me.id || can('content.edit') ? `<button class="iconbtn" data-delcomment="${c.id}" style="margin-left:auto">Delete</button>` : ''}</div>${esc(c.body).replace(/\n/g, '<br>')}</div>`).join('') : '<p class="muted small">No comments yet.</p>';
}
function bindComments(d, pid) {
  $$('[data-delcomment]', d).forEach(b => b.onclick = async () => {
    if (await act('comments.delete', { id: Number(b.dataset.delcomment) })) { const fresh = await get('posts.get', { id: pid }); $('#comments', d).innerHTML = commentsHTML(fresh.comments); bindComments(d, pid); }
  });
}

/* ---------- 7. my work ---------- */
async function renderMine() {
  const who = S.mineUser && can('reports.view') ? S.mineUser : S.me.id;
  const d = await get('posts.mine', { today, user_id: who });
  if (who === S.me.id) { S.myOverdue = d.overdue.length; renderNav(); }
  const groups = {};
  for (const t of d.upcoming) (groups[t.date] = groups[t.date] || []).push(t);
  $('#view').innerHTML = `
  ${can('reports.view') ? `<div class="row" style="margin-bottom:18px"><span class="muted">Showing work for</span><select id="whosel" style="width:auto">${S.team.filter(u => u.active).map(u => `<option value="${u.id}" ${u.id === who ? 'selected' : ''}>${esc(u.name)}${u.id === S.me.id ? ' (me)' : ''}</option>`).join('')}</select></div>` : ''}
  <div class="cards"><div class="card"><div class="muted">Past due</div><div class="big ${d.overdue.length ? 'overdue' : ''}">${d.overdue.length}</div><div class="statline">Assigned, not yet published</div></div>
    <div class="card"><div class="muted">Due today</div><div class="big">${(groups[today] || []).length}</div><div class="statline">On today’s checklist</div></div>
    <div class="card"><div class="muted">Upcoming</div><div class="big">${d.upcoming.length}</div><div class="statline">Scheduled from today onward</div></div>
    <div class="card"><div class="muted">Recently published</div><div class="big accent">${d.recent_done.length}</div><div class="statline">Last 20 checked off</div></div></div>
  ${d.overdue.length ? `<div class="section card"><h2 class="overdue">Past due</h2><div class="checklist">${d.overdue.map(t => taskHTML(t, { showDate: true })).join('')}</div></div>` : ''}
  <div class="section card"><h2>Upcoming</h2>${Object.keys(groups).length ? Object.entries(groups).map(([date, list]) => `<div class="platformheading" style="--p:var(--teal)">${pretty(date, { weekday: 'long', month: 'long', day: 'numeric' })}${date === today ? ' · TODAY' : ''}</div>${list.map(t => taskHTML(t)).join('')}`).join('') : '<div class="empty">Nothing upcoming. Ask your manager to assign posts, or pick up unassigned slots on the calendar.</div>'}</div>
  ${d.recent_done.length ? `<div class="section card"><h2>Recently published</h2><div class="checklist">${d.recent_done.map(t => taskHTML(t, { showDate: true })).join('')}</div></div>` : ''}`;
  $('#whosel') && ($('#whosel').onchange = e => { S.mineUser = Number(e.target.value); render(); });
  bindTasks($('#view'));
}

/* ---------- 8. reports ---------- */
async function renderReports() {
  const r = await get('reports.get', { month: monthKey(), today });
  const t = r.totals, rate = (a, b) => b ? Math.round(a / b * 100) : 0;
  const days = r.days, [y, mo] = monthKey().split('-').map(Number), first = new Date(y, mo - 1, 1).getDay(), n = new Date(y, mo, 0).getDate();
  const heat = [];
  for (let i = 0; i < first; i++) heat.push('<div style="background:transparent"></div>');
  for (let i = 1; i <= n; i++) {
    const ds = `${monthKey()}-${String(i).padStart(2, '0')}`, v = days[ds];
    let l = 0, cls = '';
    if (v) { const p = v.done / v.total; l = p >= 1 ? 4 : p >= .66 ? 3 : p >= .33 ? 2 : 1; if (ds < today && p < 1) cls = 'miss'; }
    heat.push(`<div data-l="${l}" class="${cls}" title="${pretty(ds)}: ${v ? `${v.done}/${v.total} published` : 'day off'}">${i}${v ? `<br><b>${v.done}/${v.total}</b>` : ''}</div>`);
  }
  $('#view').innerHTML = `
  <div class="row between" style="margin-bottom:18px"><div class="row"><button id="prev">←</button><span class="monthlabel">${period()}</span><button id="next">→</button></div>${can('backup.export') ? '<button id="csv">Export month CSV ↓</button>' : ''}</div>
  <div class="cards">
    <div class="card"><div class="muted">Completion</div><div class="big accent">${rate(t.done, t.total)}%</div><div class="statline">${t.done} of ${t.total} posts published</div>${progress([], rate(t.done, t.total))}</div>
    <div class="card"><div class="muted">On-time rate</div><div class="big">${rate(t.on_time, t.done)}%</div><div class="statline">Checked off on or before the scheduled day</div></div>
    <div class="card"><div class="muted">Past due</div><div class="big ${t.overdue ? 'overdue' : ''}">${t.overdue}</div><div class="statline">Scheduled before today, not published</div></div>
    <div class="card"><div class="muted">Consistency streak</div><div class="big">${r.streak} <span class="muted" style="font-size:18px">days</span></div><div class="statline">Consecutive fully-completed days (days off skipped)</div></div>
  </div>
  <div class="section three">
    <div class="card"><h2>By platform</h2><table class="grid" style="margin-top:12px"><thead><tr><th>Platform</th><th>Published</th><th style="width:40%">Completion</th><th>Past due</th></tr></thead><tbody>
      ${r.platforms.length ? r.platforms.map(x => { const p = plat(x.platform_id); return `<tr><td><span class="tag" style="--p:${esc(p.color)}"><i class="dot"></i><b style="color:var(--text)">${esc(p.name)}</b></span></td><td>${x.done} / ${x.total}</td><td><div class="row" style="flex-wrap:nowrap"><div class="bar" style="flex:1;--p:${esc(p.color)}"><i style="width:${rate(x.done, x.total)}%"></i></div><span class="muted small">${rate(x.done, x.total)}%</span></div></td><td class="${x.overdue ? 'overdue' : 'muted'}">${x.overdue}</td></tr>`; }).join('') : '<tr><td colspan="4" class="muted">No posts this month.</td></tr>'}
    </tbody></table></div>
    <div class="card"><h2>Daily completion</h2><p class="muted small">Brighter = more of the day published. Red = past day left incomplete.</p>
      <div class="heat" style="margin-top:10px">${['S', 'M', 'T', 'W', 'T', 'F', 'S'].map(x => `<div style="background:transparent;text-align:center;aspect-ratio:auto">${x}</div>`).join('')}${heat.join('')}</div></div>
  </div>
  <div class="section two">
    <div class="card"><h2>By team member</h2><table class="grid" style="margin-top:12px"><thead><tr><th>Person</th><th>Assigned</th><th>Published</th><th>On time</th><th>Past due</th></tr></thead><tbody>
      ${r.users.length ? r.users.map(x => { const u = member(x.user_id); return `<tr><td><div class="row" style="flex-wrap:nowrap">${u ? avatar(u, 'sm') : '<span class="avatar sm" style="--c:#2c414c">—</span>'}<span>${u ? esc(u.name) : (x.user_id ? 'Deleted user' : 'Unassigned')}</span></div></td><td>${x.total}</td><td>${x.done} <span class="muted small">(${rate(x.done, x.total)}%)</span></td><td>${rate(x.on_time, x.done)}%</td><td class="${x.overdue ? 'overdue' : 'muted'}">${x.overdue}</td></tr>`; }).join('') : '<tr><td colspan="5" class="muted">No posts this month.</td></tr>'}
    </tbody></table></div>
    <div class="card"><h2>By content type</h2><table class="grid" style="margin-top:12px"><thead><tr><th>Type</th><th>Published</th><th style="width:35%"></th></tr></thead><tbody>
      ${r.types.length ? r.types.map(x => { const p = plat(x.platform_id); return `<tr><td><span class="tag" style="--p:${esc(p.color)}"><i class="dot"></i>${esc(p.short_name || p.name)}</span> ${esc(x.type)}</td><td>${x.done} / ${x.total}</td><td><div class="bar" style="--p:${esc(p.color)}"><i style="width:${rate(x.done, x.total)}%"></i></div></td></tr>`; }).join('') : '<tr><td colspan="3" class="muted">No posts this month.</td></tr>'}
    </tbody></table></div>
  </div>
  ${r.metrics ? `<div class="section card"><div class="row between"><h2>Audience growth</h2><button id="goaud">Audience →</button></div>${growthHTML(r.metrics)}</div>` : ''}`;
  $('#prev').onclick = () => { S.current.setMonth(S.current.getMonth() - 1); render(); };
  $('#next').onclick = () => { S.current.setMonth(S.current.getMonth() + 1); render(); };
  $('#csv') && ($('#csv').onclick = exportCSV);
  $('#goaud') && ($('#goaud').onclick = () => go('audience'));
}

/* ---------- 9. audience ---------- */
function sparkline(points, color, w = 260, h = 64) {
  if (points.length < 2) return `<svg viewBox="0 0 ${w} ${h}" class="chart" style="height:${h}px" aria-hidden="true"><line x1="0" y1="${h - 4}" x2="${w}" y2="${h - 4}" stroke="#22323b" stroke-dasharray="4 4"/></svg>`;
  const vals = points.map(p => p[1]), min = Math.min(...vals), max = Math.max(...vals), span = max - min || 1;
  const xy = points.map((p, i) => [i / (points.length - 1) * (w - 8) + 4, h - 6 - (p[1] - min) / span * (h - 14)]);
  const d = xy.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
  return `<svg viewBox="0 0 ${w} ${h}" class="chart" style="height:${h}px" role="img" aria-label="Trend from ${vals[0]} to ${vals[vals.length - 1]}">
    <path d="${d} L${xy[xy.length - 1][0]} ${h} L${xy[0][0]} ${h} Z" fill="${color}" opacity=".10"/><path d="${d}" fill="none" stroke="${color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
    <circle cx="${xy[xy.length - 1][0]}" cy="${xy[xy.length - 1][1]}" r="3.5" fill="${color}"/></svg>`;
}
function seriesFor(m, pid) { return [...m.history].reverse().filter(h => h.values[pid] != null).map(h => [h.date, Number(h.values[pid])]); }
function growthHTML(m) {
  if (!m.history.length) return '<div class="empty">No audience snapshots yet. Update audience counts to start tracking growth.</div>';
  return `<div class="platforms" style="margin-top:14px">${S.platforms.filter(p => p.active).map(p => {
    const s = seriesFor(m, p.id), first = s[0]?.[1], last = s[s.length - 1]?.[1];
    const change = s.length > 1 ? last - first : null;
    return `<div class="card platform" style="--p:${esc(p.color)};padding:18px"><div class="row between"><h3>${esc(p.name)}</h3><span class="muted small">${s.length} snapshot${s.length === 1 ? '' : 's'}</span></div>
      <div class="big" style="font-size:26px">${metricNumber(last)}</div><div class="muted small">${esc(p.metric_label)}${change !== null ? ` · <span class="${change >= 0 ? 'accent' : 'overdue'}">${change >= 0 ? '+' : ''}${change.toLocaleString()}${first ? ` (${change >= 0 ? '+' : ''}${(change / first * 100).toFixed(1)}%)` : ''}</span> since ${pretty(s[0][0])}` : ''}</div>
      ${sparkline(s, p.color)}</div>`;
  }).join('')}</div>`;
}
const canViewSocials = () => can('metrics.view') || can('socials.manage');

function publishedFeedHTML(items) {
  if (!items.length) return '<p class="muted">Recent posts from linked Instagram, X, and YouTube profiles appear here after syncing.</p>';
  return `<ul class="published-feed">${items.map(item => `<li>
    <div class="published-feed-meta"><span class="eyebrow">${esc(item.label)}</span><span>@${esc(item.handle)}</span>
      <time datetime="${esc(item.published_at)}">${esc(item.published_label)}</time></div>
    <a href="${esc(item.url)}" target="_blank" rel="noopener noreferrer">${esc(item.caption || 'View post')} ↗</a>
  </li>`).join('')}</ul>`;
}

const socialCalendar = { month: '', platform: 'all' };
const socialGlyph = { instagram: 'IG', x: '𝕏', youtube: '▶' };
let todaySocialPlatform = 'all';

function bindTodaySocialTabs(data) {
  const root = $('.published-today');
  const list = $('[role=tablist]', root);
  const panel = $('#today-social-posts', root);
  const platforms = new Map([['all', 'All platforms']]);
  data.accounts.forEach(account => {
    if (Object.hasOwn(socialGlyph, account.platform)) platforms.set(account.platform, account.label);
  });
  if (!platforms.has(todaySocialPlatform)) todaySocialPlatform = 'all';
  list.innerHTML = [...platforms].map(([key, label]) => {
    const total = key === 'all' ? data.today_published.length : data.today_published.filter(item => item.platform === key).length;
    return `<button type="button" role="tab" id="today-social-${key}" data-today-platform="${key}" aria-controls="today-social-posts">
      ${esc(label)} <span>${total}</span></button>`;
  }).join('');
  const tabs = $$('[role=tab]', list);
  const select = key => {
    todaySocialPlatform = key;
    const items = key === 'all' ? data.today_published : data.today_published.filter(item => item.platform === key);
    tabs.forEach(tab => {
      const active = tab.dataset.todayPlatform === key;
      tab.setAttribute('aria-selected', active);
      tab.tabIndex = active ? 0 : -1;
    });
    panel.setAttribute('aria-labelledby', 'today-social-' + key);
    const syncing = data.configured && data.accounts.some(account =>
      platforms.has(account.platform) && (key === 'all' || key === account.platform) &&
      ['pending', 'syncing'].includes(account.status));
    panel.innerHTML = items.length ? publishedCards(items) : `<p class="empty">${syncing
      ? 'Waiting for the social sync to finish.'
      : key === 'all' ? 'No imported posts for this date.' : `No ${esc(platforms.get(key))} posts imported for today.`}</p>`;
    bindPublishedImages(panel);
    $$('[data-published-item]', panel).forEach(button => button.onclick = () => {
      const item = items[Number(button.dataset.publishedItem)];
      openPublished([item], item.label + ' post');
    });
  };
  list.onclick = e => {
    const tab = e.target.closest('[role=tab]');
    if (tab) select(tab.dataset.todayPlatform);
  };
  list.onkeydown = e => {
    const index = tabs.indexOf(e.target);
    if (index < 0) return;
    const next = {ArrowRight: (index + 1) % tabs.length, ArrowLeft: (index + tabs.length - 1) % tabs.length,
      Home: 0, End: tabs.length - 1}[e.key];
    if (next == null) return;
    e.preventDefault();
    tabs[next].focus();
    select(tabs[next].dataset.todayPlatform);
  };
  select(todaySocialPlatform);
}

function publishedVisual(item, compact = false) {
  return `<span class="published-visual ${compact ? 'mini' : ''}" data-platform="${esc(item.platform)}">
    <span aria-hidden="true">${socialGlyph[item.platform] || '●'}</span>
    ${item.preview?.thumbnail ? `<img src="${esc(item.preview.thumbnail)}" loading="lazy" referrerpolicy="no-referrer" alt="">` : ''}
  </span>`;
}

function bindPublishedImages(root) {
  $$('img', root).forEach(img => {
    const hide = () => { img.hidden = true; };
    img.addEventListener('error', hide, {once:true});
    if (img.complete && !img.naturalWidth) hide();
  });
}

function publishedCards(items, detailed = false) {
  if (!items.length) return '<p class="empty">No imported posts for this date.</p>';
  return `<div class="published-cards">${items.map((item, i) => {
    const stats = ['views','likes','comments','replies','reposts'].filter(key => item.preview?.[key] != null);
    return `<article class="published-card">
      ${publishedVisual(item)}
      <div class="published-card-body">
        <span class="eyebrow">${esc(item.label)}</span>
        <div class="published-feed-meta"><span>@${esc(item.handle)}</span><time datetime="${esc(item.published_at)}">${esc(item.published_label)}</time></div>
        <p class="${detailed ? '' : 'published-excerpt'}">${esc(item.caption || 'Published post')}</p>
        ${stats.length ? `<dl class="socialmetrics">${stats.map(key => `<div><dt>${key}</dt><dd>${metricNumber(item.preview[key])}</dd></div>`).join('')}</dl>` : ''}
        ${detailed ? `<a class="published-source" href="${esc(item.url)}" target="_blank" rel="noopener noreferrer">Open original ↗</a>`
          : `<button data-published-item="${i}">View post →</button>`}
      </div>
    </article>`;
  }).join('')}</div>`;
}

function openPublished(items, title) {
  const dialog = openDialog(`<div class="dlghead"><h2>${esc(title)}</h2><button type="button" class="iconbtn" data-close aria-label="Close">✕</button></div>
    ${publishedCards(items, true)}`, {cls:'wide published-dialog'});
  bindPublishedImages(dialog);
}

async function renderPublishedCalendar() {
  const month = socialCalendar.month;
  const d = await get('socials.calendar', month ? {month} : {});
  if (S.page !== 'social-feed' || socialCalendar.month !== month) return;
  const first = new Date(d.month + '-01T12:00:00');
  const year = first.getFullYear(), monthIndex = first.getMonth();
  const days = new Date(year, monthIndex + 1, 0).getDate();
  const grouped = new Map();
  for (const item of d.items) {
    if (socialCalendar.platform !== 'all' && item.platform !== socialCalendar.platform) continue;
    if (!grouped.has(item.workspace_date)) grouped.set(item.workspace_date, []);
    grouped.get(item.workspace_date).push(item);
  }
  const platforms = {all:'All platforms', ...d.platforms};
  const monthLabel = first.toLocaleDateString('en-US', {month:'long', year:'numeric'});
  const cells = Array.from({length:days}, (_, i) => {
    const date = d.month + '-' + String(i + 1).padStart(2, '0');
    const posts = grouped.get(date) || [];
    const label = `${pretty(date, {dateStyle:'full'})}, ${posts.length} post${posts.length === 1 ? '' : 's'}`;
    const flags = `class="published-day ${date === d.today ? 'is-today' : ''}" ${date === d.today ? 'aria-current="date"' : ''}`;
    if (!posts.length) return `<div ${flags}><span class="published-day-number">${i + 1}</span></div>`;
    return `<button ${flags} data-published-day="${date}" aria-label="${esc(label)}">
      <span class="published-day-head"><span class="published-day-number">${i + 1}</span><span class="published-day-count">${posts.length}</span></span>
      ${posts.slice(0, 2).map(item => `<span class="published-cell-post">${publishedVisual(item, true)}<span>${esc(item.caption || item.label)}</span></span>`).join('')}
      ${posts.length > 2 ? `<small>+${posts.length - 2} more</small>` : ''}
    </button>`;
  }).join('');
  const blanks = n => '<span class="published-day blank" aria-hidden="true"></span>'.repeat(n);
  $('#view').innerHTML = `<section class="card published-calendar" ${d.configured ? 'data-social-poll' : ''}>
    <div class="row between published-toolbar"><h2>${esc(monthLabel)}</h2><div class="row">
      <button id="published-prev" aria-label="Previous month">←</button><button id="published-today">Today</button><button id="published-next" aria-label="Next month">→</button>
    </div></div>
    <div class="row published-filters" role="group" aria-label="Filter by platform">
      ${Object.entries(platforms).map(([key,label]) => `<button data-published-platform="${esc(key)}" aria-pressed="${key === socialCalendar.platform}">${esc(label)}</button>`).join('')}
    </div>
    <div class="published-calendar-grid">
      ${DOW.map(day => `<div class="published-weekday">${day}</div>`).join('')}
      ${blanks(first.getDay())}${cells}${blanks((7 - (first.getDay() + days) % 7) % 7)}
    </div>
    ${grouped.size ? '' : '<p class="muted small">No imported posts this month for the selected platform.</p>'}
  </section>`;
  bindPublishedImages($('#view'));
  const changeMonth = offset => {
    first.setMonth(first.getMonth() + offset);
    socialCalendar.month = localDate(first).slice(0, 7);
    refresh();
  };
  $('#published-prev').onclick = () => changeMonth(-1);
  $('#published-next').onclick = () => changeMonth(1);
  $('#published-today').onclick = () => { socialCalendar.month = ''; refresh(); };
  $$('[data-published-platform]').forEach(button => button.onclick = async () => {
    socialCalendar.platform = button.dataset.publishedPlatform;
    await refresh();
    $(`[data-published-platform="${socialCalendar.platform}"]`)?.focus();
  });
  $$('[data-published-day]').forEach(button => button.onclick = () => {
    const date = button.dataset.publishedDay;
    openPublished(grouped.get(date), pretty(date, {dateStyle:'full'}));
  });
}

function socialCards(accounts, manage = false, configured = false) {
  return `<div class="platforms socialcards">${accounts.map(a => {
    const status = !configured && manage ? 'Setup required' :
      ({pending:'Awaiting sync', syncing:'Syncing', ready:a.stale ? 'Update due' : 'Up to date', error:'Sync error'}[a.status] || 'Awaiting sync');
    const secondary = [['Posts / videos', a.posts], ['Likes', a.likes], ['Views', a.views]]
      .filter(([, value]) => value != null);
    return `<article class="card">
      <div class="row between"><span class="eyebrow">${esc(a.label)}</span><a href="${esc(a.url)}" target="_blank" rel="noopener noreferrer">Public profile ↗</a></div>
      <h3>${esc(a.name || '@' + a.handle)}</h3>
      <div class="big">${metricNumber(a.followers)}</div>
      <div class="muted small">${esc(a.metric)}${a.growth == null ? '' : ` · <span class="${a.growth >= 0 ? 'accent' : 'overdue'}">${a.growth >= 0 ? '+' : ''}${metricNumber(a.growth)}</span> over the last 30 days`}</div>
      ${a.history.length >= 2 ? sparkline(a.history.map(day => [day.date, day.followers]), '#04e7d4') : ''}
      ${secondary.length ? `<dl class="socialmetrics">${secondary.map(([label, value]) => `<div><dt>${label}</dt><dd>${metricNumber(value)}</dd></div>`).join('')}</dl>` : ''}
      <p class="muted small">${a.last_synced ? 'Captured ' + esc(a.last_synced) : 'No snapshot yet'} · ${status}</p>
      ${manage && a.error ? `<p class="overdue small">${esc(a.error)}</p>` : ''}
      ${manage ? `<div class="row">
        <button data-social-refresh="${a.id}" ${!configured || !a.can_refresh ? 'disabled' : ''}>Sync now</button>
        <button class="danger" data-social-remove="${a.id}">Unlink</button>
      </div>` : ''}
    </article>`;
  }).join('')}</div>`;
}

async function renderSocials() {
  const d = await get('socials.list');
  if (S.page !== 'socials') return;
  const manage = can('socials.manage');
  const canConfigure = S.me.role.slug === 'superadmin';
  $('#view').innerHTML = `
    <div class="row between"><h2>Connected profiles</h2><div class="row">
      ${!d.configured && canConfigure ? '<button id="connect-apify">Connect Apify</button>' : ''}
      ${manage ? '<button class="primary" id="link-social">Link profile</button>' : ''}
    </div></div>
    ${!d.configured && manage ? `<p class="notice">${canConfigure ? 'Connect your Apify account to load analytics for profiles you have already linked.' : 'Ask the Super Admin to connect Apify to load analytics.'}</p>` : ''}
    <div ${manage && d.configured && d.accounts.length ? 'data-social-poll' : ''}>
      ${d.accounts.length ? socialCards(d.accounts, manage, d.configured) : '<p class="empty">No social profiles linked yet.</p>'}
    </div>
    <section class="section published-today">
      <div class="row between"><div><h2>Today’s posts <span class="weeklabel">${pretty(d.today, {dateStyle:'long'})}</span></h2></div>
        <button id="social-calendar-link">Open published calendar →</button></div>
      <div class="today-social-tabs" role="tablist" aria-label="Today’s posts by platform"></div>
      <div id="today-social-posts" role="tabpanel" tabindex="0"></div>
    </section>`;
  $('#social-calendar-link').onclick = () => go('social-feed');
  bindTodaySocialTabs(d);
  if (!manage) return;

  if ($('#connect-apify')) $('#connect-apify').onclick = () => {
    const dialog = openDialog(`<form><div class="dlghead"><h2>Connect Apify</h2>
      <button type="button" class="iconbtn" data-close aria-label="Close">✕</button></div>
      <label for="apify-token">Apify API token</label>
      <input id="apify-token" name="token" type="password" required maxlength="250" autocomplete="off">
      <div class="actions"><button type="button" data-close>Cancel</button><button class="primary" type="submit">Connect Apify</button></div></form>`);
    const form = $('form', dialog);
    form.onsubmit = async e => {
      e.preventDefault();
      const submit = $('button[type=submit]', form);
      submit.disabled = true;
      if (await act('socials.connect', {token: form.elements.token.value}, 'Apify connected')) {
        form.reset();
        dialog.close();
        refresh();
      } else {
        submit.disabled = false;
      }
    };
  };

  $('#link-social').onclick = () => {
    const dialog = openDialog(`<form><div class="dlghead"><h2>Link a social profile</h2>
      <button type="button" class="iconbtn" data-close aria-label="Close">✕</button></div>
      <label for="social-platform">Platform</label><select id="social-platform" name="platform">${Object.entries(d.platforms).map(([key, label]) => `<option value="${esc(key)}">${esc(label)}</option>`).join('')}</select>
      <label for="social-profile">Public profile URL or handle</label>
      <input id="social-profile" name="profile" required maxlength="1500" placeholder="@handle" autocomplete="off">
      <div class="actions"><button type="button" data-close>Cancel</button><button class="primary" type="submit">Link profile</button></div></form>`);
    const form = $('form', dialog);
    form.onsubmit = async e => {
      e.preventDefault();
      const submit = $('button[type=submit]', form);
      submit.disabled = true;
      if (await act('socials.add', Object.fromEntries(new FormData(form)), 'Profile linked')) {
        dialog.close();
        refresh();
      } else {
        submit.disabled = false;
      }
    };
  };
  $$('[data-social-refresh]').forEach(button => button.onclick = async () => {
    button.disabled = true;
    if (await act('socials.refresh', {id: Number(button.dataset.socialRefresh)})) refresh();
    else button.disabled = false;
  });
  $$('[data-social-remove]').forEach(button => button.onclick = async () => {
    if (!await confirmBox('Unlink profile?', 'The profile will be removed from analytics. Linking it again restores its saved history.', 'Unlink')) return;
    if (await act('socials.remove', {id: Number(button.dataset.socialRemove)}, 'Profile unlinked')) refresh();
  });
}

async function renderAudience() {
  const m = await get('metrics.get');
  S.metrics = m;
  const known = Object.values(m.current).filter(v => v != null);
  const totals = [...m.history].reverse().map(h => [h.date, Object.values(h.values).filter(v => v != null).reduce((a, b) => a + Number(b), 0)]);
  $('#view').innerHTML = `
  <div class="row between" style="margin-bottom:18px"><h2>Current audience <span class="weeklabel">${m.updated ? 'Last updated ' + pretty(m.updated, { month: 'long', day: 'numeric', year: 'numeric' }) : 'Not entered yet'}</span></h2>${can('metrics.update') ? '<button class="primary" id="metrics">Update audience counts</button>' : ''}</div>
  <div class="three"><div class="card"><div class="muted">Combined audience</div><div class="big">${known.length ? known.reduce((a, b) => a + Number(b), 0).toLocaleString() : '—'}</div><div class="statline">${known.length} / ${activePlatforms().length} platforms updated · not unique people</div>${sparkline(totals, '#00d8c6', 600, 90)}</div>
    <div class="card"><div class="eyebrow">How this works</div><p class="muted" style="line-height:1.8;margin-top:10px">Platform accounts aren’t connected. Enter our real totals from each app — weekly works well. Each update is saved as a dated snapshot so we can see growth over time.</p></div></div>
  <div class="section"><h2>Growth by platform</h2>${growthHTML(m)}</div>
  <div class="section card"><h2>Snapshot history</h2><div class="tablewrap"><table class="grid" style="margin-top:12px"><thead><tr><th>Date</th>${S.platforms.map(p => `<th>${esc(p.name)}</th>`).join('')}<th>By</th><th>Note</th>${can('metrics.update') ? '<th></th>' : ''}</tr></thead><tbody>
    ${m.history.length ? m.history.map(h => `<tr><td><b>${pretty(h.date, { month: 'short', day: 'numeric', year: 'numeric' })}</b></td>${S.platforms.map(p => `<td>${metricNumber(h.values[p.id])}</td>`).join('')}<td class="muted">${esc(h.by || '')}</td><td class="muted">${esc(h.note)}</td>${can('metrics.update') ? `<td><button class="iconbtn danger" data-delbatch="${h.id}">Delete</button></td>` : ''}</tr>`).join('') : `<tr><td colspan="${S.platforms.length + 4}" class="muted">No snapshots yet.</td></tr>`}
  </tbody></table></div></div>`;
  $('#metrics') && ($('#metrics').onclick = openMetrics);
  $$('[data-delbatch]').forEach(b => b.onclick = async () => { if (await confirmBox('Delete this snapshot?', 'This removes one dated set of audience counts from the history.', 'Delete') && await act('metrics.delete', { id: Number(b.dataset.delbatch) }, 'Snapshot deleted')) render(); });
}
async function openMetrics() {
  const m = S.metrics && S.page === 'audience' ? S.metrics : await get('metrics.get');
  const d = openDialog(`<form id="mf"><h2>Update audience counts</h2><p class="muted">Enter our actual totals. Leave blank until the numbers are available.</p>
    <div class="formgrid"><div><label>Snapshot date</label><input type="date" id="mdate" value="${today}" max="${today}" required></div><div><label>Note (optional)</label><input id="mnote" placeholder="e.g. After NVDA video went viral"></div></div>
    ${activePlatforms().map(p => `<label>${esc(p.name)} ${esc(p.metric_label)}</label><input data-metric="${p.id}" type="number" min="0" step="1" value="${m.current[p.id] ?? ''}" placeholder="Not entered">`).join('')}
    <div class="actions"><button type="button" data-close>Cancel</button><button class="primary">Save metrics</button></div></form>`);
  $('#mf', d).onsubmit = async e => {
    e.preventDefault();
    const values = {}; $$('[data-metric]', d).forEach(i => values[i.dataset.metric] = i.value === '' ? null : Number(i.value));
    if (await act('metrics.save', { date: $('#mdate', d).value, note: $('#mnote', d).value, values }, 'Audience counts saved')) { d.close(); render(); }
  };
}

/* ---------- 10. team ---------- */
async function renderTeam() {
  const r = await get('users.list');
  const manage = can('users.manage');
  $('#view').innerHTML = `
  <div class="row between" style="margin-bottom:18px"><h2>${r.users.filter(u => u.active).length} active members <span class="weeklabel">${r.users.filter(u => !u.active).length} deactivated</span></h2>${manage ? '<button class="primary" id="adduser">+ Add user</button>' : ''}</div>
  <div class="card"><div class="tablewrap"><table class="grid"><thead><tr><th>Member</th>${manage ? '<th>Email</th>' : ''}<th>Role</th><th>Status</th><th>Workload</th><th>Last sign-in</th>${manage ? '<th></th>' : ''}</tr></thead><tbody>
  ${r.users.map(u => `<tr style="${u.active ? '' : 'opacity:.55'}"><td><div class="row" style="flex-wrap:nowrap">${avatar(u)}<div><b>${esc(u.name)}</b>${u.id === S.me.id ? ' <span class="badge teal">You</span>' : ''}<div class="muted small">${esc(u.title)}</div></div></div></td>
    ${manage ? `<td class="muted">${esc(u.email)}</td>` : ''}<td><span class="badge ${u.role_slug === 'superadmin' ? 'amber' : u.role_slug === 'admin' ? 'teal' : ''}">${esc(u.role_name)}</span></td>
    <td>${u.active ? '<span class="badge teal">Active</span>' : '<span class="badge red">Deactivated</span>'}</td>
    <td><span class="muted small">${u.stats.done} / ${u.stats.total} published</span><div class="bar" style="margin-top:5px"><i style="width:${u.stats.total ? Math.round(u.stats.done / u.stats.total * 100) : 0}%"></i></div></td>
    <td class="muted small">${ago(u.last_login_at)}</td>
    ${manage ? `<td style="white-space:nowrap">${u.id === S.me.id || u.role_level < S.me.role.level || S.me.role.slug === 'superadmin' ? `<button class="iconbtn" data-edituser="${u.id}">Edit</button>` : ''}${u.id !== S.me.id && (u.role_level < S.me.role.level || S.me.role.slug === 'superadmin') ? `<button class="iconbtn" data-toggleuser="${u.id}">${u.active ? 'Deactivate' : 'Reactivate'}</button><button class="iconbtn danger" data-deluser="${u.id}">Remove</button>` : ''}</td>` : ''}</tr>`).join('')}
  </tbody></table></div></div>
  <div class="section card"><div class="row between"><h2>Who can do what</h2>${can('roles.manage') ? '<button id="goroles">Edit roles & permissions →</button>' : ''}</div>
    <div class="list" style="margin-top:10px">${r.roles.map(x => `<div class="li"><span class="badge ${x.slug === 'superadmin' ? 'amber' : x.slug === 'admin' ? 'teal' : ''}">${esc(x.name)}</span><div class="grow muted">${esc(x.description)}</div><span class="muted small">${x.users} user${x.users === 1 ? '' : 's'}</span></div>`).join('')}</div>
    <p class="muted small" style="margin-top:12px">Admins can add, edit and remove anyone in a role below their own. Only a Super Admin can manage other Super Admins or change permissions.</p></div>`;
  $('#adduser') && ($('#adduser').onclick = () => openUser(null, r.roles));
  $('#goroles') && ($('#goroles').onclick = () => go('roles'));
  $$('[data-edituser]').forEach(b => b.onclick = () => openUser(r.users.find(u => u.id === Number(b.dataset.edituser)), r.roles));
  $$('[data-toggleuser]').forEach(b => b.onclick = async () => {
    const u = r.users.find(x => x.id === Number(b.dataset.toggleuser));
    if (u.active && !await confirmBox('Deactivate ' + u.name + '?', 'They’ll be signed out immediately and can’t sign in until reactivated. Their posts and history stay.', 'Deactivate')) return;
    if (await act('users.save', { ...u, active: !u.active, password: '' }, u.active ? 'Deactivated' : 'Reactivated')) { await reloadTeam(); render(); }
  });
  $$('[data-deluser]').forEach(b => b.onclick = async () => {
    const u = r.users.find(x => x.id === Number(b.dataset.deluser));
    if (await confirmBox('Remove ' + u.name + '?', `This permanently deletes their account. Their ${u.stats.total} assigned posts stay on the calendar, unassigned (their name is kept as the owner label). Consider deactivating instead.`, 'Remove user')
      && await act('users.delete', { id: u.id }, 'User removed')) { await reloadTeam(); render(); }
  });
}
async function reloadTeam() { const b = await get('boot'); S.team = b.team; S.platforms = b.platforms; }
function openUser(u, roles) {
  const self = u && u.id === S.me.id;
  const assignable = roles.filter(r => r.assignable || (u && r.id === u.role_id));
  const d = openDialog(`<form id="uf"><h2>${u ? 'Edit ' + esc(u.name) : 'Add a team member'}</h2>
    <div class="formgrid"><div><label>Full name</label><input id="un" required value="${esc(u?.name)}"></div><div><label>Email (used to sign in)</label><input id="ue" type="email" required value="${esc(u?.email)}"></div>
      <div><label>Role</label><select id="ur" ${self ? 'disabled' : ''}>${assignable.map(r => `<option value="${r.id}" ${(u ? u.role_id : roles.find(x => x.slug === 'creator')?.id) === r.id ? 'selected' : ''}>${esc(r.name)}</option>`).join('')}</select></div>
      <div><label>Job title (optional)</label><input id="ut" value="${esc(u?.title)}" placeholder="Video editor"></div>
      <div><label>${u ? 'Reset password (leave blank to keep)' : 'Temporary password (min. 8)'}</label><input id="up" type="text" ${u ? '' : 'required minlength="8"'} autocomplete="new-password" placeholder="${u ? '••••••••' : ''}"></div>
      <div><label>Colour</label><div class="row" style="flex-wrap:nowrap"><input id="uc" type="color" class="swatch" value="${esc(u?.color || randomColor())}"><button type="button" id="genpw" class="iconbtn">Generate password</button></div></div></div>
    <p class="muted small" id="roledesc"></p>
    ${!self ? `<label class="check"><input type="checkbox" id="ua" ${!u || u.active ? 'checked' : ''}> Account active (can sign in)</label>` : ''}
    <div class="notice">Share the email and password with them privately. ${u ? 'Resetting a password or changing the role signs them out of every device.' : 'They can change their password from My profile.'}</div>
    <div class="actions"><button type="button" data-close>Cancel</button><button class="primary">${u ? 'Save changes' : 'Add user'}</button></div></form>`);
  const desc = () => $('#roledesc', d).textContent = roles.find(r => r.id === Number($('#ur', d).value))?.description || '';
  $('#ur', d).onchange = desc; desc();
  $('#genpw', d).onclick = () => { const a = new Uint32Array(3); crypto.getRandomValues(a); $('#up', d).value = 'Ta-' + [...a].map(x => x.toString(36)).join('').slice(0, 11); };
  $('#uf', d).onsubmit = async e => {
    e.preventDefault();
    const body = { id: u?.id, name: $('#un', d).value, email: $('#ue', d).value, role_id: Number($('#ur', d).value), title: $('#ut', d).value, password: $('#up', d).value, color: $('#uc', d).value, active: self ? true : $('#ua', d).checked };
    if (await act('users.save', body, u ? 'Saved' : 'User added')) { d.close(); await reloadTeam(); render(); }
  };
}
function randomColor() { return ['#00d8c6', '#ff6969', '#d58fff', '#77adff', '#ffc46b', '#7ee081', '#ff9ad5'][Math.floor(Math.random() * 7)]; }

/* ---------- 11. roles & permissions ---------- */
async function renderRoles() {
  const r = await get('roles.list');
  const perms = Object.entries(r.catalogue);
  $('#view').innerHTML = `
  <div class="row between" style="margin-bottom:18px"><h2>${r.roles.length} roles</h2><button class="primary" id="addrole">+ New role</button></div>
  <div class="platforms">${r.roles.map(x => `<div class="card"><div class="row between"><h3>${esc(x.name)}</h3><span class="badge">Level ${x.level}</span></div>
    <p class="muted" style="line-height:1.6;min-height:44px">${esc(x.description)}</p>
    <div class="row between"><span class="pill">${x.permissions.length} permissions · ${x.users} user${x.users === 1 ? '' : 's'}</span>
      <div>${x.slug !== 'superadmin' && x.assignable ? `<button class="iconbtn" data-editrole="${x.id}">Edit</button>` : '<span class="muted small">Locked</span>'}${!x.is_system && x.assignable ? `<button class="iconbtn danger" data-delrole="${x.id}">Delete</button>` : ''}</div></div></div>`).join('')}</div>
  <div class="section card"><h2>Permission matrix</h2><div class="tablewrap"><table class="grid matrix" style="margin-top:12px"><thead><tr><th>Permission</th>${r.roles.map(x => `<th>${esc(x.name)}</th>`).join('')}</tr></thead><tbody>
    ${perms.map(([g, list]) => `<tr><td colspan="${r.roles.length + 1}" style="color:var(--teal);font-size:10px;letter-spacing:1px;text-transform:uppercase;font-weight:800;background:#0b151b">${esc(g)}</td></tr>` + Object.entries(list).map(([k, label]) => `<tr><td>${esc(label)}<div class="muted small"><code>${esc(k)}</code></div></td>${r.roles.map(x => `<td class="${x.permissions.includes(k) ? 'yes' : 'no'}">${x.permissions.includes(k) ? '✓' : '·'}</td>`).join('')}</tr>`).join('')).join('')}
  </tbody></table></div><p class="muted small" style="margin-top:12px">Level decides who manages whom: people can only manage users and roles with a lower level than their own. Super Admin always has every permission.</p></div>`;
  $('#addrole').onclick = () => openRole(null, r);
  $$('[data-editrole]').forEach(b => b.onclick = () => openRole(r.roles.find(x => x.id === Number(b.dataset.editrole)), r));
  $$('[data-delrole]').forEach(b => b.onclick = async () => { if (await confirmBox('Delete this role?', 'Only roles with no users can be deleted.', 'Delete role') && await act('roles.delete', { id: Number(b.dataset.delrole) }, 'Role deleted')) render(); });
}
function openRole(role, r) {
  const d = openDialog(`<form id="rf"><h2>${role ? 'Edit ' + esc(role.name) : 'New role'}</h2>
    <div class="formgrid"><div><label>Role name</label><input id="rn" required value="${esc(role?.name)}" placeholder="e.g. Video Editor"></div>
      <div><label>Level (1–${S.me.role.slug === 'superadmin' ? 99 : S.me.role.level - 1})</label><input id="rl" type="number" min="1" max="${S.me.role.slug === 'superadmin' ? 99 : S.me.role.level - 1}" value="${role?.level ?? 30}"></div></div>
    <label>Description</label><input id="rd" value="${esc(role?.description)}" placeholder="What this role is for">
    <div class="row between" style="margin-top:16px"><b>Permissions</b><div><button type="button" class="iconbtn" id="rall">Select all</button><button type="button" class="iconbtn" id="rnone">Clear</button></div></div>
    ${Object.entries(r.catalogue).map(([g, list]) => `<div class="permgroup"><h4>${esc(g)}</h4>${Object.entries(list).map(([k, label]) => `<label class="check"><input type="checkbox" data-perm="${k}" ${role?.permissions.includes(k) ? 'checked' : ''} ${S.me.permissions.includes(k) ? '' : 'disabled'}><span>${esc(label)}</span></label>`).join('')}</div>`).join('')}
    <div class="actions"><button type="button" data-close>Cancel</button><button class="primary">Save role</button></div></form>`, { cls: 'wide' });
  $('#rall', d).onclick = () => $$('[data-perm]:not(:disabled)', d).forEach(i => i.checked = true);
  $('#rnone', d).onclick = () => $$('[data-perm]:not(:disabled)', d).forEach(i => i.checked = false);
  $('#rf', d).onsubmit = async e => {
    e.preventDefault();
    const body = { id: role?.id, name: $('#rn', d).value, level: Number($('#rl', d).value), description: $('#rd', d).value, permissions: $$('[data-perm]:checked', d).map(i => i.dataset.perm) };
    if (await act('roles.save', body, 'Role saved')) { d.close(); render(); }
  };
}

/* ---------- 12. platforms & templates ---------- */
async function renderPlatforms() {
  const tpl = await get('templates.get');
  S.templates = tpl;
  $('#view').innerHTML = `
  <div class="row between" style="margin-bottom:18px"><h2>Platforms</h2><button class="primary" id="addplat">+ Add platform</button></div>
  <div class="card"><div class="tablewrap"><table class="grid"><thead><tr><th>Order</th><th>Platform</th><th>Group on calendar</th><th>Content types</th><th>Channel</th><th>Status</th><th></th></tr></thead><tbody>
    ${S.platforms.map((p, i) => `<tr style="${p.active ? '' : 'opacity:.55'}"><td class="sortbtns"><button data-up="${i}" ${i ? '' : 'disabled'} aria-label="Move up">↑</button> <button data-down="${i}" ${i < S.platforms.length - 1 ? '' : 'disabled'} aria-label="Move down">↓</button></td>
      <td><span class="tag" style="--p:${esc(p.color)}"><i class="dot"></i><b style="color:var(--text)">${esc(p.name)}</b></span><div class="muted small">${esc(p.metric_label)}</div></td>
      <td class="muted">${esc(p.group_name)}</td><td class="muted small">${p.types.map(esc).join(' · ')}</td>
      <td>${p.url ? `<a href="${esc(p.url)}" target="_blank" rel="noopener">Open ↗</a>` : '<span class="muted small">Not set</span>'}</td>
      <td>${p.active ? '<span class="badge teal">Active</span>' : '<span class="badge">Hidden</span>'}</td>
      <td><button class="iconbtn" data-editplat="${p.id}">Edit</button></td></tr>`).join('')}
  </tbody></table></div></div>
  <div class="section two">
    <div class="card"><h2>Daily slot templates</h2><p class="muted small">What “Generate daily slots” offers, and which days each one repeats on (e.g. long form Mon–Fri, Shorts every day). Ticked = selected by default.</p><div id="slots"></div><div class="row" style="margin-top:12px"><button id="addslot">+ Add slot</button></div></div>
    <div class="card"><h2>Monthly checklist template</h2><p class="muted small">What “Add monthly checklist” adds to each month’s list. Give an item a usual day (e.g. live event on the 1st, product update on the 28th) and it lands on the calendar automatically.</p><div id="mtpl"></div><div class="row" style="margin-top:12px"><button id="addmtpl">+ Add item</button></div></div>
  </div>
  <div class="section card"><h2>Day view column order</h2><p class="muted small">Order of the platform groups when you open a day (comma-separated group names).</p>
    <input id="gorder" style="margin-top:10px" value="${esc((S.groupOrder.length ? S.groupOrder : [...new Set(S.platforms.map(p => p.group_name))]).join(', '))}"></div>
  <div class="row" style="margin-top:16px;justify-content:flex-end"><button class="primary" id="savetpl">Save templates</button></div>
  <div class="section card"><h2>Workspace</h2><form id="wsf" class="row" style="margin-top:12px;flex-wrap:nowrap"><input id="wsn" value="${esc(S.workspace.name)}" maxlength="60" style="max-width:320px"><button>Rename workspace</button></form><p class="muted small">Shown in the sidebar brand and page titles (e.g. “TA Toolkit” → TA / TOOLKIT).</p></div>`;
  const slotRow = s => `<div class="tplrow" data-row><div class="typeline" style="margin:0"><select data-sp>${platformOptions(s.platform_id, true)}</select><select data-st>${typeOptions(s.platform_id, s.type)}</select><label class="check" style="margin:0" title="Selected by default"><input type="checkbox" data-sa ${s.active ? 'checked' : ''}></label><button type="button" class="iconbtn danger" data-rm>✕</button></div>
    <div class="daypills">${DOW.map((n, i) => `<label><input type="checkbox" data-dow="${i}" ${(s.days || [0, 1, 2, 3, 4, 5, 6]).includes(i) ? 'checked' : ''}><span>${n[0]}</span></label>`).join('')}<span class="muted small" data-dl>${daysLabel(s.days || [0, 1, 2, 3, 4, 5, 6])}</span></div></div>`;
  const mRow = s => `<div class="tplrow" data-row><div class="typeline" style="margin:0"><select data-sp>${platformOptions(s.platform_id, true)}</select><select data-st>${typeOptions(s.platform_id, s.type)}</select><input data-sti value="${esc(s.title)}" placeholder="Title"><button type="button" class="iconbtn danger" data-rm>✕</button></div>
    <div class="daypills"><span class="muted small">Usual day of the month</span><input data-dom type="number" min="1" max="31" value="${s.day_of_month ?? ''}" placeholder="—" style="width:70px;padding:6px 8px"><span class="muted small">${s.day_of_month ? 'auto-scheduled when added' : 'pick a date each month'}</span></div></div>`;
  const paint = () => {
    $('#slots').innerHTML = `<div class="typesedit">${tpl.slots.map(slotRow).join('')}</div>`;
    $('#mtpl').innerHTML = `<div class="typesedit">${tpl.monthly.map(mRow).join('')}</div>`;
    $$('[data-row]').forEach(row => {
      $('[data-sp]', row).onchange = () => $('[data-st]', row).innerHTML = typeOptions(Number($('[data-sp]', row).value));
      $('[data-rm]', row).onclick = () => row.remove();
      $$('[data-dow]', row).forEach(c => c.onchange = () => $('[data-dl]', row).textContent = daysLabel($$('[data-dow]:checked', row).map(x => Number(x.dataset.dow))));
    });
  };
  paint();
  const collect = () => {
    tpl.slots = $$('#slots [data-row]').map(r => ({ platform_id: Number($('[data-sp]', r).value), type: $('[data-st]', r).value, active: $('[data-sa]', r).checked, days: $$('[data-dow]:checked', r).map(x => Number(x.dataset.dow)) }));
    tpl.monthly = $$('#mtpl [data-row]').map(r => ({ platform_id: Number($('[data-sp]', r).value), type: $('[data-st]', r).value, title: $('[data-sti]', r).value, day_of_month: $('[data-dom]', r).value ? Number($('[data-dom]', r).value) : null }));
    tpl.group_order = $('#gorder').value.split(',').map(x => x.trim()).filter(Boolean);
  };
  const first = activePlatforms()[0];
  $('#addslot').onclick = () => { collect(); tpl.slots.push({ platform_id: first.id, type: first.types[0], active: true, days: [0, 1, 2, 3, 4, 5, 6] }); paint(); };
  $('#addmtpl').onclick = () => { collect(); tpl.monthly.push({ platform_id: first.id, type: first.types[0], title: '' }); paint(); };
  $('#savetpl').onclick = async () => { collect(); if (tpl.slots.some(x => !x.days.length)) return toast('Each daily slot needs at least one day', { error: true }); if (await act('templates.save', tpl, 'Templates saved')) { S.groupOrder = tpl.group_order; render(); } };
  $('#addplat').onclick = () => openPlatform(null);
  $$('[data-editplat]').forEach(b => b.onclick = () => openPlatform(plat(b.dataset.editplat)));
  const reorder = async (i, j) => { const ids = S.platforms.map(p => p.id); [ids[i], ids[j]] = [ids[j], ids[i]]; const r = await act('platforms.reorder', { ids }); if (r) { S.platforms = r.platforms; render(); } };
  $$('[data-up]').forEach(b => b.onclick = () => reorder(Number(b.dataset.up), Number(b.dataset.up) - 1));
  $$('[data-down]').forEach(b => b.onclick = () => reorder(Number(b.dataset.down), Number(b.dataset.down) + 1));
  $('#wsf').onsubmit = async e => { e.preventDefault(); if (await act('settings.save', { workspace_name: $('#wsn').value }, 'Workspace renamed — reloading')) setTimeout(() => location.reload(), 600); };
}
function openPlatform(p) {
  const original = p ? [...p.types] : [];
  const d = openDialog(`<form id="plf"><h2>${p ? 'Edit ' + esc(p.name) : 'Add a platform'}</h2>
    <div class="formgrid"><div><label>Name</label><input id="pn" required value="${esc(p?.name)}" placeholder="LinkedIn"></div>
      <div><label>Colour</label><input id="pc" type="color" class="swatch" value="${esc(p?.color || '#00d8c6')}"></div>
      <div><label>Calendar group</label><input id="pg" value="${esc(p?.group_name)}" placeholder="Same as name (e.g. IG / Facebook)"></div>
      <div><label>Short label</label><input id="ps" value="${esc(p?.short_name)}" maxlength="10" placeholder="LI"></div>
      <div><label>Audience label</label><input id="pm" value="${esc(p?.metric_label || 'followers')}" placeholder="followers / subscribers"></div>
      <div><label>Channel link</label><input id="pu" type="url" value="${esc(p?.url)}" placeholder="https://"></div></div>
    <label>Description</label><input id="pd" value="${esc(p?.description)}" placeholder="What we post here">
    <label>Content types (one per line — renaming a line updates existing posts)</label>
    <div class="typesedit" id="types">${(p?.types || ['']).map((t, i) => `<div class="typeline"><input data-type data-orig="${esc(original[i] ?? '')}" value="${esc(t)}"><button type="button" class="iconbtn danger" data-rmtype>✕</button></div>`).join('')}</div>
    <button type="button" id="addtype" class="iconbtn" style="margin-top:8px">+ Add content type</button>
    <label class="check"><input type="checkbox" id="pa" ${!p || p.active ? 'checked' : ''}> Active (shown on calendar, overview and forms)</label>
    <div class="actions">${p ? '<button type="button" id="pdel" class="danger">Delete</button><span style="flex:1"></span>' : ''}<button type="button" data-close>Cancel</button><button class="primary">Save platform</button></div></form>`, { cls: 'wide' });
  const bindRm = () => $$('[data-rmtype]', d).forEach(b => b.onclick = () => b.parentElement.remove());
  bindRm();
  $('#addtype', d).onclick = () => { $('#types', d).insertAdjacentHTML('beforeend', '<div class="typeline"><input data-type data-orig="" value=""><button type="button" class="iconbtn danger" data-rmtype>✕</button></div>'); bindRm(); };
  $('#pdel', d) && ($('#pdel', d).onclick = async () => {
    if (!await confirmBox('Delete ' + p.name + '?', 'Only platforms with no posts can be deleted. To keep history, untick “Active” instead.', 'Delete')) return;
    const r = await act('platforms.delete', { id: p.id }, 'Platform deleted'); if (r) { S.platforms = r.platforms; d.close(); render(); }
  });
  $('#plf', d).onsubmit = async e => {
    e.preventDefault();
    const inputs = $$('[data-type]', d), renames = {};
    inputs.forEach(i => { if (i.dataset.orig && i.value.trim() && i.dataset.orig !== i.value.trim()) renames[i.dataset.orig] = i.value.trim(); });
    const body = { id: p?.id, name: $('#pn', d).value, color: $('#pc', d).value, group_name: $('#pg', d).value, short_name: $('#ps', d).value, metric_label: $('#pm', d).value,
      url: $('#pu', d).value.trim(), description: $('#pd', d).value, types: inputs.map(i => i.value), active: $('#pa', d).checked, renames };
    const r = await act('platforms.save', body, 'Platform saved'); if (r) { S.platforms = r.platforms; d.close(); render(); }
  };
}

/* ---------- 13. activity ---------- */
async function renderActivity(append = false) {
  if (!append) S.actPage = 0;
  const f = S.actFilter || (S.actFilter = { user_id: '', kind: '', q: '' });
  const r = await get('activity.list', { page: S.actPage, ...f });
  const kinds = { '': 'All activity', post: 'Posts', day: 'Days', month: 'Generated months', monthly: 'Monthly list', comment: 'Comments', metrics: 'Audience', user: 'Users', role: 'Roles', platform: 'Platforms', auth: 'Sign-ins', backup: 'Backups' };
  if (!append) {
    $('#view').innerHTML = `<div class="filters" style="margin-bottom:16px"><select id="af-u"><option value="">Everyone</option>${S.team.map(u => `<option value="${u.id}" ${String(f.user_id) === String(u.id) ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select>
      <select id="af-k">${Object.entries(kinds).map(([k, v]) => `<option value="${k}" ${f.kind === k ? 'selected' : ''}>${v}</option>`).join('')}</select>
      <input id="af-q" type="search" placeholder="Search the log…" value="${esc(f.q)}" style="max-width:260px"></div>
      <div class="card"><div class="list" id="actlist"></div><div id="actmore"></div></div>`;
    $('#af-u').onchange = e => { f.user_id = e.target.value; renderActivity(); };
    $('#af-k').onchange = e => { f.kind = e.target.value; renderActivity(); };
    let t; $('#af-q').oninput = e => { clearTimeout(t); t = setTimeout(() => { f.q = e.target.value; renderActivity().then(() => { const q = $('#af-q'); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }); }, 350); };
  }
  $('#actlist').insertAdjacentHTML('beforeend', r.rows.length ? r.rows.map(a => activityLine(a).replace('<span class="muted small"', `<span class="muted small">${esc(a.created_at.slice(0, 16))}</span><span class="muted small" style="min-width:60px;text-align:right"`)).join('') : (append ? '' : '<div class="empty">No activity matches.</div>'));
  $('#actmore').innerHTML = r.more ? '<div class="row" style="justify-content:center;margin-top:14px"><button id="loadmore">Load more</button></div>' : '';
  $('#loadmore') && ($('#loadmore').onclick = () => { S.actPage++; renderActivity(true); });
}

/* ---------- 14. backup & import ---------- */
async function renderData() {
  $('#view').innerHTML = `<div class="two">
    ${can('backup.export') ? `<div class="card"><div class="eyebrow">Export</div><h2 style="margin-top:10px">Download a full backup</h2><p class="muted" style="line-height:1.8">Every post, monthly list, audience snapshot, comment and template as one JSON file. Keep one before big changes.</p>
      <div class="row"><button class="primary" id="exp">Export backup ↓</button><button id="csv">This month as CSV ↓</button></div></div>` : ''}
    ${can('backup.import') ? `<div class="card"><div class="eyebrow">Import</div><h2 style="margin-top:10px">Restore or bring in data</h2><p class="muted" style="line-height:1.8">Accepts backups from this app <b>and</b> the original browser-only dashboard (“toolkit-social-backup-….json”). Owners are matched to team members by name.</p>
      <label>Import mode</label><select id="imode"><option value="merge">Merge — add new posts, skip duplicates</option><option value="replace">Replace — wipe posts, monthly lists & audience history first</option></select>
      <div class="row" style="margin-top:14px"><button class="primary" id="imp">Choose backup file ↑</button><input type="file" id="ifile" accept="application/json,.json" hidden></div></div>` : ''}
  </div>
  <div class="notice">Users, passwords, roles and permissions are never included in backups. For a full server-level backup, use Hostinger’s daily backups or export the database from phpMyAdmin.</div>`;
  $('#exp') && ($('#exp').onclick = async () => {
    try { const r = await get('backup.export'); download(new Blob([JSON.stringify(r.backup, null, 2)], { type: 'application/json' }), 'toolkit-social-backup-' + today + '.json'); toast('Backup downloaded'); }
    catch (e) { toast(e.message, { error: true }); }
  });
  $('#csv') && ($('#csv').onclick = exportCSV);
  $('#imp') && ($('#imp').onclick = () => $('#ifile').click());
  $('#ifile') && ($('#ifile').onchange = async e => {
    const file = e.target.files[0]; e.target.value = ''; if (!file) return;
    let data; try { data = JSON.parse(await file.text()); } catch { return toast('That file isn’t valid JSON.', { error: true }); }
    const mode = $('#imode').value;
    const n = (data.tasks || data.posts || []).length;
    if (!await confirmBox(mode === 'replace' ? 'Replace workspace data?' : 'Import this backup?', mode === 'replace' ? `This deletes <b>all current posts, monthly lists and audience history</b> and replaces them with the ${n} posts in this file. Export a backup first if needed.` : `Add up to ${n} posts from this file. Posts already on the calendar (same date, platform, type and title) are skipped.`, mode === 'replace' ? 'Replace everything' : 'Import')) return;
    const r = await act('backup.import', { data, mode });
    if (r) { toast(`Imported ${r.posts} posts, ${r.monthly} monthly items, ${r.snapshots} audience snapshots${r.skipped ? ` · ${r.skipped} skipped` : ''}`); await reloadTeam(); }
  });
}

/* ---------- 15. profile ---------- */
async function renderProfile() {
  const m = S.me;
  $('#view').innerHTML = `<div class="two"><div class="card"><form id="pf"><h2>Your details</h2>
    <div class="formgrid"><div><label>Name</label><input id="pn" required value="${esc(m.name)}"></div><div><label>Job title</label><input id="pt" value="${esc(m.title)}"></div>
      <div><label>Email</label><input id="pe" type="email" required value="${esc(m.email)}"></div><div><label>Colour</label><input id="pc" type="color" class="swatch" value="${esc(m.color)}"></div></div>
    <h3 style="margin-top:24px">Change password</h3>
    <div class="formgrid"><div><label>New password</label><input id="pnew" type="password" minlength="8" autocomplete="new-password" placeholder="Leave blank to keep"></div><div><label>Confirm new password</label><input id="pnew2" type="password" autocomplete="new-password"></div></div>
    <label>Current password (needed to change email or password)</label><input id="pcur" type="password" autocomplete="current-password">
    <div class="actions"><button class="primary">Save profile</button></div></form></div>
    <div class="card"><h2>Your access</h2><p class="muted">Role: <span class="badge ${m.role.slug === 'superadmin' ? 'amber' : 'teal'}">${esc(m.role.name)}</span></p>
      <div class="list">${Object.entries(PERMISSIONS_LABELS()).map(([k, l]) => `<div class="li"><span class="${m.permissions.includes(k) ? 'accent' : 'muted'}">${m.permissions.includes(k) ? '✓' : '·'}</span><span class="grow ${m.permissions.includes(k) ? '' : 'muted'}">${esc(l)}</span></div>`).join('')}</div>
      <p class="muted small" style="margin-top:12px">Keyboard: <span class="kbd">/</span> search · <span class="kbd">N</span> new post · <span class="kbd">Esc</span> close</p></div></div>`;
  $('#pf').onsubmit = async e => {
    e.preventDefault();
    if ($('#pnew').value && $('#pnew').value !== $('#pnew2').value) return toast('New passwords don’t match', { error: true });
    const body = { name: $('#pn').value, title: $('#pt').value, email: $('#pe').value, color: $('#pc').value, new_password: $('#pnew').value, current_password: $('#pcur').value };
    if (await act('profile.save', body, 'Profile saved')) { const b = await get('boot'); S.me = b.me; S.team = b.team; render(); }
  };
}
function PERMISSIONS_LABELS() {
  return { 'dashboard.view': 'View the overview', 'content.view': 'View the calendar', 'content.create': 'Add posts', 'content.edit_own': 'Edit own / assigned posts', 'content.edit': 'Edit any post', 'content.complete': 'Check posts off', 'content.delete': 'Delete posts & clear days', 'content.generate': 'Generate monthly slots', 'monthly.manage': 'Manage monthly list', 'comments.add': 'Comment', 'metrics.view': 'View audience', 'metrics.update': 'Update audience', 'reports.view': 'View reports', 'users.manage': 'Manage users', 'roles.manage': 'Manage roles', 'platforms.manage': 'Manage platforms', 'activity.view': 'View activity log', 'backup.export': 'Export backups', 'backup.import': 'Import backups' };
}

/* ---------- 16. search + boot ---------- */
function bindSearch() {
  const box = $('#globalsearch');
  if (!can('content.view')) { box.classList.add('hidden'); return; }
  let t, panel;
  const close = () => { panel && panel.remove(); panel = null; };
  box.oninput = () => {
    clearTimeout(t);
    t = setTimeout(async () => {
      const q = box.value.trim();
      if (q.length < 2) return close();
      const r = await get('posts.search', { q }).catch(() => ({ posts: [] }));
      if (!panel) { panel = document.createElement('div'); panel.className = 'searchresults'; box.parentElement.appendChild(panel); }
      panel.innerHTML = r.posts.length ? r.posts.map(p => `<div class="sr-item" data-sr="${p.id}"><b>${esc(p.title || p.type)}</b><div class="taskmeta">${pretty(p.date, { month: 'short', day: 'numeric', year: 'numeric' })} · ${esc(plat(p.platform_id).name)} · ${esc(p.type)} ${statusChip(p.status)}</div></div>`).join('') : '<p class="muted">No posts match.</p>';
      $$('[data-sr]', panel).forEach(el => el.onclick = () => { close(); box.value = ''; openPost(Number(el.dataset.sr)); });
    }, 250);
  };
  box.onkeydown = e => { if (e.key === 'Escape') { close(); box.blur(); } };
  document.addEventListener('click', e => { if (panel && !panel.contains(e.target) && e.target !== box) close(); });
}

document.addEventListener('keydown', e => {
  const typing = /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) || document.querySelector('dialog[open]');
  if (typing) return;
  if (e.key === '/') { e.preventDefault(); $('#globalsearch').focus(); }
  if ((e.key === 'n' || e.key === 'N') && can('content.create')) { e.preventDefault(); openPost(null, S.expanded); }
});

// Keep everyone in sync: refresh the current view every 60s when the tab is visible and idle
setInterval(() => {
  const newToday = localDate(new Date());
  if (newToday !== today) today = newToday;
  if (document.visibilityState === 'visible' && !document.querySelector('dialog[open]') && !S.busy && !$('.searchresults')
    && ['overview', 'tracking', 'mine', 'socials', 'social-feed'].includes(S.page) && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
    const y = window.scrollY; render().then(() => window.scrollTo(0, y));
  }
}, 60000);

// Let open admin analytics screens start and collect due jobs without cron.
setInterval(async () => {
  const page = S.page;
  if (!['socials', 'social-feed', 'overview'].includes(page) || !can('socials.manage') || S.busy ||
      document.visibilityState !== 'visible' || $('dialog[open]') ||
      !$('[data-social-poll]')) return;
  try {
    const result = await post('socials.poll');
    if (!result.processed || S.page !== page || $('dialog[open]')) return;
    if (page !== 'overview') {
      await render();
    } else {
      const data = await get('socials.list');
      const cards = $('#dashboard-social-cards');
      if (S.page === page && cards) {
        if (data.accounts.length) cards.innerHTML = socialCards(data.accounts);
        $('#dashboard-published-feed').innerHTML = publishedFeedHTML(data.published_feed.slice(0, 6));
      }
    }
  } catch (e) {
    // The next poll or cron pass can retry.
  }
}, 15000);

(async function boot() {
  try {
    const b = await get('boot');
    if (b.server_now) CLOCK_SKEW = Date.now() - new Date(b.server_now.replace(' ', 'T')).getTime();
    S.groupOrder = b.group_order || [];
    Object.assign(S, { me: b.me, platforms: b.platforms, team: b.team, statuses: b.statuses, undo: b.undo, workspace: b.workspace });
  } catch (e) {
    $('#view').innerHTML = `<div class="notice warn">Couldn't start the app: ${esc(e.message)}</div>`;
    return;
  }
  $('#add').onclick = () => openPost(null, S.page === 'tracking' ? S.expanded : null);
  $('#menutoggle').onclick = () => $('#sidebar').classList.toggle('open');
  bindSearch();
  go(location.hash.replace('#/', '') || 'overview', false);
})();
