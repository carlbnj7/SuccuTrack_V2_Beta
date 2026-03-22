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
    $lat     = (isset($_POST['pin_lat']) && $_POST['pin_lat'] !== '') ? floatval($_POST['pin_lat']) : null;
    $lng     = (isset($_POST['pin_lng']) && $_POST['pin_lng'] !== '') ? floatval($_POST['pin_lng']) : null;
    if ($name && $city && $user_id) {
        $pdo->prepare("INSERT INTO plants (user_id, plant_name, city, latitude, longitude) VALUES (?,?,?,?,?)")
            ->execute([$user_id, $name, $city, $lat, $lng]);
        $msg = "Plant '$name' added successfully" . ($lat ? " with map pin." : ".");

        // Activate user if they were pending/recommended
        $statusCheck = $pdo->prepare("SELECT status FROM users WHERE user_id=?");
        $statusCheck->execute([$user_id]);
        $currentStatus = $statusCheck->fetchColumn();
        if ($currentStatus === 'pending' || $currentStatus === 'recommended') {
            $pdo->prepare("UPDATE users SET status='active' WHERE user_id=?")->execute([$user_id]);
            // Mark admin notification as read
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE for_role='admin' AND ref_user_id=?")->execute([$user_id]);
            $msg .= " User account has been activated. ✅";
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $pid = intval($_GET['id']);
    $humIds = $pdo->prepare("SELECT humidity_id FROM humidity WHERE plant_id=?");
    $humIds->execute([$pid]);
    foreach ($humIds->fetchAll() as $row) {
        $pdo->prepare("DELETE FROM user_logs WHERE humidity_id=?")->execute([$row['humidity_id']]);
    }
    $pdo->prepare("DELETE FROM humidity WHERE plant_id=?")->execute([$pid]);
    $pdo->prepare("DELETE FROM plants  WHERE plant_id=?")->execute([$pid]);
    header("Location: manage_plants.php?deleted=1"); exit;
}
if (isset($_GET['deleted'])) $msg = "Plant deleted successfully.";

$users  = $pdo->query("SELECT user_id, username, COALESCE(status,'active') AS status FROM users WHERE role='user' ORDER BY username ASC")->fetchAll();

// Pre-select user if coming from admin onboarding panel
$preselect_uid      = intval($_GET['user_id']  ?? 0);
$preselect_username = htmlspecialchars($_GET['username'] ?? '');
$plants = $pdo->query("
    SELECT p.plant_id, p.plant_name, p.city, p.latitude, p.longitude, p.created_at,
           u.username,
           (SELECT COUNT(*) FROM humidity h JOIN user_logs ul ON h.humidity_id=ul.humidity_id WHERE h.plant_id=p.plant_id AND ul.user_id=p.user_id) AS reading_count,
           (SELECT h2.humidity_percent FROM humidity h2 WHERE h2.plant_id=p.plant_id ORDER BY h2.recorded_at DESC LIMIT 1) AS last_humidity,
           (SELECT h3.status           FROM humidity h3 WHERE h3.plant_id=p.plant_id ORDER BY h3.recorded_at DESC LIMIT 1) AS last_status
    FROM plants p JOIN users u ON p.user_id=u.user_id
    ORDER BY u.username, p.plant_id
")->fetchAll();

$activePage = 'manage_plants';
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
</head>
<body class="role-admin">
<div class="app-layout">
  <?php include 'sidebar.php'; ?>

  <div class="main-content">
    <header class="topbar">
      <div style="class="topbar-left" style="display:flex;align-items:center;gap:12px;"">
        <button class="sb-toggle" onclick="openSidebar()">☰</button>
        <div class="topbar-title">Manage <span>Plants</span></div>
      </div>
    </header>

    <div class="page-body">

      <?php if ($msg):   ?><div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

      <div class="pg-header">
        <h1>Plants &amp; IoT Devices</h1>
        <p>Add new plants with map pin locations, or delete existing ones</p>
      </div>

      <div class="plant-layout">

        <!-- Form side -->
        <div class="plant-form-panel">
          <div class="panel-header">
            <h2>Add New Plant / IoT Device</h2>
            <p>Drop a pin inside the Manolo Fortich boundary, then fill details below</p>
          </div>
          <div class="panel-body">
            <form method="POST" id="plant-form">
              <input type="hidden" name="pin_lat" id="pin_lat" value="">
              <input type="hidden" name="pin_lng" id="pin_lng" value="">

              <div class="pin-status-box" id="pin-status-box">
                <span id="pin-status-icon">🗺️</span>
                <span id="pin-status-text">No pin placed yet — click the map to set location</span>
              </div>

              <div class="form-row form-row-2" style="margin-bottom:12px;">
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
              </div>
              <div class="form-group" style="margin-bottom:14px;">
                <label>City / Location <span style="font-weight:400;color:var(--text-3);font-size:.68rem;margin-left:4px;">(auto-filled from pin)</span></label>
                <input type="text" name="new_city" id="new_city" required placeholder="Drop a pin to auto-fill →">
                <div id="city-chip" style="margin-top:5px;"></div>
              </div>
              <button type="submit" class="btn btn-primary btn-full">🌱 Add Plant</button>
            </form>
          </div>

          <?php if (!empty($plants)): ?>
          <div style="border-top:1px solid var(--border);padding:16px 20px 20px;">
            <div style="font-size:.78rem;font-weight:600;color:var(--text-2);margin-bottom:12px;">Existing Plants (<?= count($plants) ?>)</div>
            <div class="table-wrap">
              <table class="det-table">
                <thead><tr><th>Plant</th><th>Owner</th><th>City</th><th>Pin</th><th>Status</th><th>Added</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($plants as $p): ?>
                  <tr>
                    <td><strong>🪴 <?= htmlspecialchars($p['plant_name']) ?></strong></td>
                    <td><?= htmlspecialchars($p['username']) ?></td>
                    <td><?= htmlspecialchars($p['city']) ?></td>
                    <td><?= ($p['latitude'] && $p['longitude']) ? '<span style="color:var(--ideal);font-size:.75rem;">✅</span>' : '<span style="color:var(--text-3);font-size:.75rem;">—</span>' ?></td>
                    <td><?php if ($p['last_status']): ?><span class="badge badge-<?= strtolower($p['last_status']) ?>"><?= $p['last_status'] ?></span><?php else: ?>—<?php endif; ?></td>
                    <td style="font-size:.76rem;color:var(--text-3);"><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
                    <td>
                      <a href="manage_plants.php?action=delete&id=<?= $p['plant_id'] ?>"
                         class="btn btn-danger"
                         onclick="return confirm('Delete <?= htmlspecialchars($p['plant_name'],ENT_QUOTES) ?> and all its readings?')">Delete</a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Map side -->
        <div class="map-panel">
          <div class="map-panel-header">
            <span>📍 Click to place IoT device pin</span>
            <span style="font-size:.68rem;color:var(--text-3);">Inside Polygon Map only</span>
          </div>
          <div id="pin-map"></div>
          <div class="coords-strip" id="coords-strip">
            📍 <span id="coords-text"></span>
            <button class="clear-pin" onclick="clearPin()">✕ Clear pin</button>
          </div>
          <div class="map-footer idle" id="map-footer">📍 Click inside the highlighted boundary to place a pin</div>
        </div>

      </div><!-- /plant-layout -->

    </div><!-- /page-content -->
  </div><!-- /main-content -->
</div><!-- /app-layout -->

<script>
const MANOLO_POLYGON = [
  [8.4450,124.7820],[8.4600,124.8050],[8.4720,124.8280],[8.4700,124.8520],
  [8.4580,124.8760],[8.4380,124.9000],[8.4150,124.9200],[8.3900,124.9350],
  [8.3650,124.9400],[8.3380,124.9300],[8.3100,124.9150],[8.2880,124.8950],
  [8.2600,124.8750],[8.2215,124.8490],[8.2380,124.8200],[8.2620,124.7980],
  [8.2880,124.7780],[8.3150,124.7600],[8.3420,124.7480],[8.3700,124.7520],
  [8.3980,124.7680],[8.4250,124.7750],[8.4450,124.7820],
];

function pointInPolygon(lat, lng, polygon) {
  let inside = false;
  for (let i=0, j=polygon.length-1; i<polygon.length; j=i++) {
    const [xi,yi]=polygon[i], [xj,yj]=polygon[j];
    const intersect=((yi>lng)!==(yj>lng))&&(lat<(xj-xi)*(lng-yi)/(yj-yi)+xi);
    if (intersect) inside=!inside;
  }
  return inside;
}

const map = L.map('pin-map');
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
  attribution:'© OpenStreetMap contributors', maxZoom:18
}).addTo(map);
const boundaryPoly = L.polygon(MANOLO_POLYGON,{
  color:'#3d6b4a',weight:2.5,opacity:0.9,fillColor:'#3d6b4a',fillOpacity:0.05,dashArray:'8,5'
}).addTo(map).bindTooltip('Manolo Fortich, Bukidnon',{direction:'center'});
map.fitBounds(boundaryPoly.getBounds(),{padding:[12,12]});

