<!DOCTYPE html>
<html>
<style>
  @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=Syne:wght@400;600;700&display=swap');

  *{box-sizing:border-box;margin:0;padding:0}
  :root{
    --bg:#0a0b0d;--surface:#111318;--surface2:#181c22;--surface3:#1e2330;
    --border:#252b38;--border2:#303848;
    --accent:#3b82f6;--accent2:#60a5fa;
    --green:#22c55e;--red:#ef4444;--amber:#f59e0b;
    --text:#e2e8f0;--text2:#94a3b8;--text3:#475569;
    --font-ui:'Syne',sans-serif;--font-mono:'JetBrains Mono',monospace;
    --r:6px;--r2:10px;
  }
  body{background:var(--bg);color:var(--text);font-family:var(--font-ui);min-height:100vh;padding:24px 20px;line-height:1.5}

  h1{font-size:22px;font-weight:700;letter-spacing:-0.5px;margin-bottom:4px}
  .sub{font-size:13px;color:var(--text2);font-family:var(--font-mono)}

  .tabs{display:flex;gap:2px;margin:24px 0 16px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r2);padding:4px}
  .tab{flex:1;padding:8px;text-align:center;font-size:13px;font-weight:600;border-radius:var(--r);cursor:pointer;color:var(--text2);transition:all .15s;border:none;background:none}
  .tab.active{background:var(--surface3);color:var(--text);border:1px solid var(--border2)}
  .tab:hover:not(.active){color:var(--text);background:rgba(255,255,255,.04)}

  .panel{display:none}.panel.active{display:block}

  label{font-size:12px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.6px;display:block;margin-bottom:6px}

  textarea,input[type=text],input[type=password]{
    width:100%;background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r);color:var(--text);font-family:var(--font-mono);
    font-size:13px;padding:10px 12px;outline:none;resize:vertical;
    transition:border-color .15s;
  }
  textarea:focus,input:focus{border-color:var(--accent)}
  textarea{min-height:120px;line-height:1.6}

  .row{display:flex;gap:8px;align-items:center}
  .row-between{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}

  .btn{
    display:inline-flex;align-items:center;gap:6px;padding:9px 16px;
    border-radius:var(--r);font-size:13px;font-weight:600;font-family:var(--font-ui);
    cursor:pointer;border:1px solid var(--border2);background:var(--surface2);
    color:var(--text);transition:all .15s;white-space:nowrap;
  }
  .btn:hover{background:var(--surface3);border-color:var(--border2)}
  .btn:active{transform:scale(.98)}
  .btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
  .btn.primary:hover{background:var(--accent2);border-color:var(--accent2)}
  .btn.primary:disabled{opacity:.4;cursor:not-allowed;transform:none}
  .btn.danger{border-color:#7f1d1d;background:#1c0a0a;color:var(--red)}
  .btn.danger:hover{background:#2d1010}
  .btn.success{border-color:#14532d;background:#0a1c12;color:var(--green)}
  .btn.sm{padding:5px 10px;font-size:12px}
  .btn.icon{padding:6px 8px}

  .results{margin-top:16px;display:flex;flex-direction:column;gap:8px}

  .result-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden}
  .result-header{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border)}
  .result-index{font-size:11px;font-family:var(--font-mono);color:var(--text3);min-width:28px}
  .result-url{font-size:12px;color:var(--text2);font-family:var(--font-mono);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .status-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
  .dot-pending{background:var(--text3)}
  .dot-loading{background:var(--amber);animation:pulse 1s infinite}
  .dot-ok{background:var(--green)}
  .dot-err{background:var(--red)}
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

  .result-body{padding:12px 14px}
  .hd-url{
    font-family:var(--font-mono);font-size:11px;color:var(--accent2);
    word-break:break-all;line-height:1.6;background:var(--surface2);
    padding:8px 10px;border-radius:var(--r);border:1px solid var(--border);
    margin-bottom:8px;
  }
  .meta-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
  .meta-chip{font-size:11px;font-family:var(--font-mono);color:var(--text3);background:var(--surface2);padding:3px 8px;border-radius:4px}
  .error-msg{font-size:12px;color:var(--red);font-family:var(--font-mono);padding:6px 10px;background:#1c0a0a;border-radius:var(--r);border:1px solid #7f1d1d}

  .copy-btn{font-size:11px;padding:4px 10px}
  .copy-btn.copied{color:var(--green);border-color:#14532d;background:#0a1c12}

  .progress-bar{height:2px;background:var(--border);border-radius:1px;margin-top:8px;overflow:hidden}
  .progress-fill{height:100%;background:var(--accent);width:0;transition:width .3s}

  .divider{height:1px;background:var(--border);margin:16px 0}

  .cookie-section{display:flex;flex-direction:column;gap:12px}
  .cookie-status{font-size:12px;font-family:var(--font-mono);padding:8px 12px;border-radius:var(--r);display:flex;align-items:center;gap:8px}
  .cookie-status.saved{background:#0a1c12;border:1px solid #14532d;color:var(--green)}
  .cookie-status.empty{background:var(--surface2);border:1px solid var(--border);color:var(--text3)}

  .info-box{font-size:12px;color:var(--text2);font-family:var(--font-mono);padding:10px 12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);line-height:1.7}
  .info-box code{color:var(--accent2)}

  .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px}
  .stat{background:var(--surface2);border:1px solid var(--border);border-radius:var(--r);padding:10px;text-align:center}
  .stat-num{font-size:20px;font-weight:700;font-family:var(--font-mono)}
  .stat-lbl{font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--text3);margin-top:2px}
  .stat-num.ok{color:var(--green)}.stat-num.err{color:var(--red)}.stat-num.tot{color:var(--accent2)}

  .toast{
    position:fixed;bottom:20px;right:20px;background:var(--surface2);
    border:1px solid var(--border2);border-radius:var(--r);
    padding:10px 14px;font-size:13px;font-family:var(--font-mono);
    color:var(--text);transform:translateY(60px);opacity:0;
    transition:all .25s;z-index:999;pointer-events:none;
  }
  .toast.show{transform:translateY(0);opacity:1}

  .bulk-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
  .counter{font-size:12px;color:var(--text3);font-family:var(--font-mono);padding:4px 0}
</style>
<body>
<h2 class="sr-only" style="display:none">Facebook Video HD URL Extractor</h2>

<div style="max-width:680px;margin:0 auto">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:2px">
    <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
      <rect width="28" height="28" rx="6" fill="#1877F2"/>
      <path d="M15.6 21v-6.3h2.1l.32-2.44H15.6v-1.56c0-.7.19-1.18 1.2-1.18H18V7.1A16 16 0 0 0 16.1 7c-1.9 0-3.2 1.16-3.2 3.29v1.97H10.8V14.7H12.9V21h2.7z" fill="#fff"/>
    </svg>
    <h1>FB Video Extractor</h1>
  </div>
  <p class="sub">// bulk hd_url extractor — api.vbi1.my.id</p>

  <div class="tabs">
    <button class="tab active" onclick="switchTab('download')">Download</button>
    <button class="tab" onclick="switchTab('cookies')">Cookies</button>
  </div>

  <!-- Download Panel -->
  <div class="panel active" id="panel-download">
    <div class="row-between">
      <label>Facebook URLs</label>
      <span class="counter" id="url-counter">0 URL</span>
    </div>
    <textarea id="url-input" placeholder="Masukkan satu URL per baris:&#10;https://www.facebook.com/reel/123456&#10;https://www.facebook.com/watch?v=789&#10;https://fb.watch/abcXYZ" spellcheck="false" oninput="countUrls()"></textarea>

    <div class="row" style="margin-top:10px;justify-content:space-between">
      <div class="row" style="gap:6px">
        <button class="btn sm" onclick="clearInput()">Bersihkan</button>
        <button class="btn sm" onclick="pasteClipboard()">Tempel</button>
      </div>
      <button class="btn primary" id="run-btn" onclick="runExtract()">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M3 2l11 6-11 6V2z"/></svg>
        Ekstrak HD URL
      </button>
    </div>

    <div id="stats-area" style="display:none;margin-top:16px">
      <div class="stats">
        <div class="stat"><div class="stat-num tot" id="s-total">0</div><div class="stat-lbl">Total</div></div>
        <div class="stat"><div class="stat-num ok" id="s-ok">0</div><div class="stat-lbl">Berhasil</div></div>
        <div class="stat"><div class="stat-num err" id="s-err">0</div><div class="stat-lbl">Gagal</div></div>
      </div>
      <div class="progress-bar"><div class="progress-fill" id="main-progress"></div></div>
    </div>

    <div class="results" id="results-area"></div>

    <div class="bulk-actions" id="bulk-actions" style="display:none">
      <button class="btn sm" onclick="copyAllUrls()">Salin Semua HD URL</button>
      <button class="btn sm" onclick="downloadTxt()">Unduh sebagai .txt</button>
      <button class="btn sm danger" onclick="clearResults()">Hapus Hasil</button>
    </div>
  </div>

  <!-- Cookies Panel -->
  <div class="panel" id="panel-cookies">
    <div class="cookie-section">
      <div>
        <label>Status Cookie</label>
        <div class="cookie-status empty" id="cookie-status-box">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5" fill="none"/><text x="8" y="12" text-anchor="middle" font-size="10" font-family="monospace">!</text></svg>
          <span id="cookie-status-txt">Tidak ada cookie tersimpan</span>
        </div>
      </div>

      <div>
        <label>Cookie String</label>
        <textarea id="cookie-input" placeholder="Tempel cookie Facebook di sini...&#10;Contoh: c_user=123456789; xs=abc123; datr=XYZ..." style="min-height:90px;font-size:11px" spellcheck="false"></textarea>
      </div>

      <div class="info-box">
        Cara mendapatkan cookie:<br>
        1. Login ke <code>facebook.com</code> di browser<br>
        2. Buka DevTools → Application → Cookies<br>
        3. Salin nilai: <code>c_user</code>, <code>xs</code>, <code>datr</code>, <code>sb</code><br>
        Format: <code>nama=nilai; nama2=nilai2</code>
      </div>

      <div class="row" style="gap:8px">
        <button class="btn primary" onclick="saveCookie()">Simpan Cookie</button>
        <button class="btn" onclick="testCookie()">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M2 8a6 6 0 1 1 12 0A6 6 0 0 1 2 8zm6-7a7 7 0 1 0 0 14A7 7 0 0 0 8 1zM7 5v4l3 1.5-.5.87L6 9.5V5h1z"/></svg>
          Tes Cookie
        </button>
        <button class="btn danger" onclick="deleteCookie()">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M6.5 1h3a.5.5 0 0 1 .5.5v1H6v-1a.5.5 0 0 1 .5-.5zM11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3A1.5 1.5 0 0 0 5 1.5v1H2.5a.5.5 0 0 0 0 1H3v9a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-9h.5a.5.5 0 0 0 0-1H11z"/></svg>
          Hapus
        </button>
      </div>

      <div id="cookie-test-result" style="display:none"></div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const API = 'https://apifb.vbi1.my.id/api/index2.php';
const COOKIE_KEY = 'fb_extractor_cookie';

let state = { results:[], running:false, ok:0, err:0 };

// ── Storage helpers (localStorage — works di Vercel & semua browser) ─────────
async function loadCookie() {
  try {
    return localStorage.getItem(COOKIE_KEY) || null;
  } catch { return null; }
}
async function setCookie(v) {
  try { localStorage.setItem(COOKIE_KEY, v); return true; }
  catch { return false; }
}
async function removeCookie() {
  try { localStorage.removeItem(COOKIE_KEY); return true; }
  catch { return false; }
}

// ── Init ────────────────────────────────────────────────────────────────────
(async () => {
  const c = await loadCookie();
  updateCookieStatusUI(c);
})();

function updateCookieStatusUI(cookie) {
  const box = document.getElementById('cookie-status-box');
  const txt = document.getElementById('cookie-status-txt');
  if (cookie) {
    box.className = 'cookie-status saved';
    const preview = cookie.length > 40 ? cookie.slice(0, 40) + '...' : cookie;
    txt.textContent = 'Cookie tersimpan: ' + preview;
    document.getElementById('cookie-input').value = cookie;
  } else {
    box.className = 'cookie-status empty';
    txt.textContent = 'Tidak ada cookie tersimpan';
  }
}

// ── Tabs ────────────────────────────────────────────────────────────────────
function switchTab(id) {
  document.querySelectorAll('.tab').forEach((t,i)=>t.classList.toggle('active',['download','cookies'][i]===id));
  document.querySelectorAll('.panel').forEach((p,i)=>p.classList.toggle('active',['panel-download','panel-cookies'][i]==='panel-'+id));
}

// ── URL helpers ─────────────────────────────────────────────────────────────
function parseUrls() {
  return document.getElementById('url-input').value
    .split('\n').map(l=>l.trim()).filter(l=>l && /facebook\.com|fb\.watch/i.test(l));
}
function countUrls() {
  const n = parseUrls().length;
  document.getElementById('url-counter').textContent = n + ' URL';
}
function clearInput() {
  document.getElementById('url-input').value = '';
  countUrls();
}
async function pasteClipboard() {
  try {
    const t = await navigator.clipboard.readText();
    const el = document.getElementById('url-input');
    el.value = (el.value ? el.value + '\n' : '') + t;
    countUrls();
    toast('Tempel berhasil');
  } catch { toast('Izin clipboard ditolak'); }
}

// ── Fetch one URL ────────────────────────────────────────────────────────────
async function fetchOne(url, cookie) {
  const body = { url, debug: false };
  if (cookie) body.cookie = cookie;
  const res = await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  return await res.json();
}

// ── Main extract ─────────────────────────────────────────────────────────────
async function runExtract() {
  if (state.running) return;
  const urls = parseUrls();
  if (!urls.length) { toast('Masukkan minimal 1 URL'); return; }

  state = { results:[], running:true, ok:0, err:0 };
  const cookie = await loadCookie();

  document.getElementById('run-btn').disabled = true;
  document.getElementById('stats-area').style.display = 'block';
  document.getElementById('bulk-actions').style.display = 'none';
  document.getElementById('results-area').innerHTML = '';

  // Create cards
  urls.forEach((url, i) => {
    document.getElementById('results-area').insertAdjacentHTML('beforeend', resultCardHtml(i, url, 'pending'));
    state.results.push({ url, status:'pending', hd_url:null });
  });

  updateStats(urls.length, 0, 0);

  for (let i = 0; i < urls.length; i++) {
    setCardState(i, 'loading');
    try {
      const data = await fetchOne(urls[i], cookie);
      if (data.success && data.data && data.data.hd_url) {
        state.results[i] = { url: urls[i], status:'ok', hd_url: data.data.hd_url, extra: data.data.extra };
        state.ok++;
        setCardDone(i, data.data.hd_url, data.data.extra);
      } else {
        throw new Error(data.error || 'URL tidak ditemukan');
      }
    } catch(e) {
      state.results[i] = { url: urls[i], status:'err', error: e.message };
      state.err++;
      setCardError(i, e.message);
    }
    updateStats(urls.length, state.ok, state.err);
    setProgress(((i+1)/urls.length)*100);
  }

  state.running = false;
  document.getElementById('run-btn').disabled = false;
  document.getElementById('bulk-actions').style.display = 'flex';
  toast(state.ok + ' berhasil, ' + state.err + ' gagal');
}

function resultCardHtml(i, url, status) {
  const short = url.replace(/https?:\/\/(www\.)?/, '').slice(0, 55);
  return `<div class="result-card" id="card-${i}">
    <div class="result-header">
      <span class="result-index">#${String(i+1).padStart(2,'0')}</span>
      <span class="result-url" title="${url}">${short}</span>
      <span class="status-dot dot-pending" id="dot-${i}"></span>
    </div>
    <div class="result-body" id="body-${i}" style="display:none"></div>
  </div>`;
}

function setCardState(i, s) {
  const dot = document.getElementById('dot-'+i);
  dot.className = 'status-dot dot-'+s;
}

function setCardDone(i, hdUrl, extra) {
  setCardState(i,'ok');
  const body = document.getElementById('body-'+i);
  const dur = extra && extra.duration_fmt ? `<span class="meta-chip">${extra.duration_fmt}</span>` : '';
  const views = extra && extra.views ? `<span class="meta-chip">${extra.views}</span>` : '';
  const author = extra && extra.author ? `<span class="meta-chip">${extra.author}</span>` : '';
  const title = extra && extra.title ? `<div style="font-size:12px;color:var(--text2);margin-bottom:6px;font-weight:600">${extra.title}</div>` : '';
  body.innerHTML = `${title}<div class="hd-url" id="hdurl-${i}">${hdUrl}</div>
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
      <button class="btn sm copy-btn" onclick="copyUrl(${i})" id="copybtn-${i}">Salin URL</button>
      <a href="${hdUrl}" target="_blank" class="btn sm">Buka</a>
      <div class="meta-row">${dur}${views}${author}</div>
    </div>`;
  body.style.display = 'block';
}

function setCardError(i, msg) {
  setCardState(i,'err');
  const body = document.getElementById('body-'+i);
  body.innerHTML = `<div class="error-msg">${msg}</div>`;
  body.style.display = 'block';
}

function copyUrl(i) {
  const url = state.results[i]?.hd_url;
  if (!url) return;
  navigator.clipboard.writeText(url).then(()=>{
    const btn = document.getElementById('copybtn-'+i);
    btn.textContent = 'Tersalin!'; btn.classList.add('copied');
    setTimeout(()=>{ btn.textContent='Salin URL'; btn.classList.remove('copied'); }, 2000);
  });
}

function updateStats(total, ok, err) {
  document.getElementById('s-total').textContent = total;
  document.getElementById('s-ok').textContent = ok;
  document.getElementById('s-err').textContent = err;
}
function setProgress(pct) {
  document.getElementById('main-progress').style.width = pct + '%';
}

function copyAllUrls() {
  const urls = state.results.filter(r=>r.status==='ok').map(r=>r.hd_url).join('\n');
  if (!urls) { toast('Tidak ada URL untuk disalin'); return; }
  navigator.clipboard.writeText(urls).then(()=>toast(state.ok + ' HD URL disalin!'));
}
function downloadTxt() {
  const urls = state.results.filter(r=>r.status==='ok').map(r=>r.hd_url).join('\n');
  if (!urls) return;
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([urls],{type:'text/plain'}));
  a.download = 'fb_hd_urls.txt'; a.click();
}
function clearResults() {
  state.results = []; state.ok = 0; state.err = 0;
  document.getElementById('results-area').innerHTML = '';
  document.getElementById('stats-area').style.display = 'none';
  document.getElementById('bulk-actions').style.display = 'none';
}

// ── Cookie panel ─────────────────────────────────────────────────────────────
async function saveCookie() {
  const val = document.getElementById('cookie-input').value.trim();
  if (!val) { toast('Cookie tidak boleh kosong'); return; }
  const ok = await setCookie(val);
  if (ok) { updateCookieStatusUI(val); toast('Cookie tersimpan'); }
  else toast('Gagal menyimpan cookie');
}

async function deleteCookie() {
  await removeCookie();
  document.getElementById('cookie-input').value = '';
  updateCookieStatusUI(null);
  document.getElementById('cookie-test-result').style.display = 'none';
  toast('Cookie dihapus');
}

async function testCookie() {
  const cookie = await loadCookie();
  const res = document.getElementById('cookie-test-result');
  if (!cookie) { toast('Simpan cookie terlebih dahulu'); return; }

  res.style.display = 'block';
  res.innerHTML = '<div class="cookie-status empty"><span>Menguji cookie...</span></div>';

  try {
    const testUrl = 'https://www.facebook.com/reel/1855893615023668';
    const d = await fetchOne(testUrl, cookie);
    if (d.success && d.data?.hd_url) {
      res.innerHTML = `<div class="cookie-status saved">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M13.78 4.22a.75.75 0 0 1 0 1.06l-7.25 7.25a.75.75 0 0 1-1.06 0L2.22 9.28a.75.75 0 0 1 1.06-1.06L6 10.94l6.72-6.72a.75.75 0 0 1 1.06 0z"/></svg>
        Cookie valid — HD URL berhasil diekstrak
      </div>`;
    } else {
      throw new Error(d.error || 'Gagal');
    }
  } catch(e) {
    res.innerHTML = `<div class="cookie-status" style="background:#1c0a0a;border:1px solid #7f1d1d;color:var(--red)">
      Tes gagal: ${e.message}
    </div>`;
  }
}

// ── Toast ────────────────────────────────────────────────────────────────────
let toastTimer;
function toast(msg) {
  const el = document.getElementById('toast');
  el.textContent = msg; el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(()=>el.classList.remove('show'), 2500);
}
</script>
</body>
</html>
