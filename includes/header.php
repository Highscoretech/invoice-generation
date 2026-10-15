<?php
// Public pages (API docs, SLA) include this layout without a login. Derive the
// auth state once so the chrome renders cleanly for anonymous visitors too.
$is_authed = isset($_SESSION['user_id']);
$role = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? 'admin';
$initial = strtoupper(substr($username, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?> &middot; Virdi E-Invoice</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --brand:#0d7a3f; --brand-600:#0a6334; --brand-700:#085129; --brand-050:#e8f5ee;
            --accent:#34d399;
            --ink:#1f2937; --muted:#6b7280; --faint:#9ca3af;
            --line:#e7eaf0; --line-2:#eef1f6; --bg:#f4f6fa; --card:#ffffff;
            --sidebar-w:264px;
            --shadow-sm:0 1px 2px rgba(16,24,40,.04),0 1px 3px rgba(16,24,40,.06);
            --shadow-md:0 4px 12px rgba(16,24,40,.06);
        }
        *{box-sizing:border-box;}
        body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--ink);font-size:14.5px;}
        a{color:var(--brand);}
        h1,h2,h3,h4,h5,h6{color:var(--ink);font-weight:700;letter-spacing:-.01em;}

        /* ── Sidebar ───────────────────────────────────────────────── */
        .sidebar{
            width:var(--sidebar-w); height:100vh; position:fixed; top:0; left:0;
            background:linear-gradient(180deg,#0c241a 0%,#10301f 100%);
            color:#fff; display:flex; flex-direction:column;
            padding:20px 16px; transition:transform .3s ease; z-index:1000;
            border-right:1px solid rgba(255,255,255,.06);
        }
        .brand{display:flex; align-items:center; gap:12px; padding:4px 6px 18px; margin-bottom:12px; border-bottom:1px solid rgba(255,255,255,.08);}
        .brand-logo{width:40px; height:40px; border-radius:11px; background:linear-gradient(135deg,var(--brand) 0%,#12a657 100%);
            display:flex; align-items:center; justify-content:center; font-size:19px; color:#fff; box-shadow:0 4px 10px rgba(13,122,63,.35); flex:0 0 auto;}
        .brand-text .name{font-weight:800; font-size:1.02rem; line-height:1.1; letter-spacing:-.01em;}
        .brand-text .sub{font-size:.68rem; color:rgba(255,255,255,.55); font-weight:500; text-transform:uppercase; letter-spacing:.06em; margin-top:3px;}

        .nav-section{font-size:.66rem; font-weight:700; letter-spacing:.09em; text-transform:uppercase; color:rgba(255,255,255,.38); padding:14px 12px 6px;}
        .nav{gap:2px;}
        .nav-link{
            color:rgba(255,255,255,.74); padding:9px 12px; border-radius:9px; font-weight:500; font-size:.9rem;
            display:flex; align-items:center; border-left:3px solid transparent; transition:all .18s ease;
        }
        .nav-link i{width:22px; font-size:.95rem; margin-right:11px; text-align:center; color:rgba(255,255,255,.6); transition:color .18s;}
        .nav-link:hover{background:rgba(255,255,255,.07); color:#fff;}
        .nav-link:hover i{color:#fff;}
        .nav-link.active{background:rgba(52,211,153,.14); color:#fff; font-weight:600; border-left-color:var(--accent);}
        .nav-link.active i{color:var(--accent);}

        .sidebar-footer{margin-top:auto; padding-top:16px; border-top:1px solid rgba(255,255,255,.08);}
        .user-info{display:flex; align-items:center; gap:11px; margin-bottom:12px; padding:4px 6px;}
        .user-avatar{width:38px; height:38px; border-radius:10px; background:linear-gradient(135deg,var(--brand),#12a657); display:flex; align-items:center; justify-content:center; font-weight:700; color:#fff; flex:0 0 auto;}
        .user-info .name{font-size:.86rem; font-weight:700; line-height:1.2;}
        .user-info .role{font-size:.7rem; color:rgba(255,255,255,.55); text-transform:capitalize;}
        .btn-logout{display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:9px; border-radius:9px;
            background:rgba(255,255,255,.08); color:rgba(255,255,255,.85); font-size:.85rem; font-weight:500; text-decoration:none; transition:all .18s;}
        .btn-logout:hover{background:rgba(255,255,255,.15); color:#fff;}

        /* ── Main + topbar ─────────────────────────────────────────── */
        .main-content{margin-left:var(--sidebar-w); min-height:100vh; transition:margin .3s ease;}
        .topbar{
            position:sticky; top:0; z-index:500; display:flex; align-items:center; justify-content:space-between;
            background:rgba(255,255,255,.88); backdrop-filter:saturate(180%) blur(8px);
            border-bottom:1px solid var(--line); padding:14px 32px;
        }
        .topbar .page-title{font-size:1.18rem; font-weight:700; letter-spacing:-.01em; margin:0;}
        .topbar .page-sub{font-size:.75rem; color:var(--faint); margin-top:1px;}
        .topbar-right{display:flex; align-items:center; gap:14px;}
        .status-pill{display:inline-flex; align-items:center; gap:7px; font-size:.76rem; font-weight:600; color:var(--brand-600); background:var(--brand-050); border:1px solid #cdeedb; padding:5px 11px; border-radius:20px;}
        .status-pill .dot{width:7px; height:7px; border-radius:50%; background:var(--brand); box-shadow:0 0 0 3px rgba(13,122,63,.12);}
        .topbar .user-chip{display:flex; align-items:center; gap:9px;}
        .topbar .user-chip .av{width:34px; height:34px; border-radius:9px; background:linear-gradient(135deg,var(--brand),#12a657); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.85rem;}
        .topbar .user-chip .nm{font-size:.82rem; font-weight:600; line-height:1.1;}
        .topbar .user-chip .rl{font-size:.7rem; color:var(--faint); text-transform:capitalize;}
        .content{padding:28px 32px 48px;}

        /* ── Component polish (applies app-wide over Bootstrap) ────── */
        .card{border:1px solid var(--line); border-radius:14px; box-shadow:var(--shadow-sm);}
        .card-header{background:#fff; border-bottom:1px solid var(--line-2); font-weight:700; padding:16px 20px; border-radius:14px 14px 0 0 !important;}
        .card-body{padding:20px;}
        .btn{border-radius:9px; font-weight:600; font-size:.88rem; padding:.5rem .95rem;}
        .btn-primary{background:var(--brand); border-color:var(--brand);}
        .btn-primary:hover,.btn-primary:focus{background:var(--brand-600); border-color:var(--brand-600);}
        .btn-success{background:var(--brand); border-color:var(--brand);}
        .btn-success:hover{background:var(--brand-600); border-color:var(--brand-600);}
        .btn-outline-primary{color:var(--brand); border-color:#bfe0cd;}
        .btn-outline-primary:hover{background:var(--brand); border-color:var(--brand);}
        .form-control,.form-select{border-radius:9px; border-color:var(--line); padding:.5rem .8rem; font-size:.9rem;}
        .form-control:focus,.form-select:focus{border-color:var(--brand); box-shadow:0 0 0 3px rgba(13,122,63,.12);}
        .form-label{font-weight:600; color:#374151; font-size:.84rem;}
        .input-group-text{border-radius:9px;}
        .table{--bs-table-hover-bg:#f6faf7;}
        .table>thead th{background:#f7f9fc; color:var(--muted); font-weight:600; font-size:.74rem; letter-spacing:.04em; text-transform:uppercase; border-bottom:1px solid var(--line); padding:11px 14px;}
        .table>tbody td{padding:12px 14px; border-bottom:1px solid var(--line-2); vertical-align:middle;}
        .table-sm>tbody td,.table-sm>thead th{padding:8px 12px;}
        .badge{font-weight:600; border-radius:7px; padding:.38em .6em; font-size:.72rem;}
        .alert{border-radius:11px; border:1px solid transparent; font-size:.9rem;}
        .alert-success{background:var(--brand-050); color:var(--brand-700); border-color:#cdeedb;}

        /* Dashboard / KPI cards */
        .stat-card{background:#fff; border:1px solid var(--line); border-radius:14px; padding:22px 24px; box-shadow:var(--shadow-sm);
            display:flex; justify-content:space-between; align-items:center; height:100%; transition:box-shadow .2s, transform .2s;}
        .stat-card:hover{box-shadow:var(--shadow-md); transform:translateY(-1px);}
        .stat-card .label,.stat-card .stat-label{color:var(--muted); font-size:.82rem; font-weight:500; margin-bottom:6px;}
        .stat-card .value,.stat-card .stat-value{font-size:1.9rem; font-weight:800; color:var(--ink); line-height:1; letter-spacing:-.02em;}
        .stat-card .icon-box,.stat-card .stat-icon{width:52px; height:52px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:1.35rem; background:var(--brand-050); color:var(--brand); opacity:1;}
        .text-online{color:#10b981;}

        /* ── Mobile ────────────────────────────────────────────────── */
        .mobile-header{display:none;}
        .sidebar-overlay{display:none;}
        @media (max-width:768px){
            .sidebar{transform:translateX(-100%);}
            .sidebar.active{transform:translateX(0);}
            .main-content{margin-left:0;}
            .topbar{padding-left:20px; padding-right:20px;}
            .content{padding:20px 18px 40px;}
            .mobile-header{display:flex; align-items:center; justify-content:space-between; padding:13px 18px; background:#fff;
                position:sticky; top:0; z-index:999; box-shadow:0 1px 3px rgba(0,0,0,.06);}
            .topbar{display:none;}
            .sidebar-overlay{position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:990;}
            .sidebar-overlay.active{display:block;}
        }
    </style>
</head>
<body>

<!-- Mobile Header -->
<div class="mobile-header">
    <button class="btn btn-link text-dark p-0" id="sidebarToggle"><i class="fas fa-bars fa-lg"></i></button>
    <div class="fw-bold">Virdi E-Invoice</div>
    <div class="user-avatar" style="width:34px;height:34px;font-size:.85rem;"><?php echo htmlspecialchars($initial); ?></div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <div class="brand-logo"><i class="fas fa-file-invoice"></i></div>
        <div class="brand-text">
            <div class="name">Virdi E-Invoice</div>
            <div class="sub">FIRS / NRS Compliant</div>
        </div>
    </div>

    <nav class="nav flex-column">
        <?php if ($is_authed): ?>
        <a class="nav-link <?php echo $page_title === 'Dashboard' ? 'active' : ''; ?>" href="dashboard.php"><i class="fas fa-gauge-high"></i> Dashboard</a>
        <?php endif; ?>

        <?php if ($role === 'admin'): ?>
            <div class="nav-section">Manage</div>
            <a class="nav-link <?php echo $page_title === 'Customers' ? 'active' : ''; ?>" href="customers.php"><i class="fas fa-users"></i> Customers</a>
            <a class="nav-link <?php echo $page_title === 'Items' ? 'active' : ''; ?>" href="items.php"><i class="fas fa-box"></i> Items &amp; Rates</a>
            <a class="nav-link <?php echo $page_title === 'All Invoices' ? 'active' : ''; ?>" href="invoices.php"><i class="fas fa-file-invoice"></i> All Invoices</a>
        <?php elseif ($role === 'accountant'): ?>
            <div class="nav-section">Invoicing</div>
            <a class="nav-link <?php echo $page_title === 'Create Invoice' ? 'active' : ''; ?>" href="create_invoice.php"><i class="fas fa-plus-circle"></i> Create Invoice</a>
            <a class="nav-link <?php echo $page_title === 'Bulk Upload' ? 'active' : ''; ?>" href="bulk_upload.php"><i class="fas fa-file-upload"></i> Bulk Upload</a>
            <a class="nav-link <?php echo $page_title === 'My Invoices' ? 'active' : ''; ?>" href="my_invoices.php"><i class="fas fa-file-invoice"></i> All Invoices</a>
        <?php endif; ?>

        <div class="nav-section">Developer</div>
        <a class="nav-link <?php echo $page_title === 'API Documentation' ? 'active' : ''; ?>" href="api_docs.php"><i class="fas fa-code"></i> API Docs</a>
        <a class="nav-link <?php echo $page_title === 'Service Level Agreement' ? 'active' : ''; ?>" href="sla.php"><i class="fas fa-shield-halved"></i> SLA</a>
        <?php if ($role === 'admin'): ?>
            <a class="nav-link <?php echo $page_title === 'Endpoint Coverage' ? 'active' : ''; ?>" href="endpoint_tests.php"><i class="fas fa-vial"></i> Endpoint Tests</a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <?php if ($is_authed): ?>
        <div class="user-info">
            <div class="user-avatar"><?php echo htmlspecialchars($initial); ?></div>
            <div>
                <div class="name"><?php echo htmlspecialchars($username); ?></div>
                <div class="role"><?php echo htmlspecialchars(ucfirst($role ?: 'Admin')); ?></div>
            </div>
        </div>
        <a href="logout.php" class="btn-logout"><i class="fas fa-right-from-bracket"></i> Sign out</a>
        <?php else: ?>
        <a href="login.php" class="btn-logout"><i class="fas fa-right-to-bracket"></i> Staff Login</a>
        <?php endif; ?>
    </div>
</aside>

<div class="main-content">
    <header class="topbar">
        <div>
            <h1 class="page-title"><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?></h1>
            <div class="page-sub">Virdi Nigeria Limited &middot; FIRS/NRS e-invoicing</div>
        </div>
        <div class="topbar-right">
            <span class="status-pill"><span class="dot"></span> NRS Sandbox</span>
            <?php if ($is_authed): ?>
            <div class="user-chip">
                <div class="av"><?php echo htmlspecialchars($initial); ?></div>
                <div>
                    <div class="nm"><?php echo htmlspecialchars($username); ?></div>
                    <div class="rl"><?php echo htmlspecialchars(ucfirst($role ?: 'Admin')); ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </header>
    <div class="content">
