<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PMDP Bike – Výpůjčky</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <style>
    :root {
      --green: #2ecc71; --green-dark: #27ae60;
      --blue: #3498db;  --blue-dark: #2980b9;
      --orange: #e67e22;
      --gray-100: #f8f9fa; --gray-200: #e9ecef;
      --gray-400: #ced4da; --gray-600: #6c757d; --gray-800: #343a40;
      --bg: #f0f4f8; --card: #ffffff;
      --radius: 12px; --shadow: 0 2px 12px rgba(0,0,0,.08);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--gray-800); min-height: 100vh; }

    header { background: linear-gradient(135deg,#1a3a5c 0%,#1e6b3c 100%); color: #fff; padding: 28px 24px 20px; }
    .header-inner { max-width: 1100px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
    .logo-group { display: flex; align-items: center; gap: 14px; }
    .logo-icon { width: 48px; height: 48px; background: rgba(255,255,255,.95); border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    h1 { font-size: 1.5rem; font-weight: 700; line-height: 1.2; }
    h1 span { font-size: .85rem; font-weight: 400; opacity: .75; display: block; margin-top: 2px; }
    .header-nav a { color: rgba(255,255,255,.8); text-decoration: none; font-size: .85rem; }
    .header-nav a:hover { color: #fff; }

    main { max-width: 1100px; margin: 0 auto; padding: 28px 16px 48px; }

    .period-bar { display: flex; gap: 8px; margin-bottom: 28px; flex-wrap: wrap; align-items: center; }
    .period-bar span { font-size: .85rem; color: var(--gray-600); margin-right: 4px; }
    .period-btn { padding: 7px 18px; border-radius: 20px; border: 2px solid var(--gray-200); background: var(--card); cursor: pointer; font-size: .85rem; font-weight: 600; color: var(--gray-600); transition: all .15s; }
    .period-btn.active { background: var(--blue); border-color: var(--blue); color: #fff; }
    .period-btn:hover:not(.active) { border-color: var(--blue); color: var(--blue); }

    .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px,1fr)); gap: 16px; margin-bottom: 28px; }
    .card { background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); padding: 20px 22px; display: flex; flex-direction: column; gap: 6px; border-top: 4px solid transparent; }
    .card.green  { border-top-color: var(--green); }
    .card.blue   { border-top-color: var(--blue); }
    .card.orange { border-top-color: var(--orange); }
    .card-label { font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; color: var(--gray-600); font-weight: 600; }
    .card-value { font-size: 2.4rem; font-weight: 800; line-height: 1; }
    .card.green  .card-value { color: var(--green-dark); }
    .card.blue   .card-value { color: var(--blue-dark); }
    .card.orange .card-value { color: var(--orange); }
    .card-sub { font-size: .8rem; color: var(--gray-600); }

    .panel { background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); padding: 22px 24px; margin-bottom: 24px; }
    .section-title { font-size: 1rem; font-weight: 700; color: var(--gray-800); margin-bottom: 16px; }
    .chart-wrap { position: relative; height: 280px; }

    .station-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
    .station-table th { text-align: left; padding: 8px 12px; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--gray-600); border-bottom: 2px solid var(--gray-200); }
    .station-table td { padding: 9px 12px; border-bottom: 1px solid var(--gray-100); vertical-align: middle; }
    .station-table tr:last-child td { border-bottom: none; }
    .station-table tr:hover td { background: var(--gray-100); }
    .bar-wrap { display: flex; align-items: center; gap: 8px; }
    .bar { height: 8px; border-radius: 4px; background: var(--blue); opacity: .7; min-width: 4px; }
    .bar-num { font-weight: 700; color: var(--blue-dark); }

    .note { font-size: .78rem; color: var(--gray-600); margin-top: 14px; line-height: 1.5; }
    .loading { text-align: center; padding: 40px; color: var(--gray-600); font-size: .9rem; }

    footer { text-align: center; padding: 24px 16px; font-size: .78rem; color: var(--gray-600); }
    footer a { color: var(--blue); text-decoration: none; }
  </style>
</head>
<body>

<header>
  <div class="header-inner">
    <div class="logo-group">
      <div class="logo-icon">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#1a3a5c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/>
          <path d="M15 6a1 1 0 0 0 0-2h-1l-5 8h4l1.5 3"/><path d="m12 6 5 8"/>
        </svg>
      </div>
      <h1>PMDP Bike – Výpůjčky<span>Odhadované výpůjčky bikesharingu v Plzni</span></h1>
    </div>
    <nav class="header-nav"><a href="index.html">← Zpět na přehled</a></nav>
  </div>
</header>

