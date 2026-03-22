<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header("Location: index.php"); exit;
}
require 'config.php';

$msg = "";

// Handle "Recommend to Admin" action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recommend_uid'])) {
    $ruid  = intval($_POST['recommend_uid']);
    $rname = $pdo->prepare("SELECT username FROM users WHERE user_id=? AND status='pending' AND role='user'");
    $rname->execute([$ruid]);
    $rrow = $rname->fetch();
    if ($rrow) {
        $pdo->prepare("UPDATE users SET status='recommended' WHERE user_id=?")->execute([$ruid]);
        // Mark manager notification for this user as read
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE for_role='manager' AND ref_user_id=?")->execute([$ruid]);
        // Fire admin notification
        notify_admins_recommended($pdo, $ruid, $rrow['username'], $_SESSION['username']);
        $msg = "✅ @{$rrow['username']} has been recommended to the Admin for plant assignment.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_plant_id'])) {
    $pid  = intval($_POST['edit_plant_id']);
    $name = trim($_POST['plant_name']);
    $city = trim($_POST['plant_city']);
    if ($name && $city) {
        $pdo->prepare("UPDATE plants SET plant_name=?, city=? WHERE plant_id=?")->execute([$name,$city,$pid]);
        $msg = "Plant updated successfully.";
    }
}

