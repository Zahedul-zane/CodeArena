// ══════════════════════════════════════════════════════
//  CodeArena — Frontend Application (app.js)
// ══════════════════════════════════════════════════════
const API = '/api';
let currentUser = null;
let currentProblemSlug = null;
let pollInterval = null;
let allProblems = [];
let diffFilter = '';

const TOKEN_KEY = 'ca_token';
const getToken  = () => localStorage.getItem(TOKEN_KEY);
const setToken  = t  => localStorage.setItem(TOKEN_KEY, t);
const removeToken = () => localStorage.removeItem(TOKEN_KEY);

const defaultCode = {
  cpp:`#include <bits/stdc++.h>\nusing namespace std;\n\nint main() {\n    ios_base::sync_with_stdio(false);\n    cin.tie(NULL);\n    \n    // Your solution here\n    \n    return 0;\n}`,
  python:`import sys\ninput = sys.stdin.readline\n\ndef solve():\n    # Your solution here\n    pass\n\nsolve()`,
  java:`import java.util.*;\nimport java.io.*;\n\npublic class Main {\n    public static void main(String[] args) throws IOException {\n        BufferedReader br = new BufferedReader(new InputStreamReader(System.in));\n        // Your solution here\n    }\n}`,
  go:`package main\n\nimport (\n    "bufio"\n    "fmt"\n    "os"\n)\n\nvar in  = bufio.NewReader(os.Stdin)\nvar out = bufio.NewWriter(os.Stdout)\n\nfunc main() {\n    defer out.Flush()\n    // Your solution here\n    _ = fmt.Sprint("")\n}`,
  rust:`use std::io::{self, BufRead, Write};\n\nfn main() {\n    let stdin  = io::stdin();\n    let stdout = io::stdout();\n    let mut out = io::BufWriter::new(stdout.lock());\n    \n    for line in stdin.lock().lines() {\n        let _line = line.unwrap();\n        // Your solution here\n        let _ = writeln!(out, "");\n    }\n}`
};

// ── API helper ────────────────────────────────────────
async function apiFetch(path, opts = {}) {
  const token = getToken();
  const headers = { 'Content-Type': 'application/json', ...(opts.headers || {}) };
  if (token) headers['Authorization'] = `Bearer ${token}`;
  try {
    const res  = await fetch(API + path, { ...opts, headers });
    const data = await res.json();
    return { ok: res.ok, status: res.status, data };
  } catch(e) {
    return { ok: false, status: 0, data: { error: 'Network error' } };
  }
}

// ── Toast ─────────────────────────────────────────────
function toast(msg, type = 'info', duration = 3500) {
  const c = document.getElementById('toast-container');
  const icons = { success: 'fa-circle-check', error: 'fa-circle-exclamation', info: 'fa-circle-info' };
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}"></i><span>${msg}</span>`;
  c.appendChild(t);
  setTimeout(() => {
    t.style.animation = 'slideOut 0.3s ease forwards';
    setTimeout(() => t.remove(), 300);
  }, duration);
}

// ── Modal ─────────────────────────────────────────────
function openLoginModal()    { document.getElementById('login-modal').classList.add('active'); setTimeout(() => document.getElementById('login-username').focus(), 50); }
function openRegisterModal() { document.getElementById('register-modal').classList.add('active'); setTimeout(() => document.getElementById('reg-username').focus(), 50); }
function closeModal(id)      { document.getElementById(id).classList.remove('active'); }
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); }));

// ── Auth ──────────────────────────────────────────────
async function doLogin() {
  const btn = document.getElementById('login-btn');
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner" style="width:16px;height:16px;border-width:2px;"></div>';
  const r = await apiFetch('/auth/login', { method: 'POST', body: JSON.stringify({
    username: document.getElementById('login-username').value,
    password: document.getElementById('login-password').value
  })});
  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> Login';
  if (r.ok) {
    setToken(r.data.token);
    currentUser = r.data.user;
    closeModal('login-modal');
    updateNavAuth();
    toast(`Welcome back, ${currentUser.username}! 🎉`, 'success');
  } else {
    toast(r.data.error || 'Login failed', 'error');
  }
}

