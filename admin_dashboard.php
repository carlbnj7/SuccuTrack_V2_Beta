<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit;
}
require 'config.php';

$msg = "";

if (($_GET['action'] ?? '') === 'delete_log' && isset($_GET['log_id'], $_GET['humidity_id'])) {
    $pdo->prepare("DELETE FROM user_logs WHERE log_id = ?")->execute([$_GET['log_id']]);
    $pdo->prepare("DELETE FROM humidity WHERE humidity_id = ?")->execute([$_GET['humidity_id']]);
    header("Location: admin_dashboard.php?deleted=1"); exit;
}
if (isset($_GET['deleted'])) $msg = "Record deleted successfully.";

$users  = $pdo->query("SELECT user_id, username, email, role, created_at FROM users ORDER BY created_at DESC")->fetchAll();
$counts = $pdo->query("SELECT status, COUNT(*) as total FROM humidity GROUP BY status")->fetchAll();
$stats  = array_column($counts, 'total', 'status');
$total  = array_sum(array_column($counts, 'total'));

$plants = $pdo->query("
    SELECT p.plant_id, p.plant_name, p.city, p.created_at,
           u.username,
           (SELECT COUNT(*) FROM humidity h WHERE h.plant_id = p.plant_id) AS reading_count,
           (SELECT h2.humidity_percent FROM humidity h2 WHERE h2.plant_id = p.plant_id ORDER BY h2.recorded_at DESC LIMIT 1) AS last_humidity,
           (SELECT h3.status FROM humidity h3 WHERE h3.plant_id = p.plant_id ORDER BY h3.recorded_at DESC LIMIT 1) AS last_status
    FROM plants p
    JOIN users u ON p.user_id = u.user_id
    ORDER BY p.plant_id ASC
")->fetchAll();

$humidity = $pdo->query("
    SELECT h.humidity_id, p.plant_name, u.username, h.humidity_percent, h.status, h.recorded_at
    FROM humidity h
    LEFT JOIN plants p ON h.plant_id = p.plant_id
    LEFT JOIN users u ON p.user_id = u.user_id
    ORDER BY h.recorded_at DESC
    LIMIT 200
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin – SuccuTrack</title>
<link rel="stylesheet" href="style.css">
<!-- DataTables -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/css/jquery.dataTables.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/js/jquery.dataTables.min.js"></script>
<style>
/* DataTables overrides to match SuccuTrack theme */
.dataTables_wrapper { font-size: .82rem; color: var(--text); }
.dataTables_wrapper .dataTables_length select,
.dataTables_wrapper .dataTables_filter input {
  border: 1.5px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 5px 9px;
  font-family: 'DM Sans', sans-serif;
  font-size: .82rem;
  background: var(--white);
  color: var(--text);
}
.dataTables_wrapper .dataTables_filter input:focus { outline: none; border-color: var(--green); }
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter { margin-bottom: 10px; color: var(--text-2); }
.dataTables_wrapper .dataTables_info { font-size: .75rem; color: var(--text-3); margin-top: 10px; }
.dataTables_wrapper .dataTables_paginate { margin-top: 10px; }
.dataTables_wrapper .dataTables_paginate .paginate_button {
  padding: 4px 10px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border) !important;
  background: var(--white) !important;
  color: var(--text-2) !important;
  font-size: .76rem;
  cursor: pointer;
  margin-left: 3px;
}
.dataTables_wrapper .dataTables_paginate .paginate_button.current {
  background: var(--green) !important;
  color: #fff !important;
  border-color: var(--green) !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button:hover {
  background: var(--green-lt) !important;
  color: var(--green) !important;
  border-color: var(--green-md) !important;
}
table.dataTable thead th {
  background: var(--bg) !important;
  color: var(--text-3) !important;
  font-weight: 600 !important;
  font-size: .7rem !important;
  text-transform: uppercase;
  letter-spacing: .05em;
  border-bottom: 1px solid var(--border) !important;
  padding: 9px 13px !important;
}
table.dataTable tbody td { padding: 10px 13px !important; border-bottom: 1px solid var(--border) !important; }
table.dataTable tbody tr:hover td { background: var(--bg) !important; }
table.dataTable thead th.sorting:after,
table.dataTable thead th.sorting_asc:after,
table.dataTable thead th.sorting_desc:after { color: var(--green); }
.tab-nav { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }
.tab-btn {
  padding: 8px 18px; font-size: .82rem; font-weight: 500;
  border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--white); color: var(--text-2); cursor: pointer;
  font-family: 'DM Sans', sans-serif; transition: .15s;
}
.tab-btn.active { background: var(--green); color: #fff; border-color: var(--green); }
.tab-btn:hover:not(.active) { background: var(--green-lt); color: var(--green); border-color: var(--green-md); }
.tab-panel { display: none; } .tab-panel.active { display: block; }
.badge-manager { background: #f0eaff; color: #6b3ec8; border: 1px solid #d4baff; }
</style>
</head>
<body>

<nav class="navbar">
  <div class="nav-brand">🌵 SuccuTrack <span class="admin-badge">Admin</span></div>
  <div class="nav-links">
    <span class="nav-user">Hi, <?= htmlspecialchars($_SESSION['username']) ?></span>
    <div class="live-indicator" id="liveIndicator">
      <span class="dot dot-on"></span> Live
    </div>
    <a href="manage_plants.php" class="btn btn-sm">Manage Plants</a>
    <a href="manage_users.php"  class="btn btn-sm">Manage Users</a>
    <a href="logout.php"        class="btn btn-sm">Logout</a>
  </div>
</nav>

<div class="container">

  <?php if ($msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-card stat-total">
      <div class="stat-num"><?= $total ?></div>
      <div class="stat-label">📊 Total Readings</div>
    </div>
    <div class="stat-card stat-dry">
      <div class="stat-num"><?= $stats['Dry'] ?? 0 ?></div>
      <div class="stat-label">🏜️ Dry</div>
    </div>
    <div class="stat-card stat-ideal">
      <div class="stat-num"><?= $stats['Ideal'] ?? 0 ?></div>
      <div class="stat-label">✅ Ideal</div>
    </div>
    <div class="stat-card stat-humid">
      <div class="stat-num"><?= $stats['Humid'] ?? 0 ?></div>
      <div class="stat-label">💧 Humid</div>
    </div>
    <div class="stat-card stat-users">
      <div class="stat-num"><?= count($users) ?></div>
      <div class="stat-label">👤 Users</div>
    </div>
  </div>

  <!-- DataTables Section -->
  <div class="card">
    <h2>📋 System Data</h2>
    <p class="subtitle">Browse, search, and sort all records across the system</p>

    <!-- Tab Navigation -->
    <div class="tab-nav">
      <button class="tab-btn active" onclick="switchTab('tab-users')">👤 Users (<?= count($users) ?>)</button>
      <button class="tab-btn"        onclick="switchTab('tab-plants')">🪴 Plants (<?= count($plants) ?>)</button>
      <button class="tab-btn"        onclick="switchTab('tab-humidity')">💧 Humidity Readings (<?= count($humidity) ?>)</button>
      <button class="tab-btn"        onclick="switchTab('tab-logs')">📋 User Logs (<?= count($logs) ?>)</button>
    </div>

    <!-- Tab: Users -->
    <div class="tab-panel active" id="tab-users">
      <div class="table-wrap">
        <table id="dt-users" class="det-table" style="width:100%">
          <thead>
            <tr><th>#</th><th>Username</th><th>Email</th><th>Role</th><th>Joined</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
              <td><?= $u['user_id'] ?></td>
              <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
              <td><?= htmlspecialchars($u['email']) ?></td>
              <td><span class="badge badge-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
              <td data-order="<?= $u['created_at'] ?>"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
              <td>
                <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
                <a href="delete_user.php?id=<?= $u['user_id'] ?>"
                   class="btn btn-sm btn-danger"
                   onclick="return confirm('Delete <?= htmlspecialchars($u['username']) ?>?')">Delete</a>
                <?php else: ?>
                <span class="you-label">You</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Tab: Plants -->
    <div class="tab-panel" id="tab-plants">
      <div class="table-wrap">
        <table id="dt-plants" class="det-table" style="width:100%">
          <thead>
            <tr><th>#</th><th>Plant Name</th><th>Owner</th><th>City</th><th>Readings</th><th>Last Humidity</th><th>Status</th><th>Added</th></tr>
          </thead>
          <tbody>
            <?php foreach ($plants as $p): ?>
            <tr>
              <td><?= $p['plant_id'] ?></td>
              <td><strong>🪴 <?= htmlspecialchars($p['plant_name']) ?></strong></td>
              <td><?= htmlspecialchars($p['username']) ?></td>
              <td><?= htmlspecialchars($p['city']) ?></td>
              <td><?= $p['reading_count'] ?></td>
              <td><?= $p['last_humidity'] ? $p['last_humidity'] . '%' : '—' ?></td>
              <td>
                <?php if ($p['last_status']): ?>
                <span class="badge badge-<?= strtolower($p['last_status']) ?>"><?= $p['last_status'] ?></span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td data-order="<?= $p['created_at'] ?>"><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Tab: Humidity Readings -->
    <div class="tab-panel" id="tab-humidity">
      <div class="table-wrap">
        <table id="dt-humidity" class="det-table" style="width:100%">
          <thead>
            <tr><th>#</th><th>Plant</th><th>Owner</th><th>Humidity %</th><th>Status</th><th>Recorded At</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php foreach ($humidity as $h): ?>
            <tr>
              <td><?= $h['humidity_id'] ?></td>
              <td><?= htmlspecialchars($h['plant_name'] ?? '—') ?></td>
              <td><?= htmlspecialchars($h['username'] ?? '—') ?></td>
              <td><strong><?= $h['humidity_percent'] ?>%</strong></td>
              <td><span class="badge badge-<?= strtolower($h['status']) ?>"><?= $h['status'] ?></span></td>
              <td data-order="<?= $h['recorded_at'] ?>"><?= date('M d, Y H:i', strtotime($h['recorded_at'])) ?></td>
              <td>
                <a href="admin_dashboard.php?action=delete_log&log_id=0&humidity_id=<?= $h['humidity_id'] ?>"
                   class="btn btn-sm btn-danger"
                   onclick="return confirm('Delete this humidity record?')">Delete</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Tab: User Logs -->
    <div class="tab-panel" id="tab-logs">
      <div class="table-wrap">
        <table id="dt-logs" class="det-table" style="width:100%">
          <thead>
            <tr><th>Log #</th><th>User</th><th>Plant / Device</th><th>Humidity %</th><th>Status</th><th>Recorded At</th><th>Action</th></tr>
          </thead>
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
                   class="btn btn-sm btn-danger"
                   onclick="return confirm('Delete this record?')">Delete</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script>
// Tab switching
function switchTab(id) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  event.currentTarget.classList.add('active');
  // Redraw the DataTable in that tab so columns render correctly
  const map = {
    'tab-users': '#dt-users', 'tab-plants': '#dt-plants',
    'tab-humidity': '#dt-humidity', 'tab-logs': '#dt-logs'
  };
  if (map[id]) $(map[id]).DataTable().columns.adjust().draw(false);
}

// Init DataTables
$(document).ready(function () {
  const commonOpts = {
    pageLength: 10,
    lengthMenu: [5, 10, 25, 50],
    language: {
      search: 'Search:',
      lengthMenu: 'Show _MENU_ entries',
      info: 'Showing _START_ to _END_ of _TOTAL_ records',
      paginate: { previous: '‹', next: '›' }
    }
  };

  $('#dt-users').DataTable({ ...commonOpts, order: [[4, 'desc']],
    columnDefs: [{ targets: 5, orderable: false }] });

  $('#dt-plants').DataTable({ ...commonOpts, order: [[7, 'desc']],
    columnDefs: [{ targets: [4,5,6], type: 'num' }] });

  $('#dt-humidity').DataTable({ ...commonOpts, order: [[5, 'desc']],
    columnDefs: [{ targets: 6, orderable: false }] });

  $('#dt-logs').DataTable({ ...commonOpts, order: [[5, 'desc']],
    columnDefs: [{ targets: 6, orderable: false }] });
});
</script>

</body>
</html>