const existingPlants = <?= json_encode(array_values(array_filter($plants,fn($p)=>$p['latitude']&&$p['longitude']))) ?>;
const statusColors = {dry:'#c05020',ideal:'#3d6b4a',humid:'#2d63a0','':"#8aa090"};
existingPlants.forEach(p=>{
  const color=statusColors[(p.last_status||'').toLowerCase()]||'#8aa090';
  const icon=L.divIcon({className:'',html:`<div style="background:${color};width:18px;height:18px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:2px solid white;box-shadow:0 1px 4px rgba(0,0,0,.3);"></div>`,iconSize:[18,18],iconAnchor:[9,18]});
  L.marker([parseFloat(p.latitude),parseFloat(p.longitude)],{icon}).addTo(map)
   .bindPopup(`<strong>🪴 ${p.plant_name}</strong><br><small>👤 ${p.username}</small>`);
});

let currentPin=null;
const validIcon  =L.divIcon({className:'',html:`<div style="background:#3d6b4a;width:26px;height:26px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid white;box-shadow:0 2px 10px rgba(0,0,0,.35);"></div>`,iconSize:[26,26],iconAnchor:[13,26]});
const invalidIcon=L.divIcon({className:'',html:`<div style="background:#c05020;width:26px;height:26px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid white;box-shadow:0 2px 10px rgba(0,0,0,.35);"></div>`,iconSize:[26,26],iconAnchor:[13,26]});

