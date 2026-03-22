<?php
// sidebar.php — shared navigation partial
// Requires: $_SESSION['role'], $_SESSION['username'], $activePage, $pdo
$_role       = $_SESSION['role']     ?? 'user';
$_username   = $_SESSION['username'] ?? '';
$_initial    = strtoupper(mb_substr($_username, 0, 1));
$_activePage = $activePage ?? '';

// Badge counts
$_pendingCount   = 0;   // manager: users awaiting review
$_actionableCount = 0;  // admin: users pending OR recommended
$_unreadMgr      = 0;
$_unreadAdmin    = 0;
if ($_role === 'manager') {
    $_pendingCount = count_pending_users($pdo);
    $_unreadMgr    = get_unread_count($pdo, 'manager');
}
if ($_role === 'admin') {
    $_actionableCount = count_actionable_users($pdo);
    $_unreadAdmin     = get_unread_count($pdo, 'admin');
}
?>
<aside class="sidebar" id="sidebar">

  <div class="sb-brand">
    <a class="sb-logo" href="<?= $_role==='admin' ? 'admin_dashboard.php' : ($_role==='manager' ? 'manager_dashboard.php' : 'dashboard.php') ?>">
      <div class="sb-logo-icon">🌵</div>
      <span class="sb-logo-name">SuccuTrack</span>
    </a>
    <div class="sb-tagline">Humidity Monitor</div>
  </div>

  <div class="sb-user">
    <div class="sb-avatar"><?= htmlspecialchars($_initial) ?></div>
    <div>
      <div class="sb-username"><?= htmlspecialchars($_username) ?></div>
      <div class="sb-role"><?= ucfirst($_role) ?></div>
    </div>
  </div>

  <nav class="sb-nav">

    <?php if ($_role === 'admin'): ?>
      <span class="sb-section">Overview</span>
      <a href="admin_dashboard.php" class="sb-link <?= $_activePage==='admin_dashboard'?'active':'' ?>">
        <span class="ni">📊</span> Dashboard
        <?php if ($_unreadAdmin > 0): ?><span class="sb-badge"><?= $_unreadAdmin ?></span><?php endif; ?>
      </a>

      <span class="sb-section">Onboarding</span>
      <a href="admin_dashboard.php?jumptab=tab-onboarding" onclick="goAdminTab('tab-onboarding',event)"
         class="sb-link <?= $_activePage==='admin_onboarding'?'active':'' ?>">
        <span class="ni">🔔</span> Pending Assignment
        <?php if ($_actionableCount > 0): ?>
          <span class="sb-badge sb-badge-warn"><?= $_actionableCount ?></span>
        <?php endif; ?>
      </a>

      <span class="sb-section">Management</span>
      <a href="manage_plants.php" class="sb-link <?= $_activePage==='manage_plants'?'active':'' ?>">
        <span class="ni">🪴</span> Plants
      </a>
      <a href="manage_users.php" class="sb-link <?= $_activePage==='manage_users'?'active':'' ?>">
        <span class="ni">👤</span> Users
      </a>

      <span class="sb-section">Data</span>
      <a href="admin_dashboard.php" onclick="goAdminTab('tab-humidity',event)" class="sb-link">
        <span class="ni">💧</span> Humidity Records
      </a>
      <a href="admin_dashboard.php" onclick="goAdminTab('tab-logs',event)" class="sb-link">
        <span class="ni">📋</span> System Logs
      </a>

      <span class="sb-section">Analytics</span>
      <a href="admin_dashboard.php" onclick="scrollToCharts(event)" class="sb-link">
        <span class="ni">📈</span> Charts
      </a>

    <?php elseif ($_role === 'manager'): ?>
      <span class="sb-section">Overview</span>
      <a href="manager_dashboard.php" class="sb-link <?= $_activePage==='manager_dashboard'?'active':'' ?>">
        <span class="ni">📊</span> Dashboard
        <?php if ($_unreadMgr > 0): ?><span class="sb-badge"><?= $_unreadMgr ?></span><?php endif; ?>
      </a>

      <span class="sb-section">Onboarding</span>
      <a href="manager_dashboard.php?open=panel-newusers" onclick="openMgrPanel('panel-newusers',event)"
         class="sb-link">
        <span class="ni">🔔</span> New Users
        <?php if ($_pendingCount > 0): ?>
          <span class="sb-badge sb-badge-warn"><?= $_pendingCount ?></span>
        <?php endif; ?>
      </a>

      <span class="sb-section">Monitor</span>
      <a href="manager_dashboard.php" onclick="scrollToMap(event)" class="sb-link">
        <span class="ni">🗺️</span> Coverage Map
      </a>

      <span class="sb-section">Data</span>
      <a href="manager_dashboard.php" onclick="openMgrPanel('panel-plants',event)" class="sb-link">
        <span class="ni">🪴</span> Plants
      </a>
      <a href="manager_dashboard.php" onclick="openMgrPanel('panel-users',event)" class="sb-link">
        <span class="ni">👤</span> Users
      </a>
      <a href="manager_dashboard.php" onclick="openMgrPanel('panel-humidity',event)" class="sb-link">
        <span class="ni">💧</span> Readings
      </a>

      <span class="sb-section">Analytics</span>
      <a href="manager_dashboard.php" onclick="openMgrPanel('panel-analytics',event)" class="sb-link">
        <span class="ni">📈</span> Analytics
      </a>

    <?php else: ?>
      <span class="sb-section">My Dashboard</span>
      <a href="dashboard.php" class="sb-link <?= $_activePage==='dashboard'?'active':'' ?>">
        <span class="ni">🏠</span> Overview
      </a>
      <?php if (!empty($plantData)): ?>
      <span class="sb-section">My Plants</span>
      <?php foreach ($plantData as $pd):
        $ppid  = (int)$pd['plant']['plant_id'];
        $pname = htmlspecialchars($pd['plant']['plant_name']);
        $psc   = $pd['latest'] ? strtolower($pd['latest']['status']) : 'none';
        $pIcon = $psc === 'dry' ? '🏜️' : ($psc === 'ideal' ? '✅' : ($psc === 'humid' ? '💧' : '🪴'));
      ?>
      <a href="dashboard.php#card-<?= $ppid ?>" onclick="scrollToPlant(<?= $ppid ?>,event)" class="sb-link">
        <span class="ni"><?= $pIcon ?></span>
        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= $pname ?></span>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
      <span class="sb-section">History</span>
      <a href="dashboard.php#records" onclick="scrollToRecords(event)" class="sb-link">
        <span class="ni">📋</span> All Records
      </a>
    <?php endif; ?>

  </nav>

  <div class="sb-footer">
    <a href="logout.php" class="sb-signout"><span>🚪</span> Sign Out</a>
  </div>
