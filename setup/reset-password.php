<?php
// ============================================================
//  reset-password.php
//  Run this ONCE to set or reset any user's password.
//  DELETE this file after use!
// ============================================================
require_once __DIR__ . '/config.php';

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']     ?? '';
    $confirm  = $_POST['confirm']      ?? '';

    if (!$username || !$password) {
        $message = 'Username and password are required.';
    } elseif ($password !== $confirm) {
        $message = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters.';
    } else {
        $pdo = db_connect();
        if (!$pdo) {
            $message = 'Database not connected. Run config.php first.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

            // Check if user exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user) {
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = ?")
                    ->execute([$hash, $username]);
                $message = 'Password updated for user "' . htmlspecialchars($username) . '".';
                $success = true;
            } else {
                // Create admin user if not exists
                $pdo->prepare("INSERT INTO users (id, username, password_hash, role, full_name, is_active) VALUES (?,?,?,'admin',?,1)")
                    ->execute([uniqid('usr_'), $username, $hash, ucfirst($username)]);
                $message = 'User "' . htmlspecialchars($username) . '" created as admin.';
                $success = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reset Password — Content Board</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#0f172a;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;font-family:'Segoe UI',system-ui,sans-serif;}
    .card{width:100%;max-width:420px;border-radius:14px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.4);}
    .card-header{background:#dc2626;border-radius:14px 14px 0 0;padding:20px 24px;color:#fff;}
    .card-body{padding:24px;}
    .form-label{font-size:12px;font-weight:600;color:#374151;}
    .btn-reset{background:#dc2626;border:none;color:#fff;padding:10px;font-weight:600;width:100%;border-radius:8px;}
    .btn-reset:hover{background:#b91c1c;color:#fff;}
    .warning{background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px;font-size:12px;color:#b91c1c;margin-bottom:16px;}
  </style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Reset Password</h5>
    <small style="opacity:.8">Content Board — one-time utility</small>
  </div>
  <div class="card-body">

    <div class="warning">
      ⚠️ <strong>Delete this file after use.</strong> Anyone who can access it can change any password.
    </div>

    <?php if ($message): ?>
    <div class="alert <?= $success ? 'alert-success' : 'alert-danger' ?>" style="font-size:13px">
      <?= $message ?>
      <?php if ($success): ?>
      <hr><a href="login.php" class="btn btn-sm btn-success">Go to login →</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST">
      <div class="mb-3">
        <label class="form-label">Username</label>
        <input class="form-control" name="username" value="admin" required>
        <div class="form-text">Default admin username is <code>admin</code></div>
      </div>
      <div class="mb-3">
        <label class="form-label">New password</label>
        <input class="form-control" name="password" type="password" placeholder="Min 6 characters" required>
      </div>
      <div class="mb-4">
        <label class="form-label">Confirm password</label>
        <input class="form-control" name="confirm" type="password" required>
      </div>
      <button type="submit" class="btn btn-reset">Set password</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</body>
</html>
