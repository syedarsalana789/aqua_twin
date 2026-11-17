<?php
require __DIR__.'/../app/bootstrap.php';
require_role('Admin');
csrf_check();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        /* ── 1. Station Actions (Add, Edit, Delete) ── */
        if ($action === 'location') {
            $name = trim($_POST['name'] ?? '');
            $type = trim($_POST['area_type'] ?? 'River');
            $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

            if (strlen($name) < 3) throw new RuntimeException('Station name must be at least 3 characters.');
            if ($lat === false || $lat === null) throw new RuntimeException('Valid numerical Latitude required.');
            if ($lon === false || $lon === null) throw new RuntimeException('Valid numerical Longitude required.');

            $s = $pdo->prepare('INSERT INTO locations(name,area_type,latitude,longitude,description,is_active) VALUES(?,?,?,?,?,1)');
            $s->execute([$name, $type, $lat, $lon, trim($_POST['description'] ?? '')]);
            audit($pdo, 'create_location', $name);
            $msg = 'Station "' . $name . '" added successfully.';
        }

        if ($action === 'edit_location') {
            $id = (int)($_POST['location_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $type = trim($_POST['area_type'] ?? 'River');
            $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $desc = trim($_POST['description'] ?? '');

            if (!$id) throw new RuntimeException('Invalid station selected.');
            if (strlen($name) < 3) throw new RuntimeException('Station name must be at least 3 characters.');
            if ($lat === false || $lat === null || $lon === false || $lon === null) throw new RuntimeException('Valid coordinates required.');

            $s = $pdo->prepare('UPDATE locations SET name=?, area_type=?, latitude=?, longitude=?, description=? WHERE id=?');
            $s->execute([$name, $type, $lat, $lon, $desc, $id]);
            audit($pdo, 'edit_location', 'Location ' . $id);
            $msg = 'Station "' . $name . '" updated successfully.';
        }

        if ($action === 'delete_location') {
            $id = (int)($_POST['location_id'] ?? 0);
            if (!$id) throw new RuntimeException('Invalid station.');
            // Soft delete by setting is_active=0 so foreign keys remain intact
            $s = $pdo->prepare('UPDATE locations SET is_active=0 WHERE id=?');
            $s->execute([$id]);
            audit($pdo, 'delete_location', 'Location ' . $id);
            $msg = 'Station deactivated and removed from active monitoring.';
        }

        /* ── 2. Notice / Alert Actions (Publish, Edit, Remove) ── */
        if ($action === 'alert') {
            $title = trim($_POST['title'] ?? '');
            $sev = trim($_POST['severity'] ?? 'Warning');
            $message = trim($_POST['message'] ?? '');

            if (strlen($title) < 4) throw new RuntimeException('Notice title must be at least 4 characters.');
            if (strlen($message) < 8) throw new RuntimeException('Notice message must be at least 8 characters.');

            $s = $pdo->prepare('INSERT INTO alerts(title,severity,message,is_active,created_by) VALUES(?,?,?,1,?)');
            $s->execute([$title, $sev, $message, current_user()['id']]);
            audit($pdo, 'publish_alert', $title);
            $msg = 'Flood notice "' . $title . '" published.';
        }

        if ($action === 'edit_alert') {
            $id = (int)($_POST['alert_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $sev = trim($_POST['severity'] ?? 'Warning');
            $message = trim($_POST['message'] ?? '');

            if (!$id) throw new RuntimeException('Invalid notice identifier.');
            if (strlen($title) < 4) throw new RuntimeException('Title must be at least 4 characters.');
            if (strlen($message) < 8) throw new RuntimeException('Message must be at least 8 characters.');

            $s = $pdo->prepare('UPDATE alerts SET title=?, severity=?, message=? WHERE id=?');
            $s->execute([$title, $sev, $message, $id]);
            audit($pdo, 'edit_alert', 'Alert ' . $id);
            $msg = 'Flood notice updated successfully.';
        }

        if ($action === 'delete_alert') {
            $id = (int)($_POST['alert_id'] ?? 0);
            if (!$id) throw new RuntimeException('Invalid notice identifier.');
            $s = $pdo->prepare('UPDATE alerts SET is_active=0 WHERE id=?');
            $s->execute([$id]);
            audit($pdo, 'remove_alert', 'Alert ' . $id);
            $msg = 'Flood notice removed from active broadcasting.';
        }

        /* ── 3. Operator & Assignment Actions ── */
        if ($action === 'assign') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $locId = (int)($_POST['location_id'] ?? 0);
            if (!$userId || !$locId) throw new RuntimeException('Select both operator and station.');

            $s = $pdo->prepare('INSERT IGNORE INTO operator_assignments(user_id,location_id) VALUES(?,?)');
            $s->execute([$userId, $locId]);
            audit($pdo, 'assign_location', 'User ' . $userId . ' -> Loc ' . $locId);
            $msg = 'Operator assignment saved.';
        }

        if ($action === 'delete_assign') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $locId = (int)($_POST['location_id'] ?? 0);
            $s = $pdo->prepare('DELETE FROM operator_assignments WHERE user_id=? AND location_id=?');
            $s->execute([$userId, $locId]);
            audit($pdo, 'delete_assignment', 'User ' . $userId . ' / Loc ' . $locId);
            $msg = 'Station assignment unlinked.';
        }

    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$operators = $pdo->query("SELECT id,name,email FROM users WHERE role='Area Operator' AND is_active=1 ORDER BY name")->fetchAll();
$locations = $pdo->query('SELECT * FROM locations WHERE is_active=1 ORDER BY name')->fetchAll();
$assignments = $pdo->query('SELECT a.user_id, a.location_id, u.name AS operator, l.name AS location FROM operator_assignments a JOIN users u ON u.id=a.user_id JOIN locations l ON l.id=a.location_id WHERE l.is_active=1 ORDER BY u.name')->fetchAll();
$activeAlerts = $pdo->query('SELECT id, title, severity, message, created_at FROM alerts WHERE is_active=1 ORDER BY id DESC LIMIT 15')->fetchAll();
$recentObs = $pdo->query('SELECT o.observed_at,o.water_level_m,o.rainfall_mm,l.name AS location FROM weather_observations o JOIN locations l ON l.id=o.location_id WHERE l.is_active=1 ORDER BY o.id DESC LIMIT 6')->fetchAll();

layout_header('Admin dashboard');
?>
<div class="section-head">
  <div><div class="eyebrow">CONTROL CENTRE</div><h1 style="margin:4px 0">Administrator Dashboard</h1></div>
  <a class="btn btn-admin-manage" href="admin_data.php">Data Ingestion &amp; Thresholds &rarr;</a>
</div>

<?php if($msg): ?>
  <script>document.addEventListener('DOMContentLoaded', function(){ toastr.success(<?=json_encode($msg)?>, 'Admin Operation'); });</script>
  <p class="risk"><?=e($msg)?></p>
<?php endif; ?>
<?php if($err): ?>
  <script>document.addEventListener('DOMContentLoaded', function(){ toastr.error(<?=json_encode($err)?>, 'Error'); });</script>
  <p class="notice"><?=e($err)?></p>
<?php endif; ?>

<!-- Quick Stat Strip -->
<div class="statrow">
  <div class="stat"><span class="muted">Active Stations</span><strong><?=count($locations)?></strong></div>
  <div class="stat"><span class="muted">Field Operators</span><strong><?=count($operators)?></strong></div>
  <div class="stat"><span class="muted">Station Assignments</span><strong><?=count($assignments)?></strong></div>
  <div class="stat"><span class="muted">Live Flood Notices</span><strong><?=count($activeAlerts)?></strong></div>
</div>

<div class="two" style="margin-top:18px">

  <!-- Left Column: Forms for Adding Station & Notice -->
  <div class="stack">

    <!-- 1. Add Station -->
    <div class="card">
      <h2>Add Monitoring Station</h2>
      <form method="post" class="stack" id="form-add-location">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="location">
        <label>Station Name <span class="req">*</span>
          <input name="name" placeholder="e.g. Indus River — Kotri Barrage" required minlength="3">
        </label>
        <label>Station Type <span class="req">*</span>
          <select name="area_type" required>
            <option value="River" selected>River</option>
            <option value="Barrage">Barrage</option>
            <option value="Reservoir">Reservoir / Dam</option>
            <option value="Catchment">Catchment / Drainage</option>
          </select>
        </label>
        <div class="form-row-2">
          <label>Latitude <span class="req">*</span>
            <input name="latitude" type="number" step="any" min="-90" max="90" placeholder="e.g. 25.3719" required>
          </label>
          <label>Longitude <span class="req">*</span>
            <input name="longitude" type="number" step="any" min="-180" max="180" placeholder="e.g. 68.3142" required>
          </label>
        </div>
        <label>Station Description
          <input name="description" placeholder="Barrage name, river basin, or gauge notes">
        </label>
        <button type="submit">Add Station</button>
      </form>
    </div>

    <!-- 2. Publish Notice -->
    <div class="card">
      <h2>Publish Public Flood Notice</h2>
      <form method="post" class="stack" id="form-publish-notice">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="alert">
        <label>Notice Title <span class="req">*</span>
          <input name="title" placeholder="e.g. Rising flood stage at Sukkur Barrage" required minlength="4">
        </label>
        <label>Severity Level <span class="req">*</span>
          <select name="severity" required>
            <option value="Information">Information (Advisory)</option>
            <option value="Watch">Watch (Developing)</option>
            <option value="Warning" selected>Warning (High Risk)</option>
            <option value="Critical">Critical (Immediate Hazard)</option>
          </select>
        </label>
        <label>Bulletin Instructions <span class="req">*</span>
          <textarea name="message" rows="3" placeholder="Precautionary warnings, water levels or spillway status" required minlength="8"></textarea>
        </label>
        <button type="submit">Publish Notice</button>
      </form>
    </div>

    <!-- 3. Assign Operator to Station -->
    <div class="card">
      <h2>Assign Operator to Station</h2>
      <form method="post" class="stack" id="form-assign-operator">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="assign">
        <label>Select Field Operator <span class="req">*</span>
          <select name="user_id" required>
            <option value="" disabled selected>Choose operator...</option>
            <?php foreach($operators as $o): ?><option value="<?=$o['id']?>"><?=e($o['name'])?> (<?=e($o['email'])?>)</option><?php endforeach; ?>
          </select>
        </label>
        <label>Select Assigned Station <span class="req">*</span>
          <select name="location_id" required>
            <option value="" disabled selected>Choose station...</option>
            <?php foreach($locations as $l): ?><option value="<?=$l['id']?>"><?=e($l['name'])?></option><?php endforeach; ?>
          </select>
        </label>
        <button type="submit">Assign Station</button>
      </form>
    </div>

  </div>

  <!-- Right Column: Interactive Management (Edit/Remove Tables) -->
  <div class="stack">

    <!-- 4. Active Notices with Edit & Delete Modal/Inline Form -->
    <div class="card">
      <h2>Active Flood Notices &amp; Warnings</h2>
      <p class="muted small">Live public warnings. You can edit title/severity or remove any notice.</p>
      <?php if(!$activeAlerts): ?>
        <p class="muted">No active flood notices.</p>
      <?php else: foreach($activeAlerts as $alt): ?>
        <div style="border:1px solid var(--line);border-radius:var(--radius-sm);padding:12px;margin-bottom:12px;background:var(--paper)">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <b><?=e($alt['title'])?></b>
            <span class="pill pill-<?=e(strtolower($alt['severity']))?>"><?=e($alt['severity'])?></span>
          </div>
          <p style="margin:6px 0;font-size:0.88rem;color:var(--ink)"><?=e($alt['message'])?></p>
          <div style="display:flex;gap:8px;margin-top:8px;align-items:center">
            <button class="btn" type="button" style="padding:4px 10px;font-size:11.5px;min-height:30px" onclick="openEditAlert(<?=htmlspecialchars(json_encode($alt),ENT_QUOTES)?>)">Edit</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Are you sure you want to remove this notice?');">
              <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
              <input type="hidden" name="action" value="delete_alert">
              <input type="hidden" name="alert_id" value="<?=$alt['id']?>">
              <button class="btn" style="padding:4px 10px;font-size:11.5px;min-height:30px;background:#c34437;color:white" type="submit">Remove</button>
            </form>
            <small class="muted" style="margin-left:auto"><?=date('d M H:i', strtotime($alt['created_at']))?></small>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- 5. Active Stations with Edit & Deactivate Option -->
    <div class="card">
      <h2>Configured Monitoring Stations</h2>
      <p class="muted small">Manage river stations, barrages and reservoirs.</p>
      <div class="table-wrap">
        <table class="table table-compact">
          <tr><th>Station</th><th>Type</th><th>Actions</th></tr>
          <?php foreach($locations as $loc): ?>
            <tr>
              <td><b><?=e($loc['name'])?></b></td>
              <td><span class="pill pill-subtle"><?=e($loc['area_type'])?></span></td>
              <td>
                <div style="display:flex;gap:4px">
                  <button class="btn" type="button" style="padding:3px 8px;font-size:11px;min-height:28px" onclick="openEditLocation(<?=htmlspecialchars(json_encode($loc),ENT_QUOTES)?>)">Edit</button>
                  <form method="post" style="display:inline" onsubmit="return confirm('Deactivate <?=e($loc['name'])?>?');">
                    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="action" value="delete_location">
                    <input type="hidden" name="location_id" value="<?=$loc['id']?>">
                    <button class="btn" style="padding:3px 8px;font-size:11px;min-height:28px;background:#c34437;color:white" type="submit">Deactivate</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <!-- 6. Active Station Assignments with Unlink Action -->
    <div class="card">
      <h2>Operator Station Assignments</h2>
      <div class="table-wrap">
        <table class="table table-compact">
          <tr><th>Operator</th><th>Station</th><th>Action</th></tr>
          <?php if(!$assignments): ?>
            <tr><td colspan="3" class="muted">No assignments created.</td></tr>
          <?php else: foreach($assignments as $as): ?>
            <tr>
              <td><b><?=e($as['operator'])?></b></td>
              <td><?=e($as['location'])?></td>
              <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Unassign this station?');">
                  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                  <input type="hidden" name="action" value="delete_assign">
                  <input type="hidden" name="user_id" value="<?=$as['user_id']?>">
                  <input type="hidden" name="location_id" value="<?=$as['location_id']?>">
                  <button class="btn" style="padding:3px 8px;font-size:11px;min-height:28px;background:#c34437;color:white" type="submit">Unlink</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </table>
      </div>
    </div>

  </div>

</div>

<!-- ── Edit Notice Modal ── -->
<div id="modal-edit-alert" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;place-items:center;padding:16px">
  <div class="card" style="max-width:520px;width:100%;margin:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h2 style="margin:0">Edit Flood Notice</h2>
      <button type="button" class="btn" style="padding:4px 10px;min-height:30px" onclick="closeEditAlert()">✕</button>
    </div>
    <form method="post" class="stack" id="form-edit-alert">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="edit_alert">
      <input type="hidden" name="alert_id" id="edit-alert-id">
      <label>Notice Title <span class="req">*</span>
        <input name="title" id="edit-alert-title" required minlength="4">
      </label>
      <label>Severity Level <span class="req">*</span>
        <select name="severity" id="edit-alert-severity" required>
          <option value="Information">Information</option>
          <option value="Watch">Watch</option>
          <option value="Warning">Warning</option>
          <option value="Critical">Critical</option>
        </select>
      </label>
      <label>Message / Bulletin <span class="req">*</span>
        <textarea name="message" id="edit-alert-message" rows="3" required minlength="8"></textarea>
      </label>
      <div style="display:flex;gap:10px;margin-top:10px">
        <button type="submit" style="flex:1">Save Changes</button>
        <button type="button" class="btn" onclick="closeEditAlert()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Station Modal ── -->
<div id="modal-edit-location" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;place-items:center;padding:16px">
  <div class="card" style="max-width:540px;width:100%;margin:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h2 style="margin:0">Edit Monitoring Station</h2>
      <button type="button" class="btn" style="padding:4px 10px;min-height:30px" onclick="closeEditLocation()">✕</button>
    </div>
    <form method="post" class="stack" id="form-edit-location">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="edit_location">
      <input type="hidden" name="location_id" id="edit-loc-id">
      <label>Station Name <span class="req">*</span>
        <input name="name" id="edit-loc-name" required minlength="3">
      </label>
      <label>Station Type <span class="req">*</span>
        <select name="area_type" id="edit-loc-type" required>
          <option value="River">River</option>
          <option value="Barrage">Barrage</option>
          <option value="Reservoir">Reservoir / Dam</option>
          <option value="Catchment">Catchment / Drainage</option>
        </select>
      </label>
      <div class="form-row-2">
        <label>Latitude <span class="req">*</span>
          <input name="latitude" id="edit-loc-lat" type="number" step="any" required>
        </label>
        <label>Longitude <span class="req">*</span>
          <input name="longitude" id="edit-loc-lon" type="number" step="any" required>
        </label>
      </div>
      <label>Description
        <input name="description" id="edit-loc-desc">
      </label>
      <div style="display:flex;gap:10px;margin-top:10px">
        <button type="submit" style="flex:1">Save Station</button>
        <button type="button" class="btn" onclick="closeEditLocation()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditAlert(alt) {
  document.getElementById('edit-alert-id').value = alt.id;
  document.getElementById('edit-alert-title').value = alt.title;
  document.getElementById('edit-alert-severity').value = alt.severity;
  document.getElementById('edit-alert-message').value = alt.message;
  const m = document.getElementById('modal-edit-alert');
  m.style.display = 'grid';
}
function closeEditAlert() {
  document.getElementById('modal-edit-alert').style.display = 'none';
}

function openEditLocation(loc) {
  document.getElementById('edit-loc-id').value = loc.id;
  document.getElementById('edit-loc-name').value = loc.name;
  document.getElementById('edit-loc-type').value = loc.area_type;
  document.getElementById('edit-loc-lat').value = loc.latitude;
  document.getElementById('edit-loc-lon').value = loc.longitude;
  document.getElementById('edit-loc-desc').value = loc.description || '';
  const m = document.getElementById('modal-edit-location');
  m.style.display = 'grid';
}
function closeEditLocation() {
  document.getElementById('modal-edit-location').style.display = 'none';
}
</script>

<?php layout_footer(); ?>
