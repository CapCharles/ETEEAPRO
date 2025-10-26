<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// If user is already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_type'] == 'admin' || $_SESSION['user_type'] == 'evaluator') {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../candidates/profile.php');
    }
    exit();
}

$errors = [];
$success_message = '';

// Check for registration success message
if (isset($_GET['registered'])) {
    $success_message = "Registration successful! Please login with your credentials.";
}

if ($_POST) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $remember_me = isset($_POST['remember_me']);
    
    // Validation
    if (empty($email)) {
        $errors[] = "Email is required";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    }
    
    // If no validation errors, attempt login
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                SELECT id, email, password, first_name, last_name, user_type, status, 
                       application_form_status, rejection_reason 
                FROM users WHERE email = ?
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Check basic account status
                if ($user['status'] === 'inactive') {
                    $errors[] = "Your account has been deactivated. Please contact administrator.";
                } else {
                    // Allow login for all active users and pending candidates
                    // Candidates can log in even if application form is pending/rejected
                    // Access control will be handled at page level
                    
                    $can_login = false;
                    
                    if ($user['user_type'] === 'candidate') {
                        // Allow candidates to login if status is active or pending
                        if ($user['status'] === 'active' || $user['status'] === 'pending') {
                            $can_login = true;
                        } else {
                            $errors[] = "Your account is not accessible. Please contact administrator.";
                        }
                    } else {
                        // For admin and evaluator, require active status
                        if ($user['status'] === 'active') {
                            $can_login = true;
                        } else {
                            $errors[] = "Your account is not active. Please contact administrator.";
                        }
                    }
                    
                    // Proceed with login if all checks passed
                    if ($can_login) {
                        // Login successful
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                        $_SESSION['user_type'] = $user['user_type'];
                        $_SESSION['application_form_status'] = $user['application_form_status'];
                        
                        // Set remember me cookie if checked
                        if ($remember_me) {
                            setcookie('remember_email', $email, time() + (86400 * 30), "/"); // 30 days
                        } else {
                            setcookie('remember_email', '', time() - 3600, "/"); // Delete cookie
                        }
                        
                        // Log the login activity
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO system_logs (user_id, action, ip_address, user_agent) 
                                VALUES (?, 'login', ?, ?)
                            ");
                            $stmt->execute([
                                $user['id'],
                                $_SERVER['REMOTE_ADDR'],
                                $_SERVER['HTTP_USER_AGENT']
                            ]);
                        } catch (PDOException $e) {
                            // Log error but don't prevent login
                        }
                        
                        // Redirect based on user type
                        if ($user['user_type'] == 'admin' || $user['user_type'] == 'evaluator') {
                            header('Location: ../admin/dashboard.php');
                        } else {
                            header('Location: ../candidates/profile.php');
                        }
                        exit();
                    }
                }
            } else {
                $errors[] = "Invalid email or password";
            }
        } catch (PDOException $e) {
            $errors[] = "Login failed. Please try again.";
        }
    }
}

