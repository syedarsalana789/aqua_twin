<?php
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('Session token expired. Go back and try again.');
    }
}
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_role(string $role): void {
    $u = current_user();
    if (!$u || $u['role'] !== $role) { header('Location: login.php'); exit; }
}
function audit(PDO $pdo, string $action, string $detail=''): void {
    $u = current_user();
    $s = $pdo->prepare('INSERT INTO audit_logs(user_id, action, details, ip_address) VALUES(?,?,?,?)');
    $s->execute([$u['id'] ?? null, $action, $detail, $_SERVER['REMOTE_ADDR'] ?? null]);
}
function layout_header(string $title, bool $admin=false): void { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?=e($title)?> · AquaTwin</title>
<link rel="icon" type="image/png" href="assets/aquatwin-favicon.png">
<link rel="shortcut icon" href="favicon.ico">
<link rel="apple-touch-icon" href="assets/aquatwin-favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head><body><header class="topbar"><div class="topbar-container"><a class="brand" href="index.php"><img class="brand-logo" src="assets/aquatwin-logo.png" alt="AquaTwin"></a>
<button class="nav-toggle" id="nav-toggle" type="button" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="nav-menu"><span class="nav-bar"></span><span class="nav-bar"></span><span class="nav-bar"></span></button>
<nav class="nav-menu" id="nav-menu"><a class="nav-link" href="index.php">Flood Monitor</a><a class="nav-link" href="index.php#map">Map</a><?php if(current_user()): ?><a class="nav-link" href="<?=current_user()['role']==='Admin'?'admin.php':'operator.php'?>">Dashboard</a><a class="nav-link nav-btn" href="logout.php"><span class="user-greeting"><?=e(current_user()['name'])?></span> · Sign out</a><?php else: ?><a class="nav-link nav-btn" href="login.php">Staff sign in</a><?php endif; ?></nav></div></header>
<main class="page"><?php }
function layout_footer(): void { ?><footer class="footer"><div class="footer-inner">AquaTwin · Independent weather and flood intelligence. Verify emergency instructions with official authorities.</div></footer>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="assets/app.js"></script></main></body></html><?php }
