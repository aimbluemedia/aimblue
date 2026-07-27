<?php
// ============================================================
//  api.php — Content Board REST API
//  All responses are JSON. Called by index.php via fetch().
// ============================================================

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS for local dev
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// An expired session must yield JSON 401, never a redirect — fetch() would
// silently follow it to the login page and choke parsing HTML as JSON.
if (!is_logged_in()) {
    json_error('Not authenticated. Please log in again.', 401);
}

$pdo = db_connect();
if (!$pdo) {
    json_error('Database not connected. Please run config.php first.', 503);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Route ─────────────────────────────────────────────────
try {
    switch ($action) {

        // ── COMPANIES ──────────────────────────────────────

        case 'companies':
            if ($method === 'GET') {
                $rows = $pdo->query("
                    SELECT c.*,
                           cm.name AS content_manager_name,
                           sp.name AS sales_person_name
                    FROM companies c
                    LEFT JOIN people cm ON cm.id = c.content_manager_id
                    LEFT JOIN people sp ON sp.id = c.sales_person_id
                    ORDER BY c.name
                ")->fetchAll();

                // Attach posts counts
                $counts = $pdo->query("
                    SELECT company_id,
                           COUNT(*) AS total,
                           SUM(status='published') AS published,
                           SUM(status='scheduled') AS scheduled
                    FROM posts GROUP BY company_id
                ")->fetchAll(PDO::FETCH_UNIQUE);

                foreach ($rows as &$co) {
                    $co['posting_days'] = $co['posting_days']
                        ? explode(',', $co['posting_days']) : [];
                    $co['monthly_posts'] = $co['monthly_posts'] ? (int)$co['monthly_posts'] : null;
                    $co['fee']           = $co['fee'] ? (float)$co['fee'] : null;
                    $co['fee_sp_pct']    = (float)$co['fee_sp_pct'];
                    $co['fee_cm_pct']    = (float)$co['fee_cm_pct'];
                    $co['fee_sm_pct']    = (float)$co['fee_sm_pct'];
                    $co['payment_date']  = $co['payment_date'] ? (int)$co['payment_date'] : null;
                    $co['post_counts']   = $counts[$co['id']] ?? ['total'=>0,'published'=>0,'scheduled'=>0];
                }

                // CM/SP only see their own companies — must match the filter
                // the index.php PHP loader applies, or the background sync
                // would overwrite the filtered list with everything.
                $filter = user_company_filter();
                if ($filter !== null) {
                    $rows = array_values(array_filter($rows, function($co) use ($filter) {
                        return in_array($co['id'], $filter);
                    }));
                }
                json_ok($rows);
            }
            break;

        case 'save_company':
            if (in_array($method, ['POST','PUT'])) {
                // Company info (incl. CM/SP assignment, fees, schedule) is
                // admin-only; CM/SP roles only get posting access.
                if (!is_admin()) json_error('Forbidden — only admins can change company info.', 403);
                $id      = $body['id']      ?? uid();
                $name    = $body['name']    ?? '';
                $color   = $body['color']   ?? '#6c47ff';
                $cmId    = (isset($body['content_manager_id']) && $body['content_manager_id'] !== '') ? $body['content_manager_id'] : null;
                $spId    = (isset($body['sales_person_id'])    && $body['sales_person_id']    !== '') ? $body['sales_person_id']    : null;
                $posts   = isset($body['monthly_posts']) ? (int)$body['monthly_posts'] : null;
                $fee     = isset($body['fee']) ? (float)$body['fee'] : null;
                $spPct   = isset($body['fee_sp_pct']) ? (float)$body['fee_sp_pct'] : 40;
                $cmPct   = isset($body['fee_cm_pct']) ? (float)$body['fee_cm_pct'] : 40;
                $smPct   = isset($body['fee_sm_pct']) ? (float)$body['fee_sm_pct'] : 20;
                $payDay  = isset($body['payment_date']) ? (int)$body['payment_date'] : null;
                $days   = isset($body['posting_days']) ? implode(',', (array)$body['posting_days']) : null;

                // Clean platform_config before persisting: new_login blobs
                // carry a plaintext password and must never be stored — only
                // stable fields survive. New logins are created further down
                // and their ids folded back in.
                $rawCfg   = (isset($body['platform_config']) && is_array($body['platform_config'])) ? $body['platform_config'] : [];
                $cleanCfg = [];
                foreach ($rawCfg as $platKey => $platData) {
                    if (!is_array($platData)) continue;
                    $entry = [];
                    if (!empty($platData['social_login_id'])) $entry['social_login_id'] = $platData['social_login_id'];
                    if (!empty($platData['days']) && is_array($platData['days'])) $entry['days'] = array_values($platData['days']);
                    if ($entry) $cleanCfg[$platKey] = $entry;
                }
                $platCfg = $cleanCfg ? json_encode($cleanCfg) : null;

                if (!$name) json_error('Company name is required.', 422);

                // Validate person IDs exist - silently null if not found
                if ($cmId) {
                    $chk = $pdo->prepare("SELECT id FROM people WHERE id = ?");
                    $chk->execute([$cmId]);
                    if (!$chk->fetch()) $cmId = null;
                }
                if ($spId) {
                    $chk = $pdo->prepare("SELECT id FROM people WHERE id = ?");
                    $chk->execute([$spId]);
                    if (!$chk->fetch()) $spId = null;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO companies
                      (id, name, color, content_manager_id, sales_person_id, monthly_posts,
                       fee, fee_sp_pct, fee_cm_pct, fee_sm_pct, payment_date, posting_days, platform_config)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      name=VALUES(name), color=VALUES(color),
                      content_manager_id=VALUES(content_manager_id),
                      sales_person_id=VALUES(sales_person_id),
                      monthly_posts=VALUES(monthly_posts), fee=VALUES(fee),
                      fee_sp_pct=VALUES(fee_sp_pct), fee_cm_pct=VALUES(fee_cm_pct),
                      fee_sm_pct=VALUES(fee_sm_pct), payment_date=VALUES(payment_date),
                      posting_days=VALUES(posting_days),
                      platform_config=VALUES(platform_config)
                ");
                $stmt->execute([$id,$name,$color,$cmId,$spId,$posts,$fee,$spPct,$cmPct,$smPct,$payDay,$days,$platCfg]);

                // Content Ideas prompt — only touched when the client sends it
                if (array_key_exists('content_prompt', $body)) {
                    ensure_content_ideas($pdo);
                    $pdo->prepare("UPDATE companies SET content_prompt = ? WHERE id = ?")
                        ->execute([trim((string)$body['content_prompt']) ?: null, $id]);
                }

                // Create social logins declared inline in platform_config
                // (added from the Add/Edit Company modal), then fold their
                // ids into the cleaned config.
                $createdNew = false;
                foreach ($rawCfg as $platKey => $platData) {
                    if (!is_array($platData) || empty($platData['new_login']) || !is_array($platData['new_login'])) continue;
                    $nl = $platData['new_login'];
                    if (empty($nl['username']) && empty($nl['channel_url'])) continue;
                    $slId = uniqid('sl_', true);
                    $enc  = (!empty($nl['password'])) ? encrypt_pw($nl['password']) : null;
                    $pdo->prepare("INSERT INTO social_logins (id, platform, title, channel_url, username, password_enc, notes) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$slId, $platKey,
                            (!empty($nl['title'])) ? $nl['title'] : ($platKey . ' — ' . $name),
                            (!empty($nl['channel_url'])) ? $nl['channel_url'] : null,
                            (!empty($nl['username']))    ? $nl['username']    : null,
                            $enc,
                            (!empty($nl['notes'])) ? $nl['notes'] : null]);
                    $cleanCfg[$platKey]['social_login_id'] = $slId;
                    $createdNew = true;
                }

                // Keep the vault's company links in sync: every login this
                // company references becomes visible to its CM/SP users.
                $lnk = $pdo->prepare("INSERT IGNORE INTO social_login_companies (login_id, company_id) VALUES (?,?)");
                foreach ($cleanCfg as $platData) {
                    if (!empty($platData['social_login_id'])) $lnk->execute([$platData['social_login_id'], $id]);
                }

                if ($createdNew) {
                    $pdo->prepare("UPDATE companies SET platform_config = ? WHERE id = ?")
                        ->execute([json_encode($cleanCfg), $id]);
                }

                // Return the final config so the client can mirror it without
                // ever holding the new_login blob (or its password) again.
                json_ok(['id' => $id, 'platform_config' => $cleanCfg ?: null]);
            }
            break;

        case 'delete_company':
            if ($method === 'DELETE' || $method === 'POST') {
                if (!is_admin()) json_error('Forbidden — only admins can delete companies.', 403);
                $id = $_GET['id'] ?? $body['id'] ?? '';
                if (!$id) json_error('Missing id', 422);
                $pdo->prepare("DELETE FROM companies WHERE id = ?")->execute([$id]);
                json_ok(['deleted' => $id]);
            }
            break;

        // ── POSTS ──────────────────────────────────────────

        case 'posts':
            if ($method === 'GET') {
                $co_id = $_GET['company_id'] ?? null;
                if (!$co_id) json_error('company_id required', 422);
                $filter = user_company_filter();
                if ($filter !== null && !in_array($co_id, $filter)) json_error('Forbidden', 403);
                $stmt = $pdo->prepare("
                    SELECT * FROM posts WHERE company_id = ? ORDER BY post_date, platform
                ");
                $stmt->execute([$co_id]);
                json_ok($stmt->fetchAll());
            }
            break;

        case 'save_post':
            if (in_array($method, ['POST','PUT'])) {
                // Posting access: admins and content managers, own companies only
                if (!is_admin() && !has_role('content_manager')) json_error('Forbidden', 403);
                $id       = $body['id']         ?? uid();
                $coId     = $body['company_id'] ?? '';
                $filter   = user_company_filter();
                if ($filter !== null && $coId && !in_array($coId, $filter)) json_error('Forbidden', 403);
                $title    = $body['title']      ?? '';
                $platform = $body['platform']   ?? '';
                $status   = $body['status']     ?? 'scheduled';
                $date     = $body['date']       ?? null;
                $assignee = $body['assignee']   ?? null;
                $url      = $body['post_url']   ?? null;

                if (!$coId || !$title || !$platform) json_error('company_id, title and platform required.', 422);

                $stmt = $pdo->prepare("
                    INSERT INTO posts (id, company_id, title, platform, status, post_date, assignee, post_url)
                    VALUES (?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      title=VALUES(title), platform=VALUES(platform), status=VALUES(status),
                      post_date=VALUES(post_date), assignee=VALUES(assignee), post_url=VALUES(post_url)
                ");
                $stmt->execute([$id,$coId,$title,$platform,$status,$date?:null,$assignee,$url]);
                json_ok(['id' => $id]);
            }
            break;

        case 'delete_post':
            if ($method === 'DELETE' || $method === 'POST') {
                if (!is_admin() && !has_role('content_manager')) json_error('Forbidden', 403);
                $id = $_GET['id'] ?? $body['id'] ?? '';
                if (!$id) json_error('Missing id', 422);
                $filter = user_company_filter();
                if ($filter !== null) {
                    $own = $pdo->prepare("SELECT company_id FROM posts WHERE id = ?");
                    $own->execute([$id]);
                    $pco = $own->fetchColumn();
                    if ($pco && !in_array($pco, $filter)) json_error('Forbidden', 403);
                }
                $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$id]);
                json_ok(['deleted' => $id]);
            }
            break;

        // ── PEOPLE ─────────────────────────────────────────

        case 'people':
            if ($method === 'GET') {
                ensure_color_columns($pdo);
                $role = $_GET['role'] ?? null;
                if ($role) {
                    $stmt = $pdo->prepare("SELECT * FROM people WHERE role = ? ORDER BY name");
                    $stmt->execute([$role]);
                } else {
                    $stmt = $pdo->query("SELECT * FROM people ORDER BY role, name");
                }
                $people = $stmt->fetchAll();

                // Attach company IDs
                $links = $pdo->query("SELECT person_id, company_id FROM person_companies")->fetchAll();
                $map = [];
                foreach ($links as $l) $map[$l['person_id']][] = $l['company_id'];
                foreach ($people as &$p) $p['company_ids'] = $map[$p['id']] ?? [];

                json_ok($people);
            }
            break;

        case 'save_person':
            if (in_array($method, ['POST','PUT'])) {
                if (!is_admin()) json_error('Forbidden — only admins can manage people.', 403);
                $id         = $body['id']    ?? uid();
                $role       = $body['role']  ?? '';
                $name       = $body['name']  ?? '';
                $email      = $body['email'] ?? null;
                $notes      = $body['notes'] ?? null;
                $companyIds = $body['company_ids'] ?? [];

                if (!$role || !$name) json_error('role and name required.', 422);

                // people.email is UNIQUE — if another person already holds
                // this email (e.g. the same human's other-role record), store
                // NULL instead of letting ON DUPLICATE KEY hijack that row.
                if ($email) {
                    $chk = $pdo->prepare("SELECT id FROM people WHERE email = ? AND id <> ?");
                    $chk->execute([$email, $id]);
                    if ($chk->fetch()) $email = null;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO people (id, role, name, email, notes)
                    VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      role=VALUES(role), name=VALUES(name),
                      email=VALUES(email), notes=VALUES(notes)
                ");
                $stmt->execute([$id,$role,$name,$email?:null,$notes]);

                // Update company links
                $pdo->prepare("DELETE FROM person_companies WHERE person_id = ?")->execute([$id]);
                $ins = $pdo->prepare("INSERT IGNORE INTO person_companies (person_id, company_id) VALUES (?,?)");
                foreach ($companyIds as $cid) $ins->execute([$id, $cid]);

                // Link this person to a user account (people are picked from
                // the users dropdown). Sets cm_person_id / sp_person_id and
                // ensures the user's role string includes the role.
                $userId = $body['user_id'] ?? null;
                if ($userId && is_admin()) {
                    $col   = ($role === 'sales_person') ? 'sp_person_id' : 'cm_person_id';
                    $ucols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
                    if (in_array($col, $ucols)) {
                        $ustmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
                        $ustmt->execute([$userId]);
                        $usr = $ustmt->fetch();
                        if ($usr) {
                            // A person belongs to one account per role — move the
                            // link if another account previously held it.
                            $pdo->prepare("UPDATE users SET $col = NULL WHERE $col = ? AND id <> ?")->execute([$id, $userId]);
                            $pdo->prepare("UPDATE users SET $col = ? WHERE id = ?")->execute([$id, $userId]);
                            $uroles = array_values(array_filter(array_map('trim', explode(',', (string)$usr['role']))));
                            if (!in_array('admin', $uroles) && !in_array($role, $uroles)) {
                                $uroles[] = $role;
                                $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([implode(',', $uroles), $userId]);
                            }
                        }
                    }
                }

                json_ok(['id' => $id]);
            }
            break;

        case 'delete_person':
            if ($method === 'DELETE' || $method === 'POST') {
                if (!is_admin()) json_error('Forbidden — only admins can manage people.', 403);
                $id = $_GET['id'] ?? $body['id'] ?? '';
                if (!$id) json_error('Missing id', 422);
                $pdo->prepare("DELETE FROM people WHERE id = ?")->execute([$id]);
                json_ok(['deleted' => $id]);
            }
            break;

        // ── DASHBOARD ──────────────────────────────────────

        case 'dashboard':
            $summary    = $pdo->query("SELECT * FROM v_company_summary")->fetchAll();
            $revenue    = $pdo->query("SELECT * FROM v_revenue_totals")->fetch();
            $today      = $pdo->query("SELECT * FROM v_posting_today")->fetchAll();
            $team       = $pdo->query("SELECT * FROM v_team_earnings ORDER BY total_monthly_earnings DESC")->fetchAll();

            foreach ($summary as &$co) {
                $co['posting_days'] = $co['posting_days'] ? explode(',', $co['posting_days']) : [];
                $co['fee']    = $co['fee'] ? (float)$co['fee'] : null;
                foreach (['published','scheduled','total_posts'] as $k) $co[$k] = (int)$co[$k];
            }
            json_ok(compact('summary','revenue','today','team'));
            break;

        case 'platform_stats':
            $co_id = $_GET['company_id'] ?? null;
            $year  = $_GET['year']  ?? null;
            $month = $_GET['month'] ?? null;
            if (!$co_id) json_error('company_id required', 422);

            $where = 'company_id = ?';
            $params = [$co_id];
            if ($year && $month) {
                $where .= ' AND YEAR(post_date) = ? AND MONTH(post_date) = ?';
                $params[] = (int)$year;
                $params[] = (int)$month;
            }

            $stmt = $pdo->prepare("
                SELECT platform,
                       COUNT(*) AS total,
                       SUM(status='published') AS published,
                       SUM(status='scheduled') AS scheduled
                FROM posts
                WHERE $where
                GROUP BY platform
            ");
            $stmt->execute($params);
            json_ok($stmt->fetchAll());
            break;

        // ── USERS (admin only) ────────────────────────────

        case 'users':
            if (!is_admin()) json_error('Forbidden', 403);
            ensure_color_columns($pdo);
            // Check if new dual-role columns exist yet
            $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $hasDual = in_array('cm_person_id', $cols);
            if ($hasDual) {
                $rows = $pdo->query("
                    SELECT u.id, u.username, u.full_name, u.email, u.role, u.color,
                           u.cm_person_id, u.sp_person_id, u.is_active, u.last_login, u.created_at,
                           cm.name AS cm_person_name,
                           sp.name AS sp_person_name
                    FROM users u
                    LEFT JOIN people cm ON cm.id = u.cm_person_id
                    LEFT JOIN people sp ON sp.id = u.sp_person_id
                    ORDER BY u.full_name
                ")->fetchAll();
            } else {
                // Old schema fallback
                $rows = $pdo->query("
                    SELECT u.id, u.username, u.full_name, u.email, u.role, u.color,
                           NULL AS cm_person_id, NULL AS sp_person_id,
                           u.is_active, u.last_login, u.created_at,
                           p.name AS cm_person_name, NULL AS sp_person_name
                    FROM users u
                    LEFT JOIN people p ON p.id = u.person_id
                    ORDER BY u.full_name
                ")->fetchAll();
            }
            json_ok($rows);
            break;

        case 'save_user':
            if (!is_admin()) json_error('Forbidden', 403);
            ensure_color_columns($pdo);
            $id           = isset($body['id'])           ? $body['id']           : null;
            $username     = trim(isset($body['username'])     ? $body['username']     : '');
            $full_name    = trim(isset($body['full_name'])    ? $body['full_name']    : '');
            $email        = trim(isset($body['email'])        ? $body['email']        : '') ?: null;
            $is_active    = isset($body['is_active'])    ? (int)$body['is_active']    : 1;
            $password     = isset($body['password'])     ? $body['password']     : '';
            $color        = (isset($body['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'])) ? strtolower($body['color']) : null;
            $is_admin_u   = !empty($body['is_admin']);
            $is_cm        = !$is_admin_u && !empty($body['is_cm']);
            $is_sp        = !$is_admin_u && !empty($body['is_sp']);
            $cmCompanies  = (isset($body['cm_company_ids']) && is_array($body['cm_company_ids'])) ? $body['cm_company_ids'] : [];
            $spCompanies  = (isset($body['sp_company_ids']) && is_array($body['sp_company_ids'])) ? $body['sp_company_ids'] : [];

            // Build role string from selected roles
            $roles = array();
            if ($is_admin_u) {
                $roles = array('admin');
            } else {
                if ($is_cm) $roles[] = 'content_manager';
                if ($is_sp) $roles[] = 'sales_person';
                if (empty($roles)) $roles[] = 'content_manager';
            }
            $role = implode(',', $roles);

            if (!$username || !$full_name) json_error('Username and full name required.', 422);

            $exists = $id ? $pdo->query("SELECT id FROM users WHERE id = " . $pdo->quote($id))->fetch() : false;

            $colsCheck = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $hasDual2  = in_array('cm_person_id', $colsCheck);
            if ($exists) {
                // cm_person_id / sp_person_id are managed by the role sync below
                if ($password) {
                    $hash = password_hash($password, PASSWORD_BCRYPT, array('cost' => 10));
                    $stmt = $pdo->prepare("UPDATE users SET username=?,full_name=?,email=?,role=?,color=?,is_active=?,password_hash=? WHERE id=?");
                    $stmt->execute(array($username,$full_name,$email,$role,$color,$is_active,$hash,$id));
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username=?,full_name=?,email=?,role=?,color=?,is_active=? WHERE id=?");
                    $stmt->execute(array($username,$full_name,$email,$role,$color,$is_active,$id));
                }
            } else {
                if (!$password) json_error('Password required for new user.', 422);
                if (!$id) $id = uniqid('usr_', true);
                $hash = password_hash($password, PASSWORD_BCRYPT, array('cost' => 10));
                $stmt = $pdo->prepare("INSERT INTO users (id,username,password_hash,role,color,full_name,email,is_active) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute(array($id,$username,$hash,$role,$color,$full_name,$email,$is_active));
            }

            // ── Role ↔ person ↔ companies sync ─────────────────
            // The Users form assigns companies per role directly; the person
            // record behind each role is created/updated here automatically
            // (name/email mirror the user account).
            if ($hasDual2) {
                $lnkStmt = $pdo->prepare("SELECT cm_person_id, sp_person_id FROM users WHERE id = ?");
                $lnkStmt->execute(array($id));
                $links = $lnkStmt->fetch() ?: array('cm_person_id' => null, 'sp_person_id' => null);

                $syncRole = function($on, $col, $prole, $companies) use ($pdo, $id, $full_name, $email, $color, $links) {
                    $pid = $links[$col] ?? null;
                    if (!$on) {
                        // Unlink but keep the person record (posts/companies may
                        // still reference it); admins can delete it separately.
                        if ($pid) $pdo->prepare("UPDATE users SET $col = NULL WHERE id = ?")->execute(array($id));
                        return;
                    }
                    // people.email is UNIQUE, but a dual-role user owns TWO
                    // person rows — store NULL on the row that would collide.
                    $safeEmail = $email;
                    if ($safeEmail) {
                        $q = $pdo->prepare("SELECT id FROM people WHERE email = ? AND id <> ?");
                        $q->execute(array($safeEmail, $pid ?: ''));
                        if ($q->fetch()) $safeEmail = null;
                    }
                    if (!$pid) {
                        $pid = uid();
                        $pdo->prepare("INSERT INTO people (id, role, name, email, color) VALUES (?,?,?,?,?)")
                            ->execute(array($pid, $prole, $full_name, $safeEmail, $color));
                        $pdo->prepare("UPDATE users SET $col = ? WHERE id = ?")->execute(array($pid, $id));
                    } else {
                        $pdo->prepare("UPDATE people SET name = ?, email = ?, color = ? WHERE id = ?")
                            ->execute(array($full_name, $safeEmail, $color, $pid));
                    }
                    $pdo->prepare("DELETE FROM person_companies WHERE person_id = ?")->execute(array($pid));
                    $ins = $pdo->prepare("INSERT IGNORE INTO person_companies (person_id, company_id) VALUES (?,?)");
                    foreach ($companies as $cid) { if ($cid) $ins->execute(array($pid, $cid)); }
                };
                $syncRole($is_cm, 'cm_person_id', 'content_manager', $cmCompanies);
                $syncRole($is_sp, 'sp_person_id', 'sales_person',    $spCompanies);
            }

            json_ok(array('id' => $id));
            break;

        case 'delete_user':
            if (!is_admin()) json_error('Forbidden', 403);
            $del_id = isset($_GET['id']) ? $_GET['id'] : (isset($body['id']) ? $body['id'] : '');
            if ($del_id === 'usr_admin') json_error('Cannot delete the main admin.', 403);
            if (!$del_id) json_error('Missing id', 422);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute(array($del_id));
            json_ok(array('deleted' => $del_id));
            break;


        // ── SOCIAL LOGINS ──────────────────────────────────

        case 'social_logins':
            // Admin sees all; CM/SP see only their companies' logins
            $filter_companies = is_admin() ? null : user_company_filter();
            if ($filter_companies !== null && empty($filter_companies)) {
                json_ok(array());
                break;
            }
            $sql = "
                SELECT sl.id, sl.platform, sl.title, sl.channel_url, sl.username,
                       sl.notes, sl.created_at,
                       GROUP_CONCAT(slc.company_id) AS company_ids,
                       GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS company_names
                FROM social_logins sl
                LEFT JOIN social_login_companies slc ON slc.login_id = sl.id
                LEFT JOIN companies c ON c.id = slc.company_id
            ";
            if ($filter_companies !== null) {
                $in = implode(',', array_map(function($id) use ($pdo){ return $pdo->quote($id); }, $filter_companies));
                $sql .= " WHERE slc.company_id IN ($in)";
            }
            $sql .= " GROUP BY sl.id ORDER BY sl.platform, sl.title";
            $rows = $pdo->query($sql)->fetchAll();
            foreach ($rows as &$row) {
                $row['company_ids'] = $row['company_ids'] ? explode(',', $row['company_ids']) : array();
                // Never return encrypted password in list
                $row['has_password'] = !empty($pdo->query("SELECT password_enc FROM social_logins WHERE id=" . $pdo->quote($row['id']))->fetchColumn());
            }
            json_ok($rows);
            break;

        case 'reveal_password':
            $lid = $_GET['id'] ?? '';
            if (!$lid) json_error('Missing id', 422);
            $row = $pdo->prepare("SELECT password_enc FROM social_logins WHERE id = ?");
            $row->execute(array($lid));
            $r = $row->fetch();
            if (!$r) json_error('Not found', 404);
            if (!is_admin()) {
                // Allowed if ANY of the login's linked companies is theirs —
                // the old LIMIT-1 check tested one arbitrary link and gave
                // wrong answers for logins shared across companies.
                $filter = user_company_filter();
                $lnk = $pdo->prepare("SELECT company_id FROM social_login_companies WHERE login_id = ?");
                $lnk->execute(array($lid));
                $linked = $lnk->fetchAll(PDO::FETCH_COLUMN);
                if (!array_intersect($linked, $filter ?: array())) json_error('Forbidden', 403);
            }
            json_ok(array('password' => decrypt_pw($r['password_enc'])));
            break;

        case 'save_social_login':
            if (!is_admin()) json_error('Forbidden', 403);
            $id          = isset($body['id'])          ? $body['id']          : null;
            $platform    = isset($body['platform'])    ? $body['platform']    : '';
            $title       = trim(isset($body['title'])  ? $body['title']       : '');
            $channel_url = trim(isset($body['channel_url']) ? $body['channel_url'] : '') ?: null;
            $username    = trim(isset($body['username']) ? $body['username']  : '') ?: null;
            $password    = isset($body['password'])    ? $body['password']    : null;
            $notes       = trim(isset($body['notes'])  ? $body['notes']       : '') ?: null;
            $company_ids = isset($body['company_ids']) ? $body['company_ids'] : array();

            if (!$platform || !$title) json_error('Platform and title required.', 422);

            $exists = $id ? $pdo->query("SELECT id FROM social_logins WHERE id=" . $pdo->quote($id))->fetch() : false;

            if ($exists) {
                if ($password !== null && $password !== '') {
                    $enc = encrypt_pw($password);
                    $stmt = $pdo->prepare("UPDATE social_logins SET platform=?,title=?,channel_url=?,username=?,password_enc=?,notes=? WHERE id=?");
                    $stmt->execute(array($platform,$title,$channel_url,$username,$enc,$notes,$id));
                } else {
                    $stmt = $pdo->prepare("UPDATE social_logins SET platform=?,title=?,channel_url=?,username=?,notes=? WHERE id=?");
                    $stmt->execute(array($platform,$title,$channel_url,$username,$notes,$id));
                }
            } else {
                $id  = uniqid('sl_', true);
                $enc = $password ? encrypt_pw($password) : null;
                $stmt = $pdo->prepare("INSERT INTO social_logins (id,platform,title,channel_url,username,password_enc,notes) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute(array($id,$platform,$title,$channel_url,$username,$enc,$notes));
            }
            // Update company links
            $pdo->prepare("DELETE FROM social_login_companies WHERE login_id=?")->execute(array($id));
            $ins = $pdo->prepare("INSERT IGNORE INTO social_login_companies (login_id,company_id) VALUES (?,?)");
            foreach ($company_ids as $cid) { if ($cid) $ins->execute(array($id,$cid)); }
            json_ok(array('id' => $id));
            break;

        case 'delete_social_login':
            if (!is_admin()) json_error('Forbidden', 403);
            $del_id = isset($_GET['id']) ? $_GET['id'] : (isset($body['id']) ? $body['id'] : '');
            if (!$del_id) json_error('Missing id', 422);
            $pdo->prepare("DELETE FROM social_logins WHERE id=?")->execute(array($del_id));
            json_ok(array('deleted' => $del_id));
            break;


        // ── CONTENT IDEAS ──────────────────────────────────

        case 'content_ideas':
            if ($method === 'GET') {
                ensure_content_ideas($pdo);
                $co_id = $_GET['company_id'] ?? $_GET['id'] ?? '';
                if (!$co_id) json_error('company_id required', 422);
                $filter = user_company_filter();
                if ($filter !== null && !in_array($co_id, $filter)) json_error('Forbidden', 403);
                $stmt = $pdo->prepare("SELECT * FROM content_ideas WHERE company_id = ?
                                       ORDER BY COALESCE(used_at, created_at) DESC, created_at DESC");
                $stmt->execute([$co_id]);
                json_ok($stmt->fetchAll());
            }
            break;

        case 'generate_idea':
            if ($method === 'POST') {
                // Content managers (and admins) generate ideas for their companies
                if (!is_admin() && !has_role('content_manager')) json_error('Forbidden', 403);
                ensure_content_ideas($pdo);
                $co_id = $body['company_id'] ?? '';
                if (!$co_id) json_error('company_id required', 422);
                $filter = user_company_filter();
                if ($filter !== null && !in_array($co_id, $filter)) json_error('Forbidden', 403);

                $co = $pdo->prepare("SELECT name, content_prompt FROM companies WHERE id = ?");
                $co->execute([$co_id]);
                $company = $co->fetch();
                if (!$company) json_error('Company not found', 404);

                $keyEnc = get_setting($pdo, 'claude_api_key');
                $apiKey = $keyEnc ? decrypt_pw($keyEnc) : '';
                if (!$apiKey) json_error('No Claude API key configured. An admin can add one on the Content Ideas page.', 422);

                $prev = $pdo->prepare("SELECT idea FROM content_ideas WHERE company_id = ? ORDER BY created_at DESC LIMIT 100");
                $prev->execute([$co_id]);
                $previous = $prev->fetchAll(PDO::FETCH_COLUMN);

                $system = "You are a social media content strategist. Generate exactly ONE new content idea "
                        . "for the company described by the user: a short, specific, actionable post concept "
                        . "(one to three sentences) that a content manager can create today. It must be clearly "
                        . "different from every previous idea listed. Return ONLY the idea text — no numbering, "
                        . "no quotes, no preamble.";
                $userMsg = "Company: " . $company['name'] . "\n\n"
                         . "Content brief for this company:\n"
                         . (trim((string)$company['content_prompt']) !== ''
                             ? $company['content_prompt']
                             : "(No brief set — assume a general small-business social media presence.)")
                         . "\n\nPrevious ideas (do NOT repeat or closely resemble these):\n"
                         . ($previous ? "- " . implode("\n- ", $previous) : "(none yet)")
                         . "\n\nGenerate one new idea.";

                $ideaText = claude_generate($apiKey, $system, $userMsg);

                $id = uid();
                $pdo->prepare("INSERT INTO content_ideas (id, company_id, idea) VALUES (?,?,?)")
                    ->execute([$id, $co_id, $ideaText]);
                $row = $pdo->prepare("SELECT * FROM content_ideas WHERE id = ?");
                $row->execute([$id]);
                json_ok($row->fetch());
            }
            break;

        case 'use_idea':
            if ($method === 'POST') {
                if (!is_admin() && !has_role('content_manager')) json_error('Forbidden', 403);
                ensure_content_ideas($pdo);
                $id = $body['id'] ?? '';
                if (!$id) json_error('Missing id', 422);
                $own = $pdo->prepare("SELECT company_id FROM content_ideas WHERE id = ?");
                $own->execute([$id]);
                $cid = $own->fetchColumn();
                if (!$cid) json_error('Not found', 404);
                $filter = user_company_filter();
                if ($filter !== null && !in_array($cid, $filter)) json_error('Forbidden', 403);
                $pdo->prepare("UPDATE content_ideas SET used_at = NOW() WHERE id = ?")->execute([$id]);
                json_ok(['id' => $id]);
            }
            break;

        case 'delete_idea':
            if ($method === 'POST' || $method === 'DELETE') {
                if (!is_admin() && !has_role('content_manager')) json_error('Forbidden', 403);
                ensure_content_ideas($pdo);
                $id = $_GET['id'] ?? $body['id'] ?? '';
                if (!$id) json_error('Missing id', 422);
                $own = $pdo->prepare("SELECT company_id FROM content_ideas WHERE id = ?");
                $own->execute([$id]);
                $cid = $own->fetchColumn();
                if ($cid) {
                    $filter = user_company_filter();
                    if ($filter !== null && !in_array($cid, $filter)) json_error('Forbidden', 403);
                }
                $pdo->prepare("DELETE FROM content_ideas WHERE id = ?")->execute([$id]);
                json_ok(['deleted' => $id]);
            }
            break;

        case 'claude_key_status':
            if ($method === 'GET') {
                if (!is_admin()) json_error('Forbidden', 403);
                json_ok(['configured' => (bool) get_setting($pdo, 'claude_api_key')]);
            }
            break;

        case 'save_claude_key':
            if ($method === 'POST') {
                if (!is_admin()) json_error('Forbidden', 403);
                $key = trim($body['api_key'] ?? '');
                if (!$key) json_error('API key required', 422);
                // Stored AES-encrypted, same scheme as social login passwords
                put_setting($pdo, 'claude_api_key', encrypt_pw($key));
                json_ok(['configured' => true]);
            }
            break;

        default:
            json_error('Unknown action: ' . $action, 404);
    }

    // Every handled request exits inside its case, so reaching this point
    // means the action matched but the HTTP method didn't. The old code fell
    // through to an empty 200 response, which broke JSON parsing client-side.
    json_error('Method not allowed for action: ' . $action, 405);

} catch (PDOException $e) {
    error_log('[Content Board API] DB error in action "' . $action . '": ' . $e->getMessage());
    json_error('Database error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    // PHP 8 throws Error (TypeError etc.) which PDOException doesn't catch —
    // without this, fatals rendered an HTML error page instead of JSON.
    error_log('[Content Board API] Error in action "' . $action . '": '
        . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_error('Server error: ' . $e->getMessage(), 500);
}

// ── Helpers ───────────────────────────────────────────────

function json_ok($data) {
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function json_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}


// Content Ideas storage: creates the content_ideas table and the
// companies.content_prompt column on first use (self-applying, like the
// color columns below — Hostinger has no SSH).
function ensure_content_ideas($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS content_ideas (
            id         VARCHAR(36) NOT NULL,
            company_id VARCHAR(36) NOT NULL,
            idea       TEXT        NOT NULL,
            created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_at    DATETIME    DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_ci_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!$pdo->query("SHOW COLUMNS FROM companies LIKE 'content_prompt'")->fetch()) {
            $pdo->exec("ALTER TABLE companies ADD COLUMN content_prompt TEXT DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('[Content Board API] content_ideas migration failed: ' . $e->getMessage());
    }
}

function get_setting($pdo, $key) {
    $s = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? null : $v;
}

function put_setting($pdo, $key, $value) {
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$key, $value]);
}

// Calls the Claude Messages API (raw cURL — no Composer on shared hosting).
// Returns the generated text or throws with a readable message.
function claude_generate($apiKey, $system, $userMsg) {
    $payload = json_encode([
        'model'         => 'claude-opus-5',
        'max_tokens'    => 1024,
        'output_config' => ['effort' => 'low'],
        'system'        => $system,
        'messages'      => [['role' => 'user', 'content' => $userMsg]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($res === false) throw new Exception('Could not reach the Claude API: ' . $err);
    $json = json_decode($res, true);
    if ($code !== 200) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $code);
        throw new Exception('Claude API error: ' . $msg);
    }
    if (($json['stop_reason'] ?? '') === 'refusal') {
        throw new Exception('Claude declined to generate this content.');
    }
    foreach (($json['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text' && trim($block['text'] ?? '') !== '') {
            return trim($block['text']);
        }
    }
    throw new Exception('Claude returned no text.');
}

// Adds users.color / people.color on first use — Hostinger has no SSH, so
// this micro-migration self-applies instead of requiring phpMyAdmin.
// (Also documented in database/migration-user-colors.sql.)
function ensure_color_columns($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'color'")->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN color VARCHAR(7) DEFAULT NULL");
        }
        if (!$pdo->query("SHOW COLUMNS FROM people LIKE 'color'")->fetch()) {
            $pdo->exec("ALTER TABLE people ADD COLUMN color VARCHAR(7) DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('[Content Board API] color column migration failed: ' . $e->getMessage());
    }
}

// ── Encryption helpers ─────────────────────────────────────
function get_enc_key() {
    $key = defined('DB_NAME') ? hash('sha256', DB_NAME . 'cb_social_key_2026', true) : str_repeat('x', 32);
    return $key;
}
function encrypt_pw($plain) {
    if (!$plain) return null;
    $iv  = openssl_random_pseudo_bytes(16);
    $enc = openssl_encrypt($plain, 'AES-256-CBC', get_enc_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}
function decrypt_pw($stored) {
    if (!$stored) return '';
    $raw = base64_decode($stored);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return openssl_decrypt($enc, 'AES-256-CBC', get_enc_key(), OPENSSL_RAW_DATA, $iv) ?: '';
}

function uid() {
    return sprintf('%08x-%04x-%04x-%04x-%012x',
        mt_rand(0, 0xffffffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffffffffffff));
}