$users = $pdo->query("
    SELECT u.user_id, u.username, u.email, u.created_at, u.status,
           COUNT(p.plant_id) AS plant_count
    FROM users u LEFT JOIN plants p ON u.user_id = p.user_id
    WHERE u.role='user' GROUP BY u.user_id ORDER BY u.username ASC
")->fetchAll();

// Pending users awaiting manager review
$pendingUsers = $pdo->query("
    SELECT user_id, username, email, created_at
    FROM users WHERE role='user' AND status='pending'
    ORDER BY created_at ASC
")->fetchAll();
$pendingCount = count($pendingUsers);

$plants = $pdo->query("
    SELECT p.plant_id, p.plant_name, p.city, p.latitude, p.longitude, p.created_at,
           u.username, u.user_id,
           h.humidity_percent, h.status, h.recorded_at AS last_reading
    FROM plants p JOIN users u ON p.user_id = u.user_id
    LEFT JOIN humidity h ON h.humidity_id = (
        SELECT h2.humidity_id FROM humidity h2 WHERE h2.plant_id = p.plant_id ORDER BY h2.recorded_at DESC LIMIT 1
    )
    ORDER BY u.username, p.plant_id
")->fetchAll();

$counts = $pdo->query("SELECT status, COUNT(*) as total FROM humidity GROUP BY status")->fetchAll();
$stats  = array_column($counts, 'total', 'status');
$total  = array_sum(array_column($counts, 'total'));

$readings = $pdo->query("
    SELECT h.humidity_id, h.plant_id, p.plant_name, u.username,
           h.humidity_percent, h.status, h.recorded_at
    FROM humidity h
    LEFT JOIN plants p ON h.plant_id = p.plant_id
    LEFT JOIN users u  ON p.user_id  = u.user_id
    ORDER BY h.recorded_at DESC LIMIT 100
")->fetchAll();

$plantReadingCounts = [];
foreach ($plants as $p) {
    $c = $pdo->prepare("SELECT COUNT(*) FROM humidity WHERE plant_id=?");
    $c->execute([$p['plant_id']]);
    $plantReadingCounts[$p['plant_id']] = (int)$c->fetchColumn();
}

$PALETTE = ['#0d7c6b','#1656a3','#c0430e','#8b5cf6','#d97706','#1a6e3c','#db2777'];
$plantColorMap  = [];
$plantChartData = [];
foreach ($plants as $i => $p) {
    $pid = (int)$p['plant_id'];
    $plantColorMap[$pid] = $PALETTE[$i % count($PALETTE)];
    $cq = $pdo->prepare("
        SELECT humidity_percent, status,
               UNIX_TIMESTAMP(recorded_at) AS ts, recorded_at
        FROM humidity WHERE plant_id=? ORDER BY recorded_at DESC LIMIT 50
    ");
    $cq->execute([$pid]);
    $rows = array_reverse($cq->fetchAll());
    $plantChartData[] = [
        'pid'    => $pid,
        'name'   => $p['plant_name'],
        'points' => array_map(fn($r) => [
            'ts'    => (int)$r['ts'],
            'label' => date('M d H:i', (int)$r['ts']),
            'value' => (float)$r['humidity_percent'],
            'status'=> $r['status'],
        ], $rows),
    ];
}

$activePage = 'manager_dashboard';
$_unreadBadge = get_unread_count($pdo, 'manager');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manager Dashboard – SuccuTrack</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body class="role-manager">
<div class="app-layout">
  <?php include 'sidebar.php'; ?>

  <div class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <button class="sb-toggle" onclick="openSidebar()">☰</button>
        <div class="topbar-title">Manager <span>Dashboard</span></div>
      </div>
      <div class="topbar-right">
        <?php if ($_unreadBadge > 0): ?>
        <a href="manager_dashboard.php?open=panel-newusers"
           style="display:inline-flex;align-items:center;gap:5px;background:#fffbeb;border:1px solid #fcd34d;border-radius:20px;padding:3px 11px;font-size:.69rem;font-weight:700;color:#92400e;text-decoration:none;">
          🔔 <?= $_unreadBadge ?> new user<?= $_unreadBadge > 1 ? 's' : '' ?>
        </a>
        <?php endif; ?>
        <div class="live-indicator"><span class="dot dot-on"></span> Live</div>
        <span style="font-size:.68rem;color:var(--text-3);">PHT (UTC+8)</span>
      </div>
    </header>

    <div class="page-body">
      <?php if ($msg): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <div class="pg-header">
        <h1>System Monitor</h1>
        <p>Plants, users, humidity readings, map and analytics</p>
      </div>

      <!-- Stats -->
      <div class="stats-grid">
        <div class="stat-card stat-total"><div class="stat-num"><?= $total ?></div><div class="stat-label">📊 Readings</div></div>
        <div class="stat-card stat-dry"><div class="stat-num"><?= $stats['Dry']??0 ?></div><div class="stat-label">🏜️ Dry</div></div>
        <div class="stat-card stat-ideal"><div class="stat-num"><?= $stats['Ideal']??0 ?></div><div class="stat-label">✅ Ideal</div></div>
        <div class="stat-card stat-humid"><div class="stat-num"><?= $stats['Humid']??0 ?></div><div class="stat-label">💧 Humid</div></div>
        <div class="stat-card stat-plants"><div class="stat-num"><?= count($plants) ?></div><div class="stat-label">🪴 Plants</div></div>
        <div class="stat-card stat-users"><div class="stat-num"><?= count($users) ?></div><div class="stat-label">👤 Users</div></div>
      </div>

      <!-- Coverage Map — FIX: id is only "map-section" (single id) -->
      <div class="card" id="map-section">
        <div class="card-header">
          <div>
            <div class="card-title">🗺️ Coverage Map</div>
            <div class="card-subtitle">IoT device locations within Manolo Fortich</div>
          </div>
          <div class="coverage-info" style="margin:0;padding:6px 12px;font-size:.75rem;">
            📍 <?= count($plants) ?> plants · <?= count(array_unique(array_column($plants,'city'))) ?> area(s)
          </div>
        </div>
        <div id="manager-map"></div>
        <div class="map-legend">
          <span><span class="legend-dot" style="background:#c0430e"></span>Dry</span>
          <span><span class="legend-dot" style="background:#1a6e3c"></span>Ideal</span>
          <span><span class="legend-dot" style="background:#1656a3"></span>Humid</span>
          <span><span class="legend-dot" style="background:#94a3b8"></span>No data</span>
        </div>
      </div>

      <!-- Data Card — FIX: analytics panel has ONE id only -->
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title">📋 Data Management</div>
            <div class="card-subtitle">Plants · Users · Readings · Analytics · PHT (UTC+8)</div>
          </div>
        </div>

        <div class="section-tabs">
          <button class="stab <?= $pendingCount > 0 ? 'stab-notif' : '' ?>"
                  onclick="showPanel('panel-newusers',this)"
                  style="position:relative;">
            🔔 New Users
            <?php if ($pendingCount > 0): ?>
            <span style="position:absolute;top:-5px;right:-5px;min-width:16px;height:16px;padding:0 4px;border-radius:10px;background:#f59e0b;color:#fff;font-size:.58rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;line-height:1;"><?= $pendingCount ?></span>
            <?php endif; ?>
          </button>
          <button class="stab active" onclick="showPanel('panel-plants',   this)">🪴 Plants (<?= count($plants) ?>)</button>
          <button class="stab"        onclick="showPanel('panel-users',    this)">👤 Users (<?= count($users) ?>)</button>
          <button class="stab"        onclick="showPanel('panel-humidity', this)">💧 Readings (<?= count($readings) ?>)</button>
          <button class="stab"        onclick="showPanel('panel-analytics',this)" id="analyticsTabBtn">📊 Analytics</button>
        </div>

        <!-- New Users panel -->
        <div class="spanel" id="panel-newusers">
          <?php if (empty($pendingUsers)): ?>
          <div style="text-align:center;padding:26px 0;">
            <div style="font-size:1.8rem;margin-bottom:8px;">✅</div>
            <div style="font-size:.86rem;font-weight:600;color:var(--text);margin-bottom:4px;">No pending users</div>
            <p style="font-size:.74rem;color:var(--text-3);">All new registrations have been reviewed.</p>
          </div>
          <?php else: ?>
          <div class="onboard-info-bar onboard-info-mgr">
            📋 <strong><?= $pendingCount ?> user<?= $pendingCount > 1 ? 's' : '' ?></strong> registered and awaiting your review.
            Click <strong>Recommend to Admin</strong> to forward them for plant assignment.
          </div>
          <div class="table-wrap">
            <table class="det-table">
              <thead>
                <tr><th>#</th><th>Username</th><th>Email</th><th>Registered (PHT)</th><th>Status</th><th>Action</th></tr>
              </thead>
              <tbody>
                <?php foreach ($pendingUsers as $pu): ?>
                <tr>
                  <td><?= $pu['user_id'] ?></td>
                  <td><strong>@<?= htmlspecialchars($pu['username']) ?></strong></td>
                  <td><?= htmlspecialchars($pu['email']) ?></td>
                  <td><?= date('M d, Y H:i', strtotime($pu['created_at'])) ?></td>
                  <td><span class="status-pill pill-pending">⏳ Pending Review</span></td>
                  <td>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="recommend_uid" value="<?= $pu['user_id'] ?>">
                      <button type="submit" class="btn btn-primary" style="font-size:.7rem;padding:4px 11px;"
                              onclick="return confirm('Recommend @<?= htmlspecialchars($pu['username'], ENT_QUOTES) ?> to the Admin for plant assignment?')">
                        ✅ Recommend to Admin
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

        <!-- Plants panel -->
        <div class="spanel active" id="panel-plants">
          <?php if (empty($plants)): ?>
            <p class="empty-msg">No plants found.</p>
          <?php else: ?>
          <div class="table-wrap">
            <table class="det-table">
              <thead><tr><th>#</th><th>Plant</th><th>Owner</th><th>City</th><th>Humidity</th><th>Status</th><th>Last Reading (PHT)</th><th>Edit</th></tr></thead>
              <tbody>
                <?php foreach ($plants as $p): ?>
                <tr>
                  <td><?= $p['plant_id'] ?></td>
                  <td><strong>🪴 <?= htmlspecialchars($p['plant_name']) ?></strong></td>
                  <td><?= htmlspecialchars($p['username']) ?></td>
                  <td><?= htmlspecialchars($p['city']) ?></td>
                  <td><?= $p['humidity_percent'] ? '<strong>'.$p['humidity_percent'].'%</strong>' : '—' ?></td>
                  <td><?php if ($p['status']): ?><span class="badge badge-<?= strtolower($p['status']) ?>"><?= $p['status'] ?></span><?php else: ?>—<?php endif; ?></td>
                  <td><?= $p['last_reading'] ? date('M d, Y H:i', strtotime($p['last_reading'])) : '—' ?></td>
                  <td>
                    <button class="btn btn-sm" onclick="toggleEdit(<?= $p['plant_id'] ?>,'<?= htmlspecialchars($p['plant_name'],ENT_QUOTES) ?>','<?= htmlspecialchars($p['city'],ENT_QUOTES) ?>')">✏️ Edit</button>
                  </td>
                </tr>
                <tr id="edit-row-<?= $p['plant_id'] ?>" style="display:none;background:var(--sf2);">
                  <td colspan="8" style="padding:12px 14px;">
                    <form method="POST" class="edit-form">
                      <input type="hidden" name="edit_plant_id" value="<?= $p['plant_id'] ?>">
                      <input type="text" name="plant_name" id="edit-name-<?= $p['plant_id'] ?>" placeholder="Plant name" required>
                      <input type="text" name="plant_city" id="edit-city-<?= $p['plant_id'] ?>" placeholder="City" required>
                      <button type="submit" class="btn btn-primary">Save</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

        <!-- Users -->
        <div class="spanel" id="panel-users">
          <?php if (empty($users)): ?><p class="empty-msg">No users found.</p><?php else: ?>
          <div class="table-wrap">
            <table class="det-table">
              <thead><tr><th>#</th><th>Username</th><th>Email</th><th>Plants</th><th>Status</th><th>Joined (PHT)</th></tr></thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                  <td><?= $u['user_id'] ?></td>
                  <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                  <td><?= htmlspecialchars($u['email']) ?></td>
                  <td><?= $u['plant_count'] ?></td>
                  <td><?php
                    $st = $u['status'] ?? 'active';
                    $pillMap = ['pending'=>'pill-pending','recommended'=>'pill-recommended','active'=>'pill-active'];
                    $lblMap  = ['pending'=>'⏳ Pending','recommended'=>'📋 Recommended','active'=>'✅ Active'];
                    echo '<span class="status-pill '.($pillMap[$st]??'pill-active').'">'.($lblMap[$st]??ucfirst($st)).'</span>';
                  ?></td>
                  <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

        <!-- Humidity -->
        <div class="spanel" id="panel-humidity">
          <?php if (empty($readings)): ?><p class="empty-msg">No readings yet.</p><?php else: ?>
          <div class="table-wrap">
            <table class="det-table">
              <thead><tr><th>#</th><th>Plant</th><th>Owner</th><th>Humidity %</th><th>Status</th><th>Recorded At (PHT)</th></tr></thead>
              <tbody>
                <?php foreach ($readings as $i => $r): ?>
                <tr>
                  <td><?= $i+1 ?></td>
                  <td><?= htmlspecialchars($r['plant_name']??'—') ?></td>
                  <td><?= htmlspecialchars($r['username']??'—') ?></td>
                  <td><strong><?= $r['humidity_percent'] ?>%</strong></td>
                  <td><span class="badge badge-<?= strtolower($r['status']) ?>"><?= $r['status'] ?></span></td>
                  <td><?= date('M d, Y H:i', strtotime($r['recorded_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

        <!-- Analytics — FIX: single id="panel-analytics" only -->
        <div class="spanel" id="panel-analytics">
          <div class="two-col" style="margin-bottom:12px;">
            <div class="chart-wrap" style="margin:0;">
              <div class="chart-header">
                <span class="chart-title">🍩 Status Distribution</span>
                <span class="chart-count">All-time readings</span>
              </div>
              <div style="height:180px;"><canvas id="donut-chart"></canvas></div>
            </div>
            <div class="chart-wrap" style="margin:0;">
              <div class="chart-header">
                <span class="chart-title">📊 Current Humidity per Plant</span>
                <span class="chart-count">Latest reading, coloured by status</span>
              </div>
              <div style="height:180px;"><canvas id="bar-chart"></canvas></div>
            </div>
          </div>
          <div class="chart-wrap">
            <div class="chart-header">
              <span class="chart-title">📈 Humidity Trend by Plant</span>
              <span class="chart-count">Actual humidity % over time (last 50 readings each)</span>
            </div>
            <div class="chart-legend" id="trendLegend" style="margin-bottom:8px;"></div>
            <div style="height:200px;"><canvas id="trend-chart"></canvas></div>
          </div>
        </div>

      </div><!-- /card -->
    </div><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app-layout -->

<script>
// ── Coverage Map ─────────────────────────────────────────────────────────────
const MANOLO_POLYGON = [
  [8.4450,124.7820],[8.4600,124.8050],[8.4720,124.8280],[8.4700,124.8520],
  [8.4580,124.8760],[8.4380,124.9000],[8.4150,124.9200],[8.3900,124.9350],
  [8.3650,124.9400],[8.3380,124.9300],[8.3100,124.9150],[8.2880,124.8950],
  [8.2600,124.8750],[8.2215,124.8490],[8.2380,124.8200],[8.2620,124.7980],
  [8.2880,124.7780],[8.3150,124.7600],[8.3420,124.7480],[8.3700,124.7520],
  [8.3980,124.7680],[8.4250,124.7750],[8.4450,124.7820]
];
const managerMap = L.map('manager-map');
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap',maxZoom:18}).addTo(managerMap);
const polyLayer = L.polygon(MANOLO_POLYGON,{color:'#0d7c6b',weight:2.5,opacity:.9,fillColor:'#0d7c6b',fillOpacity:.04,dashArray:'7,5'}).addTo(managerMap).bindTooltip('Manolo Fortich, Bukidnon',{direction:'center'});
const statusColors = {dry:'#c0430e',ideal:'#1a6e3c',humid:'#1656a3','':"#94a3b8"};
(function plotPlants(){
  const plants = <?= json_encode($plants) ?>;
  let bounds = [];
  plants.forEach(p=>{
    if(!p.latitude||!p.longitude) return;
    const lat=parseFloat(p.latitude),lng=parseFloat(p.longitude);
    bounds.push([lat,lng]);
    const s=(p.status||'').toLowerCase(),color=statusColors[s]||'#94a3b8';
    const hum=p.humidity_percent?`${p.humidity_percent}%`:'No data';
    const time=p.last_reading?new Date(p.last_reading).toLocaleString('en-PH',{timeZone:'Asia/Manila',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',hour12:false}):'N/A';
    const icon=L.divIcon({className:'',html:`<div style="background:${color};width:20px;height:20px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:2.5px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3);"></div>`,iconSize:[20,20],iconAnchor:[10,20]});
    L.marker([lat,lng],{icon}).addTo(managerMap).bindPopup(`<div style="min-width:140px;font-family:'Instrument Sans',sans-serif;"><strong>🪴 ${p.plant_name}</strong><br><small style="color:#64748b;">👤 ${p.username}</small><br><div style="margin-top:5px;font-size:.95rem;font-weight:700;color:${color};">${hum}</div><span style="display:inline-block;padding:1px 7px;border-radius:10px;font-size:.6rem;font-weight:700;text-transform:uppercase;background:${color}22;color:${color};border:1px solid ${color}44;margin-top:2px;">${p.status||'No data'}</span><div style="font-size:.69rem;color:#94a3b8;margin-top:4px;">🕐 ${time} PHT</div></div>`);
  });
  if(bounds.length>0) managerMap.fitBounds(L.latLngBounds(bounds).pad(0.25));
  else managerMap.fitBounds(polyLayer.getBounds(),{padding:[12,12]});
})();

function toggleEdit(pid,name,city){
  const row=document.getElementById('edit-row-'+pid);
  if(!row.style.display||row.style.display==='none'){
    row.style.display='table-row';
    document.getElementById('edit-name-'+pid).value=name||'';
    document.getElementById('edit-city-'+pid).value=city||'';
  } else { row.style.display='none'; }
}

// ── Panel switching — FIX: unified function used by both stabs AND sidebar ──
function showPanel(id, btn) {
  document.querySelectorAll('.spanel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.stab').forEach(b=>b.classList.remove('active'));
  const panel = document.getElementById(id);
  if (!panel) return;
  panel.classList.add('active');
  if (btn) {
    btn.classList.add('active');
  } else {
    // Called programmatically (e.g. from sidebar) — activate matching stab
    document.querySelectorAll('.stab').forEach(b=>{
      if(b.getAttribute('onclick')&&b.getAttribute('onclick').includes("'"+id+"'")) b.classList.add('active');
    });
  }
  if (id==='panel-analytics') initCharts();
}

// ── Analytics charts ─────────────────────────────────────────────────────────
const plantColorMap  = <?= json_encode($plantColorMap) ?>;
const plantChartData = <?= json_encode($plantChartData) ?>;
const STATUS_PT = { Dry:'#c0430e', Ideal:'#1a6e3c', Humid:'#1656a3' };
const FONT = 'Instrument Sans';
const gridClr = 'rgba(0,0,0,.04)';
let chartsInited = false;

function initCharts() {
  if (chartsInited) return;
  chartsInited = true;

  // Donut
  new Chart(document.getElementById('donut-chart'),{
    type:'doughnut',
    data:{labels:['Dry','Ideal','Humid'],datasets:[{
      data:[<?= (int)($stats['Dry']??0) ?>,<?= (int)($stats['Ideal']??0) ?>,<?= (int)($stats['Humid']??0) ?>],
      backgroundColor:['#c0430e','#1a6e3c','#1656a3'],borderColor:'#fff',borderWidth:3,hoverOffset:5
    }]},
    options:{responsive:true,maintainAspectRatio:false,
      plugins:{legend:{position:'bottom',labels:{font:{size:11,family:FONT},padding:10,usePointStyle:true}}}}
  });

  // Bar — current humidity % per plant, coloured by status
  const pNames   = <?= json_encode(array_column($plants,'plant_name')) ?>;
  const pIds     = <?= json_encode(array_column($plants,'plant_id')) ?>;
  const pLatest  = <?= json_encode(array_map(fn($p)=>(float)($p['humidity_percent']??0), $plants)) ?>;
  const pStatus  = <?= json_encode(array_column($plants,'status')) ?>;
  const barCols  = pStatus.map(s=>s==='Dry'?'#c0430e':s==='Ideal'?'#1a6e3c':'#1656a3');
  new Chart(document.getElementById('bar-chart'),{
    type:'bar',
    data:{labels:pNames,datasets:[{
      label:'Humidity %',data:pLatest,
      backgroundColor:barCols.map(c=>c+'bb'),borderColor:barCols,borderWidth:1.5,borderRadius:6,
    }]},
    options:{responsive:true,maintainAspectRatio:false,
      plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>` ${c.parsed.y}% — ${pStatus[c.dataIndex]||'N/A'}`}}},
      scales:{
        y:{min:0,max:100,ticks:{font:{size:9,family:FONT},callback:v=>v+'%',stepSize:20},grid:{color:gridClr}},
        x:{ticks:{font:{size:9,family:FONT}},grid:{display:false}}
      }}
  });

  // Trend — actual humidity % per plant over time
  const tsSet=new Set();
  plantChartData.forEach(pd=>pd.points.forEach(p=>tsSet.add(p.ts)));
  const allTs=Array.from(tsSet).sort((a,b)=>a-b);
  const allLabels=allTs.map(ts=>{
    const d=new Date(ts*1000);
    return d.toLocaleString('en-PH',{month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit',hour12:false,timeZone:'Asia/Manila'});
  });
  const legendEl=document.getElementById('trendLegend');
  const plantDatasets=plantChartData.filter(pd=>pd.points.length>0).map(pd=>{
    const col=plantColorMap[pd.pid]||'#94a3b8';
    const tsMap={};
    pd.points.forEach(p=>tsMap[p.ts]=p);
    const data=allTs.map(ts=>tsMap[ts]?.value??null);
    const ptColors=allTs.map(ts=>STATUS_PT[tsMap[ts]?.status]??col);
    if(legendEl) legendEl.innerHTML+=`<div class="legend-item"><div class="legend-dot" style="background:${col}"></div>${pd.name}</div>`;
    return {label:pd.name,data,borderColor:col,backgroundColor:col+'12',borderWidth:2,fill:false,tension:0.35,spanGaps:true,pointRadius:allTs.length>60?2:4,pointHoverRadius:6,pointBackgroundColor:ptColors,pointBorderColor:'#fff',pointBorderWidth:1.5};
  });
  const zoneDry   ={label:'_dry',   data:allTs.map(()=>20),  fill:{target:'origin',above:'rgba(192,67,14,.04)'},   borderWidth:.7,borderColor:'rgba(192,67,14,.15)', borderDash:[4,4],pointRadius:0,tension:0};
  const zoneHumid ={label:'_humid', data:allTs.map(()=>100), fill:{target:{value:60},above:'rgba(22,86,163,.04)'}, borderWidth:.7,borderColor:'rgba(22,86,163,.15)',borderDash:[4,4],pointRadius:0,tension:0};
  new Chart(document.getElementById('trend-chart'),{
    type:'line',
    data:{labels:allLabels,datasets:[zoneDry,zoneHumid,...plantDatasets]},
    options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
      plugins:{legend:{display:false},tooltip:{filter:i=>!i.dataset.label.startsWith('_'),callbacks:{title:ctx=>ctx[0]?.label||'',label:ctx=>ctx.parsed.y!==null?` ${ctx.dataset.label}: ${ctx.parsed.y}%`:null}}},
      scales:{
        x:{ticks:{font:{size:9,family:FONT},maxTicksLimit:10,maxRotation:30,autoSkip:true},grid:{color:gridClr}},
        y:{min:0,max:100,ticks:{font:{size:9,family:FONT},callback:v=>v+'%',stepSize:20},grid:{color:gridClr}},
      }}
  });
}
</script>
</body>
</html>
