<?php
// Run from Windows Task Scheduler every 15 minutes or Linux cron.
require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/ffd_sync.php';
$result=ffd_sync($pdo); echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
if(!$result['success']) exit(1);
