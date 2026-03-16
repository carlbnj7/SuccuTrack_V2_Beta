<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit;
}
require 'config.php';

$msg = $error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_plant_name'])) {
    $name    = trim($_POST['new_plant_name']);
    $city    = trim($_POST['new_city']);
    $user_id = intval($_POST['plant_user_id']);
    $lat     = $_POST['pin_lat'] !== '' ? floatval($_POST['pin_lat']) : null;
    $lng     = $_POST['pin_lng'] !== '' ? floatval($_POST['pin_lng']) : null;
    if ($name && $city && $user_id) {
        $pdo->prepare("INSERT INTO plants (user_id, plant_name, city, latitude, longitude) VALUES (?,?,?,?,?)")
            ->execute([$user_id, $name, $city, $lat, $lng]);
        $msg = "Plant '$name' added successfully" . ($lat ? " with map pin." : ".");
    } else {
        $error = "Please fill in all required fields.";
    }
}

if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $pid = intval($_GET['id']);
    $humIds = $pdo->prepare("SELECT humidity_id FROM humidity WHERE plant_id = ?");
    $humIds->execute([$pid]);
    foreach ($humIds->fetchAll() as $row) {
        $pdo->prepare("DELETE FROM user_logs WHERE humidity_id = ?")->execute([$row['humidity_id']]);
    }
    $pdo->prepare("DELETE FROM humidity WHERE plant_id = ?")->execute([$pid]);
    $pdo->prepare("DELETE FROM plants WHERE plant_id = ?")->execute([$pid]);
    header("Location: manage_plants.php?deleted=1"); exit;
}
if (isset($_GET['deleted'])) $msg = "Plant deleted successfully.";

