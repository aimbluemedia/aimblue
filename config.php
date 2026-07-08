<?php
// ============================================================
//  config.php — Database Configuration
// ============================================================

define('CB_VERSION', '1.0.0');
define('CONFIG_FILE', __DIR__ . '/db.config.php');

// Load saved config if exists
if (file_exists(CONFIG_FILE)) {
    require_once CONFIG_FILE;
}

function db_connect() {
    if (!defined('DB_HOST')) return null;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            )
        );
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

function db_connected() {
    return db_connect() !== null;
}

// Handle save config form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_config') {
    $host = trim($_POST['db_host']);
    $name = trim($_POST['db_name']);
    $user = trim($_POST['db_user']);
    $pass = $_POST['db_pass'];

    $test_error = null;
    try {
        new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass);
    } catch (Throwable $e) {
        $test_error = 'Could not connect: ' . $e->getMessage();
    }

    if (!$test_error) {
        $cfg = "<?php\n" .
            "define('DB_HOST', " . var_export($host, true) . ");\n" .
            "define('DB_NAME', " . var_export($name, true) . ");\n" .
            "define('DB_USER', " . var_export($user, true) . ");\n" .
            "define('DB_PASS', " . var_export($pass, true) . ");\n";
        // file_put_contents failure was silent before — the wizard looped
        // back to an empty form with no explanation.
        if (@file_put_contents(CONFIG_FILE, $cfg) === false
            || @file_get_contents(CONFIG_FILE) !== $cfg) {
            $test_error = 'Connection OK, but the settings file could not be written. '
                . 'Check that the web server may write to ' . htmlspecialchars(dirname(CONFIG_FILE))
                . ' (folder permissions should be 755 and owned by your hosting account).';
        } else {
            header('Location: index.php');
            exit;
        }
    }
}

// Show setup page if accessed directly
if (basename($_SERVER['PHP_SELF']) === 'config.php') {
    $connected = db_connected();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Content Board — Database Setup</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#0f172a;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;font-family:system-ui,sans-serif;}
    .card{width:100%;max-width:480px;border-radius:14px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.4);overflow:hidden;}
    .card-hdr{background:#6c47ff;padding:24px;color:#fff;}
    .card-hdr h1{font-size:18px;font-weight:700;margin:0;}
    .card-hdr p{font-size:13px;opacity:.8;margin:4px 0 0;}
    .card-body{padding:24px;}
    .form-label{font-size:12px;font-weight:600;color:#374151;}
    .btn-at{background:#6c47ff;border:none;color:#fff;padding:11px;font-weight:600;border-radius:8px;width:100%;}
    .btn-at:hover{background:#5a38e8;color:#fff;}
  </style>
</head>
<body>
<div class="card">
  <div class="card-hdr">
    <h1>Database Configuration</h1>
    <p><?php echo $connected ? '✓ Connected' : '⚠ Not connected'; ?></p>
  </div>
  <div class="card-body">
    <?php if (!empty($test_error)): ?>
    <div class="alert alert-danger" style="font-size:13px"><?php echo htmlspecialchars($test_error); ?></div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="action" value="save_config">
      <div class="mb-3">
        <label class="form-label">Host</label>
        <input class="form-control" name="db_host" value="<?php echo defined('DB_HOST') ? htmlspecialchars(DB_HOST) : 'localhost'; ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Database name</label>
        <input class="form-control" name="db_name" value="<?php echo defined('DB_NAME') ? htmlspecialchars(DB_NAME) : ''; ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Username</label>
        <input class="form-control" name="db_user" value="<?php echo defined('DB_USER') ? htmlspecialchars(DB_USER) : ''; ?>" required>
      </div>
      <div class="mb-4">
        <label class="form-label">Password</label>
        <input class="form-control" type="password" name="db_pass" value="<?php echo defined('DB_PASS') ? htmlspecialchars(DB_PASS) : ''; ?>">
      </div>
      <button type="submit" class="btn btn-at">Save &amp; test connection</button>
    </form>
    <?php if ($connected): ?>
    <div class="mt-3 text-center">
      <a href="index.php" class="btn btn-outline-secondary btn-sm">Back to dashboard</a>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
<?php
    exit;
}
