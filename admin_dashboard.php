<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit;
}
require 'config.php';

$msg = "";

// Mark user as active when admin assigns a plant (triggered via ?activate=uid)
if (isset($_GET['activated'])) {
    $msg = "Plants assigned and user account activated.";
}

// Load users needing admin action (pending OR recommended)
$onboardingUsers = $pdo->query("
    SELECT user_id, username, email, status, created_at,
           (SELECT COUNT(*) FROM plants WHERE user_id = users.user_id) AS plant_count
    FROM users
    WHERE role='user' AND status IN ('pending','recommended')
    ORDER BY FIELD(status,'recommended','pending'), created_at ASC
")->fetchAll();
$onboardingCount = count($onboardingUsers);

// Unread notification count for admin badge
$_adminUnread = get_unread_count($pdo, 'admin');
if (($_GET['action'] ?? '') === 'delete_log') {
    $log_id      = intval($_GET['log_id']      ?? 0);
    $humidity_id = intval($_GET['humidity_id'] ?? 0);
    if ($humidity_id) {
        if ($log_id) {
            $pdo->prepare("DELETE FROM user_logs WHERE log_id = ?")->execute([$log_id]);
        } else {
            $pdo->prepare("DELETE FROM user_logs WHERE humidity_id = ?")->execute([$humidity_id]);
        }
        $pdo->prepare("DELETE FROM humidity WHERE humidity_id = ?")->execute([$humidity_id]);
    }
    header("Location: admin_dashboard.php?deleted=1"); exit;
}
if (isset($_GET['deleted'])) $msg = "Record deleted successfully.";

// ── Core stats ─────────────────────────────────────────────────────────────
$users  = $pdo->query("SELECT user_id, username, email, role, COALESCE(status,'active') AS status, created_at FROM users ORDER BY created_at DESC")->fetchAll();
$counts = $pdo->query("SELECT status, COUNT(*) as total FROM humidity GROUP BY status")->fetchAll();
$stats  = array_column($counts, 'total', 'status');
$total  = array_sum(array_column($counts, 'total'));

$plants = $pdo->query("
    SELECT p.plant_id, p.plant_name, p.city, p.created_at,
           u.username,
           (SELECT COUNT(*) FROM humidity h WHERE h.plant_id = p.plant_id) AS reading_count,
           (SELECT h2.humidity_percent FROM humidity h2 WHERE h2.plant_id = p.plant_id ORDER BY h2.recorded_at DESC LIMIT 1) AS last_humidity,
           (SELECT h3.status FROM humidity h3 WHERE h3.plant_id = p.plant_id ORDER BY h3.recorded_at DESC LIMIT 1) AS last_status
    FROM plants p JOIN users u ON p.user_id = u.user_id
    ORDER BY p.plant_id ASC
")->fetchAll();

$humidity = $pdo->query("
    SELECT h.humidity_id, p.plant_name, u.username, h.humidity_percent, h.status, h.recorded_at
    FROM humidity h
    LEFT JOIN plants p ON h.plant_id = p.plant_id
    LEFT JOIN users u  ON p.user_id  = u.user_id
    ORDER BY h.recorded_at DESC LIMIT 200
")->fetchAll();

$logs = $pdo->query("
    SELECT ul.log_id, ul.humidity_id, u.username,
           p.plant_name, h.humidity_percent, h.status, h.recorded_at
    FROM user_logs ul
    JOIN users u    ON ul.user_id     = u.user_id
    JOIN humidity h ON ul.humidity_id = h.humidity_id
    LEFT JOIN plants p ON h.plant_id  = p.plant_id
    ORDER BY h.recorded_at DESC LIMIT 200
")->fetchAll();

// ── Humidity-based chart data (NOT log counts) ─────────────────────────────

// Global: average humidity % per day, last 30 days
$globalHumidity = $pdo->query("
    SELECT DATE(recorded_at) AS day,
           ROUND(AVG(humidity_percent),1) AS avg_pct,
           COUNT(*) AS cnt
    FROM humidity
    WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(recorded_at)
    ORDER BY day ASC
")->fetchAll();

// Per-plant: actual humidity % readings over time
$PALETTE = ['#4f63d8','#0d7c6b','#c0430e','#8b5cf6','#d97706','#059669','#db2777'];
$plantHumidityData = [];
foreach ($plants as $i => $p) {
    $pid = (int)$p['plant_id'];
    $rows = $pdo->prepare("
        SELECT humidity_percent, status,
               UNIX_TIMESTAMP(recorded_at) AS ts,
               recorded_at
        FROM humidity
        WHERE plant_id = ?
        ORDER BY recorded_at ASC LIMIT 50
    ");
    $rows->execute([$pid]);
    $plantHumidityData[] = [
        'pid'    => $pid,
        'name'   => $p['plant_name'],
        'color'  => $PALETTE[$i % count($PALETTE)],
        'total'  => (int)$p['reading_count'],
        'latest' => $p['last_humidity'],
        'status' => $p['last_status'],
        'rows'   => $rows->fetchAll(),
    ];
}

$activePage = 'admin_dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard – SuccuTrack</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/css/jquery.dataTables.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/js/jquery.dataTables.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body class="role-admin">
<div class="app-layout">
  <?php include 'sidebar.php'; ?>

  <div class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <button class="sb-toggle" onclick="openSidebar()">☰</button>
        <div class="topbar-title">Admin <span>Dashboard</span></div>
      </div>
      <div class="topbar-right">
        <?php if ($_adminUnread > 0): ?>
        <a href="admin_dashboard.php?jumptab=tab-onboarding"
           style="display:inline-flex;align-items:center;gap:5px;background:#eff6ff;border:1px solid #93c5fd;border-radius:20px;padding:3px 11px;font-size:.69rem;font-weight:700;color:#1e40af;text-decoration:none;">
          🔔 <?= $_adminUnread ?> notification<?= $_adminUnread > 1 ? 's' : '' ?>
        </a>
        <?php endif; ?>
        <div class="live-indicator"><span class="dot dot-on"></span> Live</div>
        <span style="font-size:.73rem;color:var(--text-3);">PHT (UTC+8)</span>
      </div>
    </header>

    <div class="page-body">
      <?php if ($msg): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <div class="pg-header">
        <h1>System Overview</h1>
        <p>Monitor all plants, users, humidity readings, and system logs</p>
      </div>

      <!-- Stats -->
      <div class="stats-grid">
        <div class="stat-card stat-total"><div class="stat-num"><?= $total ?></div><div class="stat-label">📊 Total Readings</div></div>
        <div class="stat-card stat-dry"><div class="stat-num"><?= $stats['Dry'] ?? 0 ?></div><div class="stat-label">🏜️ Dry</div></div>
        <div class="stat-card stat-ideal"><div class="stat-num"><?= $stats['Ideal'] ?? 0 ?></div><div class="stat-label">✅ Ideal</div></div>
        <div class="stat-card stat-humid"><div class="stat-num"><?= $stats['Humid'] ?? 0 ?></div><div class="stat-label">💧 Humid</div></div>
        <div class="stat-card stat-plants"><div class="stat-num"><?= count($plants) ?></div><div class="stat-label">🪴 Plants</div></div>
        <div class="stat-card stat-users"><div class="stat-num"><?= count($users) ?></div><div class="stat-label">👤 Users</div></div>
        <?php if ($onboardingCount > 0): ?>
        <div class="stat-card" style="border-bottom:2px solid #f59e0b;">
          <div class="stat-num" style="color:#b45309;"><?= $onboardingCount ?></div>
          <div class="stat-label">🔔 Needs Action</div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Chart row: global humidity trend + status donut -->
      <div id="charts-section" style="scroll-margin-top:60px;">
      <div class="two-col" style="margin-bottom:20px;">
        <div class="card" style="margin-bottom:0;">
          <div class="card-header">
            <div>
              <div class="card-title">📈 Global Humidity Trend</div>
              <div class="card-subtitle">Average humidity % per day · last 30 days</div>
            </div>
          </div>
          <div style="height:210px;"><canvas id="globalHumidityChart"></canvas></div>
        </div>
        <div class="card" style="margin-bottom:0;">
          <div class="card-header">
            <div>
              <div class="card-title">🍩 Status Distribution</div>
              <div class="card-subtitle">All-time readings by classification</div>
            </div>
          </div>
          <div style="height:210px;"><canvas id="statusDonut"></canvas></div>
        </div>
      </div>

      <!-- Per-plant humidity charts -->
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title">🪴 Per-Plant Humidity History</div>
            <div class="card-subtitle">Actual humidity % readings per device (last 50 each)</div>
          </div>
        </div>
        <?php if (empty($plantHumidityData)): ?>
          <p class="chart-empty">No plant data yet.</p>
        <?php else: ?>
        <!-- Summary: current humidity per plant -->
        <div style="height:200px;margin-bottom:16px;"><canvas id="plantCurrentChart"></canvas></div>
        <div class="chart-legend" style="margin-bottom:20px;">
          <?php foreach ($plantHumidityData as $pd): ?>
          <div class="legend-item">
            <div class="legend-dot" style="background:<?= $pd['color'] ?>"></div>
            <?= htmlspecialchars($pd['name']) ?>
            <?php if ($pd['latest']): ?>
              <span style="color:var(--text-3);font-size:.68rem;">(<?= $pd['latest'] ?>%
              <?php if ($pd['status']): ?>
                <span class="badge badge-<?= strtolower($pd['status']) ?>" style="font-size:.58rem;padding:1px 6px;"><?= $pd['status'] ?></span>
              <?php endif; ?>)</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <!-- Individual mini charts -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px;">
          <?php foreach ($plantHumidityData as $pd): ?>
          <div class="chart-wrap" style="margin:0;">
            <div class="chart-header">
              <span class="chart-title">🪴 <?= htmlspecialchars($pd['name']) ?></span>
              <span class="chart-count"><?= $pd['total'] ?> readings</span>
            </div>
            <?php if (empty($pd['rows'])): ?>
              <p class="chart-empty">No data yet</p>
            <?php else: ?>
            <div style="height:100px;"><canvas id="plant-humidity-<?= $pd['pid'] ?>"></canvas></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      </div><!-- /charts-section -->
      <!-- Data tables -->
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title">📋 System Data</div>
            <div class="card-subtitle">Browse and manage all records · Asia/Manila (PHT, UTC+8)</div>
          </div>
        </div>
        <div class="tab-nav">
          <button class="tab-btn <?= $onboardingCount > 0 ? '' : '' ?>" onclick="switchTab('tab-onboarding',event)"
                  style="position:relative;">
            🔔 Onboarding
            <?php if ($onboardingCount > 0): ?>
            <span style="position:absolute;top:-5px;right:-5px;min-width:16px;height:16px;padding:0 4px;border-radius:10px;background:#f59e0b;color:#fff;font-size:.58rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;"><?= $onboardingCount ?></span>
            <?php endif; ?>
          </button>
          <button class="tab-btn active" onclick="switchTab('tab-users',event)">👤 Users (<?= count($users) ?>)</button>
          <button class="tab-btn"        onclick="switchTab('tab-plants',event)">🪴 Plants (<?= count($plants) ?>)</button>
          <button class="tab-btn"        onclick="switchTab('tab-humidity',event)">💧 Readings (<?= count($humidity) ?>)</button>
          <button class="tab-btn"        onclick="switchTab('tab-logs',event)">📋 Logs (<?= count($logs) ?>)</button>
        </div>

        <!-- Onboarding panel -->
        <div class="tab-panel" id="tab-onboarding">
          <?php if (empty($onboardingUsers)): ?>
          <div style="text-align:center;padding:28px 0;">
            <div style="font-size:2rem;margin-bottom:8px;">✅</div>
            <div style="font-size:.86rem;font-weight:600;color:var(--text);margin-bottom:4px;">No pending actions</div>
            <p style="font-size:.74rem;color:var(--text-3);">All users have been processed and plants assigned.</p>
          </div>
          <?php else: ?>
          <div class="onboard-info-bar onboard-info-admin">
            📋 <strong><?= $onboardingCount ?> user<?= $onboardingCount > 1 ? 's' : '' ?></strong> need your attention.
            Users marked <strong>Recommended</strong> have been approved by a Manager — assign plants to activate them.
          </div>
          <div class="table-wrap">
            <table class="det-table">
              <thead>
                <tr><th>#</th><th>Username</th><th>Email</th><th>Registered (PHT)</th><th>Status</th><th>Plants</th><th>Action</th></tr>
              </thead>
              <tbody>
                <?php foreach ($onboardingUsers as $ou): ?>
                <tr>
                  <td><?= $ou['user_id'] ?></td>
                  <td><strong>@<?= htmlspecialchars($ou['username']) ?></strong></td>
                  <td><?= htmlspecialchars($ou['email']) ?></td>
                  <td><?= date('M d, Y H:i', strtotime($ou['created_at'])) ?></td>
                  <td><?php
                    $st = $ou['status'];
                    $pillMap = ['pending'=>'pill-pending','recommended'=>'pill-recommended'];
                    $lblMap  = ['pending'=>'⏳ Pending','recommended'=>'📋 Recommended'];
                    echo '<span class="status-pill '.($pillMap[$st]??'').'">'.($lblMap[$st]??ucfirst($st)).'</span>';
                  ?></td>
                  <td><?= $ou['plant_count'] ?> assigned</td>
                  <td>
                    <a href="manage_plants.php?user_id=<?= $ou['user_id'] ?>&username=<?= urlencode($ou['username']) ?>"
                       class="btn btn-primary" style="font-size:.7rem;padding:4px 11px;">
                      🪴 Assign Plants
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

        <div class="tab-panel active" id="tab-users">
          <div class="table-wrap">
            <table id="dt-users" class="det-table" style="width:100%">
              <thead><tr><th>#</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Joined (PHT)</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                  <td><?= $u['user_id'] ?></td>
                  <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                  <td><?= htmlspecialchars($u['email']) ?></td>
                  <td><span class="badge badge-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
                  <td><?php
                    $ust = $u['status'] ?? 'active';
                    $pm = ['pending'=>'pill-pending','recommended'=>'pill-recommended','active'=>'pill-active'];
                    $lm = ['pending'=>'⏳ Pending','recommended'=>'📋 Recommended','active'=>'✅ Active'];
                    echo '<span class="status-pill '.($pm[$ust]??'pill-active').'">'.($lm[$ust]??ucfirst($ust)).'</span>';
                  ?></td>
                  <td data-order="<?= $u['created_at'] ?>"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                  <td>
                    <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
                    <a href="delete_user.php?id=<?= $u['user_id'] ?>" class="btn btn-danger"
                       onclick="return confirm('Delete <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?')">Delete</a>
                    <?php else: ?><span class="you-label">You</span><?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="tab-panel" id="tab-plants">
          <div class="table-wrap">
            <table id="dt-plants" class="det-table" style="width:100%">
              <thead><tr><th>#</th><th>Plant</th><th>Owner</th><th>City</th><th>Readings</th><th>Last %</th><th>Status</th><th>Added</th></tr></thead>
              <tbody>
                <?php foreach ($plants as $p): ?>
                <tr>
                  <td><?= $p['plant_id'] ?></td>
                  <td><strong>🪴 <?= htmlspecialchars($p['plant_name']) ?></strong></td>
                  <td><?= htmlspecialchars($p['username']) ?></td>
                  <td><?= htmlspecialchars($p['city']) ?></td>
                  <td><?= $p['reading_count'] ?></td>
                  <td><?= $p['last_humidity'] ? $p['last_humidity'] . '%' : '—' ?></td>
                  <td><?php if ($p['last_status']): ?><span class="badge badge-<?= strtolower($p['last_status']) ?>"><?= $p['last_status'] ?></span><?php else: ?>—<?php endif; ?></td>
                  <td data-order="<?= $p['created_at'] ?>"><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="tab-panel" id="tab-humidity">
          <div class="table-wrap">
            <table id="dt-humidity" class="det-table" style="width:100%">
              <thead><tr><th>#</th><th>Plant</th><th>Owner</th><th>Humidity %</th><th>Status</th><th>Recorded At (PHT)</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($humidity as $h): ?>
                <tr>
                  <td><?= $h['humidity_id'] ?></td>
                  <td><?= htmlspecialchars($h['plant_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($h['username']   ?? '—') ?></td>
                  <td><strong><?= $h['humidity_percent'] ?>%</strong></td>
                  <td><span class="badge badge-<?= strtolower($h['status']) ?>"><?= $h['status'] ?></span></td>
                  <td data-order="<?= $h['recorded_at'] ?>"><?= date('M d, Y H:i', strtotime($h['recorded_at'])) ?></td>
                  <td>
                    <a href="admin_dashboard.php?action=delete_log&log_id=0&humidity_id=<?= $h['humidity_id'] ?>"
                       class="btn btn-danger"
                       onclick="return confirm('Delete this reading and its log entries?')">Delete</a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="tab-panel" id="tab-logs">
          <div class="table-wrap">
            <table id="dt-logs" class="det-table" style="width:100%">
              <thead><tr><th>Log #</th><th>User</th><th>Plant</th><th>Humidity %</th><th>Status</th><th>Recorded At (PHT)</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($logs as $r): ?>
                <tr>
                  <td><?= $r['log_id'] ?></td>
                  <td><?= htmlspecialchars($r['username']) ?></td>
                  <td><?= htmlspecialchars($r['plant_name'] ?? '—') ?></td>
                  <td><strong><?= $r['humidity_percent'] ?>%</strong></td>
                  <td><span class="badge badge-<?= strtolower($r['status']) ?>"><?= $r['status'] ?></span></td>
                  <td data-order="<?= $r['recorded_at'] ?>"><?= date('M d, Y H:i', strtotime($r['recorded_at'])) ?></td>
                  <td>
                    <a href="admin_dashboard.php?action=delete_log&log_id=<?= $r['log_id'] ?>&humidity_id=<?= $r['humidity_id'] ?>"
                       class="btn btn-danger"
                       onclick="return confirm('Delete this record?')">Delete</a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app-layout -->

<script>
// ── Tab switching ───────────────────────────────────────────────────────────
function switchTab(id, ev) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  ev.currentTarget.classList.add('active');
  const map = {'tab-users':'#dt-users','tab-plants':'#dt-plants','tab-humidity':'#dt-humidity','tab-logs':'#dt-logs'};
  if (map[id]) $(map[id]).DataTable().columns.adjust().draw(false);
}

// ── DataTables ──────────────────────────────────────────────────────────────
$(document).ready(function () {
  const opts = {
    pageLength: 10, lengthMenu: [5,10,25,50],
    language: { search:'Search:', lengthMenu:'Show _MENU_ entries',
      info:'Showing _START_–_END_ of _TOTAL_', paginate:{previous:'‹',next:'›'} }
  };
  $('#dt-users').DataTable({ ...opts, order:[[4,'desc']], columnDefs:[{targets:5,orderable:false}] });
  $('#dt-plants').DataTable({ ...opts, order:[[7,'desc']] });
  $('#dt-humidity').DataTable({ ...opts, order:[[5,'desc']], columnDefs:[{targets:6,orderable:false}] });
  $('#dt-logs').DataTable({ ...opts, order:[[5,'desc']], columnDefs:[{targets:6,orderable:false}] });
});

// ── Shared chart options ────────────────────────────────────────────────────
const FONT  = 'Plus Jakarta Sans';
const gridColor = 'rgba(0,0,0,.05)';

// ── 1. Global Humidity Trend (avg % per day) ────────────────────────────────
const gDays = <?= json_encode(array_column($globalHumidity, 'day')) ?>;
const gAvg  = <?= json_encode(array_map(fn($r)=>(float)$r['avg_pct'], $globalHumidity)) ?>;

new Chart(document.getElementById('globalHumidityChart'), {
  type: 'line',
  data: {
    labels: gDays,
    datasets: [{
      label: 'Avg Humidity %',
      data: gAvg,
      borderColor: '#4f63d8',
      backgroundColor: 'rgba(79,99,216,.07)',
      borderWidth: 2.5, fill: true, tension: 0.4,
      pointRadius: 4, pointBackgroundColor: '#4f63d8',
      pointBorderColor: '#fff', pointBorderWidth: 2,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend:{display:false}, tooltip:{callbacks:{label:c=>' '+c.parsed.y+'%'}} },
    scales: {
      x: { ticks:{font:{size:9,family:FONT},maxTicksLimit:9,maxRotation:30}, grid:{color:gridColor} },
      y: { min:0, max:100,
           ticks:{font:{size:9,family:FONT},callback:v=>v+'%',stepSize:20}, grid:{color:gridColor} }
    }
  }
});

// ── 2. Status Distribution Donut ────────────────────────────────────────────
new Chart(document.getElementById('statusDonut'), {
  type: 'doughnut',
  data: {
    labels: ['Dry','Ideal','Humid'],
    datasets: [{ data: [<?= (int)($stats['Dry']??0) ?>,<?= (int)($stats['Ideal']??0) ?>,<?= (int)($stats['Humid']??0) ?>],
      backgroundColor: ['#c0430e','#1a6e3c','#1656a3'],
      borderColor: '#fff', borderWidth: 3, hoverOffset: 6 }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position:'bottom', labels:{font:{size:11,family:FONT},padding:14,usePointStyle:true} } }
  }
});

// ── 3. Per-plant current humidity bar chart ─────────────────────────────────
const plantNames  = <?= json_encode(array_column($plantHumidityData,'name')) ?>;
const plantLatest = <?= json_encode(array_map(fn($p)=>(float)($p['latest']??0), $plantHumidityData)) ?>;
const plantStatus = <?= json_encode(array_column($plantHumidityData,'status')) ?>;
const plantColors = <?= json_encode(array_column($plantHumidityData,'color')) ?>;

// Colour bars by status
const statusBarColors = plantStatus.map(s => s==='Dry'?'#c0430e' : s==='Ideal'?'#1a6e3c' : s==='Humid'?'#1656a3' : '#9ca3af');

new Chart(document.getElementById('plantCurrentChart'), {
  type: 'bar',
  data: {
    labels: plantNames,
    datasets: [{
      label: 'Current Humidity %',
      data: plantLatest,
      backgroundColor: statusBarColors.map(c => c+'bb'),
      borderColor: statusBarColors,
      borderWidth: 2, borderRadius: 8,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend:{display:false},
      tooltip:{callbacks:{label:c=>` ${c.parsed.y}% · ${plantStatus[c.dataIndex]||'N/A'}`}}
    },
    scales: {
      y: { min:0, max:100, ticks:{font:{size:9,family:FONT},callback:v=>v+'%',stepSize:20}, grid:{color:gridColor} },
      x: { ticks:{font:{size:10,family:FONT}}, grid:{display:false} }
    }
  }
});

// ── 4. Per-plant individual humidity line charts ─────────────────────────────
const STATUS_PT = {Dry:'#c0430e', Ideal:'#1a6e3c', Humid:'#1656a3'};

<?php foreach ($plantHumidityData as $pd): if (empty($pd['rows'])) continue; ?>
(function() {
  const rows = <?= json_encode($pd['rows']) ?>;
  const labels = rows.map(r => {
    const d = new Date(r.ts * 1000);
    return d.toLocaleString('en-PH',{month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit',hour12:false,timeZone:'Asia/Manila'});
  });
  const values = rows.map(r => parseFloat(r.humidity_percent));
  const ptColors = rows.map(r => STATUS_PT[r.status] || '<?= $pd['color'] ?>');
  new Chart(document.getElementById('plant-humidity-<?= $pd['pid'] ?>'), {
    type: 'line',
    data: {
      labels,
      datasets: [{
        data: values,
        borderColor: '<?= $pd['color'] ?>',
        backgroundColor: '<?= $pd['color'] ?>12',
        borderWidth: 2, fill: true, tension: 0.35,
        pointRadius: values.length > 20 ? 2 : 3,
        pointBackgroundColor: ptColors,
        pointBorderColor: '#fff', pointBorderWidth: 1.5,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend:{display:false}, tooltip:{callbacks:{label:c=>` ${c.parsed.y}%`}} },
      scales: {
        x: { ticks:{font:{size:7,family:FONT},maxTicksLimit:7,maxRotation:30,autoSkip:true}, grid:{display:false} },
        y: { min:0, max:100, ticks:{font:{size:7,family:FONT},callback:v=>v+'%',stepSize:25}, grid:{color:gridColor} }
      }
    }
  });
})();
<?php endforeach; ?>
</script>
</body>
</html>