</aside>

<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<style>
.sb-badge {
  display:inline-flex; align-items:center; justify-content:center;
  min-width:16px; height:16px; padding:0 4px; border-radius:10px;
  font-size:.58rem; font-weight:700; background:var(--accent); color:#fff;
  margin-left:auto; flex-shrink:0; line-height:1;
}
.sb-badge-warn { background:#f59e0b; }
</style>

<script>
function openSidebar()  { document.getElementById('sidebar').classList.add('open'); document.getElementById('sbOverlay').classList.add('open'); document.body.style.overflow='hidden'; }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('sbOverlay').classList.remove('open'); document.body.style.overflow=''; }

// ── Admin helpers ─────────────────────────────────────────────────────────────
function goAdminTab(tabId, evtOrEl) {
  if (evtOrEl && evtOrEl.preventDefault) evtOrEl.preventDefault();
  const panel = document.getElementById(tabId);
  if (!panel) { window.location.href = 'admin_dashboard.php?jumptab=' + tabId; return; }
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  panel.classList.add('active');
  document.querySelectorAll('.tab-btn').forEach(b => {
    if (b.getAttribute('onclick') && b.getAttribute('onclick').includes("'"+tabId+"'")) b.classList.add('active');
  });
  panel.scrollIntoView({behavior:'smooth', block:'start'});
  if (typeof $ !== 'undefined') {
    const dtMap = {'tab-users':'#dt-users','tab-plants':'#dt-plants','tab-humidity':'#dt-humidity','tab-logs':'#dt-logs'};
    const dtId = dtMap[tabId];
    if (dtId && $(dtId).length) $(dtId).DataTable().columns.adjust().draw(false);
  }
  closeSidebar();
}
function scrollToCharts(evt) {
  if (evt) evt.preventDefault();
  if (!document.getElementById('charts-section')) { window.location.href='admin_dashboard.php#charts-section'; return; }
  document.getElementById('charts-section').scrollIntoView({behavior:'smooth', block:'start'});
  closeSidebar();
}

// ── Manager helpers ───────────────────────────────────────────────────────────
function openMgrPanel(panelId, evt) {
  if (evt) evt.preventDefault();
  if (!document.getElementById('panel-plants')) { window.location.href = 'manager_dashboard.php?open=' + panelId; return; }
  document.querySelectorAll('.spanel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.stab').forEach(b => b.classList.remove('active'));
  const panel = document.getElementById(panelId);
  if (panel) {
    panel.classList.add('active');
    document.querySelectorAll('.stab').forEach(b => {
      if (b.getAttribute('onclick') && b.getAttribute('onclick').includes("'"+panelId+"'")) b.classList.add('active');
    });
    if (panelId === 'panel-analytics' && typeof initCharts === 'function') initCharts();
    panel.closest('.card')?.scrollIntoView({behavior:'smooth', block:'start'});
  }
  closeSidebar();
}
function scrollToMap(evt) {
  if (evt) evt.preventDefault();
  if (!document.getElementById('map-section')) { window.location.href = 'manager_dashboard.php#map-section'; return; }
  document.getElementById('map-section').scrollIntoView({behavior:'smooth', block:'start'});
  closeSidebar();
}

// ── User helpers ──────────────────────────────────────────────────────────────
function scrollToPlant(pid, evt) {
  if (evt) evt.preventDefault();
  const el = document.getElementById('card-'+pid);
  if (el) { el.scrollIntoView({behavior:'smooth', block:'start'}); closeSidebar(); }
  else window.location.href = 'dashboard.php#card-'+pid;
}
function scrollToRecords(evt) {
  if (evt) evt.preventDefault();
  const el = document.getElementById('records-section');
  if (el) { el.scrollIntoView({behavior:'smooth', block:'start'}); closeSidebar(); }
  else window.location.href = 'dashboard.php#records-section';
}

// ── Auto-open on page load via query param ────────────────────────────────────
(function() {
  const p = new URLSearchParams(window.location.search);
  const open = p.get('open');
  if (open && document.getElementById(open)) setTimeout(() => openMgrPanel(open, null), 200);
  const tab = p.get('jumptab');
  if (tab && document.getElementById(tab)) setTimeout(() => goAdminTab(tab, null), 200);
})();
</script>
