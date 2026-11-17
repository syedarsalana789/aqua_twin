<?php
declare(strict_types=1);
require __DIR__.'/../../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$lat=filter_input(INPUT_GET,'lat',FILTER_VALIDATE_FLOAT);$lon=filter_input(INPUT_GET,'lon',FILTER_VALIDATE_FLOAT);
if($lat===false||$lon===false||$lat===null||$lon===null||$lat < -60||$lat > 90||$lon < -180||$lon > 180){http_response_code(400);echo json_encode(['error'=>'Valid coordinates required']);exit;}
$url='https://flood-api.open-meteo.com/v1/flood?'.http_build_query(['latitude'=>$lat,'longitude'=>$lon,'daily'=>'river_discharge,river_discharge_mean,river_discharge_max','past_days'=>92,'forecast_days'=>7,'timezone'=>'GMT']);
$ctx=stream_context_create(['http'=>['timeout'=>25,'header'=>"User-Agent: AquaTwin/1.0\r\n"]]);$body=@file_get_contents($url,false,$ctx);
if(!$body){http_response_code(502);echo json_encode(['error'=>'GloFAS forecast provider unavailable']);exit;}
$d=json_decode($body,true);$daily=$d['daily']??[];$dates=$daily['time']??[];$vals=$daily['river_discharge']??[];
$history=[];$forecast=[];$today=gmdate('Y-m-d');foreach($dates as $i=>$date){$v=$vals[$i]??null;if(!is_numeric($v))continue;if($date<$today)$history[]=(float)$v;else $forecast[]=['date'=>$date,'discharge_m3s'=>(float)$v,'p90'=>(isset($daily['river_discharge_p90'][$i])?(float)$daily['river_discharge_p90'][$i]:null),'max'=>(isset($daily['river_discharge_max'][$i])?(float)$daily['river_discharge_max'][$i]:null)];}
if(count($history)<20||!$forecast){echo json_encode(['available'=>false,'message'=>'Not enough historical GloFAS values at this grid cell to calculate a defensible baseline.','method'=>'GloFAS historical percentile anomaly']);exit;}
sort($history);$p90=$history[(int)floor(.90*(count($history)-1))];$pred=[];
foreach($forecast as $f){$v=$f['max']??$f['discharge_m3s'];$ratio=$p90>0?$v/$p90:0;$score=min(100,max(0,$ratio*50));$band=$ratio>=1.5?'Very High':($ratio>=1.2?'High':($ratio>=.9?'Moderate':'Low'));$pred[]=['date'=>$f['date'],'forecast_discharge_m3s'=>$f['discharge_m3s'],'ensemble_max_m3s'=>$f['max'],'historical_p90_m3s'=>round($p90,3),'relative_to_historical_p90'=>round($ratio,3),'risk_score'=>round($score,1),'risk_band'=>$band];}
// Persist forecast snapshots (coordinate-based; no false station association).
$ins=$pdo->prepare("INSERT INTO flood_forecasts(location_id,latitude,longitude,forecast_date,discharge_m3s,baseline_p90_m3s,risk_score,risk_band,model_name,method_note,generated_at) VALUES(NULL,?,?,?,?,?,?,?,?,?,?)");
foreach($pred as $f)$ins->execute([$lat,$lon,$f['date'],$f['forecast_discharge_m3s'],$f['historical_p90_m3s'],$f['risk_score'],$f['risk_band'],'GloFAS v4 · ensemble anomaly','Heuristic comparison with the 92-day historical 90th percentile; this is not a trained AI/ML model or an official warning.',date('Y-m-d H:i:s')]);
echo json_encode(['available'=>true,'source'=>'Open-Meteo Global Flood API · Copernicus GloFAS','method'=>'GloFAS ensemble forecast compared with 92-day historical 90th percentile','is_trained_ai'=>false,'latitude'=>$d['latitude']??$lat,'longitude'=>$d['longitude']??$lon,'history_days'=>count($history),'historical_p90_m3s'=>round($p90,3),'forecasts'=>$pred,'warning'=>'Experimental model-assisted outlook only. Not a trained AI model, not a measured gauge level, and not an official flood warning. Verify with FFD and local authorities.'],JSON_UNESCAPED_SLASHES);
