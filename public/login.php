<?php
// ============================================================
//  login.php — Content Board Login
// ============================================================
require_once __DIR__ . '/auth.php';

// Already logged in?
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!$username || !$password) {
        $error = 'Please enter your username and password.';
    } elseif (!db_connected()) {
        $error = 'Database not connected. <a href="config.php">Configure database →</a>';
    } elseif (!attempt_login($username, $password)) {
        $error = 'Invalid username or password.';
        sleep(1); // slow brute force
    } else {
        $redirect = $_GET['redirect'] ?? 'index.php';
        header('Location: ' . $redirect);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Content Board — Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    *{box-sizing:border-box;}
    body{
      background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 100%);
      min-height:100vh;display:flex;align-items:center;justify-content:center;
      padding:20px;font-family:'Segoe UI',system-ui,sans-serif;
    }
    .login-card{
      width:100%;max-width:420px;
      background:#fff;border-radius:18px;
      box-shadow:0 24px 80px rgba(0,0,0,.45);overflow:hidden;
    }
    .login-header{
      background:#6c47ff;padding:32px 32px 28px;
      display:flex;flex-direction:column;align-items:center;text-align:center;
    }
    .logo-box{
      width:56px;height:56px;border-radius:14px;
      background:rgba(255,255,255,.15);
      display:flex;align-items:center;justify-content:center;
      font-size:24px;font-weight:900;color:#fff;letter-spacing:-1px;
      margin-bottom:14px;
    }
    .login-header h1{font-size:22px;font-weight:800;color:#fff;margin:0;}
    .login-header p{font-size:13px;color:rgba(255,255,255,.7);margin:5px 0 0;}
    .login-body{padding:32px;}
    .form-label{font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;}
    .form-control{
      border:1.5px solid #e2e8f0;border-radius:9px;
      font-size:14px;padding:10px 14px;color:#0f172a;
    }
    .form-control:focus{border-color:#6c47ff;box-shadow:0 0 0 3px rgba(108,71,255,.12);}
    .input-icon{position:relative;}
    .input-icon .bi{
      position:absolute;left:12px;top:50%;transform:translateY(-50%);
      color:#94a3b8;font-size:15px;pointer-events:none;
    }
    .input-icon .form-control{padding-left:38px;}
    .btn-login{
      background:#6c47ff;border:none;color:#fff;
      padding:12px;font-size:14px;font-weight:700;
      border-radius:9px;width:100%;
      transition:background .15s;
    }
    .btn-login:hover{background:#5a38e8;color:#fff;}
    .btn-login:disabled{opacity:.6;}
    .err-box{
      background:#fef2f2;border:1px solid #fca5a5;
      border-radius:8px;padding:10px 14px;
      font-size:13px;color:#b91c1c;margin-bottom:16px;
    }
    .role-hint{
      font-size:11px;color:#94a3b8;text-align:center;margin-top:16px;
      line-height:1.6;
    }
    .db-warn{
      background:#fffbeb;border:1px solid #fde68a;
      border-radius:8px;padding:10px 14px;
      font-size:13px;color:#92400e;margin-bottom:16px;
      display:flex;align-items:center;gap:8px;
    }
    .toggle-pw{
      position:absolute;right:12px;top:50%;transform:translateY(-50%);
      background:none;border:none;color:#94a3b8;cursor:pointer;padding:0;
      font-size:15px;
    }
  </style>
</head>
<body>

<div class="login-card">
  <div class="login-header">
    <div class="logo-box">CB</div>
    <h1>Content Board</h1>
    <p>Sign in to your workspace</p>
  </div>

  <div class="login-body">

    <?php if (!db_connected()): ?>
    <div class="db-warn">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span>Database not configured. <a href="config.php" style="color:#92400e;font-weight:600">Set up database →</a></span>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="err-box"><i class="bi bi-x-circle me-2"></i><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" id="loginForm">
      <div class="mb-3">
        <label class="form-label" for="username">Username</label>
        <div class="input-icon">
          <i class="bi bi-person"></i>
          <input class="form-control" type="text" id="username" name="username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
            placeholder="Enter username" autocomplete="username" autofocus required>
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label" for="password">Password</label>
        <div class="input-icon" style="position:relative">
          <i class="bi bi-lock"></i>
          <input class="form-control" type="password" id="password" name="password"
            placeholder="Enter password" autocomplete="current-password" required>
          <button type="button" class="toggle-pw" onclick="togglePw()" tabindex="-1">
            <i class="bi bi-eye" id="pwEyeIcon"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-login" id="btnLogin">
        <i class="bi bi-box-arrow-in-right me-2"></i>Sign in
      </button>
    </form>

    <div class="role-hint">
      <strong>Admin</strong> — sees all companies &amp; full access<br>
      <strong>Content Manager / Sales Person</strong> — sees assigned companies only
    </div>

  </div>
</div>

<script>
function togglePw(){
  const inp = document.getElementById('password');
  const icon = document.getElementById('pwEyeIcon');
  if(inp.type==='password'){inp.type='text';icon.className='bi bi-eye-slash';}
  else{inp.type='password';icon.className='bi bi-eye';}
}
document.getElementById('loginForm').onsubmit = function(){
  const btn = document.getElementById('btnLogin');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Signing in…';
};
</script>
</body>
</html>
