<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../config/email_config.php';

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
$token = isset($_GET['token']) ? $_GET['token'] : '';
$token_valid = false;
$user_email = '';

// Verify token
if (!empty($token)) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, email, first_name, last_name, reset_token_expiry 
            FROM users 
            WHERE reset_token = ?
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Check if token has expired
            if (strtotime($user['reset_token_expiry']) > time()) {
                $token_valid = true;
                $user_email = $user['email'];
            } else {
                $errors[] = "This password reset link has expired. Please request a new one.";
            }
        } else {
            $errors[] = "Invalid password reset link.";
        }
    } catch (PDOException $e) {
        $errors[] = "An error occurred. Please try again.";
    }
} else {
    $errors[] = "No reset token provided.";
}

// Process password reset
if ($_POST && $token_valid) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    if (empty($new_password)) {
        $errors[] = "Password is required";
    } elseif (strlen($new_password) < 6) {
        $errors[] = "Password must be at least 6 characters long";
    }
    
    if ($new_password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    // If no errors, update password
    if (empty($errors)) {
        try {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password and clear reset token
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password = ?, reset_token = NULL, reset_token_expiry = NULL 
                WHERE reset_token = ?
            ");
            $stmt->execute([$hashed_password, $token]);
            
            // Log the password reset
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO system_logs (user_id, action, ip_address, user_agent) 
                    VALUES (?, 'password_reset_completed', ?, ?)
                ");
                $stmt->execute([
                    $user['id'],
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
            } catch (PDOException $e) {
                // Log error but don't prevent password reset
            }
            
            $success_message = "Your password has been successfully reset! You can now login with your new password.";
            $token_valid = false; // Prevent form from showing again
            
        } catch (PDOException $e) {
            $errors[] = "Failed to reset password. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - ETEEAP</title>
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

        .form-control.is-valid {
            border-color: #28a745;
        }

        .form-control.is-invalid {
            border-color: #dc3545;
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

        .password-strength {
            margin-top: 0.5rem;
            font-size: 0.85rem;
        }

        .strength-weak { color: #dc3545; }
        .strength-medium { color: #ffc107; }
        .strength-strong { color: #28a745; }

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

        .position-relative .btn-outline-secondary {
            border: none;
            background: transparent;
            color: #666;
        }

        .position-relative .btn-outline-secondary:hover {
            color: #0066cc;
            background: transparent;
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
                            <i class="fas fa-lock-open"></i>
                            <h1 class="mb-2">Reset Password</h1>
                            <p class="mb-0">Create your new password</p>
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
                                <div class="text-center mt-3">
                                    <a href="forgot-password.php" class="btn btn-outline-primary">
                                        <i class="fas fa-redo me-2"></i>Request New Link
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($success_message): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <?php echo htmlspecialchars($success_message); ?>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="login.php" class="btn btn-primary">
                                        <i class="fas fa-sign-in-alt me-2"></i>Login Now
                                    </a>
                                </div>
                            <?php elseif ($token_valid): ?>
                                <div class="info-box">
                                    <i class="fas fa-info-circle"></i>
                                    <p><strong>Email:</strong> <?php echo htmlspecialchars($user_email); ?></p>
                                    <p class="mt-2 mb-0">Please enter your new password below. Make sure it's strong and secure!</p>
                                </div>
                                
                                <form method="POST" action="" id="resetForm">
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">
                                            <i class="fas fa-lock me-2"></i>New Password
                                        </label>
                                        <div class="position-relative">
                                            <input type="password" class="form-control" id="new_password" name="new_password" 
                                                   placeholder="Enter new password"
                                                   required autofocus>
                                            <button type="button" class="btn btn-outline-secondary position-absolute end-0 top-0 h-100 px-3" 
                                                    onclick="togglePassword('new_password', 'toggleIcon1')" 
                                                    style="border-top-left-radius: 0; border-bottom-left-radius: 0;">
                                                <i class="fas fa-eye" id="toggleIcon1"></i>
                                            </button>
                                        </div>
                                        <div id="passwordStrength" class="password-strength"></div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label for="confirm_password" class="form-label">
                                            <i class="fas fa-check-circle me-2"></i>Confirm Password
                                        </label>
                                        <div class="position-relative">
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                                   placeholder="Confirm new password"
                                                   required>
                                            <button type="button" class="btn btn-outline-secondary position-absolute end-0 top-0 h-100 px-3" 
                                                    onclick="togglePassword('confirm_password', 'toggleIcon2')" 
                                                    style="border-top-left-radius: 0; border-bottom-left-radius: 0;">
                                                <i class="fas fa-eye" id="toggleIcon2"></i>
                                            </button>
                                        </div>
                                        <div id="passwordMatch" class="password-strength"></div>
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Reset Password
                                        </button>
                                    </div>
                                </form>
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
        function togglePassword(fieldId, iconId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = document.getElementById(iconId);
            
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

        // Password strength checker
        document.getElementById('new_password')?.addEventListener('input', function() {
            const password = this.value;
            const strengthDiv = document.getElementById('passwordStrength');
            
            if (password.length === 0) {
                strengthDiv.innerHTML = '';
                this.classList.remove('is-valid', 'is-invalid');
                return;
            }
            
            let strength = 0;
            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/\d/.test(password)) strength++;
            if (/[^a-zA-Z\d]/.test(password)) strength++;
            
            if (password.length < 6) {
                strengthDiv.innerHTML = '<span class="strength-weak"><i class="fas fa-times-circle"></i> Too short (minimum 6 characters)</span>';
                this.classList.remove('is-valid');
                this.classList.add('is-invalid');
            } else if (strength <= 2) {
                strengthDiv.innerHTML = '<span class="strength-weak"><i class="fas fa-exclamation-circle"></i> Weak password</span>';
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            } else if (strength <= 3) {
                strengthDiv.innerHTML = '<span class="strength-medium"><i class="fas fa-check-circle"></i> Medium password</span>';
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            } else {
                strengthDiv.innerHTML = '<span class="strength-strong"><i class="fas fa-check-circle"></i> Strong password!</span>';
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            }
            
            // Check confirm password match
            const confirmPassword = document.getElementById('confirm_password').value;
            if (confirmPassword) {
                checkPasswordMatch();
            }
        });

        // Password match checker
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchDiv = document.getElementById('passwordMatch');
            const confirmField = document.getElementById('confirm_password');
            
            if (confirmPassword.length === 0) {
                matchDiv.innerHTML = '';
                confirmField.classList.remove('is-valid', 'is-invalid');
                return;
            }
            
            if (password === confirmPassword && password.length >= 6) {
                matchDiv.innerHTML = '<span class="strength-strong"><i class="fas fa-check-circle"></i> Passwords match</span>';
                confirmField.classList.remove('is-invalid');
                confirmField.classList.add('is-valid');
            } else {
                matchDiv.innerHTML = '<span class="strength-weak"><i class="fas fa-times-circle"></i> Passwords do not match</span>';
                confirmField.classList.remove('is-valid');
                confirmField.classList.add('is-invalid');
            }
        }

        document.getElementById('confirm_password')?.addEventListener('input', checkPasswordMatch);

        // Form validation
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long');
                return;
            }
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
                return;
            }
        });
    </script>
</body>
</html>
