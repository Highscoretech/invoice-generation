<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Invoice System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fc; }
        
        /* Sidebar Styling */
        .sidebar {
            width: 260px;
            height: 100vh;
            position: fixed;
            color: white;
            padding: 20px;
            display: flex;
            flex-direction: column;
        }
        .sidebar-brand { font-size: 1.25rem; font-weight: 700; margin-bottom: 30px; }
        .sidebar-brand span { font-size: 0.85rem; opacity: 0.7; display: block; }
        
        .nav-link {
            color: rgba(255,255,255,0.7);
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            transition: all 0.3s;
        }
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.15);
            color: white;
        }
        .nav-link i { width: 25px; font-size: 1.1rem; margin-right: 10px; }

        .sidebar-footer { margin-top: auto; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1); }
        .user-info { display: flex; align-items: center; margin-bottom: 15px; }
        .user-avatar { width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 10px; }
        
        /* Main Content */
        .main-content { margin-left: 260px; padding: 40px; }
        
        /* Dashboard Cards */
        .stat-card {
            background: white;
            border: none;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 100%;
        }
        .stat-card .label { color: #6b7280; font-size: 0.85rem; font-weight: 500; }
        .stat-card .value { font-size: 1.75rem; font-weight: 700; color: #111827; margin-top: 8px; }
        .stat-card .icon-box { font-size: 2rem; }
        .text-online { color: #10b981; } /* Modern Green */
    </style>
</head>
<body>

<div class="sidebar" style="background-color: <?php echo $_SESSION['role'] === 'accountant' ? '#0d542b' : '#312e81'; ?>">
    <div class="sidebar-brand">
        Invoice System
        <span>ABC Corp</span>
    </div>
    
    <nav class="nav flex-column">
        <a class="nav-link <?php echo $page_title === 'Dashboard' ? 'active' : ''; ?>" href="dashboard.php"><i class="fas fa-th-large"></i> Dashboard</a>
        
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <a class="nav-link <?php echo $page_title === 'Customers' ? 'active' : ''; ?>" href="customers.php"><i class="fas fa-users"></i> Customers</a>
            <a class="nav-link <?php echo $page_title === 'Items' ? 'active' : ''; ?>" href="items.php"><i class="fas fa-box"></i> Items & Rates</a>
            <a class="nav-link <?php echo $page_title === 'Invoices' ? 'active' : ''; ?>" href="invoices.php"><i class="fas fa-file-invoice"></i> All Invoices</a>
        <?php elseif ($_SESSION['role'] === 'accountant'): ?>
            <a class="nav-link <?php echo $page_title === 'Create Invoice' ? 'active' : ''; ?>" href="create_invoice.php"><i class="fas fa-plus-circle"></i> Create Invoice</a>
            <a class="nav-link <?php echo $page_title === 'My Invoices' ? 'active' : ''; ?>" href="my_invoices.php"><i class="fas fa-file-invoice"></i> All Invoices</a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar" style="background: <?php echo $_SESSION['role'] === 'accountant' ? '#16a34a' : '#6366f1'; ?>"><i class="fas fa-user"></i></div>
            <div>
                <div class="small fw-bold"><?php echo $_SESSION['username'] ?? 'admin'; ?></div>
                <div class="small text-white-50"><?php echo ucfirst($_SESSION['role'] ?? 'Admin'); ?></div>
            </div>
        </div>
        <a href="logout.php" class="btn btn-outline-light btn-sm w-100"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<div class="main-content">