$users  = $pdo->query("SELECT user_id, username FROM users WHERE role = 'user' ORDER BY username ASC")->fetchAll();
$plants = $pdo->query("
    SELECT p.plant_id, p.plant_name, p.city, p.latitude, p.longitude, p.created_at,
           u.username,
           (SELECT COUNT(*) FROM humidity h JOIN user_logs ul ON h.humidity_id = ul.humidity_id
            WHERE h.plant_id = p.plant_id AND ul.user_id = p.user_id) AS reading_count,
           (SELECT h2.humidity_percent FROM humidity h2 WHERE h2.plant_id = p.plant_id ORDER BY h2.recorded_at DESC LIMIT 1) AS last_humidity,
           (SELECT h3.status FROM humidity h3 WHERE h3.plant_id = p.plant_id ORDER BY h3.recorded_at DESC LIMIT 1) AS last_status
    FROM plants p JOIN users u ON p.user_id = u.user_id
    ORDER BY u.username, p.plant_id
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Plants – SuccuTrack</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
.plant-layout {
  display: grid;
  grid-template-columns: 1fr 440px;
  gap: 18px;
  align-items: start;
  margin-bottom: 18px;
}
.plant-form-panel {
  background: var(--white);
  border-radius: var(--radius);
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  overflow: hidden;
}
.panel-header {
  padding: 18px 22px 14px;
  border-bottom: 1px solid var(--border);
  background: var(--bg);
}
.panel-header h2 { font-family:'DM Serif Display',serif; font-size:1rem; font-weight:400; margin:0 0 2px; }
.panel-header p  { font-size:.75rem; color:var(--text-3); margin:0; }
.panel-body { padding: 20px 22px; }

.map-panel {
  background: var(--white);
  border-radius: var(--radius);
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  overflow: hidden;
  position: sticky;
  top: 80px;
}
.map-panel-header {
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
  background: var(--bg);
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.map-panel-header span { font-size: .8rem; font-weight: 500; color: var(--text-2); }

#pin-map {
  height: 400px;
  width: 100%;
  display: block;
  position: relative;
  z-index: 1;
  cursor: crosshair;
}

.map-footer {
  padding: 10px 14px;
  font-size: .74rem;
  border-top: 1px solid var(--border);
  background: var(--bg);
  min-height: 38px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.map-footer.ok      { color: var(--green);  background: var(--green-lt); border-top-color: var(--green-md); }
.map-footer.outside { color: var(--dry);    background: var(--dry-bg);   border-top-color: var(--dry-bd);   }
.map-footer.idle    { color: var(--text-3); }

.coords-strip {
  display: none;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  background: var(--green-lt);
  border-top: 1px solid var(--green-md);
  font-size: .73rem;
  color: var(--green);
  font-weight: 500;
}
.coords-strip.visible { display: flex; }
.clear-pin {
  margin-left: auto; background: none; border: none;
  color: var(--dry); font-size: .72rem; cursor: pointer;
  font-family: inherit; text-decoration: underline; padding: 0;
}

.pin-status-box {
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  background: var(--bg);
  border: 1px solid var(--border);
  margin-bottom: 16px;
  font-size: .78rem;
  color: var(--text-3);
  display: flex;
  align-items: center;
  gap: 8px;
}
.pin-status-box.ok      { background: var(--green-lt); border-color: var(--green-md); color: var(--green); }
.pin-status-box.outside { background: var(--dry-bg);   border-color: var(--dry-bd);   color: var(--dry);   }

.pin-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:.65rem; font-weight:600; }
.pin-badge.yes { background:var(--green-lt); color:var(--green); border:1px solid var(--green-md); }
.pin-badge.no  { background:var(--dry-bg);   color:var(--dry);   border:1px solid var(--dry-bd); }

@media (max-width: 860px) {
  .plant-layout { grid-template-columns: 1fr; }
  .map-panel    { position: static; }
}
</style>
</head>
<body>
<nav class="navbar">
  <div class="nav-brand">🌵 SuccuTrack <span class="admin-badge">Admin</span></div>
  <div class="nav-links">
    <a href="admin_dashboard.php" class="btn btn-sm">← Dashboard</a>
    <a href="manage_users.php"    class="btn btn-sm">Users</a>
    <a href="logout.php"          class="btn btn-sm">Logout</a>
  </div>
</nav>

<div class="container">

  <?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="plant-layout">

    <!-- LEFT: Form -->
    <div class="plant-form-panel">
      <div class="panel-header">
        <h2>Add Plant / IoT Device</h2>
        <p>Drop a pin inside Manolo Fortich on the map, then complete the details below</p>
      </div>
      <div class="panel-body">
        <form method="POST" id="plant-form">
          <input type="hidden" name="pin_lat" id="pin_lat" value="">
          <input type="hidden" name="pin_lng" id="pin_lng" value="">

          <!-- Pin status indicator -->
          <div class="pin-status-box" id="pin-status-box">
            <span id="pin-status-icon">🗺️</span>
            <span id="pin-status-text">No pin placed yet — click the map to set location</span>
          </div>

          <div class="form-group">
            <label>Assign to User</label>
            <select name="plant_user_id" required>
              <option value="">— Select user —</option>
              <?php foreach ($users as $u): ?>
              <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Plant Name</label>
            <input type="text" name="new_plant_name" required placeholder="e.g. Echeveria #1">
          </div>
          <div class="form-group">
            <label>City / Location
              <span style="font-weight:400;color:var(--text-3);font-size:.7rem;margin-left:4px;">(auto-filled from pin)</span>
            </label>
            <input type="text" name="new_city" id="new_city" required placeholder="Drop a pin to auto-fill →">
          </div>
          <button type="submit" class="btn btn-primary btn-full" id="submit-btn">
            Add Plant
          </button>
        </form>
      </div>
    </div>

    <!-- RIGHT: Map -->
    <div class="map-panel">
      <div class="map-panel-header">
        <span>📍 Click to place IoT device pin</span>
        <span style="font-size:.7rem;color:var(--text-3);">Manolo Fortich only</span>
      </div>
      <div id="pin-map"></div>
      <div class="coords-strip" id="coords-strip">
        <span id="coords-text">—</span>
        <span id="city-chip"></span>
        <button class="clear-pin" onclick="clearPin()">✕ Clear pin</button>
      </div>
      <div class="map-footer idle" id="map-footer">
        📍 Click anywhere inside the highlighted boundary to place a pin
      </div>
    </div>

  </div>

  <!-- All Plants Table -->
  <div class="card">
    <h2>All Plants <span class="user-count"><?= count($plants) ?></span></h2>
    <p class="subtitle">All registered IoT devices and their assigned locations</p>
    <?php if (empty($plants)): ?>
      <p class="empty-msg">No plants yet. Add one above.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="det-table">
        <thead>
          <tr><th>#</th><th>Plant Name</th><th>Owner</th><th>City</th><th>Pin</th><th>Readings</th><th>Last Humidity</th><th>Status</th><th>Added</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($plants as $p): ?>
          <tr>
            <td><?= $p['plant_id'] ?></td>
            <td><strong>🪴 <?= htmlspecialchars($p['plant_name']) ?></strong></td>
            <td><?= htmlspecialchars($p['username']) ?></td>
            <td><?= htmlspecialchars($p['city']) ?></td>
            <td>
              <?php if ($p['latitude'] && $p['longitude']): ?>
                <span class="pin-badge yes">📍 Pinned</span>
              <?php else: ?>
                <span class="pin-badge no">No pin</span>
              <?php endif; ?>
            </td>
            <td><?= $p['reading_count'] ?></td>
            <td><?= $p['last_humidity'] ? $p['last_humidity'].'%' : '—' ?></td>
            <td>
              <?php if ($p['last_status']): ?>
                <span class="badge badge-<?= strtolower($p['last_status']) ?>"><?= $p['last_status'] ?></span>
              <?php else: ?>
                <span style="color:var(--text-3);font-size:.78rem;">No data</span>
              <?php endif; ?>
            </td>
            <td><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
            <td>
              <a href="manage_plants.php?action=delete&id=<?= $p['plant_id'] ?>"
                 class="btn btn-sm btn-danger"
                 onclick="return confirm('Delete <?= htmlspecialchars($p['plant_name']) ?> and all its readings?')">Delete</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>

<script>
// ── Accurate Manolo Fortich boundary ──
// Built from real barangay coordinates (PhilAtlas/PSA data):
//   Northernmost:  Alae 8.4178, Tagoloan 8.45, Kalugmanan 8.44
//   Southernmost:  Dahilayan 8.2215 (Mt. Kitanglad foothills)
//   Easternmost:   Agusan Canyon 8.38, 124.92 (Sumilao border)
//   Westernmost:   Sankanan 8.41, 124.79 (Baungon/Libona border)
//   Area: 413.60 km²
const MANOLO_POLYGON = [
  // Starting NW, going clockwise
  [8.4450, 124.7820],  // NW — Alae/Sankanan/Baungon border
  [8.4600, 124.8050],  // N  — Kalugmanan upper / CDO border
  [8.4720, 124.8280],  // N  — CDO boundary (Tagoloan River area)
  [8.4700, 124.8520],  // NNE — CDO/Malitbog border
  [8.4580, 124.8760],  // NE — Malitbog border
  [8.4380, 124.9000],  // ENE — Malitbog/Sumilao border
  [8.4150, 124.9200],  // E  — Sumilao border (Miarayon area)
  [8.3900, 124.9350],  // E  — Sumilao border (Agusan Canyon)
  [8.3650, 124.9400],  // ESE — Sumilao border
  [8.3380, 124.9300],  // SE — Sumilao/Impasugong border
  [8.3100, 124.9150],  // SSE — Santiago/Guilang-guilang area
  [8.2880, 124.8950],  // S  — Ticala/Impasugong border
  [8.2600, 124.8750],  // SSW — Dahilayan highlands (Mt. Kitanglad)
  [8.2215, 124.8490],  // SW — Dahilayan (southernmost barangay)
  [8.2380, 124.8200],  // SW — Mintapod/Kitanglad range
  [8.2620, 124.7980],  // WSW — Mintapod/Libona border
  [8.2880, 124.7780],  // W  — Libona border
  [8.3150, 124.7600],  // WNW — Libona/Baungon border
  [8.3420, 124.7480],  // NW — Baungon border (Mambatangan)
  [8.3700, 124.7520],  // NW — Baungon border (Maluko/Sankanan)
  [8.3980, 124.7680],  // NNW — Sankanan/Baungon
  [8.4250, 124.7750],  // N  — Alae upper / Baungon border
  [8.4450, 124.7820],  // back to start
];

// Point-in-polygon test (ray casting)
function pointInPolygon(lat, lng, polygon) {
  let inside = false;
  for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
    const xi = polygon[i][0], yi = polygon[i][1];
    const xj = polygon[j][0], yj = polygon[j][1];
    const intersect = ((yi > lng) !== (yj > lng)) &&
      (lat < (xj - xi) * (lng - yi) / (yj - yi) + xi);
    if (intersect) inside = !inside;
  }
  return inside;
}

