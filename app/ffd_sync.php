<?php
declare(strict_types=1);
function ffd_http_get(string $url): string {
    $headers = "User-Agent: AquaTwin Flood Monitoring/1.0 (+local research dashboard)\r\nAccept: text/html,application/xhtml+xml\r\n";
    if (function_exists('curl_init')) {
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>4,CURLOPT_HTTPHEADER=>[$headers],CURLOPT_SSL_VERIFYPEER=>true]);
        $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
        if ($body===false || $status<200 || $status>=300) throw new RuntimeException('FFD request failed (HTTP '.$status.'). '.$err);
        return (string)$body;
    }
    $ctx=stream_context_create(['http'=>['timeout'=>25,'follow_location'=>1,'header'=>$headers],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $body=@file_get_contents($url,false,$ctx); if($body===false) throw new RuntimeException('Could not connect to the official FFD portal. Enable PHP cURL or allow_url_fopen.'); return $body;
}
function ffd_parse_home(string $html): array {
    $text=html_entity_decode(strip_tags(preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1>~is',' ',$html)),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $text=preg_replace('/\s+/u',' ',$text) ?? $text;
    $updated=null;
    if(preg_match('/(?:River data|Updated|Map last updated)\s*([0-9]{1,2}-[A-Za-z]{3}-[0-9]{4}\s+[0-9]{2}:[0-9]{2})\s*PKT/i',$text,$m)) $updated=$m[1];
    $patterns=[
      '/(?:\d+\.\s*)?(Indus|Kabul|Jhelum|Chenab|Ravi|Sutlej|Neelum)\b.{0,70}?\bat\s+(.{2,70}?)\s+([0-9][0-9,]*)\s+cusecs\s+(Normal flow|Below Low|Low|Medium|High|Very High|Exceptionally High)/iu',
    ];
    $out=[];
    foreach($patterns as $pattern){ if(preg_match_all($pattern,$text,$matches,PREG_SET_ORDER)) foreach($matches as $m){
      $river=trim(preg_replace('/\s+/u',' ',$m[1])); $station=trim(preg_replace('/\s+/u',' ',$m[2]));
      $station=preg_replace('/\s+(?:Normal flow|Below Low|Low|Medium|High|Very High|Exceptionally High)$/i','',$station);
      $out[]=['river'=>$river,'station'=>$station,'discharge_cusecs'=>(float)str_replace(',','',$m[3]),'status'=>trim($m[4]),'raw'=>trim($m[0])];
    }}
    // Some FFD builds publish the same data as a compact station table. Parse only explicit cusecs rows.
    if(!$out && preg_match_all('/\b(Indus|Kabul|Jhelum|Chenab|Ravi|Sutlej|Neelum)\b\s*[|·-]\s*([^|]{2,80})\s*[|·-]\s*([0-9][0-9,]*)\s*(?:cusecs|cusec)\s*[|·-]\s*(Normal|Low|Medium|High|Very High|Exceptionally High)/iu',$text,$mm,PREG_SET_ORDER)){
      foreach($mm as $m)$out[]=['river'=>$m[1],'station'=>trim($m[2]),'discharge_cusecs'=>(float)str_replace(',','',$m[3]),'status'=>trim($m[4]),'raw'=>trim($m[0])];
    }
    // De-duplicate repeated summary cards without fabricating station records.
    $unique=[];foreach($out as $r)$unique[strtolower($r['river'].'|'.$r['station'])]=$r;
    return ['updated_label'=>$updated,'records'=>array_values($unique),'page_text_excerpt'=>substr($text,0,1800)];
}
function ffd_sync(PDO $pdo): array {
    $started=date('Y-m-d H:i:s'); $run=$pdo->prepare("INSERT INTO data_sync_runs(source,status,started_at) VALUES('FFD','failed',?)");$run->execute([$started]);$runId=(int)$pdo->lastInsertId();
    try {
      $html=ffd_http_get('https://ffd.pmd.gov.pk/index.php');
      $parsed=ffd_parse_home($html); $records=$parsed['records'];
      if(!$records) throw new RuntimeException('FFD page loaded, but its published river-discharge rows could not be parsed. No readings were invented.');
      $updated=$parsed['updated_label']; $storedLabel=$updated ?: 'Not published by FFD';
      $obsAt=null;if($updated){$dt=DateTime::createFromFormat('d-M-Y H:i',$updated,new DateTimeZone('Asia/Karachi'));if($dt)$obsAt=$dt->format('Y-m-d H:i:s');}
      $ins=$pdo->prepare("INSERT INTO external_gauge_observations(source,river_name,station_name,discharge_cusecs,water_level_m,flood_status,source_observed_at,source_updated_label,fetched_at,raw_text) VALUES('FFD',?,?,?,NULL,?,?,?,?,?) ON DUPLICATE KEY UPDATE discharge_cusecs=VALUES(discharge_cusecs),flood_status=VALUES(flood_status),source_observed_at=VALUES(source_observed_at),fetched_at=VALUES(fetched_at),raw_text=VALUES(raw_text)");
      foreach($records as $r)$ins->execute([$r['river'],$r['station'],$r['discharge_cusecs'],$r['status'],$obsAt,$storedLabel,date('Y-m-d H:i:s'),$r['raw']]);
      $status=count($records)>=4?'success':'partial';$msg='Fetched official FFD homepage summary. Gauge values are discharge in cusecs; FFD homepage summary does not provide measured water height in metres.';
      $u=$pdo->prepare('UPDATE data_sync_runs SET status=?,records_fetched=?,message=?,finished_at=? WHERE id=?');$u->execute([$status,count($records),$msg,date('Y-m-d H:i:s'),$runId]);
      return ['success'=>true,'records'=>count($records),'updated_label'=>$updated,'message'=>$msg];
    } catch(Throwable $e){$u=$pdo->prepare('UPDATE data_sync_runs SET status=\'failed\',message=?,finished_at=? WHERE id=?');$u->execute([$e->getMessage(),date('Y-m-d H:i:s'),$runId]);return ['success'=>false,'records'=>0,'message'=>$e->getMessage()];}
}
