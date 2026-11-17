<?php
declare(strict_types=1);
require __DIR__.'/../../app/bootstrap.php'; require __DIR__.'/../../app/ffd_sync.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$latest=$pdo->query("SELECT MAX(fetched_at) FROM external_gauge_observations WHERE source='FFD'")->fetchColumn();
$age=$latest?time()-strtotime((string)$latest):PHP_INT_MAX;
$sync=null;
if($age>900) $sync=ffd_sync($pdo);
$rows=$pdo->query("SELECT river_name,station_name,discharge_cusecs,water_level_m,flood_status,source_updated_label,fetched_at FROM external_gauge_observations WHERE source='FFD' AND fetched_at=(SELECT MAX(x.fetched_at) FROM external_gauge_observations x WHERE x.source='FFD') ORDER BY river_name,station_name")->fetchAll();
$run=$pdo->query("SELECT status,records_fetched,message,started_at,finished_at FROM data_sync_runs WHERE source='FFD' ORDER BY id DESC LIMIT 1")->fetch();
echo json_encode(['source'=>'Pakistan Meteorological Department · Flood Forecasting Division','source_url'=>'https://ffd.pmd.gov.pk/index.php','retrieved_at'=>$latest,'stale_seconds'=>$age,'sync'=>$sync,'last_sync'=>$run,'records'=>$rows,'note'=>'FFD published discharge is an observed gauge flow in cusecs. Water level in metres is not inferred from discharge; it is shown only when the source explicitly supplies it.'],JSON_UNESCAPED_SLASHES);