const map = L.map('pin-map');
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© OpenStreetMap contributors', maxZoom: 18
}).addTo(map);

// Draw boundary polygon
const boundaryPoly = L.polygon(MANOLO_POLYGON, {
  color: '#4a7c59', weight: 2.5, opacity: 0.9,
  fillColor: '#4a7c59', fillOpacity: 0.06, dashArray: '8, 5'
}).addTo(map).bindTooltip('Manolo Fortich, Bukidnon', { direction: 'center' });

// Fit map to boundary
map.fitBounds(boundaryPoly.getBounds(), { padding: [12, 12] });

// Show existing pinned plants
const existingPlants = <?= json_encode(array_values(array_filter($plants, fn($p) => $p['latitude'] && $p['longitude']))) ?>;
const statusColors   = { dry:'#b85c2a', ideal:'#4a7c59', humid:'#3a6fa8', '':'#96aea0' };

existingPlants.forEach(function(p) {
  const color = statusColors[(p.last_status||'').toLowerCase()] || '#96aea0';
  const icon  = L.divIcon({
    className: '',
    html: `<div style="background:${color};width:20px;height:20px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:2px solid white;box-shadow:0 1px 4px rgba(0,0,0,.3);"></div>`,
    iconSize:[20,20], iconAnchor:[10,20]
  });
  L.marker([parseFloat(p.latitude), parseFloat(p.longitude)], { icon })
   .addTo(map)
   .bindPopup(`<strong>🪴 ${p.plant_name}</strong><br><small>👤 ${p.username}</small>`);
});

