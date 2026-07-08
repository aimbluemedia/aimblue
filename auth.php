<?php
// ============================================================
//  auth.php — Session & Authentication
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

function auth_user()    { return isset($_SESSION['cb_user']) ? $_SESSION['cb_user'] : null; }
function auth_role()    { return isset($_SESSION['cb_user']['role']) ? $_SESSION['cb_user']['role'] : null; }
function auth_id()      { return isset($_SESSION['cb_user']['id'])   ? $_SESSION['cb_user']['id']   : null; }
function is_admin()     { return auth_role() === 'admin'; }
function is_logged_in() { return auth_user() !== null; }

function has_role($role) {
    $r = auth_role();
    if (!$r) return false;
    return in_array($role, explode(',', $r));
}

function require_login($redirect = 'login.php') {
    if (!is_logged_in()) { header('Location: ' . $redirect); exit; }
}

function attempt_login($username, $password) {
    $pdo = db_connect();
    if (!$pdo) return false;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->execute(array($username));
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) return false;

    // Gather company IDs from both CM and SP person links
    $companyIds = array();
    foreach (array('cm_person_id', 'sp_person_id') as $col) {
        if (!empty($user[$col])) {
            $s = $pdo->prepare("SELECT company_id FROM person_companies WHERE person_id = ?");
            $s->execute(array($user[$col]));
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                if (!in_array($cid, $companyIds)) $companyIds[] = $cid;
            }
        }
    }

    $_SESSION['cb_user'] = array(
        'id'           => $user['id'],
        'username'     => $user['username'],
        'full_name'    => $user['full_name'],
        'email'        => $user['email'],
        'role'         => $user['role'],
        'cm_person_id' => $user['cm_person_id'],
        'sp_person_id' => $user['sp_person_id'],
        'company_ids'  => $companyIds,
    );

    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute(array($user['id']));
    return true;
}

function logout() {
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

function user_company_filter() {
    if (is_admin()) return null;
    return isset($_SESSION['cb_user']['company_ids']) ? $_SESSION['cb_user']['company_ids'] : array();
}

if (isset($_GET['logout'])) logout();
