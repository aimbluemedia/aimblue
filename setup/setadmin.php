<?php
require_once __DIR__ . '/config.php';
$pdo = db_connect();
if(!$pdo){ die('DB not connected — run config.php first'); }

$pass = 'Admin2026';
$hash = password_hash($pass, PASSWORD_BCRYPT);

// Update or insert admin user
$pdo->exec("DELETE FROM users WHERE username = 'admin'");
$pdo->prepare("INSERT INTO users (id, username, password_hash, role, full_name, email, is_active)
               VALUES ('usr_admin','admin',?,  'admin','Administrator','admin@contentboard.com',1)")
    ->execute([$hash]);

echo '<h2 style="font-family:sans-serif;color:green">✓ Admin user set</h2>
      <p style="font-family:sans-serif">
        Username: <strong>admin</strong><br>
        Password: <strong>Admin2026</strong>
      </p>
      <p style="font-family:sans-serif;color:red"><strong>Delete this file immediately after logging in.</strong></p>
      <p><a href="login.php" style="font-family:sans-serif">Go to login →</a></p>';
