<?php
require __DIR__.'/../app/bootstrap.php';
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){csrf_check();$s=$pdo->prepare('SELECT id,name,email,password_hash,role,is_active FROM users WHERE email=? LIMIT 1');$s->execute([trim($_POST['email']??'')]);$u=$s->fetch();
 if($u && $u['is_active'] && password_verify($_POST['password']??'',$u['password_hash'])){session_regenerate_id(true);$_SESSION['user']=$u;audit($pdo,'login','Successful sign in');header('Location: '.($u['role']==='Admin'?'admin.php':'operator.php'));exit;} $error='Email or password is incorrect.';}
layout_header('Staff sign in');?>
<div class="card form-card">
  <div style="text-align:center;margin-bottom:16px">
    <img src="assets/aquatwin-logo.png" alt="AquaTwin" style="height:48px;max-width:180px;object-fit:contain">
  </div>
  <div class="eyebrow">STAFF ACCESS</div>
  <h2>Sign in to AquaTwin</h2>
  <p class="muted">For administrators and assigned area operators.</p>
  <?php if($error): ?>
    <script>document.addEventListener('DOMContentLoaded', function(){ toastr.error(<?=json_encode($error)?>, 'Authentication Failed'); });</script>
    <p class="danger"><?=e($error)?></p>
  <?php endif; ?>
  <form method="post" id="form-login">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <label>Email Address <span class="req">*</span>
      <input name="email" type="email" placeholder="name@aquatwin.local" required autocomplete="email">
    </label>
    <label>Password <span class="req">*</span>
      <input name="password" type="password" placeholder="Enter your password" required autocomplete="current-password">
    </label>
    <button type="submit">Sign in</button>
  </form>
  <p class="muted" style="font-size:12.5px;margin-top:16px">Public flood forecasts do not require staff credentials.</p>
</div>
<?php layout_footer(); ?>