map.on('click',function(e){
  const lat=e.latlng.lat, lng=e.latlng.lng;
  const inside=pointInPolygon(lat,lng,MANOLO_POLYGON);
  if(currentPin) map.removeLayer(currentPin);
  currentPin=L.marker([lat,lng],{icon:inside?validIcon:invalidIcon,zIndexOffset:1000}).addTo(map);
  const footer=document.getElementById('map-footer');
  const statusBox=document.getElementById('pin-status-box');
  if(!inside){
    currentPin.bindPopup('<div style="color:#c05020;font-weight:600;">⛔ Outside the Polygon Map</div><div style="font-size:.75rem;color:#666;margin-top:3px;">Please place the pin inside the boundary.</div>').openPopup();
    document.getElementById('pin_lat').value='';
    document.getElementById('pin_lng').value='';
    document.getElementById('coords-strip').classList.remove('visible');
    footer.className='map-footer outside'; footer.textContent='⛔ Outside Polygon Map — pin not saved.';
    statusBox.className='pin-status-box outside';
    document.getElementById('pin-status-icon').textContent='⛔';
    document.getElementById('pin-status-text').textContent='Pin is outside boundary. Click inside.';
    document.getElementById('new_city').value='';
    document.getElementById('city-chip').innerHTML='';
  } else {
    document.getElementById('pin_lat').value=lat.toFixed(7);
    document.getElementById('pin_lng').value=lng.toFixed(7);
    document.getElementById('coords-text').textContent=lat.toFixed(5)+', '+lng.toFixed(5);
    document.getElementById('coords-strip').classList.add('visible');
    footer.className='map-footer ok'; footer.textContent='✅ Pin placed — click again to move.';
    statusBox.className='pin-status-box ok';
    document.getElementById('pin-status-icon').textContent='📍';
    document.getElementById('pin-status-text').textContent='Pin at '+lat.toFixed(5)+', '+lng.toFixed(5);
    reverseGeocode(lat,lng);
  }
});

function clearPin(){
  if(currentPin){map.removeLayer(currentPin);currentPin=null;}
  document.getElementById('pin_lat').value='';
  document.getElementById('pin_lng').value='';
  document.getElementById('coords-strip').classList.remove('visible');
  document.getElementById('map-footer').className='map-footer idle';
  document.getElementById('map-footer').textContent='📍 Click inside the boundary to place a pin';
  document.getElementById('pin-status-box').className='pin-status-box';
  document.getElementById('pin-status-icon').textContent='🗺️';
  document.getElementById('pin-status-text').textContent='No pin placed yet — click the map to set location';
  document.getElementById('new_city').value='';
  document.getElementById('city-chip').innerHTML='';
}

async function reverseGeocode(lat,lng){
  document.getElementById('new_city').value='Detecting…';
  try{
    const res=await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&zoom=10`,{headers:{'Accept-Language':'en'}});
    const data=await res.json();
    const addr=data.address||{};
    const city=addr.city||addr.municipality||addr.town||addr.village||addr.county||'';
    document.getElementById('new_city').value=city||'Manolo Fortich';
    if(city) document.getElementById('city-chip').innerHTML=`<span style="background:var(--surface);border:1px solid var(--accent-md);border-radius:20px;padding:2px 8px;font-size:.68rem;color:var(--accent);font-weight:500;">📍 ${city}</span>`;
  }catch(e){
    document.getElementById('new_city').value='Manolo Fortich';
  }
}

function highlightPendingUser(sel) {
  const opt = sel.options[sel.selectedIndex];
  const status = opt ? opt.getAttribute('data-status') : '';
  const banner = document.getElementById('preselect-banner');
  if (banner) banner.style.display = (status === 'pending' || status === 'recommended') ? 'flex' : 'none';
}
// Auto-highlight on load if preselected
(function(){
  const sel = document.getElementById('plant_user_id');
  if (sel) highlightPendingUser(sel);
})();
</script>
</body>
</html>