async function doRegister() {
  const username = document.getElementById('reg-username').value.trim();
  const email    = document.getElementById('reg-email').value.trim();
  const password = document.getElementById('reg-password').value;
  if (!username || !email || !password) { toast('All fields required', 'error'); return; }
  if (password.length < 6) { toast('Password must be at least 6 characters', 'error'); return; }
  const r = await apiFetch('/auth/register', { method: 'POST', body: JSON.stringify({ username, email, password }) });
  if (r.ok) { closeModal('register-modal'); toast('Account created! Please login.', 'success'); openLoginModal(); }
  else toast(r.data.error || 'Registration failed', 'error');
}

async function doLogout() {
  await apiFetch('/auth/logout', { method: 'POST' });
  removeToken(); currentUser = null; updateNavAuth();
  navigate('home'); toast('Logged out', 'info');
}

function updateNavAuth() {
  const area = document.getElementById('nav-auth-area');
  if (currentUser) {
    const initials = currentUser.username.slice(0, 2).toUpperCase();
    area.innerHTML = `
      <button class="btn btn-ghost btn-sm" onclick="navigate('profile')">
        <div class="user-avatar" style="background:${currentUser.avatar_color};">${initials}</div>
        <span style="font-weight:600;">${currentUser.username}</span>
        <span class="badge badge-easy" style="font-size:11px;">${currentUser.rating}</span>
      </button>
      <button class="btn btn-outline btn-sm" onclick="doLogout()" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></button>`;
  } else {
    area.innerHTML = `
      <button class="btn btn-outline btn-sm" onclick="openLoginModal()">Login</button>
      <button class="btn btn-primary btn-sm" onclick="openRegisterModal()">Register</button>`;
  }
}

async function loadCurrentUser() {
  if (!getToken()) return;
  const r = await apiFetch('/auth/me');
  if (r.ok) { currentUser = r.data; updateNavAuth(); }
  else removeToken();
}