// Get remembered email
$remembered_email = isset($_COOKIE['remember_email']) ? $_COOKIE['remember_email'] : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ETEEAP</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
           background: url('../school.jpg') center/cover no-repeat fixed;
            position: relative;
            min-height: 100vh;
            padding: 0 !important;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(173, 216, 230, 0.5), rgba(135, 206, 250, 0.5), rgba(100, 149, 237, 0.55));
            backdrop-filter: blur(5px);
            z-index: -1;
        }

        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 2rem 0;
        }

        .auth-card {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 61, 130, 0.25);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.8);
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .auth-header {
            background: linear-gradient(135deg, #0066cc, #0052a3);
            color: white;
            text-align: center;
            padding: 3rem 2rem;
            position: relative;
            overflow: hidden;
        }

        .auth-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: pulse 3s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        .auth-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .auth-header p {
            font-size: 1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .auth-header i {
            font-size: 3rem;
            margin-bottom: 1rem;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.2));
        }

        .card-body {
            padding: 2.5rem !important;
        }

        .form-label {
            color: #003d82;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .form-control {
            border-radius: 12px;
            padding: 14px 18px;
            border: 2px solid #e3f2fd;
            transition: all 0.3s ease;
            font-size: 1rem;
            background: #f8fbff;
        }

        .form-control:focus {
            border-color: #0066cc;
            box-shadow: 0 0 0 0.25rem rgba(0, 102, 204, 0.15);
            background: white;
            outline: none;
        }

        .position-relative .btn-outline-secondary {
            border: none;
            background: transparent;
            color: #666;
            transition: color 0.3s ease;
        }

        .position-relative .btn-outline-secondary:hover {
            color: #0066cc;
            background: transparent;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0066cc, #0052a3);
            border: none;
            border-radius: 12px;
            padding: 14px;
            font-weight: 600;
            font-size: 1.05rem;
            transition: all 0.4s ease;
            box-shadow: 0 6px 20px rgba(0, 102, 204, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 102, 204, 0.4);
            background: linear-gradient(135deg, #0052a3, #003d82);
        }

        .btn-outline-primary {
            border: 2px solid #0066cc;
            color: #0066cc;
            border-radius: 12px;
            padding: 10px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
            background: transparent;
        }

        .btn-outline-primary:hover {
            background: #0066cc;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 102, 204, 0.3);
        }

        .alert {
            border-radius: 12px;
            border: none;
            padding: 1.25rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            animation: slideIn 0.4s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-danger {
            background: linear-gradient(145deg, #ffebee, #ffcdd2);
            color: #c62828;
            border-left: 4px solid #d32f2f;
        }

        .alert-success {
            background: linear-gradient(145deg, #e8f5e9, #c8e6c9);
            color: #2e7d32;
            border-left: 4px solid #388e3c;
        }

        .alert ul {
            margin: 0;
            padding-left: 1.5rem;
        }

        .text-muted {
            color: #666 !important;
            transition: color 0.3s ease;
        }

        .text-muted:hover {
            color: #0066cc !important;
            text-decoration: none;
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 1.5rem 0;
            color: #999;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e3f2fd;
        }

        .divider span {
            padding: 0 1rem;
            font-size: 0.9rem;
        }

        .form-check {
            padding-left: 0;
        }

        .form-check-input {
            width: 1.1em;
            height: 1.1em;
            margin-top: 0.15em;
            cursor: pointer;
            border: 2px solid #dee2e6;
            border-radius: 4px;
        }

        .form-check-input:checked {
            background-color: #0066cc;
            border-color: #0066cc;
        }

        .form-check-input:focus {
            border-color: #0066cc;
            box-shadow: 0 0 0 0.2rem rgba(0, 102, 204, 0.25);
        }

        .form-check-label {
            cursor: pointer;
            font-size: 0.9rem;
            color: #666;
            margin-left: 0.5rem;
            user-select: none;
        }

        @media (max-width: 768px) {
            .auth-header {
                padding: 2rem 1.5rem;
            }

            .auth-header h1 {
                font-size: 1.5rem;
            }

            .card-body {
                padding: 2rem !important;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-6 col-lg-5">
                    <div class="auth-card">
                        <div class="auth-header">
                            <i class="fas fa-graduation-cap"></i>
                            <h1 class="mb-2">Welcome Back</h1>
                            <p class="mb-0">Sign in to continue your ETEEAPRO journey</p>
                        </div>
                        
                        <div class="card-body">
                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <ul class="mb-0">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($success_message): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <?php echo htmlspecialchars($success_message); ?>
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST" action="">
                                <div class="mb-3">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope me-2"></i>Email Address
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo $remembered_email ? htmlspecialchars($remembered_email) : (isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''); ?>" 
                                           placeholder="Enter your email"
                                           required autofocus>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="password" class="form-label">
                                        <i class="fas fa-lock me-2"></i>Password
                                    </label>
                                    <div class="position-relative">
                                        <input type="password" class="form-control" id="password" name="password" 
                                               placeholder="Enter your password"
                                               required>
                                        <button type="button" class="btn btn-outline-secondary position-absolute end-0 top-0 h-100 px-3" 
                                                onclick="togglePassword()" style="border-top-left-radius: 0; border-bottom-left-radius: 0;">
                                            <i class="fas fa-eye" id="toggleIcon"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me" <?php echo $remembered_email ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="remember_me">
                                            Remember me
                                        </label>
                                    </div>
                                    <a href="forgot-password.php" class="text-muted" style="font-size: 0.9rem;">
                                        Forgot Password?
                                    </a>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-sign-in-alt me-2"></i>Sign In
                                    </button>
                                </div>
                            </form>
                            
                            <div class="divider">
                                <span>OR</span>
                            </div>
                            
                            <div class="text-center">
                                <p class="mb-3 text-muted">Don't have an account?</p>
                                <a href="register.php" class="btn btn-outline-primary">
                                    <i class="fas fa-user-plus me-2"></i>Create Account
                                </a>
                            </div>
                            
                            <div class="text-center mt-4">
                                <a href="../index.php" class="text-muted">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Homepage
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        // Auto-focus email field if empty
        document.addEventListener('DOMContentLoaded', function() {
            const emailField = document.getElementById('email');
            if (!emailField.value) {
                emailField.focus();
            }
        });
    </script>
</body>
</html>
