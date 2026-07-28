<?php
// ============================================================
//  config.php — Database Configuration
// ============================================================

define('CB_VERSION', '1.0.0');
define('CONFIG_FILE', __DIR__ . '/db.config.php');

// Hostinger runs PHP in UTC, which rolls the date over mid-evening in US
// timezones — "today" for content ideas and posts then disagrees with the
// browser's day. Pinning the app timezone keeps every date the same day the
// user is actually having. Change this one line to move the business day.
// Valid names: https://www.php.net/manual/en/timezones.php
if (!defined('CB_TIMEZONE')) define('CB_TIMEZONE', 'America/Los_Angeles');
date_default_timezone_set(CB_TIMEZONE);

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

// ── Claude API key (Content Ideas) ─────────────────────────
// Managed here on the API settings page; stored AES-encrypted in the
// settings table with the same key derivation api.php uses to decrypt.
function cb_encrypt_secret($plain) {
    $key = defined('DB_NAME') ? hash('sha256', DB_NAME . 'cb_social_key_2026', true) : str_repeat('x', 32);
    $iv  = openssl_random_pseudo_bytes(16);
    return base64_encode($iv . openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$cb_is_admin = (($_SESSION['cb_user']['role'] ?? '') === 'admin');
$claude_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_claude_key') {
    if (!$cb_is_admin) {
        $claude_error = 'Log in as an admin to save the Claude API key.';
    } else {
        $ckey = trim($_POST['claude_api_key'] ?? '');
        $cpdo = db_connect();
        if ($ckey === '') {
            $claude_error = 'Enter an API key.';
        } elseif (!$cpdo) {
            $claude_error = 'Database not connected — save the database settings first.';
        } else {
            try {
                $cpdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('claude_api_key', ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                     ->execute([cb_encrypt_secret($ckey)]);
                header('Location: config.php?claude=saved');
                exit;
            } catch (Throwable $e) {
                $claude_error = 'Could not save: ' . $e->getMessage();
            }
        }
    }
}

// Show setup page if accessed directly
if (basename($_SERVER['PHP_SELF']) === 'config.php') {
    $connected = db_connected();
    $claude_configured = false;
    if ($connected) {
        try {
            $claude_configured = (bool) db_connect()
                ->query("SELECT setting_value FROM settings WHERE setting_key = 'claude_api_key'")
                ->fetchColumn();
        } catch (Throwable $e) { /* settings table may not exist yet */ }
    }
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

    <hr class="my-4">
    <div class="form-label" style="font-weight:700;font-size:13px;color:#111827">
      Claude API key <span style="font-weight:400;color:#9ca3af">(powers Generate Content Idea)</span>
    </div>
    <?php if (!$cb_is_admin): ?>
      <div style="font-size:12px;color:#9ca3af">Log in to the dashboard as an admin to manage the Claude API key.</div>
    <?php else: ?>
      <div style="font-size:12px;color:<?php echo $claude_configured ? '#16a34a' : '#b45309'; ?>;margin-bottom:8px">
        <?php echo $claude_configured
            ? '&#10003; A key is configured. Saving a new one replaces it.'
            : 'No key configured yet — get one at console.anthropic.com.'; ?>
      </div>
      <?php if (($_GET['claude'] ?? '') === 'saved'): ?>
        <div class="alert alert-success py-2" style="font-size:13px">Claude API key saved.</div>
      <?php endif; ?>
      <?php if ($claude_error): ?>
        <div class="alert alert-danger py-2" style="font-size:13px"><?php echo htmlspecialchars($claude_error); ?></div>
      <?php endif; ?>
      <form method="POST" class="d-flex gap-2">
        <input type="hidden" name="action" value="save_claude_key">
        <input class="form-control" type="password" name="claude_api_key" placeholder="sk-ant-…" autocomplete="off">
        <button type="submit" class="btn btn-at" style="width:auto;white-space:nowrap;padding:11px 18px">Save key</button>
      </form>
    <?php endif; ?>

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