// ── Pin drop with boundary check ──
let currentPin = null;

const validPinIcon = L.divIcon({
  className: '',
  html: `<div style="background:#4a7c59;width:28px;height:28px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid white;box-shadow:0 2px 10px rgba(0,0,0,.35);"></div>`,
  iconSize:[28,28], iconAnchor:[14,28]
});
const invalidPinIcon = L.divIcon({
  className: '',
  html: `<div style="background:#b85c2a;width:28px;height:28px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid white;box-shadow:0 2px 10px rgba(0,0,0,.35);"></div>`,
  iconSize:[28,28], iconAnchor:[14,28]
});

map.on('click', function(e) {
  const lat    = e.latlng.lat;
  const lng    = e.latlng.lng;
  const inside = pointInPolygon(lat, lng, MANOLO_POLYGON);

  if (currentPin) map.removeLayer(currentPin);
  currentPin = L.marker([lat, lng], { icon: inside ? validPinIcon : invalidPinIcon, zIndexOffset: 1000 })
    .addTo(map);

  const footer    = document.getElementById('map-footer');
  const statusBox = document.getElementById('pin-status-box');

  if (!inside) {
    // Outside boundary — reject pin
    currentPin.bindPopup(
      '<div style="color:#b85c2a;font-weight:600;font-size:.85rem;">⛔ Outside Manolo Fortich</div>' +
      '<div style="font-size:.75rem;color:#666;margin-top:4px;">Please place the pin inside the municipality boundary.</div>'
    ).openPopup();

    document.getElementById('pin_lat').value = '';
    document.getElementById('pin_lng').value = '';
    document.getElementById('coords-strip').classList.remove('visible');

    footer.className    = 'map-footer outside';
    footer.textContent  = '⛔ That location is outside Manolo Fortich — pin not saved.';
    statusBox.className = 'pin-status-box outside';
    document.getElementById('pin-status-icon').textContent = '⛔';
    document.getElementById('pin-status-text').textContent = 'Pin is outside Manolo Fortich. Please click inside the boundary.';
    document.getElementById('new_city').value = '';
    document.getElementById('city-chip').innerHTML = '';
  } else {
    // Inside boundary — accept pin
    document.getElementById('pin_lat').value = lat.toFixed(7);
    document.getElementById('pin_lng').value = lng.toFixed(7);
    document.getElementById('coords-text').textContent = lat.toFixed(5) + ', ' + lng.toFixed(5);
    document.getElementById('coords-strip').classList.add('visible');

    footer.className    = 'map-footer ok';
    footer.textContent  = '✅ Pin placed inside Manolo Fortich — click again to move it.';
    statusBox.className = 'pin-status-box ok';
    document.getElementById('pin-status-icon').textContent = '📍';
    document.getElementById('pin-status-text').textContent = 'Pin placed at ' + lat.toFixed(5) + ', ' + lng.toFixed(5);

    reverseGeocode(lat, lng);
  }
});

function clearPin() {
  if (currentPin) { map.removeLayer(currentPin); currentPin = null; }
  document.getElementById('pin_lat').value = '';
  document.getElementById('pin_lng').value = '';
  document.getElementById('coords-strip').classList.remove('visible');
  document.getElementById('map-footer').className = 'map-footer idle';
  document.getElementById('map-footer').textContent = '📍 Click anywhere inside the highlighted boundary to place a pin';
  document.getElementById('pin-status-box').className = 'pin-status-box';
  document.getElementById('pin-status-icon').textContent = '🗺️';
  document.getElementById('pin-status-text').textContent = 'No pin placed yet — click the map to set location';
  document.getElementById('new_city').value = '';
  document.getElementById('city-chip').innerHTML = '';
}

async function reverseGeocode(lat, lng) {
  document.getElementById('new_city').value = 'Detecting…';
  try {
    const res  = await fetch(
      `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&zoom=10`,
      { headers: { 'Accept-Language': 'en' } }
    );
    const data = await res.json();
    const addr = data.address || {};
    const city = addr.city || addr.municipality || addr.town || addr.village || addr.county || '';
    document.getElementById('new_city').value = city || 'Manolo Fortich';
    if (city) {
      document.getElementById('city-chip').innerHTML =
        `<span style="background:var(--white);border:1px solid var(--green-md);border-radius:20px;padding:2px 8px;font-size:.7rem;color:var(--green);font-weight:500;">📍 ${city}</span>`;
    }
  } catch(e) {
    document.getElementById('new_city').value = 'Manolo Fortich';
  }
}
</script>

</body>
</html>