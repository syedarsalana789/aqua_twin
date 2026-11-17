<?php
require __DIR__.'/../app/bootstrap.php';
require_role('Area Operator');
csrf_check();
$uid = current_user()['id'];
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $loc = (int)($_POST['location_id'] ?? 0);
        $action = $_POST['action'] ?? '';

        /* 1. Record Field Gauge Observation */
        if ($action === 'observation') {
            if (!$loc) throw new RuntimeException('Please select an assigned monitoring station.');
            $s = $pdo->prepare('SELECT 1 FROM operator_assignments WHERE user_id=? AND location_id=?');
            $s->execute([$uid, $loc]);
            if (!$s->fetchColumn()) {
                throw new RuntimeException('This area is not assigned to your operator account.');
            }

            $observedAt = trim($_POST['observed_at'] ?? '');
            if (!$observedAt) throw new RuntimeException('Observation timestamp is required.');

            $water = $_POST['water_level_m'] !== '' ? (float)$_POST['water_level_m'] : null;
            $rain = $_POST['rainfall_mm'] !== '' ? (float)$_POST['rainfall_mm'] : null;

            if ($water === null && $rain === null) {
                throw new RuntimeException('Please enter either a water level or rainfall measurement.');
            }

            $s = $pdo->prepare('INSERT INTO weather_observations(location_id,observed_at,rainfall_mm,water_level_m,source,notes,entered_by) VALUES(?,?,?,?,?,?,?)');
            $s->execute([$loc, $observedAt, $rain, $water, 'Operator entry', trim($_POST['notes'] ?? ''), $uid]);

            if ($water !== null) {
                $q = $pdo->prepare('SELECT name,warning_level_m,critical_level_m FROM locations WHERE id=?');
                $q->execute([$loc]);
                $st = $q->fetch();
                $severity = null;
                if ($st['critical_level_m'] !== null && $water >= (float)$st['critical_level_m']) $severity = 'Critical';
                elseif ($st['warning_level_m'] !== null && $water >= (float)$st['warning_level_m']) $severity = 'Warning';
                if ($severity) {
                    $title = $severity . ' water level: ' . $st['name'];
                    $dupe = $pdo->prepare('SELECT id FROM alerts WHERE title=? AND severity=? AND created_at>=DATE_SUB(NOW(),INTERVAL 60 MINUTE) LIMIT 1');
                    $dupe->execute([$title, $severity]);
                    if (!$dupe->fetchColumn()) {
                        $a = $pdo->prepare('INSERT INTO alerts(title,severity,message,created_by) VALUES(?,?,?,?)');
                        $a->execute([$title, $severity, 'Operator observation ' . $water . ' m crossed configured local threshold. Automatic AquaTwin notification.', $uid]);
                    }
                }
            }
            audit($pdo, 'record_observation', 'Location ' . $loc);
            $msg = 'Field observation successfully saved. Threshold checks passed.';
        }

        /* 2. Field Incident Report */
        if ($action === 'incident') {
            if (!$loc) throw new RuntimeException('Please select an assigned monitoring station.');
            $s = $pdo->prepare('SELECT 1 FROM operator_assignments WHERE user_id=? AND location_id=?');
            $s->execute([$uid, $loc]);
            if (!$s->fetchColumn()) {
                throw new RuntimeException('This area is not assigned to your operator account.');
            }

            $title = trim($_POST['title'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $sev = trim($_POST['severity'] ?? 'Warning');

            if (strlen($title) < 4) throw new RuntimeException('Incident title must be at least 4 characters.');
            if (strlen($desc) < 8) throw new RuntimeException('Please enter a detailed description of the incident.');

            $s = $pdo->prepare('INSERT INTO incident_reports(location_id,title,description,severity,status,reported_by) VALUES(?,?,?,?,?,?)');
            $s->execute([$loc, $title, $desc, $sev, 'Open', $uid]);
            audit($pdo, 'report_incident', 'Location ' . $loc);
            $msg = 'Field incident report "' . $title . '" submitted for administrator review.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$s = $pdo->prepare('SELECT l.* FROM locations l JOIN operator_assignments a ON a.location_id=l.id WHERE a.user_id=? AND l.is_active=1 ORDER BY l.name');
$s->execute([$uid]);
$areas = $s->fetchAll();
$ids = array_column($areas, 'id');
$obs = []; $incs = [];

if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("SELECT o.*,l.name location FROM weather_observations o JOIN locations l ON l.id=o.location_id WHERE o.location_id IN ($in) ORDER BY o.id DESC LIMIT 10");
    $s->execute($ids);
    $obs = $s->fetchAll();

    $s = $pdo->prepare("SELECT i.*,l.name location FROM incident_reports i JOIN locations l ON l.id=i.location_id WHERE i.location_id IN ($in) ORDER BY i.id DESC LIMIT 6");
    $s->execute($ids);
    $incs = $s->fetchAll();
}

$activeAlerts = $pdo->query("SELECT * FROM alerts WHERE is_active=1 ORDER BY id DESC LIMIT 4")->fetchAll();

layout_header('Area Operator');
?>
<div class="section-head">
  <div><div class="eyebrow">FIELD OPERATIONS</div><h1 style="margin:4px 0">Area Operator Dashboard</h1></div>
  <span class="pill">Operator: <?=e(current_user()['name'])?></span>
</div>

<?php if($msg): ?>
  <script>document.addEventListener('DOMContentLoaded', function(){ toastr.success(<?=json_encode($msg)?>, 'Success'); });</script>
  <p class="risk"><?=e($msg)?></p>
<?php endif; ?>
<?php if($err): ?>
  <script>document.addEventListener('DOMContentLoaded', function(){ toastr.error(<?=json_encode($err)?>, 'Error'); });</script>
  <p class="notice"><?=e($err)?></p>
<?php endif; ?>

<!-- Quick Stat Strip for Operator -->
<div class="statrow">
  <div class="stat"><span class="muted">Assigned Stations</span><strong><?=count($areas)?></strong></div>
  <div class="stat"><span class="muted">My Recorded Telemetry</span><strong><?=count($obs)?></strong></div>
  <div class="stat"><span class="muted">Reported Incidents</span><strong><?=count($incs)?></strong></div>
  <div class="stat"><span class="muted">Active System Notices</span><strong><?=count($activeAlerts)?></strong></div>
</div>

<div class="card" style="margin-top:16px">
  <h2>Your Assigned Monitoring Stations</h2>
  <?php if(!$areas): ?>
    <p class="muted">No stations assigned to your account yet. Contact system administrator.</p>
  <?php else: ?>
    <div class="actions" style="margin-top:6px">
      <?php foreach($areas as $a): ?><span class="pill" style="font-weight:600"><?=e($a['name'])?> (<?=$a['area_type']?>)</span><?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if($areas): ?>
<div class="two" style="margin-top:18px">

  <!-- Left: Operational Data Entry -->
  <div class="stack">
    
    <!-- 1. Record Observation -->
    <div class="card">
      <h2>Record Field Observation</h2>
      <form method="post" class="stack" id="form-operator-obs">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="observation">
        <label>Monitoring Station <span class="req">*</span>
          <select name="location_id" required>
            <option value="" disabled selected>Select assigned location...</option>
            <?php foreach($areas as $a): ?><option value="<?=$a['id']?>"><?=e($a['name'])?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Observation Time <span class="req">*</span>
          <input type="datetime-local" name="observed_at" value="<?=date('Y-m-d\TH:i')?>" required>
        </label>
        <div class="form-row-2">
          <label>Water Level (metres) <span class="req">*</span>
            <input name="water_level_m" type="number" step=".01" placeholder="e.g. 7.42" required>
          </label>
          <label>Rainfall (mm)
            <input name="rainfall_mm" type="number" step=".1" min="0" placeholder="e.g. 15.0">
          </label>
        </div>
        <label>Field Notes / Gauge Status
          <input name="notes" placeholder="Observer remarks, gauge conditions, or spillway flow">
        </label>
        <button type="submit">Save Telemetry Reading</button>
      </form>
    </div>

    <!-- 2. Report Incident -->
    <div class="card">
      <h2>Report Field Incident / Hazard</h2>
      <form method="post" class="stack" id="form-operator-incident">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="incident">
        <label>Monitoring Station <span class="req">*</span>
          <select name="location_id" required>
            <option value="" disabled selected>Select affected location...</option>
            <?php foreach($areas as $a): ?><option value="<?=$a['id']?>"><?=e($a['name'])?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Incident Summary <span class="req">*</span>
          <input name="title" placeholder="e.g. Embankment erosion observed near downstream" required minlength="4">
        </label>
        <label>Severity Level <span class="req">*</span>
          <select name="severity" required>
            <option value="Information">Information</option>
            <option value="Watch">Watch</option>
            <option value="Warning" selected>Warning</option>
            <option value="Critical">Critical</option>
          </select>
        </label>
        <label>Detailed Incident Description <span class="req">*</span>
          <textarea name="description" rows="3" placeholder="Describe affected infrastructure, breach risk or local flooding" required minlength="8"></textarea>
        </label>
        <button type="submit">Submit Incident Report</button>
      </form>
    </div>

  </div>

  <!-- Right: Recent Field Telemetry & Incidents -->
  <div class="stack">

    <!-- Active Notices -->
    <div class="card">
      <h2>Active Flood Notices</h2>
      <?php if(!$activeAlerts): ?>
        <p class="muted">No active notices published.</p>
      <?php else: foreach($activeAlerts as $a): ?>
        <div class="operator-alert-card">
          <div class="operator-alert-head">
            <b><?=e($a['title'])?></b>
            <span class="pill pill-<?=e(strtolower($a['severity']))?>"><?=e($a['severity'])?></span>
          </div>
          <p class="muted" style="margin:6px 0 0;font-size:0.88rem"><?=e($a['message'])?></p>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Telemetry Table -->
    <div class="card">
      <h2>My Recent Observations</h2>
      <div class="table-wrap">
        <table class="table table-compact">
          <tr><th>Station</th><th>Water (m)</th><th>Rain (mm)</th><th>Time</th></tr>
          <?php if(!$obs): ?>
            <tr><td colspan="4" class="muted">No observations recorded yet.</td></tr>
          <?php else: foreach($obs as $o): ?>
            <tr>
              <td><?=e($o['location'])?></td>
              <td><b><?=$o['water_level_m']!==null?e($o['water_level_m']).'m':'—'?></b></td>
              <td><?=$o['rainfall_mm']!==null?e($o['rainfall_mm']).'mm':'—'?></td>
              <td><span class="date-cell"><?=date('d M H:i', strtotime($o['observed_at']))?></span></td>
            </tr>
          <?php endforeach; endif; ?>
        </table>
      </div>
    </div>

    <!-- Reported Incidents -->
    <div class="card">
      <h2>My Incident Reports</h2>
      <?php if(!$incs): ?>
        <p class="muted">No incident reports filed.</p>
      <?php else: foreach($incs as $i): ?>
        <div style="border-bottom:1px solid var(--line);padding:8px 0">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <b><?=e($i['title'])?></b>
            <span class="pill pill-<?=e(strtolower($i['severity']))?>"><?=e($i['severity'])?></span>
          </div>
          <small class="muted"><?=e($i['location'])?> · Status: <b><?=e($i['status'])?></b> · <?=e(date('d M Y H:i', strtotime($i['created_at'])))?></small>
          <p style="margin:4px 0 0;font-size:0.85rem"><?=e($i['description'])?></p>
        </div>
      <?php endforeach; endif; ?>
    </div>

  </div>
</div>
<?php endif; ?>

<?php layout_footer(); ?>
