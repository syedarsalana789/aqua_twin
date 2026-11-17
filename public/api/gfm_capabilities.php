<?php
declare(strict_types=1);
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=1800');
$url='https://geoserver.gfm.eodc.eu/geoserver/gfm/wms?service=WMS&request=GetCapabilities&version=1.1.1';
$body=false;
if(function_exists('curl_init')){
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_USERAGENT=>'AquaTwin Flood Monitor/1.0']);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($status<200||$status>=300)$body=false;
}else{$ctx=stream_context_create(['http'=>['timeout'=>30,'header'=>"User-Agent: AquaTwin Flood Monitor/1.0\r\n"]]);$body=@file_get_contents($url,false,$ctx);}
if(!$body){http_response_code(502);echo '<?xml version="1.0"?><error>Copernicus GFM WMS is temporarily unavailable.</error>';exit;}
echo $body;