<main>

  <div class="period-bar">
    <span>Zobrazit za:</span>
    <button class="period-btn" onclick="setPeriod(1,this)">Dnes</button>
    <button class="period-btn active" onclick="setPeriod(7,this)">7 dní</button>
    <button class="period-btn" onclick="setPeriod(30,this)">30 dní</button>
    <button class="period-btn" onclick="setPeriod(90,this)">90 dní</button>
  </div>

  <div class="cards" id="cards"><div class="loading">Načítám…</div></div>

  <div class="panel">
    <div class="section-title">📅 Výpůjčky po dnech</div>
    <div class="chart-wrap"><canvas id="daily-chart"></canvas></div>
  </div>

  <div class="panel">
    <div class="section-title">🕐 Výpůjčky dle hodiny dne</div>
    <div class="chart-wrap"><canvas id="hourly-chart"></canvas></div>
  </div>

  <div class="panel">
    <div class="section-title">📍 Výpůjčky dle stanice</div>
    <div id="stations-wrap"><div class="loading">Načítám…</div></div>
    <p class="note">Výpůjčka je detekována, když konkrétní kolo (dle jeho ID) zmizí ze stanice mezi dvěma měřeními.</p>
  </div>

</main>

<footer>
  Data: <a href="https://www.pmdp.cz/bike/" target="_blank">PMDP Bike</a> · GBFS v3.0 API ·
  Vytvořil <a href="https://www.jasnapaka.com/" target="_blank">Pavel Cvrček</a>
</footer>

<script>
const API = 'api_vypujcky.php';
let currentDays = 7;
let dailyChart = null, hourlyChart = null;

window.addEventListener('DOMContentLoaded', loadAll);

function setPeriod(days, btn) {
  currentDays = days;
  document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadAll();
}

function loadAll() {
  loadOverview();
  loadDaily();
  loadHourly();
  loadStations();
}

async function loadOverview() {
  const el = document.getElementById('cards');
  el.innerHTML = '<div class="loading">Načítám…</div>';
  try {
    const d = await get('overview');
    const avgStr = d.avg_per_day != null ? d.avg_per_day.toFixed(1) : '–';
    el.innerHTML = `
      <div class="card green">
        <div class="card-label">Celkem výpůjček</div>
        <div class="card-value">${d.total}</div>
        <div class="card-sub">za ${d.days} ${dayLabel(d.days)}</div>
      </div>
      <div class="card blue">
        <div class="card-label">Průměr za den</div>
        <div class="card-value">${avgStr}</div>
        <div class="card-sub">${d.days_with_data > 0 ? 'z ' + d.days_with_data + ' dní s daty' : 'zatím žádná data'}</div>
      </div>`;
  } catch { el.innerHTML = '<div class="loading">Chyba při načítání.</div>'; }
}

async function loadDaily() {
  try {
    const pts = await get('daily');
    if (dailyChart) dailyChart.destroy();
    dailyChart = new Chart(document.getElementById('daily-chart'), {
      type: 'bar',
      data: {
        labels: pts.map(p => fmtDate(p.day)),
        datasets: [{ label: 'Výpůjčky', data: pts.map(p => p.trips), backgroundColor: 'rgba(52,152,219,.7)', borderColor: '#2980b9', borderWidth: 1, borderRadius: 4 }],
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { stepSize: 1 } } } },
    });
  } catch {}
}

async function loadHourly() {
  try {
    const pts = await get('hourly');
    if (hourlyChart) hourlyChart.destroy();
    hourlyChart = new Chart(document.getElementById('hourly-chart'), {
      type: 'bar',
      data: {
        labels: pts.map(p => p.hour + ':00'),
        datasets: [{ label: 'Výpůjčky', data: pts.map(p => p.trips), backgroundColor: 'rgba(46,204,113,.7)', borderColor: '#27ae60', borderWidth: 1, borderRadius: 4 }],
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { stepSize: 1 } } } },
    });
  } catch {}
}

async function loadStations() {
  const el = document.getElementById('stations-wrap');
  el.innerHTML = '<div class="loading">Načítám…</div>';
  try {
    const rows = await get('stations');
    if (!rows.length) { el.innerHTML = '<div class="loading">Zatím žádná data.</div>'; return; }
    const max = rows[0].trips;
    el.innerHTML = `<table class="station-table">
      <thead><tr><th>#</th><th>Stanice</th><th>Výpůjček</th></tr></thead>
      <tbody>${rows.map((r, i) => `<tr>
        <td style="color:var(--gray-600);font-size:.8rem">${i + 1}.</td>
        <td>${esc(r.name)}</td>
        <td><div class="bar-wrap">
          <div class="bar" style="width:${Math.max(Math.round(r.trips / max * 120), 4)}px"></div>
          <span class="bar-num">${r.trips}</span>
        </div></td>
      </tr>`).join('')}</tbody>
    </table>`;
  } catch { el.innerHTML = '<div class="loading">Chyba při načítání.</div>'; }
}

async function get(action) {
  const r = await fetch(`${API}?action=${action}&days=${currentDays}`);
  if (!r.ok) throw new Error(`HTTP ${r.status}`);
  const j = await r.json();
  if (j.error) throw new Error(j.error);
  return j;
}

function fmtDate(iso) { return new Date(iso).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' }); }
function dayLabel(n) { return n === 1 ? 'den' : n <= 4 ? 'dny' : 'dní'; }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
</body>
</html>
