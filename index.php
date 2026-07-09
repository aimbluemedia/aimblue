<?php
require_once __DIR__ . '/auth.php';
require_login('login.php');
$user        = auth_user();
$is_admin    = is_admin();
$user_role   = auth_role();
$user_name   = $user['full_name'] ?? 'User';
$company_ids = user_company_filter();
$connected   = db_connected();
// ── Load companies and people from DB for initial page render ──
$initial_companies = [];
$initial_people    = [];
$php_load_error    = $connected ? null : 'Database not connected';
if ($connected) {
    try {
        $xpdo = db_connect();
        $xcos = $xpdo->query("
            SELECT c.*, cm.name AS content_manager_name, sp.name AS sales_person_name
            FROM companies c
            LEFT JOIN people cm ON cm.id = c.content_manager_id
            LEFT JOIN people sp ON sp.id = c.sales_person_id
            ORDER BY c.name
        ")->fetchAll();
        foreach ($xcos as &$xco) {
            $xco['posting_days']  = $xco['posting_days'] ? explode(',', $xco['posting_days']) : [];
            $xco['fee']           = $xco['fee']          ? (float)$xco['fee']          : null;
            $xco['monthly_posts'] = $xco['monthly_posts']? (int)$xco['monthly_posts']  : null;
            $xco['payment_date']  = $xco['payment_date'] ? (int)$xco['payment_date']   : null;
            $xco['fee_sp_pct']    = (float)$xco['fee_sp_pct'];
            $xco['fee_cm_pct']    = (float)$xco['fee_cm_pct'];
            $xco['fee_sm_pct']    = (float)$xco['fee_sm_pct'];
            $ps = $xpdo->prepare("SELECT * FROM posts WHERE company_id=? ORDER BY post_date");
            $ps->execute([$xco['id']]);
            $xco['posts'] = $ps->fetchAll();
        }
        if (!$is_admin && is_array($company_ids)) {
            $xcos = array_values(array_filter($xcos, function($xco) use ($company_ids) {
                return in_array($xco['id'], $company_ids);
            }));
        }
        $initial_companies = array_values($xcos);
        $xppl = $xpdo->query("SELECT * FROM people ORDER BY role,name")->fetchAll();
        $xpco = $xpdo->query("SELECT person_id,company_id FROM person_companies")->fetchAll();
        $xmap = [];
        foreach ($xpco as $xpc) $xmap[$xpc['person_id']][] = $xpc['company_id'];
        foreach ($xppl as &$xp) $xp['company_ids'] = isset($xmap[$xp['id']]) ? $xmap[$xp['id']] : [];
        $initial_people = array_values($xppl);
    } catch (Throwable $xe) {
        error_log("Content Board PHP loader error: " . $xe->getMessage());
        $initial_companies = [];
        $initial_people    = [];
        $php_load_error    = $xe->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Content Board</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzMiAzMiI+PHJlY3Qgd2lkdGg9IjMyIiBoZWlnaHQ9IjMyIiByeD0iOCIgZmlsbD0iIzZjNDdmZiIvPjx0ZXh0IHg9IjE2IiB5PSIyMiIgZm9udC1mYW1pbHk9InN5c3RlbS11aSxzYW5zLXNlcmlmIiBmb250LXNpemU9IjE0IiBmb250LXdlaWdodD0iODAwIiBmaWxsPSJ3aGl0ZSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgbGV0dGVyLXNwYWNpbmc9Ii0xIj5DQjwvdGV4dD48L3N2Zz4=">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root {
      --sidebar-w: 260px;
      --topbar-h: 56px;
      --at-purple: #6c47ff;
      --at-purple-light: #ede9ff;
    }
    html,body{height:100%;margin:0;overflow:hidden;font-family:'Segoe UI',system-ui,sans-serif;}
    *{box-sizing:border-box;}

    /* ── Layout ── */
    .app{display:flex;height:100vh;overflow:hidden;}

    /* ── Sidebar ── */
    .sidebar{
      width:var(--sidebar-w);background:#0f172a;
      display:flex;flex-direction:column;flex-shrink:0;overflow:hidden;
      transition:width .22s ease;z-index:200;
    }
    .sidebar.collapsed{width:60px;}
    .sidebar.collapsed .hide-collapsed{display:none!important;}

    .sb-brand{
      display:flex;align-items:center;gap:10px;
      padding:0 14px;height:var(--topbar-h);
      border-bottom:1px solid rgba(255,255,255,.07);flex-shrink:0;overflow:hidden;
    }
    .sb-brand .logo{
      width:28px;height:28px;border-radius:7px;
      background:var(--at-purple);display:flex;align-items:center;justify-content:center;
      font-size:14px;font-weight:800;color:#fff;flex-shrink:0;letter-spacing:-1px;
    }
    .sb-brand .brand-name{font-size:14px;font-weight:700;color:#f8fafc;white-space:nowrap;}
    .sb-brand .brand-sub{font-size:10px;color:#475569;white-space:nowrap;}

    .sb-section{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:#475569;padding:14px 14px 5px;white-space:nowrap;}

    .co-list{flex:1;overflow-y:auto;overflow-x:hidden;padding:0 8px 8px;}
    .co-list::-webkit-scrollbar{width:3px;}
    .co-list::-webkit-scrollbar-thumb{background:#334155;border-radius:2px;}

    .co-btn{
      display:flex;align-items:center;gap:9px;width:100%;
      padding:8px 10px;background:none;border:none;border-radius:8px;
      cursor:pointer;text-align:left;color:#94a3b8;
      transition:all .14s;margin-bottom:2px;overflow:hidden;
    }
    .co-btn:hover{background:#1e293b;color:#cbd5e1;}
    .co-btn.active{background:#1e293b;color:#f1f5f9;}
    .co-btn .co-av{
      width:30px;height:30px;border-radius:7px;flex-shrink:0;
      display:flex;align-items:center;justify-content:center;
      font-size:11px;font-weight:700;
    }
    .co-btn .co-inf{flex:1;min-width:0;}
    .co-btn .co-nm{font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .co-btn .co-sb{font-size:11px;color:#475569;white-space:nowrap;margin-top:1px;}
    .co-btn.active .co-sb{color:#64748b;}
    .co-btn .sync-spin{animation:spin .7s linear infinite;font-size:12px;color:#6c47ff;}
    @keyframes spin{to{transform:rotate(360deg)}}

    .sb-footer{padding:8px;border-top:1px solid rgba(255,255,255,.07);flex-shrink:0;display:flex;flex-direction:column;gap:6px;}
    .sb-add{
      display:flex;align-items:center;gap:7px;width:100%;padding:8px 10px;
      background:rgba(108,71,255,.12);border:1px dashed rgba(108,71,255,.35);
      border-radius:8px;color:#7c5cfc;font-size:13px;font-weight:500;
      cursor:pointer;transition:all .14s;white-space:nowrap;overflow:hidden;
    }
    .sb-add:hover{background:rgba(108,71,255,.2);}
    .sb-settings{
      display:flex;align-items:center;gap:7px;width:100%;padding:7px 10px;
      background:none;border:1px solid rgba(255,255,255,.07);
      border-radius:8px;color:#475569;font-size:12px;font-weight:500;
      cursor:pointer;transition:all .14s;white-space:nowrap;overflow:hidden;
    }
    .sb-settings:hover{background:#1e293b;color:#94a3b8;}
    .sb-person{
      display:flex;align-items:center;gap:7px;width:100%;padding:8px 10px;
      background:none;border:1px solid rgba(255,255,255,.07);
      border-radius:8px;color:#64748b;font-size:12px;font-weight:500;
      cursor:pointer;transition:all .14s;white-space:nowrap;overflow:hidden;text-align:left;
    }
    .sb-person:hover{background:#1e293b;color:#94a3b8;}
    .sb-person .sp-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
    /* People list in sidebar */
    .people-section{padding:0 8px 4px;}
    .people-section .ps-label{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:#334155;padding:10px 4px 4px;display:flex;align-items:center;gap:6px;}
    .person-item{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:7px;color:#64748b;font-size:12px;}
    .person-item .p-av{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#fff;flex-shrink:0;}
    .person-item .p-info{flex:1;min-width:0;}
    .person-item .p-name{font-size:12px;font-weight:500;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .person-item .p-role{font-size:10px;color:#334155;}
    .person-item .p-del{background:none;border:none;color:#334155;cursor:pointer;padding:2px 4px;border-radius:4px;font-size:11px;opacity:0;transition:opacity .12s;}
    .person-item:hover .p-del{opacity:1;}
    .person-item:hover .p-del:hover{color:#dc2626;}

    /* ── Main ── */
    .main{flex:1;display:flex;flex-direction:column;overflow:hidden;background:#f8fafc;}

    /* ── Topbar ── */
    .topbar{
      height:var(--topbar-h);background:#fff;border-bottom:1px solid #e2e8f0;
      display:flex;align-items:center;padding:0 20px;gap:12px;flex-shrink:0;
    }
    .tb-toggle{background:none;border:none;color:#64748b;font-size:20px;cursor:pointer;padding:4px 6px;border-radius:6px;}
    .tb-toggle:hover{background:#f1f5f9;}
    .tb-title{font-size:16px;font-weight:700;color:#0f172a;}
    .tb-badge{
      display:inline-flex;align-items:center;gap:5px;
      background:var(--at-purple-light);color:var(--at-purple);
      font-size:11px;padding:3px 10px;border-radius:20px;font-weight:600;
    }
    .tb-sync-badge{
      display:inline-flex;align-items:center;gap:5px;
      background:#f0fdf4;color:#15803d;
      font-size:11px;padding:3px 10px;border-radius:20px;font-weight:600;
    }
    .tb-actions{margin-left:auto;display:flex;gap:6px;align-items:center;}
    .tb-last{font-size:11px;color:#94a3b8;white-space:nowrap;}

    /* ── Stats ── */
    .stats{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;padding:14px 20px 0;flex-shrink:0;}
    .sc{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;}
    .sc-lbl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:4px;}
    .sc-val{font-size:24px;font-weight:700;color:#0f172a;line-height:1;}
    .sc-prog{height:3px;background:#f1f5f9;border-radius:2px;margin-top:8px;overflow:hidden;}
    .sc-prog-fill{height:100%;border-radius:2px;transition:width .5s;}
    .sc.pub .sc-val{color:#16a34a;} .sc.pub .sc-prog-fill{background:#16a34a;}
    .sc.sch .sc-val{color:#d97706;} .sc.sch .sc-prog-fill{background:#d97706;}
    .sc.rev .sc-val{color:#2563eb;} .sc.rev .sc-prog-fill{background:#2563eb;}
    .sc.ovr .sc-val{color:#dc2626;} .sc.ovr .sc-prog-fill{background:#dc2626;}

    /* ── Content ── */
    .content{flex:1;overflow-y:auto;padding:14px 20px;}
    .content::-webkit-scrollbar{width:5px;}
    .content::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px;}

    /* ── Toolbar ── */
    .toolbar{display:flex;align-items:center;gap:7px;margin-bottom:12px;flex-wrap:wrap;}
    .fchip{
      padding:5px 13px;border-radius:20px;border:1px solid #e2e8f0;background:#fff;
      font-size:12px;font-weight:500;color:#64748b;cursor:pointer;transition:all .12s;
      display:inline-flex;align-items:center;gap:5px;
    }
    .fchip:hover{border-color:#cbd5e1;color:#374151;}
    .fchip.active{background:#0f172a;border-color:#0f172a;color:#fff;}
    .fchip .fc{background:rgba(255,255,255,.2);border-radius:20px;padding:1px 6px;font-size:10px;}
    .fchip:not(.active) .fc{background:#f1f5f9;color:#94a3b8;}
    .search-wrap{margin-left:auto;position:relative;}
    .search-wrap input{
      padding:6px 12px 6px 30px;border:1px solid #e2e8f0;border-radius:8px;
      font-size:13px;background:#fff;width:200px;color:#0f172a;outline:none;
    }
    .search-wrap input:focus{border-color:#a5b4fc;box-shadow:0 0 0 3px rgba(108,71,255,.1);}
    .search-wrap .si{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none;}

    /* ── Table ── */
    .tbl-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;}
    .tbl-wrap table{width:100%;border-collapse:collapse;font-size:13px;}
    .tbl-wrap thead th{
      background:#f8fafc;padding:9px 14px;
      font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;
      color:#94a3b8;border-bottom:1px solid #e2e8f0;white-space:nowrap;
      cursor:pointer;user-select:none;
    }
    .tbl-wrap thead th:hover{color:#64748b;}
    .tbl-wrap tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s;}
    .tbl-wrap tbody tr:last-child{border-bottom:none;}
    .tbl-wrap tbody tr:hover{background:#f8fafc;}
    .tbl-wrap td{padding:10px 14px;vertical-align:middle;color:#334155;}
    .t-title{font-weight:500;color:#0f172a;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;}
    .t-notes{max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64748b;font-size:12px;}

    /* platform pills */
    .plat{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;white-space:nowrap;}
    .pl-instagram{background:#fdf2f8;color:#9d174d;}
    .pl-linkedin{background:#eff6ff;color:#1d4ed8;}
    .pl-twitter,.pl-x{background:#f0f9ff;color:#0369a1;}
    .pl-facebook{background:#eff6ff;color:#1e40af;}
    .pl-tiktok{background:#f8fafc;color:#0f172a;}
    .pl-youtube{background:#fef2f2;color:#b91c1c;}
    .pl-blog{background:#f0fdf4;color:#15803d;}
    .pl-email{background:#fefce8;color:#a16207;}
    .pl-other{background:#f8fafc;color:#64748b;}

    /* status pills */
    .stat-pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;white-space:nowrap;}
    .stat-pill::before{content:'';width:6px;height:6px;border-radius:50%;flex-shrink:0;}
    .sp-published{background:#f0fdf4;color:#15803d;} .sp-published::before{background:#16a34a;}
    .sp-scheduled{background:#fffbeb;color:#b45309;} .sp-scheduled::before{background:#d97706;}
    .sp-draft{background:#f8fafc;color:#475569;}     .sp-draft::before{background:#94a3b8;}
    .sp-review{background:#eff6ff;color:#1d4ed8;}    .sp-review::before{background:#2563eb;}
    .sp-overdue{background:#fef2f2;color:#b91c1c;}   .sp-overdue::before{background:#dc2626;}

    /* assignee */
    .assn{display:flex;align-items:center;gap:6px;}
    .av{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;flex-shrink:0;color:#fff;}
    .av-nm{font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100px;}

    /* row actions */
    .row-acts{display:flex;gap:3px;opacity:0;transition:opacity .15s;}
    tr:hover .row-acts{opacity:1;}
    .rib{background:none;border:none;padding:3px 6px;border-radius:5px;cursor:pointer;color:#94a3b8;font-size:14px;transition:all .1s;}
    .rib:hover{background:#f1f5f9;color:#374151;}
    .rib.del:hover{background:#fef2f2;color:#dc2626;}

    /* states */
    .state-box{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 20px;color:#94a3b8;text-align:center;}
    .state-box i{font-size:40px;margin-bottom:12px;}
    .state-box strong{color:#64748b;font-size:15px;display:block;margin-bottom:6px;}
    .state-box p{font-size:13px;margin:0;max-width:300px;line-height:1.6;}
    .spin-sm{display:inline-block;animation:spin .7s linear infinite;}

    /* ── Setup screen ── */
    .setup-screen{
      flex:1;display:flex;align-items:center;justify-content:center;
      background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 100%);
    }
    .setup-card{
      background:#fff;border-radius:16px;padding:36px 40px;
      width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.3);
    }
    .setup-logo{
      width:48px;height:48px;border-radius:12px;background:var(--at-purple);
      display:flex;align-items:center;justify-content:center;
      font-size:22px;font-weight:900;color:#fff;letter-spacing:-2px;
      margin-bottom:20px;
    }
    .setup-card h2{font-size:20px;font-weight:700;color:#0f172a;margin-bottom:6px;}
    .setup-card p{font-size:13px;color:#64748b;margin-bottom:24px;line-height:1.6;}
    .setup-steps{display:flex;flex-direction:column;gap:12px;margin-bottom:24px;}
    .step{display:flex;gap:12px;align-items:flex-start;}
    .step-num{
      width:24px;height:24px;border-radius:50%;background:var(--at-purple-light);
      color:var(--at-purple);font-size:11px;font-weight:700;
      display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;
    }
    .step p{font-size:12px;color:#475569;margin:0;line-height:1.6;}
    .step strong{color:#0f172a;}
    .step a{color:var(--at-purple);text-decoration:none;}
    .step a:hover{text-decoration:underline;}

    /* ── Modals ── */
    .modal-header{border-bottom:1px solid #e2e8f0;padding:18px 20px 14px;}
    .modal-title{font-size:15px;font-weight:700;color:#0f172a;}
    .modal-footer{border-top:1px solid #e2e8f0;}
    .form-label{font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;}
    .form-control,.form-select{font-size:13px;border-color:#e2e8f0;color:#0f172a;}
    .form-control:focus,.form-select:focus{border-color:#a5b4fc;box-shadow:0 0 0 3px rgba(108,71,255,.1);}
    .form-text{font-size:11px;color:#94a3b8;margin-top:4px;}

    /* field mapping UI */
    .field-map{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;}
    .field-map-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;align-items:center;margin-bottom:8px;}
    .field-map-row:last-child{margin-bottom:0;}
    .field-key{font-size:12px;font-weight:500;color:#374151;}
    .field-key span{font-size:10px;color:#94a3b8;font-weight:400;}

    /* color dots */
    .day-toggle{display:flex;flex-direction:column;align-items:center;gap:4px;cursor:pointer;}
    .day-toggle input[type=checkbox]{display:none;}
    .day-toggle .day-label{
      width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;
      font-size:11px;font-weight:600;border:1.5px solid #e2e8f0;color:#94a3b8;
      transition:all .15s;background:#f8fafc;user-select:none;
    }
    .day-toggle input:checked + .day-label{
      background:var(--at-purple);border-color:var(--at-purple);color:#fff;
    }
    .day-toggle .day-name{font-size:9px;font-weight:500;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;}
    .color-dot{width:22px;height:22px;border-radius:50%;cursor:pointer;transition:transform .1s;border:2px solid transparent;}
    .color-dot.chosen{border-color:#0f172a;transform:scale(1.15);}

    /* airtable purple btn */
    .btn-at{background:var(--at-purple);border-color:var(--at-purple);color:#fff;}
    .btn-at:hover{background:#5a38e8;border-color:#5a38e8;color:#fff;}
    .btn-at:disabled{opacity:.6;}

    /* field discovery loading */
    .fd-loading{text-align:center;padding:16px;color:#94a3b8;font-size:13px;}

    /* Monthly Calendar Grid */
    .mcal-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-top:16px;}
    .mcal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e2e8f0;}
    .mcal-title{font-size:18px;font-weight:700;color:#0f172a;letter-spacing:-0.02em;}
    .mcal-nav{display:flex;gap:6px;align-items:center;}
    .mcal-nav-btn{background:none;border:1px solid #e2e8f0;border-radius:7px;height:30px;padding:0 12px;cursor:pointer;color:#64748b;font-size:12px;font-weight:500;transition:all .12s;display:flex;align-items:center;gap:4px;}
    .mcal-nav-btn:hover{background:#f1f5f9;color:#0f172a;border-color:#cbd5e1;}
    .mcal-grid{display:grid;grid-template-columns:repeat(7,1fr);}
    .mcal-dow{padding:10px 4px;text-align:center;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#64748b;border-bottom:1px solid #e2e8f0;background:#f8fafc;}
    .mcal-cell{min-height:80px;padding:8px 8px 6px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;vertical-align:top;position:relative;background:#fff;}
    .mcal-cell:nth-child(7n){border-right:none;}
    .mcal-cell.other-month{background:#fafafa;}
    .mcal-cell.other-month .mcal-day-num{color:#d1d5db;}
    .mcal-cell.is-today{background:#faf5ff;}
    .mcal-day-num{font-size:13px;font-weight:600;color:#374151;width:26px;height:26px;display:flex;align-items:center;justify-content:center;border-radius:50%;margin-bottom:5px;flex-shrink:0;}
    .mcal-cell.is-today .mcal-day-num{background:var(--at-purple);color:#fff;}
    .mcal-co-pill{display:flex;align-items:center;gap:5px;padding:3px 6px;border-radius:6px;margin-bottom:3px;font-size:10px;font-weight:500;cursor:pointer;transition:opacity .1s;line-height:1.3;}
    .mcal-co-pill:hover{opacity:.8;}
    .mcal-co-pill .pill-av{width:14px;height:14px;border-radius:3px;display:flex;align-items:center;justify-content:center;font-size:7px;font-weight:800;flex-shrink:0;}
    .mcal-co-pill .pill-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;}
    .mcal-co-pill .pill-count{font-size:9px;opacity:.7;flex-shrink:0;}
    .mcal-more{font-size:10px;color:#94a3b8;padding:2px 4px;cursor:pointer;}
    .mcal-count-btn{
      width:36px;height:36px;border-radius:50%;
      background:var(--at-purple);color:#fff;
      display:flex;align-items:center;justify-content:center;
      font-size:14px;font-weight:700;cursor:pointer;
      border:none;margin:2px auto 0;
      transition:transform .12s,background .12s;
      box-shadow:0 2px 6px rgba(108,71,255,.25);
    }
    .mcal-count-btn:hover{background:#5a38e8;transform:scale(1.1);}
    /* Day detail overlay */
    .day-detail-overlay{
      position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1000;
      display:flex;align-items:center;justify-content:center;
    }
    .day-detail-panel{
      background:#fff;border-radius:14px;width:360px;max-height:80vh;
      display:flex;flex-direction:column;overflow:hidden;
      box-shadow:0 20px 60px rgba(0,0,0,.2);
    }
    .ddp-header{
      display:flex;align-items:center;justify-content:space-between;
      padding:16px 20px;border-bottom:1px solid #f1f5f9;flex-shrink:0;
    }
    .ddp-title{font-size:15px;font-weight:700;color:#0f172a;}
    .ddp-close{background:none;border:none;font-size:20px;color:#94a3b8;cursor:pointer;line-height:1;padding:0;}
    .ddp-close:hover{color:#0f172a;}
    .ddp-list{overflow-y:auto;padding:10px 14px;flex:1;}
    .ddp-co-row{
      display:flex;align-items:center;gap:12px;padding:10px 8px;
      border-radius:8px;cursor:pointer;transition:background .1s;
      border-bottom:1px solid #f8fafc;
    }
    .ddp-co-row:last-child{border-bottom:none;}
    .ddp-co-row:hover{background:#f8fafc;}
    .ddp-av{width:36px;height:36px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;}
    .ddp-info{flex:1;min-width:0;}
    .ddp-name{font-size:13px;font-weight:600;color:#0f172a;}
    .ddp-meta{font-size:11px;color:#94a3b8;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .ddp-arrow{color:#cbd5e1;font-size:14px;flex-shrink:0;}
    .mcal-more:hover{color:var(--at-purple);}
    /* Companies overview table */
    .co-tbl-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;}
    .co-tbl-wrap table{width:100%;border-collapse:collapse;font-size:13px;}
    .co-tbl-wrap thead th{background:#f8fafc;padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #e2e8f0;white-space:nowrap;}
    .co-tbl-wrap tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s;cursor:pointer;}
    .co-tbl-wrap tbody tr:last-child{border-bottom:none;}
    .co-tbl-wrap tbody tr:hover{background:#f8fafc;}
    .co-tbl-wrap td{padding:11px 14px;vertical-align:middle;}
    .co-name-cell{display:flex;align-items:center;gap:10px;}
    .co-av-sm{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;}
    .co-nm-sm{font-size:13px;font-weight:600;color:#0f172a;}
    .prog-cell{min-width:120px;}
    .prog-track{height:5px;background:#f1f5f9;border-radius:3px;overflow:hidden;margin-top:4px;}
    .prog-track-fill{height:100%;border-radius:3px;background:#16a34a;transition:width .4s;}
    .prog-label{font-size:10px;color:#94a3b8;}
    /* Company detail bar */
    .co-detail-bar{
      display:flex;align-items:center;gap:8px;padding:7px 20px;
      background:#fff;border-bottom:1px solid #f1f5f9;flex-shrink:0;flex-wrap:wrap;
    }
    .co-detail-bar.hidden{display:none;}
    .cd-pill{
      display:inline-flex;align-items:center;gap:5px;
      font-size:11px;font-weight:500;color:#475569;
      background:#f8fafc;border:1px solid #e2e8f0;
      border-radius:20px;padding:3px 10px;white-space:nowrap;
    }
    .cd-pill i{font-size:12px;}
    .cd-pill.green{background:#f0fdf4;border-color:#bbf7d0;color:#15803d;}
    .cd-pill.blue{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;}
    .cd-pill.amber{background:#fffbeb;border-color:#fde68a;color:#b45309;}
    .cd-pill.purple{background:var(--at-purple-light);border-color:#c4b5fd;color:var(--at-purple);}
    @media(max-width:900px){
      .stats{grid-template-columns:repeat(3,1fr);}
      .sidebar{position:absolute;height:100%;z-index:300;}
      .sidebar:not(.sb-open){width:0;overflow:hidden;}
      .sidebar.sb-open{width:var(--sidebar-w);}
    }
    @media(max-width:600px){
      .stats{grid-template-columns:repeat(2,1fr);}
      .search-wrap{display:none;}
    }
  </style>
</head>
<body>
<div class="app" id="app">

  <!-- ══════════ SIDEBAR ══════════ -->
  <div class="sidebar" id="sidebar">
    <div class="sb-brand">
      <div class="logo">AT</div>
      <div class="hide-collapsed">
        <div class="brand-name">Content Board</div>
        <div class="brand-sub">Powered by MySQL</div>
      </div>
    </div>

    <div class="co-list" id="coList">
      <button class="co-btn" id="btnDashboard" onclick="showDashboard()">
        <div class="co-av" style="background:#1e293b;color:#6c47ff"><i class="bi bi-speedometer2" style="font-size:14px"></i></div>
        <div class="co-inf hide-collapsed">
          <div class="co-nm" style="color:#94a3b8">Dashboard</div>
        </div>
      </button>
    </div>

    <!-- People list -->
    <div class="sb-footer">
      <button class="sb-add" id="btnAddCo" onclick="showAllCompanies()">
        <i class="bi bi-plus-lg"></i>
        <span class="hide-collapsed">Company</span>
      </button>
      <?php if ($is_admin): ?>
      <button class="sb-person" id="btnNavUsers" onclick="showUsersPage()">
        <i class="bi bi-people" style="color:#f59e0b"></i>
        <span class="hide-collapsed">Users</span>
      </button>
      <?php endif; ?>
      <button class="sb-person" id="btnNavSocial" onclick="showSocialLoginsPage()">
        <i class="bi bi-key" style="color:#ec4899"></i>
        <span class="hide-collapsed">Social Logins</span>
      </button>
      <button class="sb-person" id="btnNavCM" onclick="showPeopleTable('content_manager')">
        <i class="bi bi-person-badge" style="color:#0891b2"></i>
        <span class="hide-collapsed">Content Managers</span>
      </button>
      <button class="sb-person" id="btnNavSP" onclick="showPeopleTable('sales_person')">
        <i class="bi bi-person-check" style="color:#16a34a"></i>
        <span class="hide-collapsed">Sales People</span>
      </button>
      <button class="sb-settings" id="btnSettings">
        <i class="bi bi-key"></i>
        <span class="hide-collapsed">API settings</span>
      </button>
    </div>
  </div>

  <!-- ══════════ MAIN ══════════ -->
  <div class="main">
    <?php if (!$connected): ?>
    <div style="background:#fef2f2;border-bottom:1px solid #fca5a5;padding:8px 20px;display:flex;align-items:center;justify-content:space-between;font-size:13px;color:#b91c1c">
      <span><i class="bi bi-exclamation-triangle me-2"></i><strong>Database not connected.</strong></span>
      <a href="config.php" style="background:#dc2626;color:#fff;padding:4px 14px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600">Configure database</a>
    </div>
    <?php endif; ?>
    <div class="topbar">
      <button class="tb-toggle" id="btnToggle"><i class="bi bi-list"></i></button>
      <span class="tb-title" id="tbTitle">Select a company</span>
      <span class="tb-badge" id="tbBadge" style="display:none">
        <i class="bi bi-table"></i><span id="tbBadgeTxt"></span>
      </span>
      <span class="tb-sync-badge" id="tbSyncBadge" style="display:none">
        <i class="bi bi-check-circle"></i><span id="tbSyncTxt"></span>
      </span>
      <div class="ms-auto d-flex align-items-center gap-2 me-3" style="flex-shrink:0">
        <span style="font-size:12px;color:#64748b;display:flex;align-items:center;gap:6px">
          <i class="bi bi-person-circle"></i>
          <strong><?= htmlspecialchars($user_name) ?></strong>
          <span class="badge" style="background:<?= $is_admin?'#6c47ff':($user_role==='content_manager'?'#0891b2':'#16a34a') ?>;font-size:10px">
            <?= $is_admin?'Admin':($user_role==='content_manager'?'CM':'SP') ?>
          </span>
        </span>
        <a href="?logout=1" class="btn btn-sm btn-outline-danger" style="font-size:12px" title="Logout">
          <i class="bi bi-box-arrow-right"></i>
        </a>
      </div>
      <div class="tb-actions" id="tbActions" style="display:none">
        <span class="tb-last" id="tbLast"></span>

        <button class="btn btn-sm btn-at" id="btnAddPost" style="font-size:12px">
          <i class="bi bi-plus-lg me-1"></i>Add post
        </button>

        <button class="btn btn-sm btn-at" id="btnAddCompanyTop" onclick="openAddCompanyModal()" style="font-size:12px;display:none">
          <i class="bi bi-plus-lg me-1"></i>Add company
        </button>
        <button class="btn btn-sm btn-at" id="btnAddPersonTop" style="font-size:12px;display:none" onclick="openAddPerson(this.dataset.role)">
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="btnPosting" style="font-size:12px" onclick="openPostingModal(activeId)">
          <i class="bi bi-share me-1"></i>Posting
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="btnEditCo" style="font-size:12px">
          <i class="bi bi-pencil me-1"></i>Edit company
        </button>
        <button class="btn btn-sm btn-outline-danger" id="btnDelCo" style="font-size:12px">
          <i class="bi bi-trash me-1"></i>Remove
        </button>
      </div>
    </div>

    <div class="stats" id="statsRow">
      <div class="sc"><div class="sc-lbl">Total</div><div class="sc-val" id="sT">—</div><div class="sc-prog"><div class="sc-prog-fill" id="sPt" style="width:0%;background:#0f172a;"></div></div></div>
      <div class="sc pub"><div class="sc-lbl"><i class="bi bi-check-circle me-1"></i>Published</div><div class="sc-val" id="sP">—</div><div class="sc-prog"><div class="sc-prog-fill" id="sPp" style="width:0%"></div></div></div>
      <div class="sc sch"><div class="sc-lbl"><i class="bi bi-clock me-1"></i>Scheduled</div><div class="sc-val" id="sS">—</div><div class="sc-prog"><div class="sc-prog-fill" id="sPsp" style="width:0%"></div></div></div>
      <div class="sc rev"><div class="sc-lbl"><i class="bi bi-eye me-1"></i>In review</div><div class="sc-val" id="sR">—</div><div class="sc-prog"><div class="sc-prog-fill" id="sPrp" style="width:0%"></div></div></div>
      <div class="sc ovr"><div class="sc-lbl"><i class="bi bi-exclamation-circle me-1"></i>Overdue</div><div class="sc-val" id="sO">—</div><div class="sc-prog"><div class="sc-prog-fill" id="sPop" style="width:0%"></div></div></div>
    </div>

    <div class="co-detail-bar hidden" id="coDetailBar"></div>
    <div id="platformBubbles-date" style="display:none;padding:14px 20px 0;margin-top:10px;margin-left:20px;font-size:13px;font-weight:600;color:#64748b;"></div>
    <div class="platform-bubbles" id="platformBubbles" style="display:none"></div>
    <div class="content" id="content">
      <div class="state-box">
        <i class="bi bi-building"></i>
        <strong>No company selected</strong>
        <p>Add a company from the sidebar to start tracking content posts.</p>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ MODAL: Add Company ══════════ -->
<div class="modal fade" id="mAddCo" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mAddCoTitle"><i class="bi bi-building me-2"></i>Add company</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Company name *</label>
          <input class="form-control" id="coName" placeholder="e.g. Acme Corp">
        </div>
        <input type="hidden" id="coBaseId" value="">
        <div id="tablePickRow" style="display:none"><select id="coTableSel"><option value="">—</option></select></div>
        <div id="fieldMapWrap" style="display:none"><div id="fieldMapInner"></div></div>
        <div class="mb-2" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;">Company details</div>
        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <label class="form-label">Content Manager</label>
            <select class="form-select" id="coContentManager">
              <option value="">— Select —</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Sales Person</label>
            <select class="form-select" id="coSalesPerson">
              <option value="">— Select —</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Monthly Posts</label>
            <input class="form-control" id="coMonthlyPosts" type="number" min="0" placeholder="e.g. 12">
          </div>
          <div class="col-md-4">
            <label class="form-label">Monthly Fee</label>
            <div class="input-group">
              <span class="input-group-text" style="font-size:13px">$</span>
              <input class="form-control" id="coFee" type="number" min="0" step="0.01" placeholder="0.00">
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Payment Date</label>
            <input class="form-control" id="coPaymentDate" type="number" min="1" max="31" placeholder="Day e.g. 1">
            <div class="form-text">Day of month</div>
          </div>
          <div class="col-12 mt-2">
            <label class="form-label">Fee distribution <span class="text-muted fw-normal">(must total 100%)</span></label>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;">
              <div class="row g-2 align-items-center mb-2">
                <div class="col-5"><label class="form-label mb-0" style="font-size:12px;color:#374151"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#16a34a;margin-right:5px"></span>Sales Person</label></div>
                <div class="col-4">
                  <div class="input-group input-group-sm">
                    <input class="form-control" id="coFeeSP" type="number" min="0" max="100" step="1" value="40">
                    <span class="input-group-text" style="font-size:12px">%</span>
                  </div>
                </div>
                <div class="col-3 text-end" id="coFeeSPAmt" style="font-size:12px;color:#64748b;font-weight:500"></div>
              </div>
              <div class="row g-2 align-items-center mb-2">
                <div class="col-5"><label class="form-label mb-0" style="font-size:12px;color:#374151"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#0891b2;margin-right:5px"></span>Content Manager</label></div>
                <div class="col-4">
                  <div class="input-group input-group-sm">
                    <input class="form-control" id="coFeeCM" type="number" min="0" max="100" step="1" value="40">
                    <span class="input-group-text" style="font-size:12px">%</span>
                  </div>
                </div>
                <div class="col-3 text-end" id="coFeeCMAmt" style="font-size:12px;color:#64748b;font-weight:500"></div>
              </div>
              <div class="row g-2 align-items-center mb-2">
                <div class="col-5"><label class="form-label mb-0" style="font-size:12px;color:#374151"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#6c47ff;margin-right:5px"></span>Searchmonster</label></div>
                <div class="col-4">
                  <div class="input-group input-group-sm">
                    <input class="form-control" id="coFeeSM" type="number" min="0" max="100" step="1" value="20">
                    <span class="input-group-text" style="font-size:12px">%</span>
                  </div>
                </div>
                <div class="col-3 text-end" id="coFeeSMAmt" style="font-size:12px;color:#64748b;font-weight:500"></div>
              </div>
              <div class="row g-2 align-items-center pt-2" style="border-top:1px solid #e2e8f0;margin-top:4px">
                <div class="col-5" style="font-size:11px;font-weight:600;color:#374151">Total</div>
                <div class="col-4 text-center" id="coFeeTotal" style="font-size:12px;font-weight:700;color:#0f172a">100%</div>
                <div class="col-3 text-end" id="coFeeTotalAmt" style="font-size:12px;font-weight:700;color:#0f172a"></div>
              </div>
            </div>
          </div>
        </div>
        <hr class="my-3">
        <div class="mb-3">
          <label class="form-label fw-bold">Posting Days</label>
          <div style="font-size:11px;color:#94a3b8;margin-bottom:8px">Days this company posts — applies to all platforms.</div>
          <div class="d-flex gap-2 flex-wrap" id="postingDaysWrap"></div>
        </div>
        <div class="mb-2">
          <label class="form-label fw-bold">Social Logins</label>
          <div style="font-size:11px;color:#94a3b8;margin-bottom:10px">Link a saved login for each platform. Use the arrow to show or hide its details.</div>
          <div id="platformConfigWrap" style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden">
            <!-- populated by buildPlatformConfig() -->
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Brand color</label>
          <div class="d-flex gap-2 flex-wrap" id="colorDots"></div>
        </div>
      </div>
      <div class="alert alert-danger mx-3 d-none" id="addCoErr" style="font-size:13px;"></div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-at" id="btnSaveCo"><i class="bi bi-check-lg me-1"></i>Save company</button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ MODAL: Add/Edit Post ══════════ -->
<div class="modal fade" id="mPost" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mPostTitle"><i class="bi bi-pencil me-2"></i>Add post</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Post title *</label>
            <input class="form-control" id="pTitle" placeholder="e.g. Q3 product launch announcement">
          </div>
          <div class="col-md-6">
            <label class="form-label">Platform</label>
            <select class="form-select" id="pPlatform">
              <option value="">— Select —</option>
              <option>Blog</option>
              <option>Facebook</option>
              <option>Instagram</option>
              <option>TikTok</option>
              <option>YouTube</option>
              <option>Reddit</option>
              <option>Pinterest</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Status</label>
            <select class="form-select" id="pStatus">
              <option value="scheduled">Scheduled</option>
              <option value="published">Published</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Date</label>
            <input type="date" class="form-control" id="pDate">
          </div>
          <div class="col-md-6">
            <label class="form-label">Content Manager</label>
            <select class="form-select" id="pAssignee">
              <option value="">— Select content manager —</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Post URL</label>
            <input type="url" class="form-control" id="pNotes" placeholder="https://…">
          </div>
        </div>
        <!-- Social login info panel - populated for the selected platform -->
        <div id="pSocialInfo" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;margin-top:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8">
              <i class="bi bi-key me-1" style="color:#ec4899"></i>Social Login for this Platform
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="pSocialToggle" style="font-size:11px;padding:2px 10px">Show <i class="bi bi-chevron-down"></i></button>
          </div>
          <div id="pSocialBody" style="display:none;margin-top:10px">
            <div class="row g-2" style="font-size:13px">
              <div class="col-md-6">
                <div style="color:#64748b;font-size:11px;font-weight:600;margin-bottom:2px">Channel URL</div>
                <div id="pSocialUrl" style="overflow-wrap:anywhere">—</div>
              </div>
              <div class="col-md-6">
                <div style="color:#64748b;font-size:11px;font-weight:600;margin-bottom:2px">Login</div>
                <div id="pSocialUser" style="font-family:monospace;overflow-wrap:anywhere">—</div>
              </div>
              <div class="col-md-6">
                <div style="color:#64748b;font-size:11px;font-weight:600;margin-bottom:2px">Password</div>
                <div style="display:flex;align-items:center;gap:8px">
                  <span id="pSocialPw" style="font-family:monospace;letter-spacing:2px;color:#64748b">••••••••</span>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="pSocialReveal" style="font-size:10px;padding:2px 8px"><i class="bi bi-eye"></i></button>
                </div>
              </div>
              <div class="col-md-6">
                <div style="color:#64748b;font-size:11px;font-weight:600;margin-bottom:2px">Posting Software</div>
                <div id="pSocialSoftware" style="color:#475569;overflow-wrap:anywhere">—</div>
              </div>
            </div>
          </div>
        </div>
        <div class="alert alert-danger mt-3 d-none" id="pErrMsg" style="font-size:13px;"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-at" id="btnSavePost"><i class="bi bi-check-lg me-1"></i>Save post</button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ MODAL: Confirm ══════════ -->
<div class="modal fade" id="mConfirm" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <i class="bi bi-exclamation-triangle text-danger" style="font-size:32px"></i>
        <p class="mt-3 mb-0 fw-semibold" id="confirmMsg" style="font-size:14px"></p>
      </div>
      <div class="modal-footer justify-content-center">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-danger" id="btnConfirmYes">Delete</button>
      </div>
    </div>
  </div>
</div>


<!-- ══════════ MODAL: Add Person ══════════ -->
<div class="modal fade" id="mAddPerson" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mPersonTitle"><i class="bi bi-person-badge me-2"></i>Add person</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Full name *</label>
          <input class="form-control" id="personName" placeholder="e.g. Jane Smith">
        </div>
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input class="form-control" id="personEmail" type="email" placeholder="jane@company.com">
        </div>
        <div class="mb-3">
          <label class="form-label">Companies / Clients <span class="text-muted fw-normal">(select all that apply)</span></label>
          <div id="personCompanyChecks" style="max-height:140px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;background:#f8fafc;display:flex;flex-direction:column;gap:6px;">
            <span style="font-size:12px;color:#94a3b8">No companies added yet</span>
          </div>
        </div>
        <div class="mb-1">
          <label class="form-label">Notes</label>
          <input class="form-control" id="personNotes" placeholder="e.g. handles Instagram, contact for approvals…">
        </div>
      </div>
      <div class="alert alert-danger mx-3 d-none" id="personErr" style="font-size:13px;"></div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-at" id="btnSavePerson"><i class="bi bi-check-lg me-1"></i>Save</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Auth context ──
const CURRENT_USER = {
  id:          <?= json_encode($user['id'] ?? '') ?>,
  role:        <?= json_encode($user_role) ?>,
  full_name:   <?= json_encode($user_name) ?>,
  is_admin:    <?= $is_admin ? 'true' : 'false' ?>,
  company_ids: <?= json_encode($company_ids) ?>
};

// ── API layer ──
const API = 'api.php';
async function apiCall(action, opts) {
  opts = opts || {};
  const url = API + '?action=' + encodeURIComponent(action) + (opts.id ? '&id=' + encodeURIComponent(opts.id) : '');
  let r;
  try {
    r = await fetch(url, {
      method:  opts.method || 'GET',
      headers: {'Content-Type':'application/json'},
      body:    opts.body ? JSON.stringify(opts.body) : undefined
    });
  } catch (netErr) {
    throw new Error('Network error while calling "' + action + '" — check your connection and try again.');
  }
  if (r.status === 401 || (r.redirected && r.url.indexOf('login.php') !== -1)) {
    window.location = 'login.php';
    throw new Error('Your session has expired — redirecting to login…');
  }
  let json;
  try {
    json = await r.json();
  } catch (parseErr) {
    throw new Error('Server returned an invalid response for "' + action + '" (HTTP ' + r.status + ').');
  }
  if (!json.ok) throw new Error(json.error || ('API error (HTTP ' + r.status + ')'));
  return json.data;
}

// ── Airtable stubs (no longer used) ──
async function fetchTables(){ return []; }
async function fetchAllRecords(){ return []; }
async function createRecord(coId, data){ return apiCall('save_post',{method:'POST',body:{...data,company_id:coId}}); }
async function updateRecord(coId, recId, data){ return apiCall('save_post',{method:'POST',body:{...data,id:recId,company_id:coId}}); }
async function deleteRecord(coId, recId){ return apiCall('delete_post',{method:'POST',body:{id:recId}}); }

/* ════════════════════════════════
   STATE
════════════════════════════════ */
const COLORS=['#6c47ff','#16a34a','#d97706','#dc2626','#0891b2','#db2777','#ea580c','#64748b','#0f172a'];
let db={companies:[]};
let token='';
let activeId=null;
let filterSt='all';
let filterPlatform='';
let sortCol='date';
let sortAsc=true;
let searchQ='';
let editPostId=null;
let _editCoId=null;
let confirmCb=null;
let _selectedColor=COLORS[0];
let _availableFields=[];

function save(){try{localStorage.setItem('contentBoard_v2',JSON.stringify(db));}catch(e){}}
function load(){
  try{
    const r=localStorage.getItem('contentBoard_v2');
    if(r)db=JSON.parse(r);
    token=localStorage.getItem('at_token')||'';
  }catch(e){}
}
function saveToken(t){token=t;localStorage.setItem('at_token',t);}

/* ════════════════════════════════
   AIRTABLE API
════════════════════════════════ */
// Airtable removed

/* ════════════════════════════════
   HELPERS
════════════════════════════════ */
function normStatus(s){
  if(!s)return'draft';
  const l=s.toLowerCase().trim();
  if(['published','live','done','posted','complete','completed'].includes(l))return'published';
  if(['scheduled','queued','approved','ready','planned'].includes(l))return'scheduled';
  if(['review','in review','pending','awaiting','pending review'].includes(l))return'review';
  if(['overdue','late','missed'].includes(l))return'overdue';
  return'draft';
}
function normPlatform(p){
  if(!p)return'other';
  const l=p.toLowerCase().trim();
  if(l.includes('instagram')||l==='ig')return'instagram';
  if(l.includes('linkedin')||l==='li')return'linkedin';
  if(l==='twitter'||l==='tw')return'twitter';
  if(l==='x'||l==='x (twitter)')return'x';
  if(l.includes('facebook')||l==='fb')return'facebook';
  if(l.includes('tiktok')||l==='tt')return'tiktok';
  if(l.includes('youtube')||l==='yt')return'youtube';
  if(l.includes('blog'))return'blog';
  if(l.includes('email')||l==='newsletter')return'email';
  if(l.includes('reddit'))return'reddit';
  if(l.includes('pinterest'))return'pinterest';
  return'other';
}
function platLabel(p){return{instagram:'Instagram',linkedin:'LinkedIn',twitter:'Twitter',x:'X',facebook:'Facebook',tiktok:'TikTok',youtube:'YouTube',blog:'Blog',email:'Email',reddit:'Reddit',pinterest:'Pinterest',other:'Other'}[p]||p||'—';}
function statLabel(s){return{published:'Published',scheduled:'Scheduled',draft:'Draft',review:'In review',overdue:'Overdue'}[s]||s;}
function initials(n){if(!n)return'?';return n.trim().split(/\s+/).map(w=>w[0]).join('').slice(0,2).toUpperCase();}
function avBg(n){const p=['#6366f1','#0891b2','#16a34a','#d97706','#dc2626','#9333ea','#db2777'];return p[(n||'A').charCodeAt(0)%p.length];}
function uid(){return Date.now().toString(36)+Math.random().toString(36).slice(2,6);}
function esc(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function getCo(){return db.companies.find(c=>c.id===activeId);}
function pct(n,t){return t?Math.round(n/t*100):0;}

/* Map Airtable record → post using stored field mapping */
function mapRecord(rec,mapping){
  const f=rec.fields||{};
  const get=(key)=>{
    const v=f[mapping[key]||key];
    if(Array.isArray(v))return v.join(', ');
    if(v&&typeof v==='object'&&v.name)return v.name;
    return v||'';
  };
  return{
    id:rec.id,
    atId:rec.id,
    title:String(get('title')||''),
    platform:String(get('platform')||''),
    status:normStatus(String(get('status')||'')),
    date:String(get('date')||''),
    assignee:String(get('assignee')||''),
    notes:String(get('notes')||''),
  };
}

/* Build Airtable fields from post using mapping */
function postToFields(post,mapping){
  const flip={};
  Object.entries(mapping).forEach(([k,v])=>{if(v)flip[k]=v;});
  const f={};
  const set=(key,val)=>{if(mapping[key])f[mapping[key]]=val;};
  set('title',post.title);
  set('platform',post.platform);
  set('status',statLabel(post.status));
  set('date',post.date||null);
  set('assignee',post.assignee);
  set('notes',post.notes);
  return f;
}

/* ════════════════════════════════
   RENDER SIDEBAR
════════════════════════════════ */
function renderSidebar(){ /* companies no longer listed in sidebar */ }

/* ════════════════════════════════
   RENDER STATS
════════════════════════════════ */
function renderStats(posts){
  const t=posts.length;
  const p=posts.filter(x=>x.status==='published').length;
  const s=posts.filter(x=>x.status==='scheduled').length;
  const r=posts.filter(x=>x.status==='review').length;
  const o=posts.filter(x=>x.status==='overdue').length;
  document.getElementById('sT').textContent=t||'—';
  document.getElementById('sP').textContent=p||'—';
  document.getElementById('sS').textContent=s||'—';
  document.getElementById('sR').textContent=r||'—';
  document.getElementById('sO').textContent=o||'—';
  document.getElementById('sPt').style.width='100%';
  document.getElementById('sPp').style.width=pct(p,t)+'%';
  document.getElementById('sPsp').style.width=pct(s,t)+'%';
  document.getElementById('sPrp').style.width=pct(r,t)+'%';
  document.getElementById('sPop').style.width=pct(o,t)+'%';
}

/* ════════════════════════════════
   RENDER MAIN
════════════════════════════════ */
function showDashboard(){
  activeId = null;
  document.querySelectorAll('.co-btn, .sb-person').forEach(b=>b.classList.remove('active'));
  document.getElementById('btnDashboard')?.classList.add('active');
  document.getElementById('tbTitle').textContent = 'Dashboard';
  document.getElementById('coDetailBar').classList.add('hidden');
  ['tbBadge','tbSyncBadge'].forEach(id=>document.getElementById(id).style.display='none');
  document.getElementById('tbActions').style.display = 'flex';
  document.getElementById('btnAddCompanyTop').style.display = 'none';
  document.getElementById('btnAddPersonTop').style.display = 'none';
  ['btnAddPost','btnEditCo','btnDelCo','btnPosting'].forEach(id=>{
    const el=document.getElementById(id); if(el) el.style.display='none';
  });

  const companies = db.companies;
  const people = db.people || [];
  const allPosts = companies.flatMap(co => co.posts || []);
  const totalPub = allPosts.filter(p=>p.status==='published').length;
  const totalSched = allPosts.filter(p=>p.status==='scheduled').length;
  const totalDraft = allPosts.filter(p=>p.status==='draft').length;
  const totalOver = allPosts.filter(p=>p.status==='overdue').length;
  const cms = people.filter(p=>p.role==='content_manager');
  const sps = people.filter(p=>p.role==='sales_person');
  const totalFee = companies.reduce((s,co)=>s+(co.fee||0),0);
  const totalSP = companies.reduce((s,co)=>s+(co.fee||0)*(co.feeSP!=null?co.feeSP:40)/100,0);
  const totalCM = companies.reduce((s,co)=>s+(co.fee||0)*(co.feeCM!=null?co.feeCM:40)/100,0);
  const totalSM = companies.reduce((s,co)=>s+(co.fee||0)*(co.feeSM!=null?co.feeSM:20)/100,0);

  // Update stat cards with aggregate data
  document.getElementById('sT').textContent = companies.length || '—';
  document.getElementById('sP').textContent = totalPub || '—';
  document.getElementById('sS').textContent = totalSched || '—';
  document.getElementById('sR').textContent = cms.length || '—';
  document.getElementById('sO').textContent = totalOver || '—';
  // Stat labels
  document.querySelector('.sc:nth-child(1) .sc-lbl').textContent = 'Companies';
  document.querySelector('.sc.pub .sc-lbl').innerHTML = '<i class="bi bi-check-circle me-1"></i>Published';
  document.querySelector('.sc.sch .sc-lbl').innerHTML = '<i class="bi bi-clock me-1"></i>Scheduled';
  document.querySelector('.sc.rev .sc-lbl').innerHTML = '<i class="bi bi-people me-1"></i>Content Managers';
  document.querySelector('.sc.ovr .sc-lbl').innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Overdue';

  const wrap = document.getElementById('content');

  if(!companies.length){
    wrap.innerHTML = '<div class="state-box"><i class="bi bi-speedometer2" style="font-size:40px"></i><strong>Welcome to Content Board</strong><p>Add your first company to get started tracking content posts.</p><button class="btn btn-at mt-3" onclick="openAddCompanyModal()" style="font-size:13px"><i class="bi bi-plus-lg me-1"></i>Add company</button></div>';
    return;
  }

  // Summary fee card
  function fmtMoney(n){ return '$'+Number(n).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }

  const feeCard = totalFee > 0
    ? '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">'+
        '<div class="sc" style="background:linear-gradient(135deg,#6c47ff11,#6c47ff22);border-color:#c4b5fd">'+
          '<div class="sc-lbl" style="color:#6c47ff"><i class="bi bi-cash-stack me-1"></i>Monthly Revenue</div>'+
          '<div class="sc-val" style="color:#6c47ff;font-size:20px">'+fmtMoney(totalFee)+'</div>'+
          '<div class="sc-prog"><div class="sc-prog-fill" style="width:100%;background:#6c47ff"></div></div>'+
        '</div>'+
        '<div class="sc" style="background:linear-gradient(135deg,#16a34a11,#16a34a22);border-color:#bbf7d0">'+
          '<div class="sc-lbl" style="color:#15803d"><i class="bi bi-person-check me-1"></i>Sales Person</div>'+
          '<div class="sc-val" style="color:#15803d;font-size:20px">'+fmtMoney(totalSP)+'</div>'+
          '<div style="font-size:10px;color:#86efac;margin-top:4px">'+Math.round(totalSP/totalFee*100)+'% of revenue</div>'+
        '</div>'+
        '<div class="sc" style="background:linear-gradient(135deg,#0891b211,#0891b222);border-color:#bae6fd">'+
          '<div class="sc-lbl" style="color:#0369a1"><i class="bi bi-person-badge me-1"></i>Content Manager</div>'+
          '<div class="sc-val" style="color:#0369a1;font-size:20px">'+fmtMoney(totalCM)+'</div>'+
          '<div style="font-size:10px;color:#7dd3fc;margin-top:4px">'+Math.round(totalCM/totalFee*100)+'% of revenue</div>'+
        '</div>'+
        '<div class="sc" style="background:linear-gradient(135deg,#d9770611,#d9770622);border-color:#fed7aa">'+
          '<div class="sc-lbl" style="color:#c2410c"><i class="bi bi-search me-1"></i>Searchmonster</div>'+
          '<div class="sc-val" style="color:#c2410c;font-size:20px">'+fmtMoney(totalSM)+'</div>'+
          '<div style="font-size:10px;color:#fdba74;margin-top:4px">'+Math.round(totalSM/totalFee*100)+'% of revenue</div>'+
        '</div>'+
      '</div>'
    : '';

  // Today's posting companies
  const todayDate = new Date();
  const dowName2 = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  const todayDow = dowName2[todayDate.getDay()];
  const todayMonthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const todayLabel = todayMonthNames[todayDate.getMonth()] + ' ' + todayDate.getDate() + ', ' + todayDate.getFullYear();

  const todayCompanies = companies.filter(function(co){
    const pdays = co.postingDays || [];
    return pdays.length === 0 || pdays.includes(todayDow);
  });

  function buildTodayRow(co){
    const posts = co.posts || [];
    const pub = posts.filter(p=>p.status==='published').length;
    const over = posts.filter(p=>p.status==='overdue').length;
    const cm = people.find(p=>p.id===co.contentManagerId);
    const sp = people.find(p=>p.id===co.salesPersonId);
    const pct2 = posts.length ? Math.round(pub/posts.length*100) : 0;
    return '<tr data-coid="'+co.id+'" style="cursor:pointer">'+
      '<td><div style="display:flex;align-items:center;gap:10px">'+
        '<div class="co-av-sm" style="background:'+co.color+'22;color:'+co.color+'">'+co.name.slice(0,2).toUpperCase()+'</div>'+
        '<span style="font-size:13px;font-weight:600;color:#0f172a">'+esc(co.name)+'</span>'+
      '</div></td>'+
      '<td style="font-size:12px;color:#475569">'+(cm?esc(cm.name):'<span style="color:#cbd5e1">—</span>')+'</td>'+
      '<td style="font-size:12px;color:#475569">'+(sp?esc(sp.name):'<span style="color:#cbd5e1">—</span>')+'</td>'+
      '<td style="text-align:center"><span class="stat-pill sp-published" style="font-size:11px">'+pub+'</span></td>'+
      '<td style="text-align:center">'+(over?'<span class="stat-pill sp-overdue" style="font-size:11px">'+over+'</span>':'<span style="color:#cbd5e1">—</span>')+'</td>'+
      '<td style="font-size:12px;color:#475569">'+(co.fee!=null?'<strong>$'+Number(co.fee).toLocaleString('en',{minimumFractionDigits:2})+'</strong>/mo':'<span style="color:#cbd5e1">—</span>')+'</td>'+
    '</tr>';
  }

  const todaySection = todayCompanies.length
    ? '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-top:16px">'+
        '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc">'+
          '<div style="font-size:14px;font-weight:700;color:#0f172a"><i class="bi bi-calendar-check me-2" style="color:var(--at-purple)"></i>Posting today — '+todayLabel+'</div>'+
          '<span style="background:var(--at-purple);color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600">'+todayCompanies.length+' compan'+(todayCompanies.length!==1?'ies':'y')+'</span>'+
        '</div>'+
        '<table style="width:100%;border-collapse:collapse;font-size:13px">'+
        '<thead><tr style="background:#faf5ff">'+
          '<th style="padding:8px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #f1f5f9">Company</th>'+
          '<th style="padding:8px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #f1f5f9">Content Manager</th>'+
          '<th style="padding:8px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #f1f5f9">Sales Person</th>'+
          '<th style="padding:8px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #f1f5f9;text-align:center">Published</th>'+
          '<th style="padding:8px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #f1f5f9;text-align:center">Overdue</th>'+
          '<th style="padding:8px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #f1f5f9">Fee</th>'+
        '</tr></thead>'+
        '<tbody>'+todayCompanies.map(buildTodayRow).join('')+'</tbody>'+
      '</table></div>'
    : '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-top:16px;text-align:center;color:#94a3b8;font-size:13px">'+
        '<i class="bi bi-calendar-x" style="font-size:24px;display:block;margin-bottom:8px"></i>No companies scheduled to post today ('+todayDow+')'+
      '</div>';

  wrap.innerHTML =
    feeCard +
    '<div id="dashCalContainer"></div>' +
    todaySection;

  // Wire today company row clicks
  wrap.querySelectorAll('tr[data-coid]').forEach(function(tr){
    tr.addEventListener('click', function(){ selectCo(tr.dataset.coid); });
  });

  // Render calendar
  const now = new Date();
  window.renderCalendar(now.getFullYear(), now.getMonth());
}

window.openEditPerson = function(id){
  const p = (db.people||[]).find(function(x){return x.id===id;});
  if(!p) return;
  _personRole = p.role;
  window._editPersonId = id;
  const isCM = p.role==='content_manager';
  document.getElementById('mPersonTitle').innerHTML = isCM
    ? '<i class="bi bi-person-badge me-2" style="color:#0891b2"></i>Edit content manager'
    : '<i class="bi bi-person-check me-2" style="color:#16a34a"></i>Edit sales person';
  document.getElementById('personName').value = p.name||'';
  document.getElementById('personEmail').value = p.email||'';
  document.getElementById('personNotes').value = p.notes||'';
  document.getElementById('personErr').classList.add('d-none');
  // Support both old single companyId and new companyIds array
  const existingIds = p.companyIds || (p.companyId ? [p.companyId] : []);
  populatePersonCompanyChecks(existingIds);
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mAddPerson')).show();
};




window.openEditCompanyModal = function openEditCompanyModal(id){
  const co = db.companies.find(function(c){ return c.id===id; });
  if(!co) return;
  _editCoId = id;
  document.getElementById('mAddCoTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit company';
  document.getElementById('btnSaveCo').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update company';
  document.getElementById('addCoErr').classList.add('d-none');
  document.getElementById('coName').value = co.name || '';
  buildColorDots('colorDots');
  _selectedColor = co.color || COLORS[0];
  document.querySelectorAll('#colorDots .color-dot').forEach(function(d){
    d.classList.toggle('chosen', d.dataset.color === _selectedColor);
  });
  document.getElementById('tablePickRow').style.display = 'none';
  document.getElementById('fieldMapWrap').style.display = 'none';
  _availableFields = [];
  if(co.tableId){
    document.getElementById('tablePickRow').style.display = '';
    const sel = document.getElementById('coTableSel');
    sel.innerHTML = '<option value="'+co.tableId+'" data-name="'+esc(co.tableName||co.tableId)+'" selected>'+esc(co.tableName||co.tableId)+'</option>';
  }
  var cmSelE = document.getElementById('coContentManager');
  var spSelE = document.getElementById('coSalesPerson');
  function fillCMSP(people) {
    var cmsE = people.filter(function(p){ return p.role==='content_manager'; });
    var spsE = people.filter(function(p){ return p.role==='sales_person'; });
    cmSelE.innerHTML = '<option value="">— Select —</option>' + cmsE.map(function(p){ return '<option value="'+p.id+'"'+(p.id===co.contentManagerId||p.id===co.content_manager_id?' selected':'')+'>'+esc(p.name)+'</option>'; }).join('');
    spSelE.innerHTML = '<option value="">— Select —</option>' + spsE.map(function(p){ return '<option value="'+p.id+'"'+(p.id===co.salesPersonId||p.id===co.sales_person_id?' selected':'')+'>'+esc(p.name)+'</option>'; }).join('');
    // (was cmSel/spSel — undefined here; the typo aborted this whole
    // function whenever no CMs or SPs existed yet)
    if(!cmsE.length) cmSelE.innerHTML += '<option value="" disabled style="color:#94a3b8">No content managers added yet</option>';
    if(!spsE.length) spSelE.innerHTML += '<option value="" disabled style="color:#94a3b8">No sales people added yet</option>';
  }
  fillCMSP(db.people||[]);
  apiCall('people').then(function(fresh){
    if(fresh && fresh.length){ db.people = fresh.map(normalizePerson); save(); fillCMSP(db.people); }
  }).catch(function(){ /* selects already filled from local data above */ });
  document.getElementById('coMonthlyPosts').value = co.monthlyPosts != null ? co.monthlyPosts : '';
  document.getElementById('coFee').value = co.fee != null ? co.fee : '';
  document.getElementById('coPaymentDate').value = co.paymentDate != null ? co.paymentDate : '';
  document.getElementById('coFeeSP').value = co.feeSP != null ? co.feeSP : 40;
  document.getElementById('coFeeCM').value = co.feeCM != null ? co.feeCM : 40;
  document.getElementById('coFeeSM').value = co.feeSM != null ? co.feeSM : 20;
  updateFeeDistribution();
  buildPostingDays(co.postingDays || []);
  buildPlatformConfig(co.platform_config || {});
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mAddCo')).show();
};

// ── showAllCompanies: show companies overview table ──
function showAllCompanies(){
  activeId = null;
  const wrap = document.getElementById('content');
  document.getElementById('tbTitle').textContent = 'Companies';
  document.getElementById('coDetailBar').classList.add('hidden');
  const _pb=document.getElementById('platformBubbles'); if(_pb) _pb.style.display='none';
  const _pd=document.getElementById('platformBubbles-date'); if(_pd) _pd.style.display='none';
  ['tbBadge','tbSyncBadge'].forEach(id=>document.getElementById(id).style.display='none');
  document.getElementById('tbActions').style.display = 'flex';
  document.getElementById('btnAddCompanyTop').style.display = '';
  document.getElementById('btnAddPersonTop').style.display = 'none';
  ['btnAddPost','btnEditCo','btnDelCo','btnPosting'].forEach(id=>{
    const el=document.getElementById(id); if(el) el.style.display='none';
  });
  renderStats([]);
  document.querySelectorAll('.co-btn, .sb-person').forEach(b=>b.classList.remove('active'));
  document.getElementById('btnAddCo')?.classList.add('active');
  document.getElementById('coDetailBar').classList.add('hidden');

  const companies = db.companies;

  if(!companies.length){
    wrap.innerHTML = '<div class="state-box"><i class="bi bi-building" style="font-size:40px"></i><strong>No companies yet</strong><p>Click <strong>Add company</strong> above to get started.</p></div>';
    return;
  }

  const people = db.people || [];
  const coRows = companies.map(function(co){
    const posts = co.posts || [];
    const pub = posts.filter(p=>p.status==='published').length;
    const over = posts.filter(p=>p.status==='overdue').length;
    const cm = people.find(p=>p.id===co.contentManagerId);
    const sp = people.find(p=>p.id===co.salesPersonId);
    return '<tr data-coid="'+co.id+'" style="cursor:pointer">'+
      '<td><div style="display:flex;align-items:center;gap:10px">'+
        '<div class="co-av-sm" style="background:'+co.color+'22;color:'+co.color+'">'+co.name.slice(0,2).toUpperCase()+'</div>'+
        '<div><div style="font-size:13px;font-weight:600;color:#0f172a">'+esc(co.name)+'</div>'+
        (co.tableName?'<div style="font-size:10px;color:#94a3b8"><i class="bi bi-table me-1"></i>'+esc(co.tableName)+'</div>':'')+
        '</div></div></td>'+
      '<td>'+(cm?'<div class="assn"><div class="av" style="background:'+avBg(cm.name)+'">'+initials(cm.name)+'</div><span class="av-nm">'+esc(cm.name)+'</span></div>':'<span style="color:#cbd5e1">—</span>')+'</td>'+
      '<td>'+(sp?'<div class="assn"><div class="av" style="background:'+avBg(sp.name)+'">'+initials(sp.name)+'</div><span class="av-nm">'+esc(sp.name)+'</span></div>':'<span style="color:#cbd5e1">—</span>')+'</td>'+
      '<td style="text-align:center"><span class="stat-pill sp-published" style="font-size:11px">'+pub+'</span></td>'+
      '<td style="text-align:center">'+(over?'<span class="stat-pill sp-overdue" style="font-size:11px">'+over+'</span>':'<span style="color:#cbd5e1">—</span>')+'</td>'+
      '<td style="font-size:12px;color:#475569">'+(co.fee!=null?'<strong>$'+Number(co.fee).toLocaleString('en',{minimumFractionDigits:2})+'</strong>/mo':'<span style="color:#cbd5e1">—</span>')+'</td>'+
      '<td style="white-space:nowrap">'+
        '<button class="btn btn-sm btn-outline-secondary" data-edit-co="'+co.id+'" style="font-size:11px;padding:3px 10px;margin-right:4px"><i class="bi bi-pencil me-1"></i>Edit</button>'+'<button class="btn btn-sm btn-outline-secondary" data-posting-co="'+co.id+'" style="font-size:11px;padding:3px 10px"><i class="bi bi-share me-1"></i>Posting</button>'+
      '</td>'+
    '</tr>';
  }).join('');

  wrap.innerHTML = '<div class="tbl-wrap" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">'+
    '<table style="width:100%;border-collapse:collapse;font-size:13px">'+
    '<thead><tr style="background:#f8fafc">'+
      '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #e2e8f0">Company</th>'+
      '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #e2e8f0">Content Manager</th>'+
      '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #e2e8f0">Sales Person</th>'+
      '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #e2e8f0;text-align:center">Published</th>'+
      '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #e2e8f0;text-align:center">Overdue</th>'+
      '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #e2e8f0">Fee</th>'+
      '<th style="padding:9px 14px;border-bottom:1px solid #e2e8f0"></th>'+
    '</tr></thead>'+
    '<tbody>'+coRows+'</tbody>'+
    '</table></div>';

  wrap.querySelectorAll('tr[data-coid]').forEach(function(tr){
    tr.addEventListener('click',function(){ selectCo(tr.dataset.coid); });
  });
  wrap.querySelectorAll('[data-edit-co]').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      openEditCompanyModal(btn.dataset.editCo);
    });
  });
}

// ── showPeopleTable: show content managers or sales people table ──
window.showPeopleTable = showPeopleTable;
function showPeopleTable(role){
  activeId = null;
  const isCM = role === 'content_manager';
  const wrap = document.getElementById('content');
  document.getElementById('tbTitle').textContent = isCM ? 'Content Managers' : 'Sales People';
  document.getElementById('coDetailBar').classList.add('hidden');
  const _pb=document.getElementById('platformBubbles'); if(_pb) _pb.style.display='none';
  const _pd=document.getElementById('platformBubbles-date'); if(_pd) _pd.style.display='none';
  ['tbBadge','tbSyncBadge'].forEach(id=>document.getElementById(id).style.display='none');
  document.getElementById('tbActions').style.display = 'flex';
  renderStats([]);

  document.querySelectorAll('.co-btn, .sb-person').forEach(b=>b.classList.remove('active'));
  document.getElementById(isCM ? 'btnNavCM' : 'btnNavSP')?.classList.add('active');

  document.getElementById('btnAddCompanyTop').style.display = 'none';
  document.getElementById('btnAddPersonTop').style.display = '';
  const addBtn = document.getElementById('btnAddPersonTop');
  addBtn.dataset.role = role;
  addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add ' + (isCM ? 'content manager' : 'sales person');
  ['btnAddPost','btnEditCo','btnDelCo','btnPosting'].forEach(id=>{
    const el=document.getElementById(id); if(el) el.style.display='none';
  });

  const people = (db.people||[]).filter(p=>p.role===role);

  if(!people.length){
    wrap.innerHTML = '<div class="state-box">' +
      '<i class="bi bi-' + (isCM?'person-badge':'person-check') + '" style="font-size:40px;color:' + (isCM?'#0891b2':'#16a34a') + '"></i>' +
      '<strong>No ' + (isCM?'content managers':'sales people') + ' yet</strong>' +
      '<p>Click <strong>Add ' + (isCM?'content manager':'sales person') + '</strong> above to get started.</p>' +
      '</div>';
    return;
  }

  const bg = isCM ? '#0891b2' : '#16a34a';

  const tableRows = people.map(function(p){
    const coIds = p.companyIds || (p.companyId ? [p.companyId] : []);
    const linkedCos = coIds.map(function(id){ return db.companies.find(function(c){return c.id===id;}); }).filter(Boolean);
    const coCount = linkedCos.length;

    // Monthly earnings: sum of this person's % share across their companies
    var monthlyEarnings = 0;
    linkedCos.forEach(function(co){
      if(co.fee){
        const pct2 = isCM
          ? (co.feeCM != null ? co.feeCM : 40)
          : (co.feeSP != null ? co.feeSP : 40);
        monthlyEarnings += co.fee * pct2 / 100;
      }
    });

    // Posts per month: sum of monthlyPosts across their companies
    var postsPerMonth = linkedCos.reduce(function(s,co){ return s + (co.monthlyPosts||0); }, 0);

    const coLabel = coCount > 0
      ? '<span style="background:var(--at-purple-light);color:var(--at-purple);border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600">'+coCount+' compan'+(coCount!==1?'ies':'y')+'</span>'
      : '<span style="color:#94a3b8;font-size:12px">None</span>';

    const earningsLabel = monthlyEarnings > 0
      ? '<strong style="color:#15803d">$'+monthlyEarnings.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2})+'</strong>'
      : '<span style="color:#cbd5e1">—</span>';

    const postsLabel = postsPerMonth > 0
      ? '<span style="font-weight:600;color:#0f172a">'+postsPerMonth+'</span><span style="font-size:11px;color:#94a3b8"> /mo</span>'
      : '<span style="color:#cbd5e1">—</span>';

    return '<tr>'+
      '<td>'+
        '<div style="display:flex;align-items:center;gap:10px">'+
          '<div class="av" style="background:'+bg+';width:34px;height:34px;font-size:12px">'+initials(p.name)+'</div>'+
          '<div>'+
            '<div style="font-size:13px;font-weight:600;color:#0f172a">'+esc(p.name)+'</div>'+
            (p.email ? '<div style="font-size:11px;color:#94a3b8">'+esc(p.email)+'</div>' : '')+
          '</div>'+
        '</div>'+
      '</td>'+
      '<td>'+coLabel+'</td>'+
      '<td style="font-size:13px">'+earningsLabel+'</td>'+
      '<td style="font-size:13px">'+postsLabel+'</td>'+
      '<td onclick="event.stopPropagation()" style="white-space:nowrap">'+
        '<button class="btn btn-sm btn-outline-secondary" data-person-edit="'+p.id+'" style="font-size:11px;padding:3px 10px;margin-right:4px"><i class="bi bi-pencil me-1"></i>Edit</button>'+
        '<button class="btn btn-sm btn-outline-danger" data-person-delete="'+p.id+'" data-person-role="'+role+'" style="font-size:11px;padding:3px 8px"><i class="bi bi-trash"></i></button>'+
      '</td>'+
    '</tr>';
  }).join('');

  wrap.innerHTML =
    '<div class="tbl-wrap" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">'+
    '<table style="width:100%;border-collapse:collapse;font-size:13px">'+
    '<thead><tr style="background:#f8fafc">'+
      '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #e2e8f0">'+(isCM?'Content Manager':'Sales Person')+'</th>'+
      '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #e2e8f0">Companies</th>'+
      '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #e2e8f0">Monthly Earnings</th>'+
      '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;border-bottom:1px solid #e2e8f0">Posts / Month</th>'+
      '<th style="padding:9px 14px;border-bottom:1px solid #e2e8f0"></th>'+
    '</tr></thead>'+
    '<tbody>'+tableRows+'</tbody>'+
    '</table></div>';

  wrap.querySelectorAll('[data-person-edit]').forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      window.openEditPerson(btn.dataset.personEdit);
    });
  });
  wrap.querySelectorAll('[data-person-delete]').forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      window.deletePerson(btn.dataset.personDelete, btn.dataset.personRole);
    });
  });
};


function buildMonthCalendar(year, month, companies){
  var today = new Date();
  var monthNames = ['JANUARY','FEBRUARY','MARCH','APRIL','MAY','JUNE','JULY','AUGUST','SEPTEMBER','OCTOBER','NOVEMBER','DECEMBER'];
  var daysInMonth = new Date(year, month+1, 0).getDate();
  var firstDow = new Date(year, month, 1).getDay();
  var prevDays = new Date(year, month, 0).getDate();
  var dows = ['SUN','MON','TUES','WED','THURS','FRI','SAT'];
  var dowName = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

  // Build map: day-of-month -> [co] based on postingDays schedule
  var dayCoMap = {};
  for(var d2=1; d2<=daysInMonth; d2++){
    var dow = new Date(year,month,d2).getDay();
    var dowStr = dowName[dow];
    companies.forEach(function(co){
      var pdays = co.postingDays||[];
      if(pdays.length===0 || pdays.includes(dowStr)){
        if(!dayCoMap[d2]) dayCoMap[d2]=[];
        dayCoMap[d2].push(co);
      }
    });
  }

  var dowHtml = dows.map(function(d){ return '<div class="mcal-dow">'+d+'</div>'; }).join('');

  var cells = '';
  // Prev month fillers
  for(var i=firstDow-1;i>=0;i--){
    cells += '<div class="mcal-cell other-month"><div class="mcal-day-num">'+(prevDays-i)+'</div></div>';
  }

  // Current month days
  for(var d=1;d<=daysInMonth;d++){
    var isToday = today.getFullYear()===year && today.getMonth()===month && today.getDate()===d;
    var dayCos = dayCoMap[d]||[];
    var count = dayCos.length;
    var dateStr = year+'-'+(String(month+1).padStart(2,'0'))+'-'+(String(d).padStart(2,'0'));
    var titleTxt = dayCos.map(function(co){return co.name;}).join(', ');

    var inner = '';
    if(count>0){
      inner = '<button class="mcal-count-btn" '+
        'data-date="'+dateStr+'" data-day="'+d+'" data-month="'+month+'" data-year="'+year+'" '+
        'title="'+count+' compan'+(count!==1?'ies':'y')+' posting — click to view">'+
        count+'</button>';
    }

    cells += '<div class="mcal-cell'+(isToday?' is-today':'')+'">'+
      '<div class="mcal-day-num">'+d+'</div>'+
      inner+'</div>';
  }

  // Next month fillers
  var total = firstDow + daysInMonth;
  var rem = total % 7;
  if(rem>0){
    for(var n=1;n<=7-rem;n++){
      cells += '<div class="mcal-cell other-month"><div class="mcal-day-num">'+n+'</div></div>';
    }
  }

  var prevY=month===0?year-1:year, prevM=month===0?11:month-1;
  var nextY=month===11?year+1:year, nextM=month===11?0:month+1;
  var ty=today.getFullYear(), tm=today.getMonth();

  return '<div class="mcal-wrap">'+
    '<div class="mcal-header">'+
      '<div class="mcal-title">'+monthNames[month]+' '+year+'</div>'+
      '<div class="mcal-nav">'+
        '<button class="mcal-nav-btn" data-cal-y="'+prevY+'" data-cal-m="'+prevM+'"><i class="bi bi-chevron-left"></i></button>'+
        '<button class="mcal-nav-btn mcal-today-btn">Today</button>'+
        '<button class="mcal-nav-btn" data-cal-y="'+nextY+'" data-cal-m="'+nextM+'"><i class="bi bi-chevron-right"></i></button>'+
      '</div>'+
    '</div>'+
    '<div class="mcal-grid">'+dowHtml+cells+'</div>'+
  '</div>';
}

// ── renderCalendar: wrapper that calls buildMonthCalendar ──

window.showDayDetail = function(e, dateStr, day, month, year){
  e.stopPropagation();
  var monthNames=['January','February','March','April','May','June','July','August','September','October','November','December'];
  var dayNames=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  var dow = new Date(year, month, day).getDay();
  var label = dayNames[dow]+', '+monthNames[month]+' '+day+', '+year;
  var dowName=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  var dowStr = dowName[dow];
  var companies = db.companies||[];
  var dayCos = companies.filter(function(co){
    var pdays = co.postingDays||[];
    return pdays.length===0 || pdays.includes(dowStr);
  });
  var rows = dayCos.map(function(co){
    var cm=(db.people||[]).find(function(p){return p.id===co.contentManagerId;});
    var sp=(db.people||[]).find(function(p){return p.id===co.salesPersonId;});
    var meta=[];
    if(cm) meta.push('<i class="bi bi-person-badge" style="color:#0891b2"></i>'+esc(cm.name));
    if(sp) meta.push('<i class="bi bi-person-check" style="color:#16a34a"></i>'+esc(sp.name));
    if(co.monthlyPosts) meta.push('<i class="bi bi-calendar3" style="color:#6c47ff"></i>'+co.monthlyPosts+'/mo');
    return '<div class="ddp-co-row" data-coid="'+co.id+'">'+
      '<div class="ddp-av" style="background:'+co.color+'">'+co.name.slice(0,2).toUpperCase()+'</div>'+
      '<div class="ddp-info">'+
        '<div class="ddp-name">'+esc(co.name)+'</div>'+
        (meta.length?'<div class="ddp-meta">'+meta.join(' &nbsp;·&nbsp; ')+'</div>':'')+
      '</div>'+
      '<i class="bi bi-chevron-right ddp-arrow"></i>'+
    '</div>';
  }).join('');
  var overlay = document.createElement('div');
  overlay.className='day-detail-overlay';
  overlay.id='dayDetailOverlay';
  overlay.innerHTML=
    '<div class="day-detail-panel">'+
      '<div class="ddp-header">'+
        '<div class="ddp-title"><i class="bi bi-calendar-event me-2" style="color:var(--at-purple)"></i>'+label+'</div>'+
        '<button class="ddp-close" onclick="window.closeDayDetail()">&#215;</button>'+
      '</div>'+
      '<div class="ddp-list">'+
        (rows||'<div style="padding:20px;text-align:center;color:#94a3b8;font-size:13px">No companies scheduled</div>')+
      '</div>'+
    '</div>';
  document.body.appendChild(overlay);
  overlay.addEventListener('click',function(ev){if(ev.target===overlay)window.closeDayDetail();});
  overlay.querySelectorAll('.ddp-co-row[data-coid]').forEach(function(row){
    row.addEventListener('click',function(){window.closeDayDetail();selectCo(row.dataset.coid);});
  });
};

window.closeDayDetail = function(){
  var el=document.getElementById('dayDetailOverlay');
  if(el) el.remove();
};

window.renderCalendar = function(year, month){
  const el = document.getElementById('dashCalContainer');
  if(el){
    el.innerHTML = buildMonthCalendar(year, month, db.companies||[]);
    el.querySelectorAll('.mcal-count-btn[data-date]').forEach(function(btn){
      btn.addEventListener('click',function(e){
        showDayDetail(e, btn.dataset.date, parseInt(btn.dataset.day), parseInt(btn.dataset.month), parseInt(btn.dataset.year));
      });
    });
    el.querySelectorAll('.mcal-nav-btn[data-cal-y]').forEach(function(btn){
      btn.addEventListener('click',function(){
        window.renderCalendar(parseInt(btn.dataset.calY), parseInt(btn.dataset.calM));
      });
    });
    var todayBtn = el.querySelector('.mcal-today-btn');
    if(todayBtn) todayBtn.addEventListener('click',function(){
      var n=new Date(); window.renderCalendar(n.getFullYear(),n.getMonth());
    });
  }
};



const TRACKED_PLATFORMS = [
  {key:'blog',      label:'Blog',      icon:'bi-pencil-square', color:'#16a34a'},
  {key:'facebook',  label:'Facebook',  icon:'bi-facebook',      color:'#1d4ed8'},
  {key:'instagram', label:'Instagram', icon:'bi-instagram',     color:'#9d174d'},
  {key:'tiktok',    label:'TikTok',    icon:'bi-tiktok',        color:'#0f172a'},
  {key:'youtube',   label:'YouTube',   icon:'bi-youtube',       color:'#b91c1c'},
  {key:'reddit',    label:'Reddit',    icon:'bi-reddit',        color:'#c2410c'},
  {key:'pinterest', label:'Pinterest', icon:'bi-pin-angle-fill',color:'#9f1239'},
];

function renderPlatformBubbles(co, filterYear, filterMonth){
  const el = document.getElementById('platformBubbles');
  if(!el) return;
  if(!co){ el.style.display='none'; return; }

  var now2 = new Date();
  var fy = (filterYear !== undefined) ? filterYear : null;
  var fm = (filterMonth !== undefined) ? filterMonth : null;
  const posts = (co.posts || []).filter(function(p){
    if(fy === null) return true; // no filter initially — show all
    if(!p.date) return false;
    var d2 = new Date(p.date);
    return d2.getFullYear() === fy && d2.getMonth() === fm;
  });

  // Count published vs total per platform
  function statVal(n){
    return n > 0
      ? n
      : '<span class="plat-stat-val nil">—</span>';
  }

  // Target per platform = monthlyPosts / 7 (rounded)
  var targetPerPlatform = co.monthlyPosts ? Math.round(co.monthlyPosts / TRACKED_PLATFORMS.length) : 0;

  el.innerHTML = TRACKED_PLATFORMS.map(function(plat, idx){
    const all       = posts.filter(function(p){ return normPlatform(p.platform)===plat.key; });
    const total     = all.length;
    const published = all.filter(function(p){ return p.status==='published'; }).length;
    // Scheduled = actual scheduled posts OR target minus published (remaining to do)
    const actualSched = all.filter(function(p){ return p.status==='scheduled'; }).length;
    const scheduled = actualSched > 0 ? actualSched : Math.max(0, targetPerPlatform - published);
    const isFirst   = idx === 0;
    const isLast    = idx === TRACKED_PLATFORMS.length - 1;

    var radius = '10px';
    var borderRight = '1px solid #e2e8f0';

    function v(n, color){
      return n > 0
        ? '<span style="font-weight:700;color:'+color+';font-size:13px">'+n+'</span>'
        : '<span style="color:#cbd5e1;font-size:13px">—</span>';
    }
    function row(label, n, color){
      return '<div style="display:flex;justify-content:space-between;align-items:center;padding:3px 0;border-bottom:1px solid #f8fafc">'+
        '<span style="font-size:11px;color:#94a3b8;white-space:nowrap">'+label+'</span>'+
        v(n, color)+
      '</div>';
    }

    return '<div onclick="openPostForPlatform(\'' + plat.key + '\')" style="background:#fff;border:1px solid #e2e8f0;border-right:'+borderRight+';border-radius:'+radius+';padding:12px 16px;flex-shrink:0;min-width:130px;margin-right:5px;cursor:pointer;transition:box-shadow .15s,border-color .15s" '+
      'onmouseover="this.style.boxShadow=\'0 4px 12px rgba(108,71,255,.15)\';this.style.borderColor=\'#6c47ff\'" '+
      'onmouseout="this.style.boxShadow=\'none\';this.style.borderColor=\'#e2e8f0\'" '+
    '>'+
      '<div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:6px;white-space:nowrap">'+
        '<i class="bi '+plat.icon+'" style="color:'+plat.color+';font-size:14px"></i>'+plat.label+
      '</div>'+
      row('Published', published, '#16a34a')+
      '<div style="display:flex;justify-content:space-between;align-items:center;padding:3px 0">'+
        '<span style="font-size:11px;color:#94a3b8">Scheduled</span>'+
        v(scheduled, '#d97706')+
      '</div>'+
    '</div>';
  }).join('');

  el.style.display = 'flex';
  el.style.marginTop = '10px';
  el.style.marginLeft = '20px';
  var dateEl = document.getElementById('platformBubbles-date');
  if(dateEl){
    var dn = new Date();
    var mn = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    // Build 12-month options centred on today
    var options = '';
    for(var i = -6; i <= 6; i++){
      var d2 = new Date(dn.getFullYear(), dn.getMonth() + i, 1);
      var val = d2.getFullYear()+'-'+d2.getMonth();
      var label = mn[d2.getMonth()]+' '+d2.getFullYear();
      var sel = i === 0 ? ' selected' : '';
      options += '<option value="'+val+'"'+sel+'>'+label+'</option>';
    }
    dateEl.innerHTML =
      '<label style="font-size:12px;font-weight:600;color:#64748b;display:flex;align-items:center;gap:8px">'+
        '<i class="bi bi-calendar3" style="color:var(--at-purple)"></i>'+
        '<select id="platformMonthSel" style="border:1px solid #e2e8f0;border-radius:7px;padding:4px 10px;font-size:13px;font-weight:600;color:#0f172a;background:#fff;cursor:pointer;outline:none">'+
          options+
        '</select>'+
      '</label>';
    dateEl.style.display = 'block';
    document.getElementById('platformMonthSel').addEventListener('change', function(){
      var parts = this.value.split('-');
      renderPlatformBubbles(getCo(), parseInt(parts[0]), parseInt(parts[1]));
    });
  }
}

function renderCoDetails(co){
  const bar=document.getElementById('coDetailBar');
  if(!co){bar.classList.add('hidden');return;}
  const pills=[];
  const cm=db.people?.find(p=>p.id===co.contentManagerId);
  const sp=db.people?.find(p=>p.id===co.salesPersonId);
  if(cm) pills.push(`<span class="cd-pill blue"><i class="bi bi-person-badge"></i>${esc(cm.name)}</span>`);
  if(sp) pills.push(`<span class="cd-pill green"><i class="bi bi-person-check"></i>${esc(sp.name)}</span>`);
  if(co.monthlyPosts) pills.push(`<span class="cd-pill purple"><i class="bi bi-calendar3"></i>${co.monthlyPosts} posts/mo</span>`);
  if(co.fee!=null){
    const feeStr = '$'+Number(co.fee).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2})+'/mo';
    pills.push('<span class="cd-pill amber"><i class="bi bi-currency-dollar"></i>'+feeStr+'</span>');
    const spAmt = (co.fee*(co.feeSP!=null?co.feeSP:40)/100).toFixed(2);
    const cmAmt = (co.fee*(co.feeCM!=null?co.feeCM:40)/100).toFixed(2);
    const smAmt = (co.fee*(co.feeSM!=null?co.feeSM:20)/100).toFixed(2);
    pills.push('<span class="cd-pill green" title="Sales Person share"><i class="bi bi-person-check"></i>SP $'+Number(spAmt).toLocaleString('en',{minimumFractionDigits:2})+'</span>');
    pills.push('<span class="cd-pill blue" title="Content Manager share"><i class="bi bi-person-badge"></i>CM $'+Number(cmAmt).toLocaleString('en',{minimumFractionDigits:2})+'</span>');
    pills.push('<span class="cd-pill purple" title="Searchmonster share"><i class="bi bi-search"></i>SM $'+Number(smAmt).toLocaleString('en',{minimumFractionDigits:2})+'</span>');
  }
  if(co.paymentDate) pills.push(`<span class="cd-pill"><i class="bi bi-calendar-check"></i>Due day ${co.paymentDate}</span>`);
  if(!pills.length){bar.classList.add('hidden');return;}
  bar.classList.remove('hidden');
  bar.innerHTML=pills.join('');
}

function renderMain(){
  const co=getCo();
  const wrap=document.getElementById('content');

  if(!co){
    showAllCompanies();
    return;
  }

  document.getElementById('tbTitle').textContent=co.name;
  document.getElementById('tbActions').style.display='flex';  renderPlatformBubbles(co);
  // Restore stat card labels for company view
  document.querySelector('.sc:nth-child(1) .sc-lbl').textContent = 'Total';
  document.querySelector('.sc.pub .sc-lbl').innerHTML = '<i class="bi bi-check-circle me-1"></i>Published';
  document.querySelector('.sc.sch .sc-lbl').innerHTML = '<i class="bi bi-clock me-1"></i>Scheduled';
  document.querySelector('.sc.rev .sc-lbl').innerHTML = '<i class="bi bi-eye me-1"></i>In review';
  document.querySelector('.sc.ovr .sc-lbl').innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Overdue';

  document.getElementById('btnAddCompanyTop').style.display='none';
  document.getElementById('btnAddPersonTop').style.display='none';
  ['btnAddPost','btnEditCo','btnDelCo'].forEach(id=>{
    const el=document.getElementById(id); if(el) el.style.display='';
  });
  if(co.baseId&&co.tableId){
    document.getElementById('tbBadge').style.display='inline-flex';
    document.getElementById('tbBadgeTxt').textContent=co.tableName||co.tableId;
    if(co.lastSync){
      document.getElementById('tbSyncBadge').style.display='inline-flex';
      document.getElementById('tbSyncTxt').textContent='Synced '+fmtAgo(co.lastSync);
      document.getElementById('tbLast').textContent='';
    }
  }else{
    document.getElementById('tbBadge').style.display='none';
    document.getElementById('tbSyncBadge').style.display='none';
  }
  // Render company detail pills
  renderCoDetails(co);

  const posts=co.posts||[];
  renderStats(posts);

  let filtered=posts.filter(p=>{
    if(filterSt!=='all'&&p.status!==filterSt)return false;
    if(filterPlatform&&normPlatform(p.platform)!==filterPlatform)return false;
    if(searchQ){
      const q=searchQ.toLowerCase();
      return(p.title||'').toLowerCase().includes(q)||(p.assignee||'').toLowerCase().includes(q)||(p.platform||'').toLowerCase().includes(q);
    }
    return true;
  });

  filtered=filtered.slice().sort((a,b)=>{
    let va=(a[sortCol]||'').toLowerCase(), vb=(b[sortCol]||'').toLowerCase();
    return sortAsc?(va<vb?-1:va>vb?1:0):(va>vb?-1:va<vb?1:0);
  });

  const cnt={all:posts.length};
  ['published','scheduled','draft','review','overdue'].forEach(s=>cnt[s]=posts.filter(p=>p.status===s).length);
  const filters=['all','published','scheduled','review','draft','overdue'].filter(f=>f==='all'||cnt[f]>0);

  const filterHTML=filters.map(f=>`<button class="fchip${filterSt===f?' active':''}" onclick="setFilter('${f}')">${f==='all'?'All':statLabel(f)}<span class="fc">${cnt[f]}</span></button>`).join('');

  // Build platform dropdown from unique platforms in this company's posts
  const platforms=[...new Set(posts.map(p=>normPlatform(p.platform)).filter(p=>p&&p!=='other'))].sort();
  const platOptions=`<option value="">All platforms</option>`+
    platforms.map(p=>`<option value="${p}"${filterPlatform===p?' selected':''}>${platLabel(p)}</option>`).join('');
  const platDropdown=platforms.length
    ? `<select class="form-select form-select-sm" style="width:auto;font-size:12px;border-color:#e2e8f0;color:#374151;padding:4px 28px 4px 10px;height:32px" onchange="setPlatformFilter(this.value)">${platOptions}</select>`
    : '';

  const searchHTML=`<div class="search-wrap"><i class="bi bi-search si"></i><input type="text" placeholder="Search…" value="${esc(searchQ)}" oninput="setSearch(this.value)"></div>`;
  const toolbar=`<div class="toolbar">${filterHTML}${platDropdown}${searchHTML}</div>`;

  if(!filtered.length){
    wrap.innerHTML=toolbar+`<div class="tbl-wrap"><div class="state-box"><i class="bi bi-inbox"></i><strong>No posts</strong><p>${searchQ||filterSt!=='all'?'Adjust your filter or search.':'Add your first post.'}</p></div></div>`;
    return;
  }

  const si=col=>sortCol===col?(sortAsc?'<i class="bi bi-arrow-up" style="font-size:10px"></i>':'<i class="bi bi-arrow-down" style="font-size:10px"></i>'):'<i class="bi bi-arrow-down-up" style="font-size:10px;opacity:.4"></i>';

  const rows=filtered.map(p=>{
    const pl=normPlatform(p.platform);
    const av=avBg(p.assignee);
    return`<tr>
      <td><span class="t-title" title="${esc(p.title)}">${esc(p.title)||'—'}</span></td>
      <td>${p.platform?`<span class="plat pl-${pl}">${platLabel(pl)}</span>`:'<span style="color:#94a3b8">—</span>'}</td>
      <td><span class="stat-pill sp-${p.status}">${statLabel(p.status)}</span></td>
      <td style="font-size:12px;color:#64748b;font-family:monospace;white-space:nowrap">${p.date||'—'}</td>
      <td>${p.assignee?`<div class="assn"><div class="av" style="background:${av}">${initials(p.assignee)}</div><span class="av-nm">${esc(p.assignee)}</span></div>`:'—'}</td>
      <td>${p.notes ? `<a href="${esc(p.notes)}" target="_blank" rel="noopener noreferrer" style="color:#6c47ff;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:4px" title="${esc(p.notes)}"><i class="bi bi-box-arrow-up-right" style="font-size:11px"></i><span style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block">${esc(p.notes)}</span></a>` : '<span style="color:#cbd5e1">—</span>'}</td>
      <td><div class="row-acts">
        <button class="rib" title="Edit" onclick="openEditPost('${p.id}')"><i class="bi bi-pencil"></i></button>
        <button class="rib del" title="Delete" onclick="confirmDeletePost('${p.id}')"><i class="bi bi-trash"></i></button>
      </div></td>
    </tr>`;
  }).join('');

  wrap.innerHTML=toolbar+`<div class="tbl-wrap"><table>
    <thead><tr>
      <th onclick="setSort('title')">Title ${si('title')}</th>
      <th>Platform</th>
      <th onclick="setSort('status')">Status ${si('status')}</th>
      <th onclick="setSort('date')">Date ${si('date')}</th>
      <th onclick="setSort('assignee')">Content Manager ${si('assignee')}</th>
      <th>Post URL</th><th></th>
    </tr></thead>
    <tbody>${rows}</tbody>
  </table></div>`;
}

function fmtAgo(ts){
  const s=Math.floor((Date.now()-ts)/1000);
  if(s<60)return'just now';
  if(s<3600)return Math.floor(s/60)+'m ago';
  if(s<86400)return Math.floor(s/3600)+'h ago';
  return Math.floor(s/86400)+'d ago';
}

function setFilter(f){filterSt=f;renderMain();}
function setPlatformFilter(p){filterPlatform=p;renderMain();}
function setSearch(q){searchQ=q;renderMain();}
function setSort(col){if(sortCol===col)sortAsc=!sortAsc;else{sortCol=col;sortAsc=true;}renderMain();}

/* ════════════════════════════════
   SELECT + SYNC COMPANY
════════════════════════════════ */
function selectCo(id){
  activeId=id; filterSt='all'; filterPlatform=''; searchQ=''; sortCol='date'; sortAsc=true;
  document.querySelectorAll('.co-btn, .sb-person').forEach(b=>b.classList.remove('active'));
  renderSidebar(); renderMain();
  const co=getCo();
  if(co&&co.baseId&&co.tableId&&token&&!co.posts?.length){
    syncCo(co);
  }
}

async function syncCo(co){
  co.syncing=true;
  renderSidebar();
  document.getElementById('content').innerHTML=`<div class="state-box"><i class="bi bi-arrow-repeat spin-sm" style="font-size:32px"></i><strong>Syncing from Airtable…</strong><p>Fetching records from "${esc(co.tableName||co.tableId)}"</p></div>`;
  try{
    const records=await fetchAllRecords(co.baseId,co.tableId);
    co.posts=records.map(r=>mapRecord(r,co.mapping||{}));
    co.lastSync=Date.now();
    co.syncing=false;
    save(); renderSidebar(); renderMain();
  }catch(e){
    co.syncing=false;
    document.getElementById('content').innerHTML=`<div class="state-box"><i class="bi bi-exclamation-triangle text-danger" style="font-size:32px"></i><strong>Sync failed</strong><p>${esc(e.message)}</p><button class="btn btn-sm btn-outline-secondary mt-3" onclick="syncCo(getCo())"><i class="bi bi-arrow-repeat me-1"></i>Retry</button></div>`;
    renderSidebar();
  }
}



/* ════════════════════════════════
   COMPANY MANAGEMENT
════════════════════════════════ */
function buildColorDots(containerId){
  const el=document.getElementById(containerId);
  el.innerHTML=COLORS.map((c,i)=>
    `<div class="color-dot${i===0?' chosen':''}" data-color="${c}" style="background:${c}" onclick="pickColor('${c}',this,'${containerId}')"></div>`
  ).join('');
  _selectedColor=COLORS[0];
}

window.pickColor=function(c,el,containerId){
  document.querySelectorAll(`#${containerId} .color-dot`).forEach(d=>d.classList.remove('chosen'));
  el.classList.add('chosen');
  _selectedColor=c;
};

const DAYS_OF_WEEK = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

function renderPeople(){ /* no-op: people shown in main table view */ }

function populatePersonCompanyChecks(selectedIds){
  const wrap = document.getElementById('personCompanyChecks');
  if(!wrap) return;
  const companies = db.companies || [];
  if(!companies.length){
    wrap.innerHTML = '<span style="font-size:12px;color:#94a3b8">No companies added yet</span>';
    return;
  }
  const ids = selectedIds || [];
  wrap.innerHTML = companies.map(function(co){
    const checked = ids.includes(co.id) ? 'checked' : '';
    return '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#0f172a">'+
      '<input type="checkbox" value="'+co.id+'" '+checked+' class="person-co-check" style="width:15px;height:15px;accent-color:'+co.color+'">'+
      '<span style="width:10px;height:10px;border-radius:3px;background:'+co.color+';display:inline-block;flex-shrink:0"></span>'+
      esc(co.name)+
    '</label>';
  }).join('');
}

function buildPostingDays(selectedDays){
  var wrap = document.getElementById('postingDaysWrap');
  if(!wrap) return;
  var sel = selectedDays || [];
  wrap.innerHTML = DAYS_OF_WEEK.map(function(day){
    var checked = sel.includes(day) ? 'checked' : '';
    return '<label class="day-toggle">'+
      '<input type="checkbox" name="postingDay" value="'+day+'" '+checked+'>'+
      '<span class="day-label">'+day.slice(0,1)+'</span>'+
      '<span class="day-name">'+day+'</span>'+
    '</label>';
  }).join('');
}

// ── Per-platform social logins (companies.platform_config) ──
// One row per platform in the Add/Edit Company modal: a dropdown of the
// saved social logins for that platform plus a show/hide details panel
// (login, channel, posting software). Posting days are company-wide
// (postingDaysWrap), not per platform. Saved to the DB as:
// {"facebook":{"social_login_id":"sl_x"}, ...}
var _slCache = {};
function buildPlatformConfig(cfg){
  var wrap = document.getElementById('platformConfigWrap');
  if(!wrap) return;
  cfg = (cfg && typeof cfg === 'object') ? cfg : {};
  wrap.innerHTML = POSTING_PLATFORMS.map(function(pl){
    var pc = cfg[pl.key] || {};
    var curLogin = pc.social_login_id || '';
    return '<div class="pcfg-row" data-platform="'+pl.key+'" style="border-bottom:1px solid #f1f5f9">'+
      '<div style="display:flex;align-items:center;gap:12px;padding:8px 12px">'+
        '<div style="width:105px;flex-shrink:0;font-size:12px;font-weight:600;color:#0f172a"><i class="bi '+pl.icon+' me-1" style="color:'+pl.color+'"></i>'+pl.label+'</div>'+
        // The current login id is embedded as a selected option right away, so
        // the selection survives even if the social_logins request fails.
        '<select class="form-select form-select-sm pcfg-login" style="font-size:12px;flex:1">'+
          '<option value="">— No login linked —</option>'+
          (curLogin ? '<option value="'+esc(curLogin)+'" selected>(current login)</option>' : '')+
          '<option value="__new__">＋ Add new login…</option>'+
        '</select>'+
        '<button type="button" class="btn btn-sm btn-outline-secondary pcfg-toggle" style="font-size:11px;padding:2px 10px;display:none">Details <i class="bi bi-chevron-down"></i></button>'+
      '</div>'+
      '<div class="pcfg-details" style="display:none;padding:10px 16px 12px;font-size:12px;color:#475467;background:#f8fafc"></div>'+
    '</div>';
  }).join('');

  function renderRowDetails(row){
    var sel = row.querySelector('.pcfg-login');
    var btn = row.querySelector('.pcfg-toggle');
    var box = row.querySelector('.pcfg-details');
    var sl  = _slCache[sel.value];
    btn.style.display = sel.value ? '' : 'none';
    if(!sel.value){ box.style.display = 'none'; return; }
    if(sel.value === '__new__'){
      // Build the inline form only once so typed values survive re-renders
      if(!box.querySelector('.pcfg-new')){
        box.innerHTML =
          '<div class="pcfg-new"><div class="row g-2">'+
            '<div class="col-md-6"><input class="form-control form-control-sm pcfg-nl-title" placeholder="Title (e.g. AimBlue Facebook)"></div>'+
            '<div class="col-md-6"><input class="form-control form-control-sm pcfg-nl-user" placeholder="Login / username" autocomplete="off"></div>'+
            '<div class="col-md-6"><input type="password" class="form-control form-control-sm pcfg-nl-pass" placeholder="Password (stored encrypted)" autocomplete="new-password"></div>'+
            '<div class="col-md-6"><input class="form-control form-control-sm pcfg-nl-url" placeholder="Channel URL"></div>'+
            '<div class="col-md-12"><input class="form-control form-control-sm pcfg-nl-notes" placeholder="Posting software (e.g. Buffer, Hootsuite)"></div>'+
          '</div>'+
          '<div style="font-size:10px;color:#94a3b8;margin-top:5px">Saved to the Social Logins vault and linked to this company when you save.</div></div>';
      }
      return;
    }
    if(sl){
      // One field per line: fixed label column, wrapping value column —
      // long URLs and emails can never overlap neighbouring fields.
      var fld = function(label, valueHtml){
        return '<div style="display:flex;gap:12px;align-items:baseline;padding:3px 0">'+
          '<div style="width:130px;flex-shrink:0;font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:.5px;text-transform:uppercase">'+label+'</div>'+
          '<div style="flex:1;min-width:0;overflow-wrap:anywhere;word-break:break-word">'+valueHtml+'</div>'+
        '</div>';
      };
      // Any URL-ish value renders as a truncated link opening in a new tab
      var linkify = function(raw){
        if(!raw) return '—';
        var t = String(raw).trim();
        if(!/^(https?:\/\/|www\.)/i.test(t)) return esc(t);
        var href = /^https?:/i.test(t) ? t : 'https://'+t;
        var disp = t.replace(/^https?:\/\/(www\.)?/i,'');
        if(disp.length > 60) disp = disp.slice(0,60)+'…';
        return '<a href="'+esc(href)+'" target="_blank" rel="noopener" title="'+esc(t)+'">'+esc(disp)+'</a>';
      };
      box.innerHTML =
        fld('Login',            sl.username ? esc(sl.username) : '—') +
        fld('Channel',          linkify(sl.channel_url)) +
        fld('Password',         sl.has_password
          ? '<span class="pcfg-pw" style="font-family:monospace">••••••••</span> <a href="javascript:void(0)" class="pcfg-pw-btn" style="font-size:11px;margin-left:8px">Show</a>'
          : '—') +
        fld('Posting software', linkify(sl.notes));
      var pwBtn = box.querySelector('.pcfg-pw-btn');
      if(pwBtn) pwBtn.onclick = async function(){
        var span = box.querySelector('.pcfg-pw');
        if(this.dataset.shown === '1'){
          span.textContent = '••••••••'; this.dataset.shown = '0'; this.textContent = 'Show'; return;
        }
        this.textContent = '…';
        try {
          var d = await apiCall('reveal_password', {id: sl.id});
          span.textContent = d.password || '(empty)';
          this.dataset.shown = '1'; this.textContent = 'Hide';
        } catch(err){
          span.textContent = 'could not reveal: ' + (err.message || err);
          this.textContent = 'Show';
        }
      };
    } else {
      box.innerHTML = '<span style="color:#94a3b8">Login details unavailable.</span>';
    }
  }

  wrap.querySelectorAll('.pcfg-row').forEach(function(row){
    row.querySelector('.pcfg-login').onchange = function(){
      renderRowDetails(row);
      var box = row.querySelector('.pcfg-details');
      if(this.value && box.style.display === 'none') toggleRow(row); // reveal on pick
    };
    row.querySelector('.pcfg-toggle').onclick = function(){ toggleRow(row); };
    renderRowDetails(row);
  });
  function toggleRow(row){
    var box = row.querySelector('.pcfg-details');
    var btn = row.querySelector('.pcfg-toggle');
    var open = box.style.display === 'none';
    box.style.display = open ? '' : 'none';
    btn.innerHTML = open ? 'Hide <i class="bi bi-chevron-up"></i>' : 'Details <i class="bi bi-chevron-down"></i>';
  }

  apiCall('social_logins').then(function(logins){
    _slCache = {};
    (logins||[]).forEach(function(sl){ _slCache[sl.id] = sl; });
    (logins||[]).forEach(function(sl){
      var row = wrap.querySelector('.pcfg-row[data-platform="'+sl.platform+'"]');
      var sel = row && row.querySelector('.pcfg-login');
      if(!sel) return;
      var cur = sel.value;
      var placeholder = sel.querySelector('option[value="'+CSS.escape(sl.id)+'"]');
      if(placeholder) placeholder.remove();
      var opt = document.createElement('option');
      opt.value = sl.id;
      opt.textContent = sl.title + (sl.username ? ' ('+sl.username+')' : '');
      if(sl.id === cur) opt.selected = true;
      sel.insertBefore(opt, sel.querySelector('option[value="__new__"]'));
      if(cur && sel.value !== cur) sel.value = cur;
    });
    wrap.querySelectorAll('.pcfg-row').forEach(renderRowDetails);
  }).catch(function(e){ console.error('Could not load social logins for platform config:', e); });
}

function getPlatformConfig(){
  var wrap = document.getElementById('platformConfigWrap');
  if(!wrap || !wrap.querySelector('.pcfg-row')){
    // UI not built (shouldn't happen) — preserve what the company already
    // has instead of wiping platform_config on save.
    var co = _editCoId ? db.companies.find(function(c){ return c.id===_editCoId; }) : null;
    return (co && co.platform_config && typeof co.platform_config === 'object') ? co.platform_config : {};
  }
  var cfg = {};
  wrap.querySelectorAll('.pcfg-row').forEach(function(row){
    var sel = row.querySelector('.pcfg-login');
    if(!sel || !sel.value) return;
    if(sel.value === '__new__'){
      var v = function(cls){ var el = row.querySelector(cls); return el ? el.value.trim() : ''; };
      var nl = {
        title:       v('.pcfg-nl-title'),
        username:    v('.pcfg-nl-user'),
        password:    (row.querySelector('.pcfg-nl-pass')||{}).value || '',
        channel_url: v('.pcfg-nl-url'),
        notes:       v('.pcfg-nl-notes')
      };
      var allEmpty = !nl.title && !nl.username && !nl.password && !nl.channel_url && !nl.notes;
      if(allEmpty) return; // picked "Add new" but typed nothing — treat as no login
      if(!nl.username && !nl.channel_url){
        var pl = POSTING_PLATFORMS.find(function(p){ return p.key===row.dataset.platform; });
        throw new Error('New '+(pl?pl.label:row.dataset.platform)+' login needs at least a username or a channel URL.');
      }
      cfg[row.dataset.platform] = { new_login: nl };
    } else {
      cfg[row.dataset.platform] = { social_login_id: sel.value };
    }
  });
  return cfg;
}

function updateFeeDistribution(){
  var fee = parseFloat(document.getElementById('coFee')?.value)||0;
  var sp  = parseFloat(document.getElementById('coFeeSP')?.value)||0;
  var cm  = parseFloat(document.getElementById('coFeeCM')?.value)||0;
  var sm  = parseFloat(document.getElementById('coFeeSM')?.value)||0;
  var total = sp+cm+sm;

  function fmt(pct){ return fee ? '$'+(fee*pct/100).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}) : ''; }

  var spAmt=document.getElementById('coFeeSPAmt'), cmAmt=document.getElementById('coFeeCMAmt'), smAmt=document.getElementById('coFeeSMAmt');
  var totEl=document.getElementById('coFeeTotal'), totAmt=document.getElementById('coFeeTotalAmt');
  if(spAmt) spAmt.textContent=fmt(sp);
  if(cmAmt) cmAmt.textContent=fmt(cm);
  if(smAmt) smAmt.textContent=fmt(sm);
  if(totEl){
    totEl.textContent=total+'%';
    totEl.style.color = total===100 ? '#16a34a' : '#dc2626';
  }
  if(totAmt) totAmt.textContent=fee ? '$'+fee.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}) : '';
}

function openAddPerson(role){
  _personRole = role || 'content_manager';
  const isCM = _personRole === 'content_manager';
  document.getElementById('mPersonTitle').innerHTML = isCM
    ? '<i class="bi bi-person-badge me-2" style="color:#0891b2"></i>Add content manager'
    : '<i class="bi bi-person-check me-2" style="color:#16a34a"></i>Add sales person';
  document.getElementById('personName').value = '';
  document.getElementById('personEmail').value = '';
  document.getElementById('personNotes').value = '';
  document.getElementById('personErr').classList.add('d-none');
  populatePersonCompanyChecks([]);
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mAddPerson')).show();
}

function openAddCompanyModal(){
  _editCoId=null;
  document.getElementById('mAddCoTitle').innerHTML='<i class="bi bi-building me-2"></i>Add company';
  document.getElementById('btnSaveCo').innerHTML='<i class="bi bi-check-lg me-1"></i>Save company';
  document.getElementById('coName').value='';
  document.getElementById('addCoErr').classList.add('d-none');
  buildColorDots('colorDots');
  _availableFields=[];
  // Always use fresh people from API to ensure MySQL IDs
  apiCall('people').then(function(fresh){
    if(fresh && fresh.length) { db.people = fresh; save(); }
    var cms = (db.people||[]).filter(function(p){ return p.role==='content_manager'; });
    var sps = (db.people||[]).filter(function(p){ return p.role==='sales_person'; });
    var cmSel = document.getElementById('coContentManager');
    var spSel = document.getElementById('coSalesPerson');
    if(!cmSel || !spSel) return;
    cmSel.innerHTML = '<option value="">— Select —</option>' + cms.map(function(p){ return '<option value="'+p.id+'">'+esc(p.name)+'</option>'; }).join('');
    spSel.innerHTML = '<option value="">— Select —</option>' + sps.map(function(p){ return '<option value="'+p.id+'">'+esc(p.name)+'</option>'; }).join('');
    if(!cms.length) cmSel.innerHTML += '<option value="" disabled style="color:#94a3b8">No content managers yet</option>';
    if(!sps.length) spSel.innerHTML += '<option value="" disabled style="color:#94a3b8">No sales people yet</option>';
  }).catch(function(){
    // fallback to cached
    var cms = (db.people||[]).filter(function(p){ return p.role==='content_manager'; });
    var sps = (db.people||[]).filter(function(p){ return p.role==='sales_person'; });
    var cmSel = document.getElementById('coContentManager');
    var spSel = document.getElementById('coSalesPerson');
    if(!cmSel||!spSel) return;
    cmSel.innerHTML = '<option value="">— Select —</option>' + cms.map(function(p){ return '<option value="'+p.id+'">'+esc(p.name)+'</option>'; }).join('');
    spSel.innerHTML = '<option value="">— Select —</option>' + sps.map(function(p){ return '<option value="'+p.id+'">'+esc(p.name)+'</option>'; }).join('');
  });
  document.getElementById('coMonthlyPosts').value='';
  document.getElementById('coFee').value='';
  document.getElementById('coPaymentDate').value='';
  document.getElementById('coFeeSP').value='40';
  document.getElementById('coFeeCM').value='40';
  document.getElementById('coFeeSM').value='20';
  updateFeeDistribution();
  buildPostingDays([]);
  buildPlatformConfig({});
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mAddCo')).show();
}

document.getElementById('btnSaveCo').onclick=async function(){
  const name = document.getElementById('coName').value.trim();
  if(!name){ showAddCoErr('Company name is required.'); return; }

  const btn = document.getElementById('btnSaveCo');
  btn.disabled = true;
  document.getElementById('addCoErr').classList.add('d-none');

  // Everything lives inside the try — an error while COLLECTING the form
  // (not just while saving) must surface in the modal, not vanish.
  try {
    const body = {
      name:               name,
      color:              _selectedColor,
      content_manager_id: document.getElementById('coContentManager').value || null,
      sales_person_id:    document.getElementById('coSalesPerson').value    || null,
      monthly_posts:      document.getElementById('coMonthlyPosts').value   ? parseInt(document.getElementById('coMonthlyPosts').value)   : null,
      fee:                document.getElementById('coFee').value            ? parseFloat(document.getElementById('coFee').value)         : null,
      payment_date:       document.getElementById('coPaymentDate').value    ? parseInt(document.getElementById('coPaymentDate').value)    : null,
      fee_sp_pct:         parseFloat(document.getElementById('coFeeSP').value) || 40,
      fee_cm_pct:         parseFloat(document.getElementById('coFeeCM').value) || 40,
      fee_sm_pct:         parseFloat(document.getElementById('coFeeSM').value) || 20,
      posting_days:       getPostingDays(),
      platform_config:    getPlatformConfig(),
    };

    const coId = _editCoId || uid();
    const saved = await apiCall('save_company', { method:'POST', body: Object.assign({id: coId}, body) });

    // Update local db (map API names → JS names for UI compatibility)
    const local = {
      id:               coId,
      name:             body.name,
      color:            body.color,
      contentManagerId: body.content_manager_id,
      salesPersonId:    body.sales_person_id,
      content_manager_id: body.content_manager_id,
      sales_person_id:  body.sales_person_id,
      monthlyPosts:     body.monthly_posts,
      monthly_posts:    body.monthly_posts,
      fee:              body.fee,
      paymentDate:      body.payment_date,
      payment_date:     body.payment_date,
      feeSP:            body.fee_sp_pct,
      feeCM:            body.fee_cm_pct,
      feeSM:            body.fee_sm_pct,
      fee_sp_pct:       body.fee_sp_pct,
      fee_cm_pct:       body.fee_cm_pct,
      fee_sm_pct:       body.fee_sm_pct,
      postingDays:      body.posting_days,
      posting_days:     body.posting_days,
      // The server returns the final config (new logins created there get
      // their real ids; password blobs are stripped) — mirror that, never
      // the raw form data.
      platform_config:  (saved && saved.platform_config) || {},
    };

    if (_editCoId) {
      var idx2 = db.companies.findIndex(function(x){ return x.id === _editCoId; });
      if (idx2 >= 0) db.companies[idx2] = Object.assign({}, db.companies[idx2], local);
      save();
      bootstrap.Modal.getInstance('#mAddCo').hide();
      renderSidebar();
      if (activeId){ renderMain(); renderCoDetails(getCo()); } else { showAllCompanies(); }
    } else {
      local.posts = [];
      db.companies.push(local);
      save();
      bootstrap.Modal.getInstance('#mAddCo').hide();
      renderSidebar();
      selectCo(coId);
    }
  } catch(e) {
    showAddCoErr(e.message);
  } finally {
    btn.disabled = false;
  }
};



function showAddCoErr(msg){
  const el=document.getElementById('addCoErr');
  el.textContent=msg; el.classList.remove('d-none');
}

document.getElementById('btnEditCo').onclick=()=>{
  if(activeId) openEditCompanyModal(activeId);
};

document.getElementById('btnDelCo').onclick=()=>{
  const co=getCo();
  if(!co)return;
  document.getElementById('confirmMsg').textContent=`Remove "${co.name}" from this dashboard?`;
  confirmCb=()=>{db.companies=db.companies.filter(c=>c.id!==co.id);activeId=null;save();renderSidebar();renderMain();};
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mConfirm')).show();
};

document.getElementById('btnConfirmYes').onclick=()=>{
  bootstrap.Modal.getInstance('#mConfirm').hide();
  if(confirmCb){confirmCb();confirmCb=null;}
};

/* ════════════════════════════════
   POST MANAGEMENT
════════════════════════════════ */
document.getElementById('btnAddPost').onclick=()=>{
  editPostId=null;
  document.getElementById('mPostTitle').innerHTML='<i class="bi bi-plus-lg me-2"></i>Add post';
  ['pTitle','pNotes'].forEach(id=>document.getElementById(id).value='');
  // Auto-select the CM assigned to this company
  const _co = getCo();
  const _cm = _co && _co.contentManagerId
    ? (db.people||[]).find(p=>p.id===_co.contentManagerId)
    : null;
  populateCMDropdown(_cm ? _cm.name : null);
  document.getElementById('pPlatform').value='';
  document.getElementById('pStatus').value='scheduled';
  document.getElementById('pDate').value='';
  document.getElementById('pErrMsg').classList.add('d-none');
  initPostSocialInfo();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mPost')).show();
};

window.openEditPost=function(postId){
  const co=getCo();
  const post=(co?.posts||[]).find(p=>p.id===postId);
  if(!post)return;
  editPostId=postId;
  document.getElementById('mPostTitle').innerHTML='<i class="bi bi-pencil me-2"></i>Edit post';
  document.getElementById('pTitle').value=post.title||'';
  document.getElementById('pPlatform').value=post.platform||'';
  document.getElementById('pStatus').value=post.status||'draft';
  document.getElementById('pDate').value=post.date||'';
  populateCMDropdown(post.assignee||'');
  document.getElementById('pNotes').value=post.notes||'';
  document.getElementById('pErrMsg').classList.add('d-none');
  initPostSocialInfo();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mPost')).show();
};

document.getElementById('btnSavePost').onclick=async()=>{
  const title=document.getElementById('pTitle').value.trim();
  if(!title){document.getElementById('pErrMsg').textContent='Title is required.';document.getElementById('pErrMsg').classList.remove('d-none');return;}
  const co=getCo(); if(!co)return;
  const post={
    id:editPostId||uid(),
    title,
    platform:document.getElementById('pPlatform').value,
    status:document.getElementById('pStatus').value,
    date:document.getElementById('pDate').value,
    assignee:document.getElementById('pAssignee').value.trim(),
    notes:document.getElementById('pNotes').value.trim(),
  };

  const btn=document.getElementById('btnSavePost');
  btn.disabled=true;
  document.getElementById('pErrMsg').classList.add('d-none');

  if(editPostId){
    co.posts=co.posts.map(p=>p.id===editPostId?post:p);
  }else{
    co.posts.push(post);
  }
  save(); btn.disabled=false;
  bootstrap.Modal.getInstance('#mPost').hide();
  renderSidebar(); renderMain();
};

window.confirmDeletePost=function(postId){
  document.getElementById('confirmMsg').textContent='Delete this post? This cannot be undone.';
  confirmCb=async()=>{
    const co=getCo(); if(!co)return;
    const post=co.posts.find(p=>p.id===postId);
    co.posts=co.posts.filter(p=>p.id!==postId);
    save(); renderSidebar(); renderMain();
  };
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mConfirm')).show();
};

/* ════════════════════════════════
   SETTINGS
════════════════════════════════ */
document.getElementById('btnSettings').onclick=()=>{ window.open('config.php','_blank'); };

/* ════════════════════════════════
   SIDEBAR TOGGLE
════════════════════════════════ */
document.getElementById('btnToggle').onclick=()=>{
  document.getElementById('sidebar').classList.toggle('collapsed');
};

document.getElementById('btnSavePerson').onclick = () => {
  const name = document.getElementById('personName').value.trim();
  if (!name){ document.getElementById('personErr').textContent='Name is required.'; document.getElementById('personErr').classList.remove('d-none'); return; }
  if (!db.people) db.people = [];
  const personData = {
    role: _personRole,
    name,
    email: document.getElementById('personEmail').value.trim(),
    companyIds: getPersonCompanyIds(),
    notes: document.getElementById('personNotes').value.trim(),
  };
  if(window._editPersonId){
    const idx = db.people.findIndex(p=>p.id===window._editPersonId);
    if(idx>=0) db.people[idx] = {...db.people[idx], ...personData};
    window._editPersonId = null;
  } else {
    db.people.push({ id: uid(), ...personData });
  }
  save();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mAddPerson')).hide();
  renderPeople();
  populateCMDropdown(null);
  showPeopleTable(_personRole);
};

function getFieldMapping(){
  const keys=['title','platform','status','date','assignee','notes'];
  const m={};
  keys.forEach(k=>{
    const el=document.getElementById('fm_'+k);
    if(el)m[k]=el.value;
  });
  return m;
}

function getPostingDays(){
  return Array.from(document.querySelectorAll('input[name="postingDay"]:checked')).map(function(el){ return el.value; });
}

function getPersonCompanyIds(){
  return Array.from(document.querySelectorAll('.person-co-check:checked')).map(function(el){ return el.value; });
}

function populateCMDropdown(selected){
  const sel = document.getElementById('pAssignee');
  if (!sel) return;
  const co = getCo();
  // Only show the content manager linked to this company
  const allCMs = (db.people||[]).filter(p=>p.role==='content_manager');
  const cms = co && co.contentManagerId
    ? allCMs.filter(p=>p.id===co.contentManagerId)
    : allCMs;
  sel.innerHTML = '<option value="">— Select —</option>' +
    cms.map(p=>`<option value="${esc(p.name)}"${selected===p.name?' selected':''}>${esc(p.name)}</option>`).join('');
  if (!cms.length) sel.innerHTML += '<option value="" disabled style="color:#94a3b8">No content manager assigned to this company</option>';
  if (selected && !cms.find(p=>p.name===selected)){
    sel.innerHTML += `<option value="${esc(selected)}" selected>${esc(selected)}</option>`;
  }
}

window.deletePerson = function(id, role){
  db.people = (db.people||[]).filter(p=>p.id!==id);
  save();
  renderPeople();
  if(role) showPeopleTable(role);
};


// ══════════ SOCIAL LOGIN PANEL IN THE POST MODAL ══════════
// Shows the linked login for the active company + selected platform.
// Runs on every modal open and whenever the Platform dropdown changes.
var _currentSocialLoginId = null;

function linkifyUrl(raw){
  if(!raw) return '—';
  var t = String(raw).trim();
  if(!/^(https?:\/\/|www\.)/i.test(t)) return esc(t);
  var href = /^https?:/i.test(t) ? t : 'https://'+t;
  var disp = t.replace(/^https?:\/\/(www\.)?/i,'');
  if(disp.length > 60) disp = disp.slice(0,60)+'…';
  return '<a href="'+esc(href)+'" target="_blank" rel="noopener" title="'+esc(t)+'">'+
    '<i class="bi bi-box-arrow-up-right me-1" style="font-size:11px"></i>'+esc(disp)+'</a>';
}

async function updatePostSocialInfo(){
  var panel = document.getElementById('pSocialInfo');
  if(!panel) return;
  // reset password + collapse state
  document.getElementById('pSocialPw').textContent = '••••••••';
  var rev = document.getElementById('pSocialReveal');
  rev.innerHTML = '<i class="bi bi-eye"></i>'; rev.dataset.shown = '0';
  _currentSocialLoginId = null;

  var co = getCo();
  var platKey = normPlatform(document.getElementById('pPlatform').value || '');
  if(!co || !platKey || platKey === 'other'){ panel.style.display = 'none'; return; }

  try {
    var logins = await apiCall('social_logins');
    var match = null;
    // The explicit link from the Edit Company popup wins…
    var cfg = co.platform_config && co.platform_config[platKey];
    if (cfg && cfg.social_login_id) {
      match = logins.find(function(l){ return l.id === cfg.social_login_id; });
    }
    // …else any login of that platform linked to this company in the vault
    if (!match) {
      match = logins.find(function(l){
        return l.platform === platKey && (l.company_ids||[]).indexOf(co.id) > -1;
      });
    }
    if (!match){ panel.style.display = 'none'; return; }
    _currentSocialLoginId = match.id;
    document.getElementById('pSocialUrl').innerHTML  = linkifyUrl(match.channel_url);
    document.getElementById('pSocialUser').textContent = match.username || '—';
    document.getElementById('pSocialSoftware').innerHTML = linkifyUrl(match.notes);
    panel.style.display = '';
  } catch(e) {
    console.error('Post modal: could not load social login info:', e);
    panel.style.display = 'none';
  }
}

document.getElementById('pPlatform').addEventListener('change', updatePostSocialInfo);

document.getElementById('pSocialToggle').addEventListener('click', function(){
  var body = document.getElementById('pSocialBody');
  var open = body.style.display === 'none';
  body.style.display = open ? '' : 'none';
  this.innerHTML = open ? 'Hide <i class="bi bi-chevron-up"></i>' : 'Show <i class="bi bi-chevron-down"></i>';
});

// Collapse the panel and refresh it — called on every modal open
function initPostSocialInfo(){
  document.getElementById('pSocialBody').style.display = 'none';
  document.getElementById('pSocialToggle').innerHTML = 'Show <i class="bi bi-chevron-down"></i>';
  updatePostSocialInfo();
}

// ══════════ OPEN POST MODAL FROM PLATFORM CARD ══════════
window.openPostForPlatform = async function(platKey) {
  const co = getCo();
  if (!co) return;

  // Reset modal
  document.getElementById('mPostTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Add post';
  document.getElementById('pTitle').value = '';
  document.getElementById('pStatus').value = 'scheduled';
  document.getElementById('pNotes').value = '';
  document.getElementById('pErrMsg').classList.add('d-none');
  editPostId = null;

  // Pre-select platform
  const platLabel = {blog:'Blog',facebook:'Facebook',instagram:'Instagram',tiktok:'TikTok',youtube:'YouTube',reddit:'Reddit',pinterest:'Pinterest'};
  const pSel = document.getElementById('pPlatform');
  if (pSel) {
    for (var i = 0; i < pSel.options.length; i++) {
      if (pSel.options[i].text.toLowerCase() === platKey.toLowerCase() ||
          pSel.options[i].value.toLowerCase() === platKey.toLowerCase()) {
        pSel.selectedIndex = i; break;
      }
    }
  }

  // Pre-fill date from month selector (use 1st of selected month, or today if current month)
  var selEl = document.getElementById('platformMonthSel');
  if (selEl && selEl.value) {
    var parts = selEl.value.split('-');
    var selYear = parseInt(parts[0]), selMonth = parseInt(parts[1]);
    var today = new Date();
    var useDay = (selYear === today.getFullYear() && selMonth === today.getMonth())
      ? today.getDate()
      : 1;
    var dd = String(useDay).padStart(2,'0');
    var mm = String(selMonth + 1).padStart(2,'0');
    document.getElementById('pDate').value = selYear + '-' + mm + '-' + dd;
  } else {
    var t = new Date();
    document.getElementById('pDate').value = t.getFullYear()+'-'+String(t.getMonth()+1).padStart(2,'0')+'-'+String(t.getDate()).padStart(2,'0');
  }

  // Pre-fill content manager
  populateCMDropdown(null);
  const cm = (db.people || []).find(function(p){ return p.id === co.contentManagerId && p.role === 'content_manager'; });
  if (cm) populateCMDropdown(cm.name);

  initPostSocialInfo();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mPost')).show();
};

// Reveal password inside post modal
document.getElementById('pSocialReveal').addEventListener('click', async function(){
  if (!_currentSocialLoginId) return;
  if (this.dataset.shown === '1') {
    document.getElementById('pSocialPw').textContent = '••••••••';
    this.innerHTML = '<i class="bi bi-eye"></i>';
    this.dataset.shown = '0';
    return;
  }
  this.disabled = true;
  try {
    const res = await apiCall('reveal_password', {id: _currentSocialLoginId});
    document.getElementById('pSocialPw').textContent = res.password || '(empty)';
    this.innerHTML = '<i class="bi bi-eye-slash"></i>';
    this.dataset.shown = '1';
  } catch(e) { alert(e.message); }
  finally { this.disabled = false; }
});


// ══════════ COMPANY POSTING CHANNELS MODAL ══════════
var _postingCoId   = null;
var _postingPlatKey = null;
var _allSocialLogins = [];

var POSTING_PLATFORMS = [
  {key:'blog',      label:'Blog',      icon:'bi-pencil-square',  color:'#16a34a'},
  {key:'facebook',  label:'Facebook',  icon:'bi-facebook',       color:'#1d4ed8'},
  {key:'instagram', label:'Instagram', icon:'bi-instagram',      color:'#9d174d'},
  {key:'youtube',   label:'YouTube',   icon:'bi-youtube',        color:'#b91c1c'},
  {key:'tiktok',    label:'TikTok',    icon:'bi-tiktok',         color:'#0f172a'},
  {key:'reddit',    label:'Reddit',    icon:'bi-reddit',         color:'#c2410c'},
  {key:'pinterest', label:'Pinterest', icon:'bi-pin-angle-fill', color:'#9f1239'},
];

window.openPostingModal = async function(coId) {
  if (!coId) return;
  _postingCoId = coId;
  var co = db.companies.find(function(x){ return x.id === coId; });
  document.getElementById('mPostingTitle').innerHTML =
    '<i class="bi bi-share me-2" style="color:#6c47ff"></i>Posting Channels' +
    (co ? ' — <span style="color:#6c47ff">' + esc(co.name) + '</span>' : '');
  document.getElementById('postingAddForm').style.display = 'none';

  // Load all social logins
  try {
    _allSocialLogins = await apiCall('social_logins');
  } catch(e) { _allSocialLogins = []; }

  // Build platform tabs
  var tabs = document.getElementById('postingPlatformTabs');
  tabs.innerHTML = POSTING_PLATFORMS.map(function(p) {
    var count = _allSocialLogins.filter(function(l){
      return l.platform === p.key && l.company_ids && l.company_ids.indexOf(coId) > -1;
    }).length;
    return '<button class="posting-tab" data-platkey="' + p.key + '" style="' +
      'background:none;border:none;border-bottom:3px solid transparent;padding:12px 16px;' +
      'font-size:13px;font-weight:600;color:#64748b;cursor:pointer;white-space:nowrap;' +
      'display:flex;align-items:center;gap:7px;transition:color .15s">' +
      '<i class="bi ' + p.icon + '" style="color:' + p.color + ';font-size:15px"></i>' + p.label +
      (count ? '<span style="background:#6c47ff;color:#fff;border-radius:20px;font-size:10px;padding:1px 7px;font-weight:700">' + count + '</span>' : '') +
    '</button>';
  }).join('');

  tabs.querySelectorAll('.posting-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      selectPostingPlatform(tab.dataset.platkey);
    });
    tab.addEventListener('mouseover', function(){ if(!tab.classList.contains('active-tab')) tab.style.color='#0f172a'; });
    tab.addEventListener('mouseout',  function(){ if(!tab.classList.contains('active-tab')) tab.style.color='#64748b'; });
  });

  // Default to first platform
  selectPostingPlatform(POSTING_PLATFORMS[0].key);
  bootstrap.Modal.getOrCreateInstance(document.getElementById('mPosting')).show();
};

function selectPostingPlatform(platKey) {
  _postingPlatKey = platKey;
  var plat = POSTING_PLATFORMS.find(function(p){ return p.key === platKey; });

  // Update tab styles
  document.querySelectorAll('.posting-tab').forEach(function(t) {
    var isActive = t.dataset.platkey === platKey;
    t.style.borderBottomColor = isActive ? '#6c47ff' : 'transparent';
    t.style.color = isActive ? '#6c47ff' : '#64748b';
    if (isActive) t.classList.add('active-tab'); else t.classList.remove('active-tab');
  });

  // Filter logins for this platform + company
  var linked = _allSocialLogins.filter(function(l) {
    return l.platform === platKey && l.company_ids && l.company_ids.indexOf(_postingCoId) > -1;
  });
  var content = document.getElementById('postingChannelContent');
  document.getElementById('postingAddForm').style.display = 'none';

  var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">' +
    '<div style="font-size:13px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px">' +
      '<i class="bi ' + plat.icon + '" style="color:' + plat.color + ';font-size:18px"></i>' + plat.label + ' channels' +
    '</div>' +
    '<button class="btn btn-sm btn-at" id="btnAddPostingChannel" style="font-size:12px">' +
      '<i class="bi bi-plus-lg me-1"></i>Add channel</button>' +
  '</div>';

  if (!linked.length) {
    html += '<div style="text-align:center;padding:30px 0;color:#94a3b8;font-size:13px">' +
      '<i class="bi ' + plat.icon + '" style="font-size:32px;color:#e2e8f0;display:block;margin-bottom:8px"></i>' +
      'No ' + plat.label + ' channels linked to this company yet.' +
    '</div>';
  } else {
    html += '<div style="display:flex;flex-direction:column;gap:10px">';
    linked.forEach(function(l) {
      html += '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">' +
        '<div style="flex:1;min-width:120px">' +
          '<div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px">Title</div>' +
          '<div style="font-size:13px;font-weight:700;color:#0f172a">' + esc(l.title) + '</div>' +
        '</div>' +
        '<div style="flex:1;min-width:120px">' +
          '<div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px">Channel URL</div>' +
          '<div style="font-size:12px">' + (l.channel_url
            ? '<a href="' + esc(l.channel_url) + '" target="_blank" rel="noopener" style="color:#6c47ff;text-decoration:none"><i class="bi bi-box-arrow-up-right me-1"></i>' + esc(l.channel_url) + '</a>'
            : '<span style="color:#cbd5e1">—</span>') + '</div>' +
        '</div>' +
        '<div style="flex:1;min-width:100px">' +
          '<div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px">Username</div>' +
          '<div style="font-size:12px;font-family:monospace">' + (l.username ? esc(l.username) : '<span style="color:#cbd5e1">—</span>') + '</div>' +
        '</div>' +
        '<div style="flex:1;min-width:140px">' +
          '<div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px">Password</div>' +
          '<div style="display:flex;align-items:center;gap:6px">' +
            (l.has_password
              ? '<span class="pm-pw" data-lid="' + l.id + '" style="font-family:monospace;letter-spacing:2px;color:#64748b;font-size:13px">••••••••</span>' +
                '<button class="btn btn-sm btn-outline-secondary pm-eye" data-lid="' + l.id + '" style="font-size:10px;padding:2px 7px"><i class="bi bi-eye"></i></button>'
              : '<span style="color:#cbd5e1">—</span>') +
          '</div>' +
        '</div>' +
        '<div style="flex:1;min-width:100px">' +
          '<div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px">Posting Software</div>' +
          '<div style="font-size:12px;color:#475569">' + (l.notes ? esc(l.notes) : '<span style="color:#cbd5e1">—</span>') + '</div>' +
        '</div>' +
        '<div style="display:flex;gap:6px;flex-shrink:0">' +
          '<button class="btn btn-sm btn-outline-secondary pm-edit" data-lid="' + l.id + '" style="font-size:11px;padding:3px 10px"><i class="bi bi-pencil me-1"></i>Edit</button>' +
          '<button class="btn btn-sm btn-outline-danger pm-unlink" data-lid="' + l.id + '" data-ltitle="' + esc(l.title) + '" style="font-size:11px;padding:3px 8px" title="Remove from company"><i class="bi bi-x-lg"></i></button>' +
        '</div>' +
      '</div>';
    });
    html += '</div>';
  }

  content.innerHTML = html;

  // Wire Add Channel
  document.getElementById('btnAddPostingChannel').addEventListener('click', function() {
    openPostingForm(null, platKey);
  });

  // Wire reveal passwords
  content.querySelectorAll('.pm-eye').forEach(function(btn) {
    btn.addEventListener('click', async function() {
      var lid = btn.dataset.lid;
      var pw  = content.querySelector('.pm-pw[data-lid="' + lid + '"]');
      if (btn.dataset.shown === '1') {
        pw.textContent = '••••••••'; btn.innerHTML = '<i class="bi bi-eye"></i>'; btn.dataset.shown = '0'; return;
      }
      btn.disabled = true;
      try {
        var r = await apiCall('reveal_password', {id: lid});
        pw.textContent = r.password || '(empty)';
        btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
        btn.dataset.shown = '1';
      } catch(e) { alert(e.message); } finally { btn.disabled = false; }
    });
  });

  // Wire Edit
  content.querySelectorAll('.pm-edit').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var l = linked.find(function(x){ return x.id === btn.dataset.lid; });
      if (l) openPostingForm(l, platKey);
    });
  });

  // Wire Unlink
  content.querySelectorAll('.pm-unlink').forEach(function(btn) {
    btn.addEventListener('click', async function() {
      if (!confirm('Remove "' + btn.dataset.ltitle + '" from this company?')) return;
      var l = _allSocialLogins.find(function(x){ return x.id === btn.dataset.lid; });
      var newCids = l ? (l.company_ids || []).filter(function(id){ return id !== _postingCoId; }) : [];
      try {
        await apiCall('save_social_login', {method:'POST', body:{id:btn.dataset.lid, company_ids:newCids}});
        _allSocialLogins = await apiCall('social_logins');
        openPostingModal(_postingCoId);  // Refresh modal
      } catch(e) { alert(e.message); }
    });
  });
}

function openPostingForm(login, platKey) {
  var plat  = POSTING_PLATFORMS.find(function(p){ return p.key === platKey; });
  var isEdit = !!login;
  var fw     = document.getElementById('postingAddForm');

  // Logins for this platform NOT yet linked to this company (for the "use existing" dropdown)
  var unlinked = _allSocialLogins.filter(function(l) {
    return l.platform === platKey && (!l.company_ids || l.company_ids.indexOf(_postingCoId) === -1);
  });

  fw.innerHTML =
    '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:14px">' +
      '<i class="bi ' + plat.icon + ' me-1" style="color:' + plat.color + '"></i>' +
      (isEdit ? 'Edit — ' + esc(login.title) : 'Add ' + plat.label + ' channel') +
    '</div>' +
    (!isEdit && unlinked.length
      ? '<div class="mb-3">' +
          '<label class="form-label" style="font-size:12px;font-weight:600">Use existing login ' +
            '<span style="font-weight:400;color:#94a3b8">(or fill below to create new)</span></label>' +
          '<select class="form-select form-select-sm" id="pmExistSel" style="max-width:400px">' +
            '<option value="">— Create new —</option>' +
            unlinked.map(function(l){
              return '<option value="' + l.id + '">' + esc(l.title) +
                (l.username ? ' (' + esc(l.username) + ')' : '') + '</option>';
            }).join('') +
          '</select>' +
        '</div>'
      : '') +
    '<input type="hidden" id="pmId" value="' + (isEdit ? login.id : '') + '">' +
    '<div class="row g-3">' +
      '<div class="col-md-6"><label class="form-label">Title *</label>' +
        '<input class="form-control" id="pmTitle" placeholder="e.g. ' + plat.label + ' Main" value="' + (isEdit ? esc(login.title) : '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Channel URL</label>' +
        '<input class="form-control" id="pmUrl" type="url" placeholder="https://…" value="' + (isEdit && login.channel_url ? esc(login.channel_url) : '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Username</label>' +
        '<input class="form-control" id="pmUser" value="' + (isEdit && login.username ? esc(login.username) : '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Password' + (isEdit ? ' <span style="font-size:11px;color:#94a3b8;font-weight:400">(blank = keep)</span>' : '') + '</label>' +
        '<input class="form-control" id="pmPw" type="password" autocomplete="new-password"></div>' +
      '<div class="col-md-6"><label class="form-label">Posting Software</label>' +
        '<input class="form-control" id="pmSoftware" placeholder="e.g. Buffer, Hootsuite…" value="' + (isEdit && login.notes ? esc(login.notes) : '') + '"></div>' +
    '</div>' +
    '<div class="alert alert-danger d-none mt-3" id="pmErr" style="font-size:13px"></div>' +
    '<div class="d-flex gap-2 mt-3">' +
      '<button class="btn btn-sm btn-at" id="pmSaveBtn" style="padding:8px 22px;font-weight:600"><i class="bi bi-check-lg me-1"></i>Save</button>' +
      '<button class="btn btn-sm btn-outline-secondary" id="pmCancelBtn">Cancel</button>' +
    '</div>';

  fw.style.display = '';

  // Pre-fill from existing login selection
  var existSel = document.getElementById('pmExistSel');
  if (existSel) {
    existSel.addEventListener('change', function() {
      var l = _allSocialLogins.find(function(x){ return x.id === this.value; }, this);
      if (!l) { document.getElementById('pmId').value = ''; return; }
      document.getElementById('pmId').value      = l.id;
      document.getElementById('pmTitle').value   = l.title || '';
      document.getElementById('pmUrl').value     = l.channel_url || '';
      document.getElementById('pmUser').value    = l.username || '';
      document.getElementById('pmSoftware').value= l.notes || '';
    });
  }

  document.getElementById('pmCancelBtn').addEventListener('click', function() { fw.style.display = 'none'; });

  document.getElementById('pmSaveBtn').addEventListener('click', async function() {
    var existId = existSel ? existSel.value : '';
    var id      = existId || document.getElementById('pmId').value;
    var title   = document.getElementById('pmTitle').value.trim();
    if (!title) {
      document.getElementById('pmErr').textContent = 'Title is required.';
      document.getElementById('pmErr').classList.remove('d-none'); return;
    }

    // Build company_ids — keep existing + add current company
    var existLogin = id ? _allSocialLogins.find(function(x){ return x.id === id; }) : null;
    var cids       = existLogin ? (existLogin.company_ids || []).slice() : [];
    if (cids.indexOf(_postingCoId) === -1) cids.push(_postingCoId);

    var body = {
      platform:    platKey,
      title:       title,
      channel_url: document.getElementById('pmUrl').value.trim(),
      username:    document.getElementById('pmUser').value.trim(),
      password:    document.getElementById('pmPw').value,
      notes:       document.getElementById('pmSoftware').value.trim(),
      company_ids: cids,
    };
    if (id) body.id = id;

    this.disabled = true;
    try {
      await apiCall('save_social_login', {method:'POST', body:body});
      _allSocialLogins = await apiCall('social_logins');
      fw.style.display = 'none';
      selectPostingPlatform(platKey); // Refresh the channel list + tabs
      // Refresh tab counts
      openPostingModal(_postingCoId);
    } catch(e) {
      document.getElementById('pmErr').textContent = e.message;
      document.getElementById('pmErr').classList.remove('d-none');
    } finally { this.disabled = false; }
  });
}

// Keep old panel function as no-op for backward compat
window.showCompanyPosting = function(){ if(activeId) openPostingModal(activeId); };


// ══════════ ROLE BADGES ══════════
function roleBadges(roleStr){
  if(!roleStr) return '';
  var map = {
    admin:           '<span class="badge me-1" style="background:#6c47ff;font-size:10px">Admin</span>',
    content_manager: '<span class="badge me-1" style="background:#0891b2;font-size:10px">CM</span>',
    sales_person:    '<span class="badge me-1" style="background:#16a34a;font-size:10px">SP</span>'
  };
  return roleStr.split(',').map(function(r){ return map[r.trim()] || r; }).join('');
}

// ══════════ USERS PAGE ══════════
window.showUsersPage = async function(){
  if(!CURRENT_USER.is_admin) return;
  activeId = null;
  document.querySelectorAll('.co-btn,.sb-person').forEach(function(b){b.classList.remove('active');});
  var nb = document.getElementById('btnNavUsers'); if(nb) nb.classList.add('active');
  document.getElementById('tbTitle').textContent = 'Users';
  document.getElementById('coDetailBar').classList.add('hidden');
  var pb=document.getElementById('platformBubbles'); if(pb) pb.style.display='none';
  ['tbBadge','tbSyncBadge'].forEach(function(id){document.getElementById(id).style.display='none';});
  document.getElementById('tbActions').style.display='flex';
  ['btnAddCompanyTop','btnAddPersonTop','btnAddPost','btnEditCo','btnDelCo'].forEach(function(id){
    var el=document.getElementById(id); if(el) el.style.display='none';
  });
  renderStats([]);
  var wrap = document.getElementById('content');
  wrap.innerHTML = '<div class="text-center p-5 text-muted"><div class="spinner-border me-2"></div>Loading…</div>';
  try {
    var users = await apiCall('users');
    var rb = {
      admin:'<span class="badge" style="background:#6c47ff;font-size:10px">Admin</span>',
      content_manager:'<span class="badge" style="background:#0891b2;font-size:10px">Content Manager</span>',
      sales_person:'<span class="badge" style="background:#16a34a;font-size:10px">Sales Person</span>'
    };
    var rows = users.map(function(u){
      var ll = u.last_login ? new Date(u.last_login).toLocaleDateString('en',{month:'short',day:'numeric',year:'numeric'}) : 'Never';
      return '<tr>'+
        '<td style="padding:11px 14px"><div style="display:flex;align-items:center;gap:10px">'+
          '<div class="av" style="background:'+(u.role==='admin'?'#6c47ff':u.role==='content_manager'?'#0891b2':'#16a34a')+';width:34px;height:34px;font-size:12px">'+initials(u.full_name)+'</div>'+
          '<div><div style="font-size:13px;font-weight:600;color:#0f172a">'+esc(u.full_name)+'</div>'+
          '<div style="font-size:11px;color:#94a3b8">'+esc(u.email||'')+'</div></div></div></td>'+
        '<td style="padding:11px 14px;font-family:monospace;font-size:12px;color:#64748b">'+esc(u.username)+'</td>'+
        '<td style="padding:11px 14px">'+roleBadges(u.role)+'</td>'+
        '<td style="padding:11px 14px;font-size:12px;color:#475569">'+
          (u.cm_person_name?'<div style="color:#0891b2"><i class="bi bi-person-badge me-1"></i>'+esc(u.cm_person_name)+'</div>':'')+
          (u.sp_person_name?'<div style="color:#16a34a"><i class="bi bi-person-check me-1"></i>'+esc(u.sp_person_name)+'</div>':'')+
          (!u.cm_person_name&&!u.sp_person_name?'<span style="color:#cbd5e1">—</span>':'')+
        '</td>'+
        '<td style="padding:11px 14px">'+(u.is_active?'<span style="color:#16a34a;font-size:12px">● Active</span>':'<span style="color:#dc2626;font-size:12px">● Disabled</span>')+'</td>'+
        '<td style="padding:11px 14px;font-size:11px;color:#94a3b8">'+ll+'</td>'+
        '<td style="padding:11px 14px;white-space:nowrap">'+
          '<button class="btn btn-sm btn-outline-secondary" data-uid="'+u.id+'" style="font-size:11px;padding:3px 10px;margin-right:4px"><i class="bi bi-pencil me-1"></i>Edit</button>'+
          (u.id!=='usr_admin'?'<button class="btn btn-sm btn-outline-danger" data-udel="'+u.id+'" data-uname="'+esc(u.full_name)+'" style="font-size:11px;padding:3px 8px"><i class="bi bi-trash"></i></button>':'')+
        '</td></tr>';
    }).join('');

    var tbl = document.createElement('div');
    tbl.style.cssText = 'background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:14px';
    tbl.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc">'+
        '<div style="font-size:14px;font-weight:700;color:#0f172a"><i class="bi bi-people me-2" style="color:#f59e0b"></i>All Users</div>'+
        '<button class="btn btn-sm btn-at" id="btnShowAddUser" style="font-size:12px"><i class="bi bi-plus-lg me-1"></i>Add user</button>'+
      '</div>'+
      '<table style="width:100%;border-collapse:collapse;font-size:13px">'+
      '<thead><tr style="background:#f8fafc">'+
        '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid #e2e8f0">Name</th>'+
        '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid #e2e8f0">Username</th>'+
        '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid #e2e8f0">Role</th>'+
        '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid #e2e8f0">Linked</th>'+
        '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid #e2e8f0">Status</th>'+
        '<th style="padding:9px 14px;font-size:10px;font-weight:600;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid #e2e8f0">Last Login</th>'+
        '<th style="padding:9px 14px;border-bottom:1px solid #e2e8f0"></th>'+
      '</tr></thead><tbody>'+rows+'</tbody></table>';
    wrap.innerHTML = '';
    wrap.appendChild(tbl);

    var form = document.createElement('div');
    form.id = 'userFormWrap';
    form.style.display = 'none';
    form.style.cssText = 'display:none;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:22px;margin-top:14px';
    form.innerHTML =
      '<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:16px" id="uFormTitle">Add user</div>'+
      '<input type="hidden" id="uId">'+
      '<div class="row g-3">'+
        '<div class="col-md-6"><label class="form-label">Full name *</label><input class="form-control" id="uFullName" placeholder="Jane Smith"></div>'+
        '<div class="col-md-6"><label class="form-label">Username *</label><input class="form-control" id="uUsername" placeholder="janesmith" autocomplete="off"></div>'+
        '<div class="col-md-6"><label class="form-label">Email</label><input class="form-control" id="uEmail" type="email"></div>'+
        '<div class="col-md-6"><label class="form-label">Password <span id="uPwHint" style="font-size:11px;color:#94a3b8">(required for new)</span></label><input class="form-control" id="uPassword" type="password" autocomplete="new-password"></div>'+
        '<div class="col-md-12"><label class="form-label">Roles</label>'+
          '<div class="d-flex gap-4 mt-1">'+
            '<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer"><input type="checkbox" id="uIsAdmin" style="width:16px;height:16px;accent-color:#6c47ff"> Admin</label>'+
            '<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer"><input type="checkbox" id="uIsCM" style="width:16px;height:16px;accent-color:#0891b2"> Content Manager</label>'+
            '<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer"><input type="checkbox" id="uIsSP" style="width:16px;height:16px;accent-color:#16a34a"> Sales Person</label>'+
          '</div>'+
        '</div>'+
        '<div class="col-md-6" id="uCMRow" style="display:none"><label class="form-label" style="color:#0891b2">Content Manager person</label><select class="form-select" id="uCMPerson"><option value="">— Select —</option></select></div>'+
        '<div class="col-md-6" id="uSPRow" style="display:none"><label class="form-label" style="color:#16a34a">Sales Person person</label><select class="form-select" id="uSPPerson"><option value="">— Select —</option></select></div>'+
        '<div class="col-md-4"><label class="form-label">Status</label><select class="form-select" id="uIsActive"><option value="1">Active</option><option value="0">Disabled</option></select></div>'+
      '</div>'+
      '<div class="alert alert-danger d-none mt-3" id="uErr" style="font-size:13px"></div>'+
      '<div class="d-flex gap-2 mt-3">'+
        '<button class="btn btn-sm btn-at" id="btnSaveUser2" style="padding:8px 20px;font-weight:600"><i class="bi bi-check-lg me-1"></i>Save</button>'+
        '<button class="btn btn-sm btn-outline-secondary" id="btnCancelUser">Cancel</button>'+
      '</div>';
    wrap.appendChild(form);

    function refreshUserRoleSel(){
      var isA=document.getElementById('uIsAdmin').checked;
      var isCM=document.getElementById('uIsCM').checked;
      var isSP=document.getElementById('uIsSP').checked;
      document.getElementById('uCMRow').style.display=(!isA&&isCM)?'':'none';
      document.getElementById('uSPRow').style.display=(!isA&&isSP)?'':'none';
      var cms=(db.people||[]).filter(function(p){return p.role==='content_manager';});
      var sps=(db.people||[]).filter(function(p){return p.role==='sales_person';});
      document.getElementById('uCMPerson').innerHTML='<option value="">— Select —</option>'+cms.map(function(p){return '<option value="'+p.id+'">'+esc(p.name)+'</option>';}).join('');
      document.getElementById('uSPPerson').innerHTML='<option value="">— Select —</option>'+sps.map(function(p){return '<option value="'+p.id+'">'+esc(p.name)+'</option>';}).join('');
    }
    ['uIsAdmin','uIsCM','uIsSP'].forEach(function(id){
      document.getElementById(id).addEventListener('change', refreshUserRoleSel);
    });

    document.getElementById('btnShowAddUser').addEventListener('click', function(){
      document.getElementById('uFormTitle').textContent='Add user';
      document.getElementById('uId').value='';
      ['uFullName','uUsername','uEmail','uPassword'].forEach(function(id){document.getElementById(id).value='';});
      ['uIsAdmin','uIsCM','uIsSP'].forEach(function(id){document.getElementById(id).checked=false;});
      document.getElementById('uIsActive').value='1';
      document.getElementById('uPwHint').textContent='(required for new)';
      document.getElementById('uErr').classList.add('d-none');
      refreshUserRoleSel();
      form.style.display='';
      form.scrollIntoView({behavior:'smooth'});
    });

    document.getElementById('btnCancelUser').addEventListener('click', function(){ form.style.display='none'; });

    document.getElementById('btnSaveUser2').addEventListener('click', async function(){
      var isAdmin = document.getElementById('uIsAdmin').checked;
      var body = {
        id:           document.getElementById('uId').value || undefined,
        full_name:    document.getElementById('uFullName').value.trim(),
        username:     document.getElementById('uUsername').value.trim(),
        email:        document.getElementById('uEmail').value.trim(),
        password:     document.getElementById('uPassword').value,
        is_admin:     isAdmin,
        cm_person_id: (!isAdmin&&document.getElementById('uIsCM').checked) ? document.getElementById('uCMPerson').value||null : null,
        sp_person_id: (!isAdmin&&document.getElementById('uIsSP').checked) ? document.getElementById('uSPPerson').value||null : null,
        is_active:    parseInt(document.getElementById('uIsActive').value)
      };
      if(!body.full_name||!body.username){ document.getElementById('uErr').textContent='Name and username required.'; document.getElementById('uErr').classList.remove('d-none'); return; }
      this.disabled=true;
      try { await apiCall('save_user',{method:'POST',body}); showUsersPage(); }
      catch(e){ document.getElementById('uErr').textContent=e.message; document.getElementById('uErr').classList.remove('d-none'); }
      finally { this.disabled=false; }
    });

    wrap.querySelectorAll('[data-uid]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var u=users.find(function(x){return x.id===btn.dataset.uid;}); if(!u) return;
        document.getElementById('uFormTitle').textContent='Edit — '+u.full_name;
        document.getElementById('uId').value=u.id;
        document.getElementById('uFullName').value=u.full_name||'';
        document.getElementById('uUsername').value=u.username||'';
        document.getElementById('uEmail').value=u.email||'';
        document.getElementById('uPassword').value='';
        document.getElementById('uPwHint').textContent='(leave blank to keep)';
        document.getElementById('uIsActive').value=u.is_active?'1':'0';
        var roles=(u.role||'').split(',').map(function(r){return r.trim();});
        document.getElementById('uIsAdmin').checked=roles.indexOf('admin')>-1;
        document.getElementById('uIsCM').checked=roles.indexOf('content_manager')>-1;
        document.getElementById('uIsSP').checked=roles.indexOf('sales_person')>-1;
        refreshUserRoleSel();
        if(u.cm_person_id) document.getElementById('uCMPerson').value=u.cm_person_id;
        if(u.sp_person_id) document.getElementById('uSPPerson').value=u.sp_person_id;
        document.getElementById('uErr').classList.add('d-none');
        form.style.display='';
        form.scrollIntoView({behavior:'smooth'});
      });
    });
    wrap.querySelectorAll('[data-udel]').forEach(function(btn){
      btn.addEventListener('click', async function(){
        if(!confirm('Delete user "'+btn.dataset.uname+'"?')) return;
        try { await apiCall('delete_user',{method:'POST',body:{id:btn.dataset.udel}}); showUsersPage(); }
        catch(e){ alert(e.message); }
      });
    });
  } catch(e) {
    wrap.innerHTML='<div class="state-box"><i class="bi bi-exclamation-triangle text-danger" style="font-size:32px"></i><strong>Error</strong><p>'+e.message+'</p></div>';
  }
};

// ══════════ SOCIAL LOGINS PAGE ══════════
var SOCIAL_PLATFORMS = [
  {key:'blog',      label:'Blog',      icon:'bi-pencil-square',  color:'#16a34a'},
  {key:'facebook',  label:'Facebook',  icon:'bi-facebook',       color:'#1d4ed8'},
  {key:'instagram', label:'Instagram', icon:'bi-instagram',      color:'#9d174d'},
  {key:'youtube',   label:'YouTube',   icon:'bi-youtube',        color:'#b91c1c'},
  {key:'tiktok',    label:'TikTok',    icon:'bi-tiktok',         color:'#0f172a'},
  {key:'reddit',    label:'Reddit',    icon:'bi-reddit',         color:'#c2410c'},
  {key:'pinterest', label:'Pinterest', icon:'bi-pin-angle-fill', color:'#9f1239'}
];

window.showSocialLoginsPage = async function(){
  activeId = null;
  document.querySelectorAll('.co-btn,.sb-person').forEach(function(b){b.classList.remove('active');});
  var nb=document.getElementById('btnNavSocial'); if(nb) nb.classList.add('active');
  document.getElementById('tbTitle').textContent = 'Social Media Logins';
  document.getElementById('coDetailBar').classList.add('hidden');
  var pb=document.getElementById('platformBubbles'); if(pb) pb.style.display='none';
  ['tbBadge','tbSyncBadge'].forEach(function(id){document.getElementById(id).style.display='none';});
  document.getElementById('tbActions').style.display='flex';
  ['btnAddCompanyTop','btnAddPersonTop','btnAddPost','btnEditCo','btnDelCo'].forEach(function(id){
    var el=document.getElementById(id); if(el) el.style.display='none';
  });
  renderStats([]);
  var wrap=document.getElementById('content');
  wrap.innerHTML='<div class="text-center p-5 text-muted"><div class="spinner-border me-2"></div>Loading…</div>';
  try {
    var logins = await apiCall('social_logins');
    var platMap={};
    SOCIAL_PLATFORMS.forEach(function(p){platMap[p.key]=p;});
    var grouped={};
    SOCIAL_PLATFORMS.forEach(function(p){grouped[p.key]=[];});
    logins.forEach(function(l){if(grouped[l.platform]) grouped[l.platform].push(l);});

    var html='<div style="display:flex;flex-direction:column;gap:16px">';
    if(CURRENT_USER.is_admin){
      html+='<div><button class="btn btn-sm btn-at" id="slAddBtn" style="font-size:12px"><i class="bi bi-plus-lg me-1"></i>Add login</button></div>';
    }
    var hasAny=false;
    SOCIAL_PLATFORMS.forEach(function(plat){
      var items=grouped[plat.key]; if(!items.length) return;
      hasAny=true;
      html+='<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">'+
        '<div style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc;font-size:14px;font-weight:700;color:#0f172a">'+
          '<i class="bi '+plat.icon+'" style="color:'+plat.color+';font-size:18px"></i>'+plat.label+
          '<span style="background:#f1f5f9;color:#64748b;border-radius:20px;padding:1px 10px;font-size:11px">'+items.length+'</span>'+
        '</div>'+
        '<table style="width:100%;border-collapse:collapse;font-size:13px">'+
        '<thead><tr style="background:#f8fafc">'+
          '<th style="padding:8px 14px;font-size:10px;font-weight:600;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid #f1f5f9">Title</th>'+
          '<th style="padding:8px 14px;font-size:10px;font-weight:600;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid #f1f5f9">Channel URL</th>'+
          '<th style="padding:8px 14px;font-size:10px;font-weight:600;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid #f1f5f9">Username</th>'+
          '<th style="padding:8px 14px;font-size:10px;font-weight:600;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid #f1f5f9">Password</th>'+
          '<th style="padding:8px 14px;font-size:10px;font-weight:600;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid #f1f5f9">Companies</th>'+
          '<th style="padding:8px 14px;border-bottom:1px solid #f1f5f9"></th>'+
        '</tr></thead><tbody>'+
        items.map(function(l){
          return '<tr>'+
            '<td style="padding:10px 14px;font-weight:600;color:#0f172a">'+esc(l.title)+'</td>'+
            '<td style="padding:10px 14px">'+(l.channel_url?'<a href="'+esc(l.channel_url)+'" target="_blank" rel="noopener" style="color:#6c47ff;font-size:12px"><i class="bi bi-box-arrow-up-right me-1"></i>Link</a>':'<span style="color:#cbd5e1">—</span>')+'</td>'+
            '<td style="padding:10px 14px;font-family:monospace;font-size:12px">'+(l.username?esc(l.username):'<span style="color:#cbd5e1">—</span>')+'</td>'+
            '<td style="padding:10px 14px">'+(l.has_password?'<div style="display:flex;align-items:center;gap:6px"><span class="sl-pw" data-lid="'+l.id+'" style="font-family:monospace;letter-spacing:2px;color:#64748b">••••••••</span><button class="btn btn-sm btn-outline-secondary sl-eye" data-lid="'+l.id+'" style="font-size:10px;padding:2px 8px"><i class="bi bi-eye"></i></button></div>':'<span style="color:#cbd5e1">—</span>')+'</td>'+
            '<td style="padding:10px 14px;font-size:11px;color:#64748b">'+(l.company_names?esc(l.company_names):'<span style="color:#cbd5e1">—</span>')+'</td>'+
            '<td style="padding:10px 14px;white-space:nowrap">'+
              (CURRENT_USER.is_admin?
                '<button class="btn btn-sm btn-outline-secondary sl-edit" data-lid="'+l.id+'" style="font-size:11px;padding:3px 10px;margin-right:4px"><i class="bi bi-pencil me-1"></i>Edit</button>'+
                '<button class="btn btn-sm btn-outline-danger sl-del" data-lid="'+l.id+'" style="font-size:11px;padding:3px 8px"><i class="bi bi-trash"></i></button>':'')+
            '</td>'+
          '</tr>';
        }).join('')+
        '</tbody></table></div>';
    });
    if(!hasAny) html+='<div class="state-box"><i class="bi bi-key" style="font-size:40px;color:#ec4899"></i><strong>No social logins yet</strong>'+(CURRENT_USER.is_admin?'<p>Click Add login to get started.</p>':'<p>No logins have been shared with your companies.</p>')+'</div>';
    html+='</div><div id="slFormWrap" style="margin-top:14px"></div>';
    wrap.innerHTML=html;

    wrap.querySelectorAll('.sl-eye').forEach(function(btn){
      btn.addEventListener('click', async function(){
        var lid=btn.dataset.lid;
        var pw=wrap.querySelector('.sl-pw[data-lid="'+lid+'"]');
        if(btn.dataset.shown==='1'){pw.textContent='••••••••';btn.innerHTML='<i class="bi bi-eye"></i>';btn.dataset.shown='0';return;}
        btn.disabled=true;
        try{var r=await apiCall('reveal_password',{id:lid});pw.textContent=r.password||'(empty)';btn.innerHTML='<i class="bi bi-eye-slash"></i>';btn.dataset.shown='1';}
        catch(e){alert(e.message);}finally{btn.disabled=false;}
      });
    });

    if(CURRENT_USER.is_admin){
      var addBtn=document.getElementById('slAddBtn');
      if(addBtn) addBtn.addEventListener('click',function(){buildSocialForm(null,logins);});
      wrap.querySelectorAll('.sl-edit').forEach(function(btn){
        btn.addEventListener('click',function(){
          var l=logins.find(function(x){return x.id===btn.dataset.lid;}); if(l) buildSocialForm(l,logins);
        });
      });
      wrap.querySelectorAll('.sl-del').forEach(function(btn){
        btn.addEventListener('click',async function(){
          if(!confirm('Delete this login?')) return;
          try{await apiCall('delete_social_login',{method:'POST',body:{id:btn.dataset.lid}});showSocialLoginsPage();}
          catch(e){alert(e.message);}
        });
      });
    }
  } catch(e) {
    wrap.innerHTML='<div class="state-box"><i class="bi bi-exclamation-triangle text-danger" style="font-size:32px"></i><strong>Error</strong><p>'+e.message+'</p></div>';
  }
};

function buildSocialForm(login, allLogins){
  var fw=document.getElementById('slFormWrap'); if(!fw) return;
  var isEdit=!!login;
  var companies=db.companies||[];
  var selCids=isEdit?(login.company_ids||[]):[];
  var f=document.createElement('div');
  f.style.cssText='background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:22px';
  f.innerHTML=
    '<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:16px">'+(isEdit?'Edit — '+esc(login.title):'Add social login')+'</div>'+
    '<input type="hidden" id="slId" value="'+(isEdit?login.id:'')+'">'+
    '<div class="row g-3">'+
      '<div class="col-md-6"><label class="form-label">Title *</label><input class="form-control" id="slTitle" placeholder="e.g. Storm Facebook Main" value="'+(isEdit?esc(login.title):'')+'"></div>'+
      '<div class="col-md-6"><label class="form-label">Platform *</label><select class="form-select" id="slPlatform">'+
        '<option value="">— Select —</option>'+
        SOCIAL_PLATFORMS.map(function(p){return '<option value="'+p.key+'"'+(isEdit&&login.platform===p.key?' selected':'')+'>'+p.label+'</option>';}).join('')+
      '</select></div>'+
      '<div class="col-md-12"><label class="form-label">Channel URL</label><input class="form-control" id="slUrl" value="'+(isEdit&&login.channel_url?esc(login.channel_url):'')+'"></div>'+
      '<div class="col-md-6"><label class="form-label">Username</label><input class="form-control" id="slUser" value="'+(isEdit&&login.username?esc(login.username):'')+'"></div>'+
      '<div class="col-md-6"><label class="form-label">Password'+(isEdit?' <span style="font-size:11px;color:#94a3b8">(blank = keep)</span>':'')+'</label>'+
        '<input class="form-control" id="slPw" type="password" autocomplete="new-password"></div>'+
      '<div class="col-md-12"><label class="form-label">Companies</label>'+
        '<div style="display:flex;flex-wrap:wrap;gap:8px;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">'+
          companies.map(function(co){
            return '<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:4px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:7px">'+
              '<input type="checkbox" class="sl-co" value="'+co.id+'"'+(selCids.indexOf(co.id)>-1?' checked':'')+' style="accent-color:'+co.color+'">'+
              '<span style="width:8px;height:8px;border-radius:50%;background:'+co.color+';display:inline-block"></span>'+
              esc(co.name)+'</label>';
          }).join('')+
        '</div></div>'+
      '<div class="col-md-12"><label class="form-label">Posting Software</label><input type="text" class="form-control" id="slNotes" placeholder="e.g. Buffer, Hootsuite, Later…" value="'+(isEdit&&login.notes?esc(login.notes):'')+'"></div>'+
    '</div>'+
    '<div class="alert alert-danger d-none mt-3" id="slErr" style="font-size:13px"></div>'+
    '<div class="d-flex gap-2 mt-3">'+
      '<button class="btn btn-sm btn-at" id="slSave" style="padding:8px 20px;font-weight:600"><i class="bi bi-check-lg me-1"></i>Save</button>'+
      '<button class="btn btn-sm btn-outline-secondary" id="slCancel">Cancel</button>'+
    '</div>';
  fw.innerHTML=''; fw.appendChild(f);
  document.getElementById('slCancel').addEventListener('click',function(){fw.innerHTML='';});
  document.getElementById('slSave').addEventListener('click',async function(){
    var body={
      id:document.getElementById('slId').value||undefined,
      platform:document.getElementById('slPlatform').value,
      title:document.getElementById('slTitle').value.trim(),
      channel_url:document.getElementById('slUrl').value.trim(),
      username:document.getElementById('slUser').value.trim(),
      password:document.getElementById('slPw').value,
      notes:document.getElementById('slNotes').value.trim(),
      company_ids:[...document.querySelectorAll('.sl-co:checked')].map(function(c){return c.value;})
    };
    if(!body.platform||!body.title){document.getElementById('slErr').textContent='Platform and title required.';document.getElementById('slErr').classList.remove('d-none');return;}
    this.disabled=true;
    try{await apiCall('save_social_login',{method:'POST',body});showSocialLoginsPage();}
    catch(e){document.getElementById('slErr').textContent=e.message;document.getElementById('slErr').classList.remove('d-none');}
    finally{this.disabled=false;}
  });
  fw.scrollIntoView({behavior:'smooth'});
}
// ── Init ──
window.onerror = function(msg,src,line){
  document.body.insertAdjacentHTML('afterbegin','<div style="position:fixed;top:0;left:0;right:0;background:#dc2626;color:#fff;padding:8px 16px;font-size:12px;font-family:monospace;z-index:9999">JS ERROR: '+msg+' (line '+line+')</div>');
};

// ── Notice banner (init/sync problems are shown, never swallowed) ──
function cbNotice(html, kind){
  var el = document.getElementById('cbNotice');
  if (!el) {
    el = document.createElement('div');
    el.id = 'cbNotice';
    document.body.insertAdjacentElement('afterbegin', el);
  }
  el.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:9998;padding:9px 44px 9px 16px;font-size:13px;font-family:system-ui,sans-serif;color:#fff;background:'
    + (kind==='error' ? '#dc2626' : '#b45309') + ';';
  el.innerHTML = html
    + '<button onclick="this.parentNode.remove()" style="position:absolute;right:8px;top:4px;background:none;border:none;color:#fff;font-size:18px;cursor:pointer" aria-label="Dismiss">&times;</button>';
}
function cbClearNotice(){
  var el = document.getElementById('cbNotice');
  if (el) el.remove();
}

// ── Normalisers ──
// Data reaches the client from two sources: the PHP loader (json_encode'd at
// render time) and api.php. Both may hand us posting_days as an ARRAY (already
// exploded server-side) or as a raw CSV STRING — the old init assumed string
// and crashed on `.split` whenever it got an array, which killed the whole
// init and left the page blank. Normalise defensively for every shape.
function toDayList(v){
  if (Array.isArray(v)) return v.filter(Boolean);
  if (typeof v === 'string' && v) return v.split(',').filter(Boolean);
  return [];
}
function normalizeCompany(co){
  co.contentManagerId = co.content_manager_id || null;
  co.salesPersonId    = co.sales_person_id    || null;
  co.monthlyPosts     = co.monthly_posts      || null;
  co.paymentDate      = co.payment_date       || null;
  co.feeSP = co.fee_sp_pct != null ? co.fee_sp_pct : 40;
  co.feeCM = co.fee_cm_pct != null ? co.fee_cm_pct : 40;
  co.feeSM = co.fee_sm_pct != null ? co.fee_sm_pct : 20;
  co.postingDays  = toDayList(co.posting_days != null ? co.posting_days : co.postingDays);
  co.posting_days = co.postingDays;
  if (typeof co.platform_config === 'string') {
    try { co.platform_config = JSON.parse(co.platform_config) || {}; } catch(e){ co.platform_config = {}; }
  }
  if (!co.platform_config || typeof co.platform_config !== 'object') co.platform_config = {};
  if (!Array.isArray(co.posts)) co.posts = [];
  return co;
}
function normalizePerson(p){
  p.companyIds  = Array.isArray(p.company_ids) ? p.company_ids
                : (Array.isArray(p.companyIds) ? p.companyIds : []);
  p.company_ids = p.companyIds;
  return p;
}
// One bad row must not blank the whole board: normalise per-item, skip failures.
function normalizeList(list, fn, label){
  var out = [], failed = 0;
  (Array.isArray(list) ? list : []).forEach(function(item){
    try { out.push(fn(item)); }
    catch(e){ failed++; console.error('Skipping '+label+' that failed to normalise:', item, e); }
  });
  if (failed) cbNotice(failed + ' ' + label + '(s) could not be loaded — details in the browser console.');
  return out;
}

function renderAll(){
  renderSidebar();
  showDashboard();
  renderPeople();
}

// ── Background API sync (server is the source of truth) ──
async function syncFromServer(){
  try {
    var results = await Promise.all([apiCall('companies'), apiCall('people')]);
    var cos = normalizeList(results[0], normalizeCompany, 'company');
    var ppl = normalizeList(results[1], normalizePerson, 'person');
    // api.php 'companies' carries no posts — keep the ones the PHP loader gave us
    cos.forEach(function(co){
      var existing = db.companies.find(function(x){ return x.id === co.id; });
      co.posts = (existing && Array.isArray(existing.posts)) ? existing.posts : [];
    });
    var before = JSON.stringify([db.companies, db.people]);
    db.companies = cos;
    db.people    = ppl;
    save();
    // Re-render only if the data actually changed AND the user is still on the
    // dashboard — never yank them out of another view they navigated to.
    var onDashboard = (document.getElementById('tbTitle')||{}).textContent === 'Dashboard';
    if (onDashboard && JSON.stringify([db.companies, db.people]) !== before) renderAll();
    cbClearNotice();
  } catch(e){
    console.error('Content Board: background sync failed:', e);
    cbNotice('Could not refresh data from the server: ' + esc(e.message || String(e))
      + ' &nbsp;<a href="javascript:void(0)" onclick="cbClearNotice();syncFromServer()" style="color:#fff;text-decoration:underline">Retry</a>');
  }
}

function initApp(){
  try {
    try { localStorage.removeItem('contentBoard_v2'); } catch(e){}
    load(); // restores the token; the data key was just cleared
    if (!db || typeof db !== 'object')  db = {};
    if (!Array.isArray(db.companies))   db.companies = [];
    if (!Array.isArray(db.people))      db.people = [];

    var phpCos = <?= json_encode($initial_companies) ?>;
    var phpPpl = <?= json_encode($initial_people) ?>;
    var phpErr = <?= json_encode($php_load_error) ?>;

    db.companies = normalizeList(phpCos, normalizeCompany, 'company');
    db.people    = normalizeList(phpPpl, normalizePerson, 'person');
    save();
    renderAll();

    if (phpErr) cbNotice('Initial data could not be loaded (' + esc(phpErr) + ') — retrying from the API…');
    syncFromServer();
  } catch(e){
    console.error('Content Board: init failed:', e);
    cbNotice('The dashboard failed to initialise: ' + esc(e.message || String(e))
      + ' &nbsp;<a href="javascript:location.reload()" style="color:#fff;text-decoration:underline">Reload</a>', 'error');
  }
}

// Run only once the full document (incl. modals below this script) is parsed.
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initApp);
} else {
  initApp();
}

</script>

<!-- ══════════ MODAL: Company Posting Channels ══════════ -->
<div class="modal fade" id="mPosting" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header" style="border-bottom:1px solid #e2e8f0">
        <h5 class="modal-title" id="mPostingTitle"><i class="bi bi-share me-2" style="color:#6c47ff"></i>Posting Channels</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:0">
        <!-- Platform tabs -->
        <div id="postingPlatformTabs" style="display:flex;border-bottom:1px solid #e2e8f0;overflow-x:auto;background:#f8fafc;padding:0 16px"></div>
        <!-- Channel list for selected platform -->
        <div id="postingChannelContent" style="padding:20px;min-height:200px"></div>
        <!-- Add/Edit form -->
        <div id="postingAddForm" style="display:none;padding:20px;border-top:1px solid #e2e8f0;background:#f8fafc"></div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
