<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=900');
$lat=filter_input(INPUT_GET,'lat',FILTER_VALIDATE_FLOAT);
$lon=filter_input(INPUT_GET,'lon',FILTER_VALIDATE_FLOAT);
if($lat===false||$lon===false||$lat===null||$lon===null||$lat < -60||$lat > 90||$lon < -180||$lon > 180){
 http_response_code(400);echo json_encode(['error'=>'Valid latitude and longitude are required.']);exit;
}
$url='https://flood-api.open-meteo.com/v1/flood?'.http_build_query([
 'latitude'=>$lat,'longitude'=>$lon,
 'daily'=>'river_discharge,river_discharge_mean,river_discharge_p25,river_discharge_p75',
 'past_days'=>7,'forecast_days'=>7,'timezone'=>'auto'
]);
$body=false;
if(function_exists('curl_init')){
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>18,CURLOPT_FOLLOWLOCATION=>true]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($status<200||$status>=300)$body=false;
}else{
 $ctx=stream_context_create(['http'=>['timeout'=>18,'header'=>"User-Agent: AquaTwin/1.0\r\n"]]);$body=@file_get_contents($url,false,$ctx);
}
if(!$body){http_response_code(502);echo json_encode(['error'=>'Global flood data provider is temporarily unavailable.']);exit;}
$data=json_decode($body,true);
if(!is_array($data)||!isset($data['daily'])){http_response_code(502);echo json_encode(['error'=>'Flood provider returned an unexpected response.']);exit;}
echo json_encode(['source'=>'Open-Meteo Global Flood API · GloFAS','retrieved_at'=>gmdate('c'),'latitude'=>$data['latitude']??$lat,'longitude'=>$data['longitude']??$lon,'daily'=>$data['daily'],'daily_units'=>$data['daily_units']??[],'timezone'=>$data['timezone']??'UTC','note'=>'Modelled river discharge at approximately 5 km grid resolution; not a measured gauge water level. Nearby river selection may be imperfect.'],JSON_UNESCAPED_SLASHES);
