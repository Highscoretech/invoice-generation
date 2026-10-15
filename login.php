<?php
require_once 'includes/auth.php';

$auth = new Auth();
$error = '';

// CSRF token for the login form (defence-in-depth, Pentest finding M1).
if (empty($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}

if ($_POST) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $token    = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_login'] ?? '', $token)) {
        $error = 'Invalid or expired session token. Please try again.';
    } elseif ($auth->login($username, $password)) {
        header('Location: dashboard.php');
        exit();
    } else {
        $error = $auth->loginError ?: 'Invalid username or password';
    }
}

if ($auth->isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in &middot; Virdi E-Invoice</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--brand:#0d7a3f;--brand-600:#0a6334;}
        *{box-sizing:border-box;}
        body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#eef2f1;min-height:100vh;margin:0;color:#1f2937;}
        .auth{display:grid;grid-template-columns:1.05fr .95fr;min-height:100vh;}
        /* Brand panel */
        .auth-brand{position:relative;overflow:hidden;color:#fff;padding:56px 56px;display:flex;flex-direction:column;justify-content:space-between;
            background:linear-gradient(150deg,#0c241a 0%,#0f3a26 55%,#0d7a3f 140%);}
        .auth-brand::after{content:"";position:absolute;right:-120px;top:-120px;width:360px;height:360px;border-radius:50%;background:rgba(52,211,153,.12);}
        .auth-brand::before{content:"";position:absolute;left:-80px;bottom:-100px;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.04);}
        .ab-logo{display:flex;align-items:center;gap:13px;position:relative;z-index:1;}
        .ab-logo .mark{width:46px;height:46px;border-radius:13px;background:linear-gradient(135deg,#0d7a3f,#12a657);display:flex;align-items:center;justify-content:center;font-size:21px;box-shadow:0 6px 18px rgba(0,0,0,.25);}
        .ab-logo .name{font-size:1.2rem;font-weight:800;letter-spacing:-.01em;}
        .ab-logo .sub{font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.6);margin-top:2px;}
        .ab-headline{position:relative;z-index:1;}
        .ab-headline h1{color:#fff;font-size:2.1rem;font-weight:800;line-height:1.15;letter-spacing:-.02em;margin:0 0 16px;}
        .ab-headline p{color:rgba(255,255,255,.75);font-size:1.02rem;max-width:420px;line-height:1.6;}
        .ab-points{list-style:none;padding:0;margin:26px 0 0;position:relative;z-index:1;}
        .ab-points li{display:flex;align-items:center;gap:11px;color:rgba(255,255,255,.88);font-size:.95rem;margin-bottom:13px;}
        .ab-points i{width:26px;height:26px;border-radius:8px;background:rgba(52,211,153,.22);color:#eafff4;display:flex;align-items:center;justify-content:center;font-size:.8rem;}
        .ab-foot{position:relative;z-index:1;font-size:.78rem;color:rgba(255,255,255,.5);}
        /* Form panel */
        .auth-form{display:flex;align-items:center;justify-content:center;padding:40px;}
        .login-card{width:100%;max-width:400px;}
        .login-card h2{font-size:1.5rem;font-weight:800;margin:0 0 4px;letter-spacing:-.01em;}
        .login-card .lead-sub{color:#6b7280;margin:0 0 28px;font-size:.95rem;}
        .form-label{font-weight:600;color:#374151;font-size:.85rem;margin-bottom:6px;}
        .input-group-text{background:#fff;border-right:none;color:#9ca3af;border-radius:10px 0 0 10px;border-color:#e5e7eb;}
        .form-control{border-left:none;border-radius:0 10px 10px 0;padding:11px 12px;border-color:#e5e7eb;font-size:.95rem;}
        .input-group:focus-within .input-group-text{border-color:var(--brand);}
        .form-control:focus{box-shadow:none;border-color:var(--brand);}
        .btn-signin{background:var(--brand);border:none;padding:12px;font-weight:700;border-radius:10px;margin-top:6px;color:#fff;width:100%;transition:background .18s;}
        .btn-signin:hover{background:var(--brand-600);color:#fff;}
        .mobile-brand{display:none;}
        @media (max-width:860px){
            .auth{grid-template-columns:1fr;}
            .auth-brand{display:none;}
            .mobile-brand{display:flex;align-items:center;gap:11px;justify-content:center;margin-bottom:26px;}
            .mobile-brand .mark{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#0d7a3f,#12a657);color:#fff;display:flex;align-items:center;justify-content:center;font-size:19px;}
            .mobile-brand .name{font-size:1.15rem;font-weight:800;}
        }
    </style>
</head>
<body>
<div class="auth">
    <div class="auth-brand">
        <div class="ab-logo">
            <div class="mark"><i class="fas fa-file-invoice"></i></div>
            <div><div class="name">Virdi E-Invoice</div><div class="sub">FIRS / NRS Compliant</div></div>
        </div>
        <div class="ab-headline">
            <h1>FIRS-compliant e-invoicing, done right.</h1>
            <p>Create, validate, sign and manage your invoices on Nigeria&rsquo;s NRS platform &mdash; from one secure dashboard.</p>
            <ul class="ab-points">
                <li><i class="fas fa-check"></i> Validate &amp; sign invoices with FIRS/NRS</li>
                <li><i class="fas fa-check"></i> Automatic IRN &amp; QR-code generation</li>
                <li><i class="fas fa-check"></i> Bulk upload &amp; REST API for your systems</li>
            </ul>
        </div>
        <div class="ab-foot">&copy; <?php echo date('Y'); ?> Virdi Nigeria Limited. All rights reserved.</div>
    </div>

    <div class="auth-form">
        <div class="login-card">
            <div class="mobile-brand">
                <div class="mark"><i class="fas fa-file-invoice"></i></div>
                <div class="name">Virdi E-Invoice</div>
            </div>
            <h2>Welcome back</h2>
            <p class="lead-sub">Sign in to your account to continue.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_login'], ENT_QUOTES); ?>">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="far fa-user"></i></span>
                        <input type="text" class="form-control" name="username" placeholder="Enter your username" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" name="password" placeholder="Enter your password" required>
                    </div>
                </div>
                <button type="submit" class="btn-signin">Sign in</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>