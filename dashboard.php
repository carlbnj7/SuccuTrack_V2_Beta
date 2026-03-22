<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
require 'config.php';

$uid = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM plants WHERE user_id = ? ORDER BY plant_id ASC");
$stmt->execute([$uid]);
$plants = $stmt->fetchAll();

// Fetch account status for workflow banner
$_statusRow = $pdo->prepare("SELECT status FROM users WHERE user_id=?");
$_statusRow->execute([$uid]);
$_userStatus = $_statusRow->fetchColumn() ?: 'active';

$plantData = [];
foreach ($plants as $plant) {
    $pid = (int)$plant['plant_id'];

    $q = $pdo->prepare("
        SELECT h.humidity_percent, h.status, h.recorded_at
        FROM humidity h JOIN user_logs ul ON h.humidity_id = ul.humidity_id
        WHERE h.plant_id = ? AND ul.user_id = ?
        ORDER BY h.recorded_at DESC LIMIT 1
    ");
    $q->execute([$pid, $uid]);
    $latest = $q->fetch();

    $s  = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=?");
    $s->execute([$uid,$pid]); $total=(int)$s->fetchColumn();
    $s2 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Dry'");
    $s2->execute([$uid,$pid]); $dry=(int)$s2->fetchColumn();
    $s3 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Ideal'");
    $s3->execute([$uid,$pid]); $ideal=(int)$s3->fetchColumn();
    $s4 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Humid'");
    $s4->execute([$uid,$pid]); $humid=(int)$s4->fetchColumn();

    $chartQ = $pdo->prepare("
        SELECT h.humidity_percent, h.status, h.recorded_at
        FROM humidity h JOIN user_logs ul ON h.humidity_id = ul.humidity_id
        WHERE h.plant_id = ? AND ul.user_id = ?
        ORDER BY h.recorded_at DESC LIMIT 50
    ");
    $chartQ->execute([$pid, $uid]);
    $chartRows = array_reverse($chartQ->fetchAll());
    $chartPoints = array_map(fn($r) => [
        'ts'     => strtotime($r['recorded_at']),
        'label'  => date('M d H:i', strtotime($r['recorded_at'])),
        'value'  => (float)$r['humidity_percent'],
        'status' => $r['status'],
    ], $chartRows);

    $plantData[] = [
        'plant'       => $plant,
        'latest'      => $latest,
        'stats'       => compact('total','dry','ideal','humid'),
        'chartPoints' => $chartPoints,
    ];
}

$logs = $pdo->prepare("
    SELECT p.plant_name, h.humidity_percent, h.status, h.recorded_at
    FROM humidity h JOIN user_logs ul ON h.humidity_id = ul.humidity_id
    JOIN plants p ON h.plant_id = p.plant_id
    WHERE ul.user_id = ?
    ORDER BY h.recorded_at DESC LIMIT 100
");
$logs->execute([$uid]);
$allLogs = $logs->fetchAll();

$tips = [
    'Dry'   => ['💧 Water immediately — give a thorough soak and let it drain fully.',
                '☀️ Too much direct sun speeds up drying — consider partial shade.',
                '🪴 Check if soil is pulling away from the pot edges.'],
    'Ideal' => ['✅ Perfect conditions! Maintain your current watering schedule.',
                '🌿 A diluted succulent fertiliser will boost growth right now.',
                '🔄 Rotate the pot a quarter turn weekly for even sun exposure.'],
    'Humid' => ['🚫 Stop watering until the top 2 cm of soil is completely dry.',
                '💨 Move to a well-ventilated area to reduce excess moisture.',
                '🪨 Ensure the pot has drainage holes to prevent root rot.'],
];

$PALETTE_LINES = ['#1a6e3c','#1656a3','#c0430e','#8b5cf6','#d97706','#0d7c6b','#db2777'];
$plantColorMap = [];
foreach ($plantData as $i => $pd) {
    $plantColorMap[$pd['plant']['plant_id']] = $PALETTE_LINES[$i % count($PALETTE_LINES)];
}

$sumTotal = array_sum(array_column(array_column($plantData,'stats'),'total'));
$sumDry   = array_sum(array_column(array_column($plantData,'stats'),'dry'));
$sumIdeal = array_sum(array_column(array_column($plantData,'stats'),'ideal'));
$sumHumid = array_sum(array_column(array_column($plantData,'stats'),'humid'));

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$activePage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Dashboard – SuccuTrack</title>
<link rel="stylesheet" href="style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body class="role-user">
<div class="app-layout">
  <?php include 'sidebar.php'; ?>

  <div class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <button class="sb-toggle" onclick="openSidebar()">☰</button>
        <div class="topbar-title">My <span>Plants</span></div>
      </div>
      <div class="topbar-right">
        <div class="live-indicator"><span class="dot dot-on"></span> Live</div>
        <div class="countdown-chip">Next auto-fetch: <strong id="countdown">10:00</strong></div>
        <span style="font-size:.7rem;color:var(--text-3);">PHT</span>
      </div>
    </header>

    <div class="page-body">

      <div class="pg-header">
        <h1><?= $greeting ?>, <?= htmlspecialchars($_SESSION['username']) ?>! 👋</h1>
        <p>Here's the current status of your succulent plants</p>
      </div>

      <?php if ($_userStatus === 'pending'): ?>
      <!-- ── PENDING: waiting for manager review ── -->
      <div class="onboard-banner onboard-pending">
        <div class="ob-icon">⏳</div>
        <div class="ob-body">
          <div class="ob-title">Your account is under review</div>
          <div class="ob-desc">A Manager has been notified and will review your profile shortly. Once approved, the Admin will assign your plants.</div>
          <div class="ob-steps">
            <div class="ob-step ob-done"><div class="ob-dot">✓</div><span>Registered</span></div>
            <div class="ob-line ob-line-done"></div>
            <div class="ob-step ob-active"><div class="ob-dot">2</div><span>Manager Review</span></div>
            <div class="ob-line"></div>
            <div class="ob-step"><div class="ob-dot">3</div><span>Plant Assignment</span></div>
            <div class="ob-line"></div>
            <div class="ob-step"><div class="ob-dot">4</div><span>Active</span></div>
          </div>
        </div>
      </div>
      <?php elseif ($_userStatus === 'recommended'): ?>
      <!-- ── RECOMMENDED: waiting for admin plant assignment ── -->
      <div class="onboard-banner onboard-recommended">
        <div class="ob-icon">📋</div>
        <div class="ob-body">
          <div class="ob-title">Recommended — awaiting plant assignment</div>
          <div class="ob-desc">Great news! A Manager has reviewed and recommended your account. The Admin has been notified and will assign your plants shortly.</div>
          <div class="ob-steps">
            <div class="ob-step ob-done"><div class="ob-dot">✓</div><span>Registered</span></div>
            <div class="ob-line ob-line-done"></div>
            <div class="ob-step ob-done"><div class="ob-dot">✓</div><span>Manager Review</span></div>
            <div class="ob-line ob-line-done"></div>
            <div class="ob-step ob-active-blue"><div class="ob-dot">3</div><span>Plant Assignment</span></div>
            <div class="ob-line"></div>
            <div class="ob-step"><div class="ob-dot">4</div><span>Active</span></div>
          </div>
        </div>
      </div>
      <?php elseif (empty($plants)): ?>
      <div class="card" style="text-align:center;padding:28px 20px;">
        <div style="font-size:2.2rem;margin-bottom:10px;">🪴</div>
        <div style="font-family:var(--font-d);font-size:.95rem;font-weight:700;margin-bottom:5px;">No plants assigned yet</div>
        <p style="font-size:.77rem;color:var(--text-3);">Your account is active. The Admin will assign your plants shortly.</p>
      </div>
      <?php endif; ?>

      <?php if (!empty($plants)): ?>

      <!-- Summary Stats -->
      <div class="stats-grid">
        <div class="stat-card stat-plants"><div class="stat-num"><?= count($plants) ?></div><div class="stat-label">🪴 My Plants</div></div>
        <div class="stat-card stat-total"><div class="stat-num"><?= $sumTotal ?></div><div class="stat-label">📊 Total Readings</div></div>
        <div class="stat-card stat-dry"><div class="stat-num"><?= $sumDry ?></div><div class="stat-label">🏜️ Dry</div></div>
        <div class="stat-card stat-ideal"><div class="stat-num"><?= $sumIdeal ?></div><div class="stat-label">✅ Ideal</div></div>
        <div class="stat-card stat-humid"><div class="stat-num"><?= $sumHumid ?></div><div class="stat-label">💧 Humid</div></div>
      </div>

      <!-- Combined Chart -->
      <?php if ($sumTotal > 0): ?>
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title">📈 Combined Humidity Trend</div>
            <div class="card-subtitle">All plants over time · Dry zone &lt;20% · Humid zone &gt;60%</div>
          </div>
          <div class="chart-legend" id="combinedLegend">
            <?php foreach ($plantData as $pd): ?>
            <div class="legend-item">
              <div class="legend-dot" style="background:<?= $plantColorMap[$pd['plant']['plant_id']] ?>"></div>
              <?= htmlspecialchars($pd['plant']['plant_name']) ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div style="height:220px;"><canvas id="combinedChart"></canvas></div>
      </div>
      <?php endif; ?>

      <!-- Per-Plant Cards -->
      <?php foreach ($plantData as $idx => $pd):
        $plant       = $pd['plant'];
        $latest      = $pd['latest'];
        $stats       = $pd['stats'];
        $pid         = (int)$plant['plant_id'];
        $statusClass = $latest ? strtolower($latest['status']) : 'none';
        $pColor      = $plantColorMap[$pid];
      ?>
      <div class="plant-card plant-border-<?= $statusClass ?>" id="card-<?= $pid ?>">

        <div class="plant-header">
          <div>
            <div class="plant-name">🪴 <?= htmlspecialchars($plant['plant_name']) ?></div>
            <div class="plant-meta">
              <span>📍 <?= htmlspecialchars($plant['city']) ?></span>
              <span>Device #<?= $pid ?></span>
            </div>
          </div>
          <div class="reading-block" id="header-reading-<?= $pid ?>">
            <?php if ($latest): ?>
            <div class="reading-value reading-<?= $statusClass ?>"><?= $latest['humidity_percent'] ?><span class="reading-unit">%</span></div>
            <span class="badge badge-<?= $statusClass ?> badge-lg"><?= $latest['status'] ?></span>
            <?php else: ?>
            <div class="reading-empty">No reading yet</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="plant-mini-stats">
          <div class="mini-stat"><span class="mini-val" id="stat-total-<?= $pid ?>"><?= $stats['total'] ?></span><span class="mini-lbl">Total</span></div>
          <div class="mini-stat mini-dry"><span class="mini-val" id="stat-dry-<?= $pid ?>"><?= $stats['dry'] ?></span><span class="mini-lbl">Dry</span></div>
          <div class="mini-stat mini-ideal"><span class="mini-val" id="stat-ideal-<?= $pid ?>"><?= $stats['ideal'] ?></span><span class="mini-lbl">Ideal</span></div>
          <div class="mini-stat mini-humid"><span class="mini-val" id="stat-humid-<?= $pid ?>"><?= $stats['humid'] ?></span><span class="mini-lbl">Humid</span></div>
        </div>

        <div id="reading-info-<?= $pid ?>">
          <?php if ($latest): ?>
          <div class="weather-info">
            <span>📍 <?= htmlspecialchars($plant['city']) ?></span>
            <span>🕐 <?= date('M d, Y H:i:s', strtotime($latest['recorded_at'])) ?> (PHT)</span>
          </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($pd['chartPoints'])): ?>
        <div class="chart-wrap">
          <div class="chart-header">
            <span class="chart-title">Humidity History</span>
            <span class="chart-count"><?= count($pd['chartPoints']) ?> readings</span>
          </div>
          <div style="height:110px;"><canvas id="chart-<?= $pid ?>"></canvas></div>
        </div>
        <?php endif; ?>

        <div id="tips-<?= $pid ?>">
          <?php if ($latest && isset($tips[$latest['status']])): ?>
          <div class="plant-tips plant-tips-<?= $statusClass ?>">
            <?php foreach ($tips[$latest['status']] as $tip): ?>
            <div class="plant-tip"><?= $tip ?></div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <div style="margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <button id="fetchBtn-<?= $pid ?>" class="btn btn-primary" onclick="fetchReading(<?= $pid ?>)">
            🌐 Fetch Live Reading
          </button>
          <div style="flex:1;min-width:120px;">
            <div class="sim-status-text" id="fetchStatus-<?= $pid ?>"></div>
            <div class="auto-fetch-bar"><div class="auto-fetch-progress" id="autoProgress-<?= $pid ?>" style="width:100%;"></div></div>
          </div>
        </div>

      </div>
      <?php endforeach; ?>
      <?php endif; // end !empty($plants) ?>

      <!-- Records Table -->
      <div class="card" id="records-section">
        <div class="card-header">
          <div>
            <div class="card-title">📋 All Detection Records <span class="user-count"><?= count($allLogs) ?></span></div>
            <div class="card-subtitle">Combined readings from all your devices · PHT (UTC+8)</div>
          </div>
        </div>
        <?php if (empty($allLogs)): ?>
          <p class="empty-msg">No records yet. Click "Fetch Live Reading" on any plant above.</p>
        <?php else: ?>
        <div class="table-wrap">
          <table class="det-table">
            <thead><tr><th>#</th><th>Plant</th><th>Humidity %</th><th>Status</th><th>Date &amp; Time (PHT)</th></tr></thead>
            <tbody>
              <?php foreach ($allLogs as $i => $log): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td>🪴 <?= htmlspecialchars($log['plant_name']) ?></td>
                <td><strong><?= $log['humidity_percent'] ?>%</strong></td>
                <td><span class="badge badge-<?= strtolower($log['status']) ?>"><?= $log['status'] ?></span></td>
                <td><?= date('M d, Y H:i:s', strtotime($log['recorded_at'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

<script>
const FONT     = 'Instrument Sans';
const gridClr  = 'rgba(0,0,0,.05)';
const STATUS_PT = { Dry:'#c0430e', Ideal:'#1a6e3c', Humid:'#1656a3' };
const tipsData  = {
  Dry:   ['💧 Water immediately — give a thorough soak and let it drain fully.','☀️ Too much direct sun speeds up drying — consider partial shade.','🪴 Check if soil is pulling away from the pot edges.'],
  Ideal: ['✅ Perfect conditions! Maintain your current watering schedule.','🌿 A diluted succulent fertiliser will boost growth right now.','🔄 Rotate the pot a quarter turn weekly for even sun exposure.'],
  Humid: ['🚫 Stop watering until the top 2 cm of soil is completely dry.','💨 Move to a well-ventilated area to reduce excess moisture.','🪨 Ensure the pot has drainage holes to prevent root rot.'],
};

const plantDatasetIndex = {};
<?php foreach ($plantData as $i => $pd): ?>
plantDatasetIndex[<?= $pd['plant']['plant_id'] ?>] = <?= $i + 2 ?>;
<?php endforeach; ?>

// ── Combined chart ──────────────────────────────────────────────────────────
const PALETTE = <?= json_encode(array_values($plantColorMap)) ?>;
const allPlantData = <?= json_encode(array_values(array_map(fn($pd) => [
    'pid'    => $pd['plant']['plant_id'],
    'name'   => $pd['plant']['plant_name'],
    'points' => $pd['chartPoints'],
], $plantData))) ?>;

window._combinedTs = [];
let combinedChart  = null;

(function buildCombinedChart() {
  const tsSet = new Set();
  allPlantData.forEach(pd => pd.points.forEach(p => tsSet.add(p.ts)));
  const allTs = Array.from(tsSet).sort((a,b) => a-b);
  window._combinedTs = allTs;

  const labels = allTs.map(ts => {
    const d = new Date(ts*1000);
    return d.toLocaleString('en-PH',{month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit',hour12:false,timeZone:'Asia/Manila'});
  });

  const zoneDry   = {label:'_dry',   data:allTs.map(()=>20),  fill:{target:'origin',above:'rgba(192,67,14,.04)'},   borderWidth:.7,borderColor:'rgba(192,67,14,.15)', borderDash:[4,4],pointRadius:0,tension:0};
  const zoneHumid = {label:'_humid', data:allTs.map(()=>100), fill:{target:{value:60},above:'rgba(22,86,163,.04)'}, borderWidth:.7,borderColor:'rgba(22,86,163,.15)',borderDash:[4,4],pointRadius:0,tension:0};

  const plantDatasets = allPlantData.map((pd, i) => {
    const col  = PALETTE[i] || '#1a6e3c';
    const tsMap = {};
    pd.points.forEach(p => tsMap[p.ts] = p);
    const data     = allTs.map(ts => tsMap[ts]?.value ?? null);
    const ptColors = allTs.map(ts => STATUS_PT[tsMap[ts]?.status] ?? col);
    return {
      label:pd.name, data, borderColor:col, backgroundColor:col+'12',
      borderWidth:2, fill:false, tension:0.35, spanGaps:true,
      pointRadius: allTs.length>60?2:4, pointHoverRadius:6,
      pointBackgroundColor:ptColors, pointBorderColor:'#fff', pointBorderWidth:1.5,
    };
  });

  const el = document.getElementById('combinedChart');
  if (el) {
    combinedChart = new Chart(el, {
      type:'line',
      data:{labels, datasets:[zoneDry,zoneHumid,...plantDatasets]},
      options:{
        responsive:true, maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{
          legend:{display:false},
          tooltip:{
            filter:item=>!item.dataset.label.startsWith('_'),
            callbacks:{label:ctx=>ctx.parsed.y!==null?` ${ctx.dataset.label}: ${ctx.parsed.y}%`:null}
          }
        },
        scales:{
          x:{ticks:{font:{size:9,family:FONT},maxTicksLimit:10,maxRotation:30,autoSkip:true},grid:{color:gridClr}},
          y:{min:0,max:100,ticks:{font:{size:9,family:FONT},callback:v=>v+'%',stepSize:20},grid:{color:gridClr}},
        },
      },
    });
    window._combinedChart = combinedChart;
  }
})();

// ── Per-plant mini charts ───────────────────────────────────────────────────
<?php foreach ($plantData as $pd):
  $pid = (int)$pd['plant']['plant_id'];
  if (empty($pd['chartPoints'])) continue;
?>
(function() {
  const pts    = <?= json_encode($pd['chartPoints']) ?>;
  const col    = '<?= $plantColorMap[$pid] ?>';
  const labels = pts.map(p => p.label);
  const values = pts.map(p => p.value);
  const ptCols = pts.map(p => STATUS_PT[p.status] || col);
  new Chart(document.getElementById('chart-<?= $pid ?>'), {
    type:'line',
    data:{labels, datasets:[{
      data:values, borderColor:col, backgroundColor:col+'18',
      borderWidth:2, fill:true, tension:0.35,
      pointRadius:pts.length>20?2:3,
      pointBackgroundColor:ptCols, pointBorderColor:'#fff', pointBorderWidth:1,
    }]},
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>` ${ctx.parsed.y}%`}}},
      scales:{
        x:{ticks:{font:{size:8,family:FONT},maxTicksLimit:8,maxRotation:30,autoSkip:true},grid:{color:'rgba(0,0,0,.04)'}},
        y:{min:0,max:100,ticks:{font:{size:8,family:FONT},callback:v=>v+'%',stepSize:25},grid:{color:'rgba(0,0,0,.04)'}},
      },
    },
  });
})();
<?php endforeach; ?>

// ── Manual fetch ────────────────────────────────────────────────────────────
function fetchReading(pid) {
  const btn    = document.getElementById('fetchBtn-'+pid);
  const status = document.getElementById('fetchStatus-'+pid);
  btn.disabled = true; btn.innerHTML = '⏳ Fetching…'; status.textContent = '';

  const form = new FormData();
  form.append('plant_id', pid);

  fetch('simulate.php', {method:'POST', body:form})
    .then(r => r.json())
    .then(data => {
      btn.disabled = false; btn.innerHTML = '🌐 Fetch Live Reading';
      if (!data.success) { status.textContent='⚠️ '+data.error; status.style.color='var(--dry)'; return; }

      status.textContent = '✅ Saved at ' + data.detected_at + ' (PHT)';
      status.style.color = 'var(--ideal)';

      const s = data.status.toLowerCase();
      const card = document.getElementById('card-'+pid);
      card.className = card.className.replace(/plant-border-\w+/, 'plant-border-'+s);

      document.getElementById('header-reading-'+pid).innerHTML =
        `<div class="reading-value reading-${s}">${data.humidity}<span class="reading-unit">%</span></div>
         <span class="badge badge-${s} badge-lg">${data.status}</span>`;

      document.getElementById('reading-info-'+pid).innerHTML =
        `<div class="weather-info">
           <span>📍 ${data.city}</span>
           <span>🌤️ ${data.description}</span>
           <span>🕐 ${data.detected_at} (PHT)</span>
         </div>`;

      document.getElementById('stat-total-'+pid).textContent = data.total;
      document.getElementById('stat-dry-'+pid).textContent   = data.dry;
      document.getElementById('stat-ideal-'+pid).textContent = data.ideal;
      document.getElementById('stat-humid-'+pid).textContent = data.humid;

      const ts    = Math.floor(new Date(data.detected_at.replace(' ','T')+'+08:00').getTime()/1000);
      const label = data.detected_at.slice(5,16);
      pushToCombinedChart(pid, ts, label, data.humidity, data.status);

      document.getElementById('tips-'+pid).innerHTML =
        `<div class="plant-tips plant-tips-${s}">` +
        (tipsData[data.status]||[]).map(t=>`<div class="plant-tip">${t}</div>`).join('') + `</div>`;

      const ri = document.getElementById('reading-info-'+pid);
      ri.classList.add('pulse'); setTimeout(()=>ri.classList.remove('pulse'), 600);
    })
    .catch(() => {
      btn.disabled=false; btn.innerHTML='🌐 Fetch Live Reading';
      status.textContent='⚠️ Server error. Try again.'; status.style.color='var(--dry)';
    });
}

function pushToCombinedChart(pid, ts, label, value, status) {
  const chart = window._combinedChart;
  if (!chart) return;
  const dsIdx = plantDatasetIndex[pid];
  if (dsIdx === undefined) return;
  const tsArr = window._combinedTs;
  const existIdx = tsArr.indexOf(ts);
  if (existIdx === -1) {
    let insertAt = tsArr.length;
    for (let i=0;i<tsArr.length;i++) { if (tsArr[i]>ts) { insertAt=i; break; } }
    tsArr.splice(insertAt,0,ts);
    chart.data.labels.splice(insertAt,0,label);
    chart.data.datasets[0].data.splice(insertAt,0,20);
    chart.data.datasets[1].data.splice(insertAt,0,100);
    chart.data.datasets.forEach((ds,i) => { if(i>=2) ds.data.splice(insertAt,0,null); });
  }
  const labelIdx = tsArr.indexOf(ts);
  const ds = chart.data.datasets[dsIdx];
  ds.data[labelIdx] = value;
  if (Array.isArray(ds.pointBackgroundColor)) ds.pointBackgroundColor[labelIdx] = STATUS_PT[status] || '#9ca3af';
  chart.update();
}

// ── Auto-fetch every 10 minutes ─────────────────────────────────────────────
const AUTO_SEC  = 10 * 60; // ← 10 minutes
const plantIds  = <?= json_encode(array_column($plants, 'plant_id')) ?>;
let timeLeft    = AUTO_SEC;

function tick() {
  if (timeLeft <= 0) {
    timeLeft = AUTO_SEC;
    plantIds.forEach(pid => fetchReading(pid));
  } else {
    timeLeft--;
  }
  const m  = Math.floor(timeLeft / 60);
  const s  = timeLeft % 60;
  const el = document.getElementById('countdown');
  if (el) el.textContent = `${m}:${String(s).padStart(2,'0')}`;

  const pct = ((AUTO_SEC - timeLeft) / AUTO_SEC) * 100;
  plantIds.forEach(pid => {
    const bar = document.getElementById('autoProgress-'+pid);
    if (bar) bar.style.width = (100 - pct) + '%';
  });
}
setInterval(tick, 1000);
tick();
</script>
</body>
</html>
