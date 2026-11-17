<?php
require __DIR__.'/../app/bootstrap.php';
$place=trim($_GET['place']??'Karachi, Pakistan');
$lat=filter_var($_GET['lat']??null,FILTER_VALIDATE_FLOAT);$lon=filter_var($_GET['lon']??null,FILTER_VALIDATE_FLOAT);
if($lat===false||$lat===null)$lat=24.8607;if($lon===false||$lon===null)$lon=67.0011;

$locations=$pdo->query("SELECT l.id,l.name,l.latitude,l.longitude,l.area_type,l.description,
 (SELECT o.water_level_m FROM weather_observations o WHERE o.location_id=l.id ORDER BY o.observed_at DESC LIMIT 1) water_level_m,
 (SELECT o.rainfall_mm FROM weather_observations o WHERE o.location_id=l.id ORDER BY o.observed_at DESC LIMIT 1) rainfall_mm,
 (SELECT o.observed_at FROM weather_observations o WHERE o.location_id=l.id ORDER BY o.observed_at DESC LIMIT 1) observed_at,
 (SELECT o.source FROM weather_observations o WHERE o.location_id=l.id ORDER BY o.observed_at DESC LIMIT 1) observation_source
 FROM locations l WHERE l.is_active=1 ORDER BY l.name")->fetchAll();

$points=[];
foreach($locations as $l){
  if($l['latitude']!==null&&$l['longitude']!==null){
    $points[]=['id'=>(int)$l['id'],'name'=>$l['name'],'lat'=>(float)$l['latitude'],'lon'=>(float)$l['longitude'],'kind'=>$l['area_type'],'water'=>$l['water_level_m'],'rain'=>$l['rainfall_mm'],'observed'=>$l['observed_at'],'source'=>$l['observation_source']];
  }
}

// Live Ingested Telemetry Feed (From Operator Entries & CSV Imports)
$telemetryFeed = $pdo->query("SELECT o.id, o.observed_at, o.water_level_m, o.rainfall_mm, o.source, o.notes, l.name AS location, l.area_type, l.warning_level_m, l.critical_level_m 
  FROM weather_observations o 
  JOIN locations l ON l.id = o.location_id 
  WHERE l.is_active = 1 
  ORDER BY o.observed_at DESC, o.id DESC 
  LIMIT 25")->fetchAll();

$layers=$pdo->query('SELECT title,source,geojson_file,observed_at FROM flood_map_layers WHERE is_active=1 ORDER BY created_at DESC')->fetchAll();
$alerts=$pdo->query("SELECT title,severity,message,created_at FROM alerts WHERE is_active=1 ORDER BY created_at DESC LIMIT 6")->fetchAll();

layout_header('Pakistan Flood Monitoring');
?>

<!-- 1. Hero Search Header -->
<section class="hero flood-hero">
  <div>
    <div class="eyebrow" style="color:#b6dce9">AQUATWIN · FLOOD INTELLIGENCE</div>
    <h1>Pakistan River &amp; Flood Monitoring System</h1>
    <p>Real-time river discharge, gauge levels and flood outlook across Pakistan's river basins.</p>
  </div>
  <div class="hero-actions">
    <button class="btn-light" id="use-location" type="button">◎ Use my location</button>
    <div class="search">
      <input id="city" placeholder="Search city or river (e.g. Sukkur, Indus, Kabul)" value="<?=e($place)?>">
      <button class="btn-light" id="search-place" type="button">Search</button>
    </div>
  </div>
</section>

<!-- 2. Active Location Strip -->
<div class="location-strip">
  <div><b id="place-label"><?=e($place)?></b><span class="muted"> · Pakistan flood monitoring area</span></div>
  <span class="pill" id="geo-status">Choose location or allow GPS</span>
</div>

<!-- 3. Primary Telemetry Snapshot (Modelled vs Measured) -->
<section id="flood-data" data-lat="<?=e($lat)?>" data-lon="<?=e($lon)?>" data-place="<?=e($place)?>" class="grid">
  <div class="card">
    <div class="eyebrow">MODELLED RIVER DISCHARGE</div>
    <div class="discharge-main">
      <div>
        <div class="muted">Latest available daily value</div>
        <div class="temp" id="discharge-now">—</div>
        <div class="muted" id="discharge-date">Waiting for flood data</div>
      </div>
      <div class="flow-icon">↗</div>
    </div>
    <div class="metrics">
      <div class="metric"><small>Recent trend</small><b id="flow-trend">—</b></div>
      <div class="metric"><small>7-day peak</small><b id="flow-peak">—</b></div>
      <div class="metric"><small>Grid location</small><b id="grid-point">—</b></div>
      <div class="metric"><small>Data source</small><b>GloFAS</b></div>
    </div>
    <p class="muted small" id="flood-source">Connecting to river discharge telemetry…</p>
  </div>

  <div class="card">
    <div class="eyebrow">MEASURED WATER LEVEL</div>
    <h2 id="gauge-title">Nearby configured gauge</h2>
    <div class="gauge-value" id="gauge-level">No verified gauge reading</div>
    <div class="muted" id="gauge-meta">A water-level reading appears only when a configured station has supplied a measurement.</div>
    <div class="notice" style="margin-top:16px"><b>Important:</b> GloFAS provides modelled river discharge (m³/s), not direct water height (m). Flash-flood risk and localized hill torrents require specialized local observations.</div>
  </div>
</section>

<!-- 4. Unified Interactive Hydrological Map -->
<div class="section-head">
  <div><h2>Pakistan river basins &amp; gauge map</h2><span class="muted small">Select any monitoring station marker or click the map</span></div>
  <div class="map-tools">
    <button class="map-chip active" data-layer="all">All Pakistan stations</button>
    <button class="map-chip" id="gfm-toggle" type="button">Show live satellite flood layer</button>
  </div>
</div>
<div class="card map-card">
  <div id="map" data-gfm-wms="https://geoserver.gfm.eodc.eu/geoserver/gfm/wms" data-points="<?=e(json_encode($points,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP))?>" data-layers="<?=e(json_encode($layers,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP))?>"></div>
  <p class="muted small">Pins identify configured Pakistani monitoring stations and reservoirs. Uploaded verified GeoJSON flood extents are drawn as map overlays.</p>
</div>

<!-- 5. Live Ingested Telemetry Feed (Ground Truth from Operator & CSV) -->
<div class="section-head">
  <div>
    <h2>Verified field &amp; gauge telemetry</h2>
    <span class="muted small">Live observations submitted by field operators and imported sensor feeds</span>
  </div>
  <span class="pill">Live Field Feed</span>
</div>
<div class="card card-table">
  <div class="table-scroll-hint"><span>↔</span> Swipe horizontally to view full table</div>
  <div class="table-wrap">
    <table class="table table-enterprise">
      <thead>
        <tr>
          <th>Station / Water Body</th>
          <th>Type</th>
          <th>Water Level (m)</th>
          <th>Rainfall (mm)</th>
          <th>Observation Time</th>
          <th>Status / Threshold</th>
          <th>Source</th>
        </tr>
      </thead>
      <tbody>
        <?php if(!$telemetryFeed): ?>
          <tr><td colspan="7" class="muted" style="text-align:center;padding:24px">No ground telemetry readings recorded yet. (Upload CSV or record operator reading to view here).</td></tr>
        <?php else: foreach($telemetryFeed as $f): 
          // Check threshold stage
          $statusBadge = '<span class="pill pill-subtle">Normal</span>';
          if($f['water_level_m'] !== null) {
            $lvl = (float)$f['water_level_m'];
            if($f['critical_level_m'] !== null && $lvl >= (float)$f['critical_level_m']) {
              $statusBadge = '<span class="pill pill-critical">Critical Stage</span>';
            } elseif($f['warning_level_m'] !== null && $lvl >= (float)$f['warning_level_m']) {
              $statusBadge = '<span class="pill pill-warning">Warning Stage</span>';
            }
          }
        ?>
          <tr>
            <td><b><?=e($f['location'])?></b></td>
            <td><span class="pill pill-subtle"><?=e($f['area_type'])?></span></td>
            <td class="num-cell"><?=$f['water_level_m'] !== null ? '<b>'.e(number_format((float)$f['water_level_m'], 2)).' m</b>' : '<span class="muted">—</span>'?></td>
            <td class="num-cell"><?=$f['rainfall_mm'] !== null ? e(number_format((float)$f['rainfall_mm'], 1)).' mm' : '<span class="muted">—</span>'?></td>
            <td><span class="date-cell"><?=date('d M Y, H:i', strtotime($f['observed_at']))?></span></td>
            <td><?=$statusBadge?></td>
            <td><span class="muted small"><?=e($f['source'])?></span></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- 6. 7-Day Forecast Curve -->
<div class="section-head">
  <h2>River discharge history &amp; 7-day outlook</h2>
  <span class="pill">7 days history · 7 days forecast</span>
</div>
<div class="card">
  <div id="discharge-chart" class="chart-area"><span class="muted">Loading river-discharge series…</span></div>
  <div class="legend"><span><i class="dot history-dot"></i> Historical model data</span><span><i class="dot forecast-dot"></i> Forecast data</span></div>
</div>

<!-- 7. GloFAS Ensemble Statistical Flood Outlook -->
<div class="section-head">
  <div><h2>7-day flood-risk outlook</h2><span class="muted small">GloFAS forecast compared with its recent historical baseline</span></div>
  <span class="pill">Statistical baseline comparison</span>
</div>
<div class="card" id="risk-outlook-card">
  <div id="risk-outlook" class="muted">Loading forecast-based risk outlook…</div>
</div>

<!-- 8. Official Live Pakistan FFD River Flow Observations -->
<div class="section-head">
  <div><h2>Automatically ingested FFD river flows</h2><span class="muted section-sub">Official Pakistan Meteorological Department river discharge in cusecs</span></div>
  <span class="pill" id="ffd-sync-status">Connecting to FFD…</span>
</div>
<div class="card card-table">
  <div class="table-scroll-hint"><span>↔</span> Swipe horizontally to view full table</div>
  <div class="table-wrap">
    <table class="table table-enterprise">
      <thead>
        <tr><th>River</th><th>Gauge station</th><th>Discharge</th><th>Flood status</th><th>FFD update</th></tr>
      </thead>
      <tbody id="ffd-gauge-rows">
        <tr><td colspan="5" class="muted">Fetching official FFD data…</td></tr>
      </tbody>
    </table>
  </div>
  <div class="table-footer-note">
    <p class="muted small" id="ffd-note">FFD discharge is an observed flow measurement in cusecs published by the Pakistan Meteorological Department Flood Forecasting Division.</p>
  </div>
</div>

<!-- 9. Active Public Flood Notices & Bulletins -->
<div class="section-head">
  <h2>Published civil protection notices</h2>
  <span class="muted small">Active warnings &amp; flood alerts</span>
</div>
<div class="card">
  <?php if(!$alerts): ?>
    <p class="muted">No active platform notices published.</p>
  <?php else: foreach($alerts as $a): ?>
    <div class="alert-row">
      <span class="pill pill-<?=e(strtolower($a['severity']))?>"><?=e($a['severity'])?></span>
      <div>
        <b><?=e($a['title'])?></b>
        <div class="muted"><?=e($a['message'])?></div>
        <small class="muted"><?=e($a['created_at'])?></small>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<?php layout_footer(); ?>
