<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../config/email_config.php';
include_once '../includes/email_pass_reset.php';

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

if ($_POST) {
    $email = trim($_POST['email']);
    
    // Validation
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    }
    
    // If no validation errors, process password reset request
    if (empty($errors)) {
        try {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT id, email, first_name, last_name FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Generate reset token
                $reset_token = bin2hex(random_bytes(32));
                $reset_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Store reset token in database
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET reset_token = ?, reset_token_expiry = ? 
                    WHERE email = ?
                ");
                $stmt->execute([$reset_token, $reset_expiry, $email]);
                
                // Construct reset link - USING YOUR ACTUAL DOMAIN
                $reset_link = "https://eteeapro.site/auth/reset-password.php?token=" . $reset_token;
                
                // Send password reset email
                sendPasswordResetEmail($email, $user['first_name'] . ' ' . $user['last_name'], $reset_link, $reset_token);
                
                // Log the password reset request
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO system_logs (user_id, action, ip_address, user_agent) 
                        VALUES (?, 'password_reset_request', ?, ?)
                    ");
                    $stmt->execute([
                        $user['id'],
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                } catch (PDOException $e) {
                    // Log error but don't prevent reset request
                }
                
                $success_message = "Password reset instructions have been sent to your email address. Please check your inbox and spam folder.";
            } else {
                // Don't reveal if email exists or not for security
                $success_message = "If an account exists with this email, you will receive password reset instructions shortly.";
            }
        } catch (PDOException $e) {
            $errors[] = "An error occurred. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - ETEEAP</title>
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
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                opacity: 0.5;
            }
            50% {
                transform: scale(1.1);
                opacity: 0.8;
            }
        }

        .auth-header i {
            font-size: 3.5rem;
            margin-bottom: 1rem;
            display: inline-block;
            animation: float 3s ease-in-out infinite;
            position: relative;
            z-index: 1;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
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
            opacity: 0.95;
            position: relative;
            z-index: 1;
        }

        .card-body {
            padding: 2.5rem;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
        }

        .form-label i {
            color: #0066cc;
            width: 20px;
        }

        .form-control {
            padding: 0.85rem 1.1rem;
            border: 2px solid #e3f2fd;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            border-color: #0066cc;
            box-shadow: 0 0 0 4px rgba(0, 102, 204, 0.1);
            background: white;
            outline: none;
        }

        .form-control::placeholder {
            color: #bbb;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0066cc, #0052a3);
            border: none;
            padding: 0.95rem;
            font-size: 1.05rem;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 102, 204, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(0, 102, 204, 0.4);
            background: linear-gradient(135deg, #0052a3, #003d7a);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-outline-primary {
            border: 2px solid #0066cc;
            color: #0066cc;
            padding: 0.85rem 2rem;
            font-weight: 600;
            border-radius: 12px;
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

        .alert-info {
            background: linear-gradient(145deg, #e3f2fd, #bbdefb);
            color: #0277bd;
            border-left: 4px solid #0288d1;
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

        .info-box {
            background: linear-gradient(145deg, #f0f9ff, #e0f2fe);
            border-left: 4px solid #0066cc;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .info-box i {
            color: #0066cc;
            font-size: 1.2rem;
            margin-right: 0.75rem;
        }

        .info-box p {
            margin: 0;
            color: #555;
            font-size: 0.95rem;
            line-height: 1.6;
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
                            <i class="fas fa-key"></i>
                            <h1 class="mb-2">Forgot Password?</h1>
                            <p class="mb-0">No worries, we'll send you reset instructions</p>
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
                                <div class="text-center mt-3">
                                    <a href="login.php" class="btn btn-primary">
                                        <i class="fas fa-arrow-left me-2"></i>Back to Login
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="info-box">
                                    <i class="fas fa-info-circle"></i>
                                    <p>Enter your email address and we'll send you instructions to reset your password.</p>
                                </div>
                                
                                <form method="POST" action="">
                                    <div class="mb-4">
                                        <label for="email" class="form-label">
                                            <i class="fas fa-envelope me-2"></i>Email Address
                                        </label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                                               placeholder="Enter your registered email"
                                               required autofocus>
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-paper-plane me-2"></i>Send Reset Instructions
                                        </button>
                                    </div>
                                </form>
                                
                                <div class="divider">
                                    <span>OR</span>
                                </div>
                                
                                <div class="text-center">
                                    <p class="mb-3 text-muted">Remember your password?</p>
                                    <a href="login.php" class="btn btn-outline-primary">
                                        <i class="fas fa-sign-in-alt me-2"></i>Back to Login
                                    </a>
                                </div>
                            <?php endif; ?>
                            
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
        // Auto-focus email field
        document.addEventListener('DOMContentLoaded', function() {
            const emailField = document.getElementById('email');
            if (emailField && !emailField.value) {
                emailField.focus();
            }
        });
    </script>
</body>
</html>