// ── Navigation ────────────────────────────────────────
function navigate(page, slug = '') {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
  const navBtn = document.getElementById('nav-' + page);
  if (navBtn) navBtn.classList.add('active');
  const pageEl = document.getElementById('page-' + page);
  if (pageEl) pageEl.classList.add('active');
  if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
  switch (page) {
    case 'home':            loadHome(); break;
    case 'problems':        loadProblems(); break;
    case 'editor':          loadEditor(slug); break;
    case 'contests':        loadContests(); break;
    case 'contest-detail':  loadContestDetail(slug); break;
    case 'leaderboard':     loadLeaderboard(); break;
    case 'submissions':     loadSubmissions(); break;
    case 'profile':         loadProfile(); break;
  }
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Home ──────────────────────────────────────────────
async function loadHome() {
  const sr = await apiFetch('/stats');
  if (sr.ok) {
    document.getElementById('stat-users').textContent       = sr.data.users;
    document.getElementById('stat-problems').textContent    = sr.data.problems;
    document.getElementById('stat-submissions').textContent = sr.data.submissions;
    document.getElementById('stat-contests').textContent    = sr.data.contests;
  }
  const [cr, pr] = await Promise.all([apiFetch('/contests'), apiFetch('/problems')]);
  if (cr.ok) document.getElementById('home-contests').innerHTML = cr.data.slice(0, 4).map(contestCard).join('');
  if (pr.ok) { allProblems = pr.data; document.getElementById('home-problems-list').innerHTML = pr.data.slice(0, 5).map(problemRow).join(''); }
}

// ── Problems ──────────────────────────────────────────
async function loadProblems() {
  document.getElementById('problems-list').innerHTML = '<div class="loading"><div class="spinner"></div>Loading...</div>';
  const r = await apiFetch('/problems');
  if (r.ok) { allProblems = r.data; renderProblems(); }
  else document.getElementById('problems-list').innerHTML = '<div class="loading">Failed to load problems.</div>';
}

function renderProblems() {
  const q    = (document.getElementById('problem-search')?.value || '').toLowerCase();
  let list   = allProblems;
  if (diffFilter) list = list.filter(p => p.difficulty === diffFilter);
  if (q) list = list.filter(p => p.title.toLowerCase().includes(q) || (p.tags || []).join(',').includes(q));
  const el = document.getElementById('problems-list');
  el.innerHTML = list.length ? list.map(problemRow).join('') : '<div class="loading">No problems found.</div>';
}

function setDiffFilter(d) {
  diffFilter = d;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('filter-' + (d || 'all')).classList.add('active');
  renderProblems();
}
function filterProblems() { renderProblems(); }

function problemRow(p, idx) {
  const i = typeof idx === 'number' ? idx : (allProblems.indexOf(p) + 1);
  const dc = { Easy: 'easy', Medium: 'medium', Hard: 'hard' }[p.difficulty] || 'easy';
  const tags = (p.tags || []).filter(Boolean).map(t => `<span class="badge badge-tag">${t}</span>`).join('');
  return `<div class="problem-row" onclick="navigate('editor','${p.slug}')">
    <div class="problem-num">#${i}</div>
    <div class="problem-title-cell">
      <div>${p.title}</div>
      <div class="problem-tags">${tags}</div>
    </div>
    <div style="display:flex;gap:12px;align-items:center;margin-left:auto;flex-shrink:0;">
      <span style="font-size:12px;color:var(--text3);font-family:var(--mono);">${p.time_limit_ms}ms</span>
      <span class="badge badge-${dc}">${p.difficulty}</span>
    </div>
  </div>`;
}

// ── Editor ────────────────────────────────────────────
async function loadEditor(slug) {
  currentProblemSlug = slug;
  const editor = document.getElementById('code-editor');
  editor.value = defaultCode[document.getElementById('lang-select').value] || '';
  document.getElementById('editor-problem-title').textContent = 'Loading...';
  document.getElementById('code-result').style.display = 'none';
  document.getElementById('code-status').textContent = 'Ready';

  const r = await apiFetch('/problems/' + slug);
  if (!r.ok) { toast('Problem not found', 'error'); navigate('problems'); return; }
  const p = r.data;
  const dc = { Easy: 'easy', Medium: 'medium', Hard: 'hard' }[p.difficulty] || 'easy';
  document.getElementById('editor-problem-title').textContent = p.title;
  document.getElementById('editor-problem-badge').innerHTML   = `<span class="badge badge-${dc}">${p.difficulty}</span>`;
  document.getElementById('prob-meta').innerHTML = `
    <span class="badge badge-${dc}">${p.difficulty}</span>
    <span class="prob-meta-item"><i class="fa-solid fa-clock"></i> ${p.time_limit_ms}ms</span>
    <span class="prob-meta-item"><i class="fa-solid fa-memory"></i> ${p.mem_limit_mb}MB</span>`;
  document.getElementById('prob-detail-statement').textContent    = p.statement;
  document.getElementById('prob-detail-input').textContent        = p.input_format;
  document.getElementById('prob-detail-output').textContent       = p.output_format;
  document.getElementById('prob-detail-constraints').textContent  = p.constraints;
  document.getElementById('prob-detail-examples').innerHTML = `
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:12px;">
      <div class="prob-section-label" style="margin-bottom:8px;">Sample Input</div>
      <pre style="font-family:var(--mono);font-size:13px;color:var(--cyan);white-space:pre-wrap;">${escHtml(String(p.sample_input))}</pre>
    </div>
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:16px;">
      <div class="prob-section-label" style="margin-bottom:8px;">Sample Output</div>
      <pre style="font-family:var(--mono);font-size:13px;color:var(--green);white-space:pre-wrap;">${escHtml(String(p.sample_output))}</pre>
    </div>`;
  loadMySubmissionsForProblem(slug);
}

function switchProblemTab(tab, btn) {
  document.querySelectorAll('#page-editor .tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('#page-editor .tab-panel').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('tab-' + tab).classList.add('active');
  if (tab === 'submissions-tab') loadMySubmissionsForProblem(currentProblemSlug);
}

async function loadMySubmissionsForProblem(slug) {
  const el = document.getElementById('prob-my-submissions');
  if (!currentUser) { el.innerHTML = '<div style="padding:20px;color:var(--text3);font-size:14px;">Login to see submissions</div>'; return; }
  const r = await apiFetch(`/submissions?problem_slug=${slug}&user_id=${currentUser.id}&limit=10`);
  if (!r.ok || !r.data.length) { el.innerHTML = '<div style="padding:20px;color:var(--text3);font-size:14px;">No submissions yet</div>'; return; }
  el.innerHTML = r.data.map(s => `
    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);">
      <span class="verdict verdict-${s.verdict}">${s.verdict}</span>
      <span style="font-size:13px;color:var(--text2);font-family:var(--mono);">${s.language}</span>
      <span style="font-size:12px;color:var(--text3);margin-left:auto;">${relTime(s.submitted_at)}</span>
    </div>`).join('');
}

function resetEditor() {
  document.getElementById('code-editor').value = defaultCode[document.getElementById('lang-select').value] || '';
}
function copyCode() {
  navigator.clipboard.writeText(document.getElementById('code-editor').value).then(() => toast('Code copied!', 'success'));
}

document.getElementById('lang-select').addEventListener('change', resetEditor);
document.getElementById('code-editor').addEventListener('keydown', function(e) {
  if (e.key === 'Tab') {
    e.preventDefault();
    const s = this.selectionStart, end = this.selectionEnd;
    this.value = this.value.substring(0, s) + '    ' + this.value.substring(end);
    this.selectionStart = this.selectionEnd = s + 4;
  }
});

async function runCode() {
  const el = document.getElementById('code-result');
  el.style.display = 'block';
  el.innerHTML = '<div class="loading" style="padding:12px;"><div class="spinner"></div>Running on sample...</div>';
  await new Promise(r => setTimeout(r, 600));
  el.innerHTML = `<div style="color:var(--cyan);font-weight:600;margin-bottom:6px;">ℹ️  Local Run</div>
    <div style="color:var(--text2);font-size:13px;">Full judging is done on submission. Use Submit to test all cases.</div>`;
}

async function submitCode() {
  if (!currentUser) { toast('Please login to submit', 'error'); openLoginModal(); return; }
  const code = document.getElementById('code-editor').value.trim();
  if (!code) { toast('Write some code first!', 'error'); return; }
  const lang = document.getElementById('lang-select').value;
  const btn  = document.getElementById('submit-btn');
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner" style="width:14px;height:14px;border-width:2px;"></div> Submitting...';
  document.getElementById('code-status').textContent = 'Submitting...';

  const r = await apiFetch('/submissions', { method: 'POST', body: JSON.stringify({
    problem_slug: currentProblemSlug, language: lang, code
  })});
  if (!r.ok) {
    toast(r.data.error || 'Submission failed', 'error');
    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit'; return;
  }
  const sid = r.data.submission_id;
  const el  = document.getElementById('code-result');
  el.style.display = 'block';
  el.innerHTML     = '<div style="display:flex;align-items:center;gap:10px;"><span class="verdict verdict-PENDING">⏳ JUDGING...</span><span style="color:var(--text3);font-size:13px;">Waiting for judge result...</span></div>';
  document.getElementById('code-status').textContent = 'Judging...';

  let attempts = 0;
  pollInterval = setInterval(async () => {
    attempts++;
    const pr = await apiFetch('/submissions/' + sid);
    const v  = pr.data.verdict || 'PENDING';
    if (v !== 'PENDING' || attempts > 25) {
      clearInterval(pollInterval); pollInterval = null;
      btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit';
      renderVerdictResult(el, pr.data);
      document.getElementById('code-status').textContent = 'Result: ' + v;
      loadMySubmissionsForProblem(currentProblemSlug);
    }
  }, 1500);
}

function renderVerdictResult(el, data) {
  const v = data.verdict || 'PENDING';
  const icons  = { AC:'🎉', WA:'❌', TLE:'⏱️', MLE:'💾', RE:'💥', CE:'🔨', SE:'⚠️', PENDING:'⏳' };
  el.innerHTML = `
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:10px;flex-wrap:wrap;">
      <span class="verdict verdict-${v}" style="font-size:15px;padding:6px 18px;">${icons[v]||''} ${v}</span>
      ${data.max_time_ms   ? `<span style="color:var(--text3);font-size:13px;"><i class="fa-solid fa-clock"></i> ${data.max_time_ms}ms</span>` : ''}
      ${data.max_memory_mb ? `<span style="color:var(--text3);font-size:13px;"><i class="fa-solid fa-memory"></i> ${parseFloat(data.max_memory_mb).toFixed(1)}MB</span>` : ''}
    </div>
    ${data.compile_error ? `<pre style="background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:10px;font-size:12px;color:var(--red);white-space:pre-wrap;max-height:120px;overflow-y:auto;">${escHtml(data.compile_error)}</pre>` : ''}
    ${v === 'AC' ? '<div style="color:var(--green);font-size:14px;font-weight:600;">All test cases passed! ✅</div>' : ''}`;
  if (v === 'AC') toast('Accepted! All test cases passed! 🎉', 'success', 5000);
  else if (v === 'WA') toast('Wrong Answer on at least one test case.', 'error');
  else if (v === 'TLE') toast('Time Limit Exceeded.', 'error');
  else if (v === 'CE') toast('Compilation Error — check your code.', 'error');
}

// ── Contests ──────────────────────────────────────────
async function loadContests() {
  const el = document.getElementById('contests-list');
  el.innerHTML = '<div class="loading"><div class="spinner"></div>Loading...</div>';
  const r = await apiFetch('/contests');
  el.innerHTML = r.ok && r.data.length ? r.data.map(contestCard).join('') : '<div class="loading">No contests found.</div>';
}

function contestCard(c) {
  const sc = { ongoing: 'status-ongoing', upcoming: 'status-upcoming', ended: 'status-ended' }[c.status] || 'status-ended';
  const sl = { ongoing: '🔴 Live', upcoming: '🔵 Upcoming', ended: '⬛ Ended' }[c.status] || c.status;
  return `<div class="contest-card" onclick="navigate('contest-detail','${c.slug}')">
    <div class="contest-card-top">
      <div class="contest-status ${sc}">${sl}</div>
      <div class="contest-title">${c.title}</div>
      <div class="contest-time"><i class="fa-regular fa-clock"></i> ${formatDate(new Date(c.start_time))} → ${formatDate(new Date(c.end_time))}</div>
    </div>
    <div class="contest-card-bottom">
      <span style="font-size:13px;color:var(--text2);">${(c.description||'').slice(0,55)}${c.description && c.description.length>55?'…':''}</span>
      <button class="btn btn-outline btn-sm">Enter <i class="fa-solid fa-arrow-right"></i></button>
    </div>
  </div>`;
}

async function loadContestDetail(slug) {
  const el = document.getElementById('contest-detail-content');
  const r  = await apiFetch('/contests/' + slug);
  if (!r.ok) { el.innerHTML = '<div class="loading">Contest not found.</div>'; return; }
  const c  = r.data;
  const sc = { ongoing: 'status-ongoing', upcoming: 'status-upcoming', ended: 'status-ended' }[c.status] || 'status-ended';
  el.innerHTML = `
    <div style="background:linear-gradient(135deg,rgba(124,58,237,0.15),rgba(6,182,212,0.08));border:1px solid var(--border);border-radius:16px;padding:32px;margin-bottom:32px;">
      <div class="contest-status ${sc}" style="margin-bottom:12px;">${c.status.toUpperCase()}</div>
      <h1 style="font-size:28px;font-weight:800;margin-bottom:8px;">${c.title}</h1>
      <p style="color:var(--text2);margin-bottom:16px;">${c.description}</p>
      <div style="display:flex;gap:24px;flex-wrap:wrap;">
        <span style="color:var(--text2);font-size:14px;"><i class="fa-solid fa-calendar"></i> ${formatDate(new Date(c.start_time))}</span>
        <span style="color:var(--text2);font-size:14px;"><i class="fa-solid fa-flag-checkered"></i> ${formatDate(new Date(c.end_time))}</span>
      </div>
    </div>
    <div style="font-size:18px;font-weight:700;margin-bottom:16px;">Problems</div>
    <div class="card card-list">
      ${c.problems.map((p, i) => `<div class="problem-row" onclick="navigate('editor','${p.slug}')">
        <div class="problem-num" style="font-weight:700;color:var(--text2);">${String.fromCharCode(65+i)}</div>
        <div class="problem-title-cell"><div>${p.title}</div></div>
        <div style="margin-left:auto;display:flex;gap:12px;align-items:center;">
          <span style="color:var(--purple3);font-weight:700;font-size:14px;">${p.points}pts</span>
          <span class="badge badge-${(p.difficulty||'Easy').toLowerCase()}">${p.difficulty}</span>
        </div>
      </div>`).join('')}
    </div>`;
}

// ── Leaderboard ───────────────────────────────────────
async function loadLeaderboard() {
  const el = document.getElementById('leaderboard-content');
  el.innerHTML = '<div class="loading"><div class="spinner"></div>Loading...</div>';
  const r = await apiFetch('/leaderboard');
  if (!r.ok) { el.innerHTML = '<div class="loading">Error.</div>'; return; }
  const medals = ['🥇','🥈','🥉'];
  el.innerHTML = `<table>
    <thead><tr><th>Rank</th><th>User</th><th>Rating</th><th>Solved</th></tr></thead>
    <tbody>${r.data.map((u, i) => `<tr class="${i<3?'rank-'+(i+1):''}">
      <td style="text-align:center;font-family:var(--mono);font-weight:700;">${medals[i]||i+1}</td>
      <td><div style="display:flex;align-items:center;gap:10px;">
        <div class="user-avatar" style="background:${u.avatar_color||'#7c3aed'};width:32px;height:32px;font-size:12px;">${u.username.slice(0,2).toUpperCase()}</div>
        <span style="font-weight:600;">${u.username}</span>
      </div></td>
      <td><span style="color:var(--purple3);font-weight:700;font-family:var(--mono);">${u.rating}</span></td>
      <td style="color:var(--text2);">${u.problems_solved}</td>
    </tr>`).join('')}</tbody></table>`;
}

// ── Submissions ───────────────────────────────────────
async function loadSubmissions() {
  const el = document.getElementById('submissions-content');
  el.innerHTML = '<div class="loading"><div class="spinner"></div>Loading...</div>';
  const uid = currentUser ? '&user_id=' + currentUser.id : '';
  const r = await apiFetch('/submissions?limit=50' + uid);
  if (!r.ok || !r.data.length) { el.innerHTML = '<div class="loading">No submissions yet.</div>'; return; }
  el.innerHTML = `<table><thead><tr><th>#</th><th>Problem</th><th>Verdict</th><th>Language</th><th>Time</th><th>Memory</th><th>Submitted</th></tr></thead>
    <tbody>${r.data.map((s, i) => `<tr>
      <td style="color:var(--text3);font-family:var(--mono);">${i+1}</td>
      <td><a onclick="navigate('editor','${s.problem_slug}')" style="color:var(--cyan);cursor:pointer;font-weight:500;">${s.problem_title}</a></td>
      <td><span class="verdict verdict-${s.verdict}">${s.verdict}</span></td>
      <td style="font-family:var(--mono);font-size:13px;color:var(--text2);">${s.language}</td>
      <td style="font-family:var(--mono);font-size:13px;">${s.time_ms != null ? s.time_ms+'ms' : '–'}</td>
      <td style="font-family:var(--mono);font-size:13px;">${s.memory_mb != null ? parseFloat(s.memory_mb).toFixed(1)+'MB' : '–'}</td>
      <td style="font-size:13px;color:var(--text3);">${relTime(s.submitted_at)}</td>
    </tr>`).join('')}</tbody></table>`;
}

// ── Profile ───────────────────────────────────────────
async function loadProfile() {
  if (!currentUser) { navigate('home'); openLoginModal(); return; }
  const u = currentUser;
  document.getElementById('profile-avatar').style.background = u.avatar_color;
  document.getElementById('profile-avatar').textContent = u.username.slice(0,2).toUpperCase();
  document.getElementById('profile-username').textContent = u.username;
  document.getElementById('profile-email').textContent   = u.email;
  document.getElementById('profile-role').textContent    = u.role;
  document.getElementById('profile-rating').textContent  = u.rating;
  const r = await apiFetch(`/submissions?user_id=${u.id}&limit=50`);
  if (r.ok) {
    document.getElementById('profile-submissions').textContent = r.data.length;
    const solved = new Set(r.data.filter(s => s.verdict === 'AC').map(s => s.problem_slug)).size;
    document.getElementById('profile-solved').textContent = solved;
    document.getElementById('profile-submissions-table').innerHTML = `<table>
      <thead><tr><th>Problem</th><th>Verdict</th><th>Language</th><th>Submitted</th></tr></thead>
      <tbody>${r.data.slice(0,20).map(s => `<tr>
        <td><a onclick="navigate('editor','${s.problem_slug}')" style="color:var(--cyan);cursor:pointer;">${s.problem_title}</a></td>
        <td><span class="verdict verdict-${s.verdict}">${s.verdict}</span></td>
        <td style="font-family:var(--mono);font-size:13px;color:var(--text2);">${s.language}</td>
        <td style="font-size:13px;color:var(--text3);">${relTime(s.submitted_at)}</td>
      </tr>`).join('')}</tbody></table>`;
  }
}

// ── Helpers ───────────────────────────────────────────
function formatDate(d) {
  if (isNaN(d)) return 'Invalid';
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function relTime(iso) {
  if (!iso) return '–';
  try {
    const diff = Date.now() - new Date(iso.endsWith('Z') ? iso : iso + 'Z').getTime();
    const s = Math.floor(diff / 1000);
    if (s < 60)    return s + 's ago';
    if (s < 3600)  return Math.floor(s/60) + 'm ago';
    if (s < 86400) return Math.floor(s/3600) + 'h ago';
    return Math.floor(s/86400) + 'd ago';
  } catch { return '–'; }
}
function escHtml(str) {
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ──────────────────────────────────────────────
(async () => {
  await loadCurrentUser();
  loadHome();
})();
