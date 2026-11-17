<?php
// FFD Live Portal Reverse Proxy & Embed Handler
// Bypasses X-Frame-Options: SAMEORIGIN sent by ffd.pmd.gov.pk
// Allows seamless embedding in AquaTwin iframe on localhost and all screens

header_remove('X-Frame-Options');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=180');

$targetUrl = 'https://ffd.pmd.gov.pk/index.php/river-state';

$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 AquaTwin/1.0',
    CURLOPT_HTTPHEADER     => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
    ]
]);

$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && !empty($html)) {
    // Inject <base href="https://ffd.pmd.gov.pk/"> so all styles, JS, maps and images load directly from PMD
    $baseTag = '<base href="https://ffd.pmd.gov.pk/">';
    $customStyles = '<style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important; }
        html, body { width: 100% !important; height: 100% !important; margin: 0 !important; overflow: auto !important; }
    </style>';

    if (stripos($html, '<head>') !== false) {
        $html = str_ireplace('<head>', '<head>' . $baseTag . $customStyles, $html);
    } elseif (stripos($html, '<head ') !== false) {
        $html = preg_replace('/<head([^>]*)>/i', '<head$1>' . $baseTag . $customStyles, $html, 1);
    } else {
        $html = $baseTag . $customStyles . $html;
    }

    echo $html;
    exit;
}

// Fallback HTML if PMD portal is temporarily unreachable
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      margin: 0; padding: 24px; font-family: Inter, ui-sans-serif, system-ui, -apple-system, sans-serif;
      background: #f8fafb; color: #172b3a; display: flex; align-items: center; justify-content: center;
      min-height: 100vh; box-sizing: border-box;
    }
    .card {
      background: white; border: 1px solid #dce6eb; border-radius: 14px; padding: 26px;
      max-width: 520px; text-align: center; box-shadow: 0 4px 18px rgba(0,0,0,0.06);
    }
    .icon { font-size: 38px; margin-bottom: 10px; }
    h2 { margin: 0 0 10px; font-size: 18px; color: #0e4567; }
    p { margin: 0 0 16px; color: #637787; font-size: 13.5px; line-height: 1.5; }
    .btn {
      display: inline-block; padding: 10px 18px; background: #126a91; color: white;
      text-decoration: none; border-radius: 9px; font-weight: 700; font-size: 13px;
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">🌊</div>
    <h2>Pakistan FFD River Status</h2>
    <p>The official Flood Forecasting Division portal connection took too long to respond. You can open the live portal directly in a new tab:</p>
    <a class="btn" href="https://ffd.pmd.gov.pk/index.php/river-state" target="_blank" rel="noopener">Open FFD Portal Directly ↗</a>
  </div>
</body>
</html>

