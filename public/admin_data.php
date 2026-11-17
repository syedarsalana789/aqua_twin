<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/ffd_sync.php';
require_role('Admin');
csrf_check();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {

        /* ── 1. Pakistan FFD Ingestion ── */
        if ($action === 'ffd_sync') {
            $r = ffd_sync($pdo);
            if (!$r['success']) throw new RuntimeException($r['message']);
            $msg = 'Pakistan FFD sync complete: ' . $r['records'] . ' live river discharge records saved.';
            audit($pdo, 'sync_ffd', $msg);
        }

        /* ── 2. Configure Station Thresholds ── */
        if ($action === 'threshold') {
            $id = (int)$_POST['location_id'];
            $w  = $_POST['warning_level_m']  !== '' ? (float)$_POST['warning_level_m']  : null;
            $c  = $_POST['critical_level_m'] !== '' ? (float)$_POST['critical_level_m'] : null;
            if ($w !== null && $c !== null && $c <= $w)
                throw new RuntimeException('Critical flood stage must be strictly higher than Warning level.');
            $s = $pdo->prepare('UPDATE locations SET warning_level_m=?,critical_level_m=? WHERE id=?');
            $s->execute([$w, $c, $id]);
            audit($pdo, 'set_flood_thresholds', 'Location ' . $id);
            $msg = 'Station warning & critical thresholds successfully updated.';
        }

        /* ── 3. Smart CSV Telemetry Import ── */
        if ($action === 'csv') {
            if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name']))
                throw new RuntimeException('Please select a valid CSV file.');

            $fh_detect = fopen($_FILES['csv']['tmp_name'], 'r');
            $raw_first  = fgets($fh_detect);
            fclose($fh_detect);
            $delim_counts = [
                ','  => substr_count($raw_first, ','),
                ';'  => substr_count($raw_first, ';'),
                "\t" => substr_count($raw_first, "\t"),
                '|'  => substr_count($raw_first, '|'),
            ];
            arsort($delim_counts);
            $delim = array_key_first($delim_counts);
            if ($delim_counts[$delim] === 0) $delim = ',';

            $aliases = [
                'station_name'  => ['station_name','station','location','site','gauge','name','gauge_station','river_station','gauging_station','area','station name','loc','place','point','gauge_name','gaugename','river','stationname','station_id'],
                'observed_at'   => ['observed_at','datetime','timestamp','date','time','date_time','obs_time','reading_time','recorded_at','measurement_time','obs_date','reading_date','date time','dateandtime','record_date','observation_date','obs_datetime','obs datetime','reading date','reading datetime','recordedat','observedat'],
                'water_level_m' => ['water_level_m','water_level','stage','level','height','water_height','gauge_level','stage_m','wl','water_m','level_m','water_stage','gage_height','gage height','river_level','water level','gauge level','depth','depth_m','river level','level (m)','stage (m)','waterlevel','gaugeheight','stagelevel'],
                'rainfall_mm'   => ['rainfall_mm','rainfall','rain','precip','precipitation','rain_mm','rainfall_amount','precip_mm','precipitation_mm','rain amount','rainfall amount','rain (mm)','precip (mm)','daily_rain','daily rain','monthly_rain','rainamount','rainmm'],
                'source'        => ['source','data_source','provider','authority','origin','instrument','source_name','agency','network','data source','datasource','sourceagency'],
            ];

            $fh       = fopen($_FILES['csv']['tmp_name'], 'r');
            $raw_head = fgetcsv($fh, 0, $delim);
            if (!$raw_head) {
                fclose($fh);
                throw new RuntimeException('CSV file appears empty or cannot be read.');
            }
            $norm_head = array_map(fn($v) => strtolower(trim($v)), $raw_head);

            $col_map = [];
            $detected_labels = [];

            foreach ($aliases as $canonical => $alias_list) {
                foreach ($norm_head as $idx => $h) {
                    if (in_array($h, $alias_list, true)) {
                        $col_map[$canonical]        = $idx;
                        $detected_labels[$canonical] = $raw_head[$idx];
                        break;
                    }
                }
            }

            foreach ($aliases as $canonical => $alias_list) {
                if (isset($col_map[$canonical])) continue;
                foreach ($norm_head as $idx => $h) {
                    foreach ($alias_list as $alias) {
                        if (str_contains($h, $alias) || str_contains($alias, $h)) {
                            $col_map[$canonical]        = $idx;
                            $detected_labels[$canonical] = $raw_head[$idx];
                            break 2;
                        }
                    }
                }
            }

            if (!isset($col_map['station_name']))
                throw new RuntimeException('Could not detect station name column. Accepted names: station, location, site, gauge, name, river...');
            if (!isset($col_map['observed_at']))
                throw new RuntimeException('Could not detect date/time column. Accepted names: date, datetime, timestamp, observed_at, reading_time...');

            $clean_num = function (string $v): ?float {
                $v = preg_replace('/[^0-9.\-]/', '', $v);
                return $v === '' ? null : (float)$v;
            };

            $all_locs  = $pdo->query('SELECT id, name, LOWER(name) AS name_lower, warning_level_m, critical_level_m FROM locations WHERE is_active=1')->fetchAll(PDO::FETCH_ASSOC);
            $loc_exact = [];
            $loc_lower = [];
            foreach ($all_locs as $l) {
                $loc_exact[$l['name']]        = $l;
                $loc_lower[$l['name_lower']]  = $l;
            }

            $ins = $pdo->prepare('INSERT INTO weather_observations(location_id,observed_at,water_level_m,rainfall_mm,source,notes,entered_by) VALUES(?,?,?,?,?,?,?)');
            $orig_name = $_FILES['csv']['name'] ?? 'CSV import';
            $count = 0; $skipped = 0; $skipped_stations = [];

            while (($row = fgetcsv($fh, 0, $delim)) !== false) {
                if (count($row) < 2) continue;
                $name = trim($row[$col_map['station_name']] ?? '');
                if ($name === '' || strtolower($name) === 'station_name' || strtolower($name) === 'station') continue;

                $loc = null;
                if (isset($loc_exact[$name])) {
                    $loc = $loc_exact[$name];
                } elseif (isset($loc_lower[strtolower($name)])) {
                    $loc = $loc_lower[strtolower($name)];
                } else {
                    foreach ($all_locs as $l) {
                        $a = strtolower($l['name']);
                        $b = strtolower($name);
                        if (str_contains($a, $b) || str_contains($b, $a)) {
                            $loc = $l;
                            break;
                        }
                    }
                }

                if (!$loc) {
                    $skipped++;
                    $skipped_stations[] = '"' . $name . '"';
                    continue;
                }

                $date_raw = trim($row[$col_map['observed_at']] ?? '');
                if ($date_raw === '') continue;
                $ts = strtotime($date_raw);
                if ($ts === false) continue;
                $date = date('Y-m-d H:i:s', $ts);

                $water  = isset($col_map['water_level_m']) ? $clean_num(trim($row[$col_map['water_level_m']] ?? '')) : null;
                $rain   = isset($col_map['rainfall_mm'])   ? $clean_num(trim($row[$col_map['rainfall_mm']] ?? ''))   : null;
                $source = isset($col_map['source'])        ? trim($row[$col_map['source']] ?? '')                    : '';
                if ($source === '') $source = $orig_name;

                $ins->execute([$loc['id'], $date, $water, $rain, $source, 'Smart CSV Import', current_user()['id']]);
                $count++;

                if ($water !== null) {
                    $severity = null;
                    if ($loc['critical_level_m'] !== null && $water >= (float)$loc['critical_level_m']) $severity = 'Critical';
                    elseif ($loc['warning_level_m'] !== null && $water >= (float)$loc['warning_level_m']) $severity = 'Warning';
                    if ($severity) {
                        $title = $severity . ' water level: ' . $loc['name'];
                        $dupe  = $pdo->prepare('SELECT id FROM alerts WHERE title=? AND severity=? AND created_at>=DATE_SUB(NOW(),INTERVAL 60 MINUTE) LIMIT 1');
                        $dupe->execute([$title, $severity]);
                        if (!$dupe->fetchColumn()) {
                            $m = 'Imported gauge reading ' . $water . ' m crossed configured threshold. Source: ' . $source . '.';
                            $a = $pdo->prepare('INSERT INTO alerts(title,severity,message,created_by) VALUES(?,?,?,?)');
                            $a->execute([$title, $severity, $m, current_user()['id']]);
                        }
                    }
                }
            }
            fclose($fh);

            $col_report  = implode(', ', array_map(fn($k, $v) => "$k → \"$v\"", array_keys($detected_labels), $detected_labels));
            $skip_report = $skipped > 0 ? " Skipped $skipped unmapped rows." : '';
            $msg = "Successfully imported $count observation rows.{$skip_report} Auto-detected: {$col_report}.";
            audit($pdo, 'import_gauge_csv', $count . ' rows');
        }

        /* ── 4. Satellite GeoJSON Flood Layer ── */
        if ($action === 'geojson') {
            if (empty($_FILES['geojson']['tmp_name']) || !is_uploaded_file($_FILES['geojson']['tmp_name']))
                throw new RuntimeException('Select a GeoJSON polygon file.');
            $raw = file_get_contents($_FILES['geojson']['tmp_name']);
            $obj = json_decode($raw, true);
            if (!is_array($obj) || !in_array($obj['type'] ?? '', ['FeatureCollection', 'Feature', 'Polygon', 'MultiPolygon'], true))
                throw new RuntimeException('Upload a valid GeoJSON FeatureCollection, Polygon or MultiPolygon.');
            $dir = __DIR__ . '/../storage/flood_layers';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $name = 'layer_' . bin2hex(random_bytes(8)) . '.geojson';
            if (file_put_contents($dir . '/' . $name, $raw) === false)
                throw new RuntimeException('Could not save GeoJSON file.');
            $s = $pdo->prepare('INSERT INTO flood_map_layers(title,source,geojson_file,observed_at,uploaded_by) VALUES(?,?,?,?,?)');
            $s->execute([trim($_POST['title']), trim($_POST['source']), $name, $_POST['observed_at'] ?: null, current_user()['id']]);
            audit($pdo, 'upload_flood_extent', $_POST['title']);
            $msg = 'Satellite flood extent layer uploaded and activated on the map.';
        }

    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$locations = $pdo->query('SELECT * FROM locations WHERE is_active=1 ORDER BY name')->fetchAll();
$layers    = $pdo->query('SELECT * FROM flood_map_layers WHERE is_active=1 ORDER BY created_at DESC')->fetchAll();

layout_header('Data & Thresholds');
?>
<div class="section-head">
  <div>
    <div class="eyebrow">DATA &amp; TELEMETRY</div>
    <h1 style="margin:4px 0">Flood Data &amp; Thresholds</h1>
  </div>
  <a class="btn" href="admin.php">← Back to Admin</a>
</div>

<?php if ($msg): ?>
  <script>document.addEventListener('DOMContentLoaded', function(){ toastr.success(<?=json_encode($msg)?>, 'Success'); });</script>
  <p class="risk"><?=e($msg)?></p>
<?php endif; ?>
<?php if ($err): ?>
  <script>document.addEventListener('DOMContentLoaded', function(){ toastr.error(<?=json_encode($err)?>, 'Error'); });</script>
  <p class="notice"><?=e($err)?></p>
<?php endif; ?>

<!-- 1. Automatic FFD River Ingestion -->
<div class="card">
  <h2>Pakistan FFD Automated River Ingestion</h2>
  <p class="muted small">Fetches live river discharge directly from the Pakistan Meteorological Department Flood Forecasting Division (Indus, Jhelum, Chenab, Ravi, Sutlej, Kabul basins) and updates telemetry tables.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="ffd_sync">
    <button type="submit">Sync Pakistan FFD Now</button>
  </form>
</div>

<!-- 2. Side-by-Side: Configure Thresholds & Smart CSV -->
<div class="two" style="margin-top:18px">

  <!-- Thresholds -->
  <div class="card">
    <h2>Configure Flood Thresholds</h2>
    <p class="muted small">Define warning and critical river water elevations in metres. Crossing these stages triggers automatic platform alerts.</p>
    <form method="post" class="stack" id="form-threshold">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="threshold">
      <label>Monitoring Station <span class="req">*</span>
        <select name="location_id" required>
          <option value="" disabled selected>Select station...</option>
          <?php foreach ($locations as $l): ?>
          <option value="<?=$l['id']?>"><?=e($l['name'])?> (Current: W=<?=$l['warning_level_m']??'—'?>m, C=<?=$l['critical_level_m']??'—'?>m)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Warning Stage (metres) <span class="req">*</span>
        <input type="number" step=".001" min="0" name="warning_level_m" placeholder="e.g. 8.500" required>
      </label>
      <label>Critical Stage (metres) <span class="req">*</span>
        <input type="number" step=".001" min="0" name="critical_level_m" placeholder="e.g. 10.200" required>
      </label>
      <button type="submit">Save Thresholds</button>
    </form>
  </div>

  <!-- Smart CSV Import -->
  <div class="card">
    <h2>Smart CSV Telemetry Import</h2>
    <p class="muted small">Auto-detects column names, units (<code>4.25m</code>, <code>15mm</code>) and delimiters. Ignores extra unneeded columns.</p>
    
    <form method="post" enctype="multipart/form-data" class="stack" id="form-csv">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="csv">
      <label>Choose CSV File <span class="req">*</span>
        <input type="file" name="csv" accept=".csv,.tsv,text/csv,text/plain" required>
      </label>
      <button type="submit">Import Observations</button>
    </form>

    <div style="margin-top:14px;display:flex;flex-direction:column;gap:6px;font-size:0.83rem">
      <span><b>Ready sample datasets:</b></span>
      <a href="sample_standard_upload.csv" download>1. Standard Format CSV (Exact headers)</a>
      <a href="sample_field_telemetry.csv" download>2. Field Telemetry CSV (Smart Aliases)</a>
    </div>
  </div>

</div>

<!-- 3. Satellite GeoJSON Upload -->
<div class="card" style="margin-top:18px">
  <h2>Upload Satellite Flood Extent (GeoJSON)</h2>
  <p class="muted small">Upload verified satellite flood inundation polygons (from Sentinel-1, QGIS, or Copernicus) to render on top of the Pakistan map.</p>
  <form method="post" enctype="multipart/form-data" class="two form-geojson" id="form-geojson">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="geojson">
    <label>Layer Title <span class="req">*</span>
      <input name="title" placeholder="e.g. Sukkur Inundation Boundary" required minlength="3">
    </label>
    <label>Source / Satellite <span class="req">*</span>
      <input name="source" placeholder="e.g. Sentinel-1 SAR / SUPARCO" required>
    </label>
    <label>Observed Date &amp; Time <span class="req">*</span>
      <input type="datetime-local" name="observed_at" required>
    </label>
    <label>GeoJSON File <span class="req">*</span>
      <input type="file" name="geojson" accept=".geojson,.json,application/geo+json,application/json" required>
    </label>
    <button type="submit">Upload Map Layer</button>
  </form>
</div>

<?php layout_footer(); ?>
