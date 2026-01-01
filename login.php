<?php
require_once 'includes/auth.php';

$auth = new Auth();
$error = '';

if ($_POST) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($auth->login($username, $password)) {
        header('Location: dashboard.php');
        exit();
    } else {
        $error = 'Invalid username or password';
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
    <title>Login - Invoice Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #f0f4ff;
            font-family: 'Inter', sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
        }
        
        .login-card {
            background: #fff;
            padding: 50px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            border: none;
            font-size: 1.1rem;
        }
        
        .icon-circle {
            background: #6366f1;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 24px;
        }
        
        .form-label {
            font-weight: 500;
            color: #4b5563;
        }
        
        .input-group-text {
            background-color: transparent;
            border-right: none;
            color: #9ca3af;
        }
        
        .form-control {
            border-left: none;
            padding: 10px 12px;
        }
        
        .form-control:focus {
            box-shadow: none;
            border-color: #ced4da;
        }
        
        .btn-primary {
            background-color: #4f46e5;
            border: none;
            padding: 12px;
            font-weight: 600;
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .btn-primary:hover {
            background-color: #4338ca;
        }
        
        .demo-box {
            background-color: #f9fafb;
            border-radius: 8px;
            padding: 15px;
            margin-top: 25px;
            font-size: 0.85rem;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-6">
                <div class="login-card">
                    <div class="text-center mb-4">
                        <div class="icon-circle">
                            <i class="fas fa-lock"></i>
                        </div>
                        <h4 class="fw-bold mb-1">Invoice Management System</h4>
                        <p class="text-muted">Sign in to your account</p>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2 small"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="far fa-user"></i>
                                </span>
                                <input type="text" class="form-control" name="username" placeholder="Enter username" required>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-lock-open"></i>
                                </span>
                                <input type="password" class="form-control" name="password" placeholder="Enter password" required>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">Sign In</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>