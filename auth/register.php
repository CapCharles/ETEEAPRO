<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
include_once '../includes/email_notifications.php';


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

$programs = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, program_name, program_code, description
        FROM programs
        WHERE status = 'active'
        ORDER BY program_name
    ");
    $stmt->execute();
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $programs = [];
}
if ($_POST) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $middle_name = trim($_POST['middle_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $preferred_program_id = $_POST['preferred_program'] ?? '';
$secondary_program_id = $_POST['secondary_program'] ?? '';
    
    // Validation
    if (empty($first_name)) {
        $errors[] = "First name is required";
    }
    
    if (empty($last_name)) {
        $errors[] = "Last name is required";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }

    if (empty($preferred_program_id)) {
    $errors[] = "Please select your preferred program";
}
if (!empty($secondary_program_id) && $preferred_program_id === $secondary_program_id) {
    $errors[] = "Secondary program must be different from preferred program";
}
    
    // File upload validation function
    function validateFileUpload($file_key, $file_label, $max_size_mb = 10, $allowed_types = ['application/pdf']) {
        global $errors;
        
        if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Please upload the {$file_label}";
            return false;
        }
        
        $file = $_FILES[$file_key];
        $file_size = $file['size'];
        $file_type = $file['type'];
        $original_name = $file['name'];
        
        // Validate file size
        if ($file_size > $max_size_mb * 1024 * 1024) {
            $errors[] = "{$file_label} file size must be less than {$max_size_mb}MB";
            return false;
        }
        
        // Validate file type
        if (!in_array($file_type, $allowed_types)) {
            $allowed_extensions = [];
            foreach ($allowed_types as $type) {
                if ($type === 'application/pdf') $allowed_extensions[] = 'PDF';
                if ($type === 'application/msword') $allowed_extensions[] = 'DOC';
                if ($type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') $allowed_extensions[] = 'DOCX';
            }
            $errors[] = "{$file_label} must be in " . implode(' or ', $allowed_extensions) . " format only";
            return false;
        }
        
        return true;
    }
    
    // File types allowed for all documents
    $allowed_doc_types = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    
    // Validate ETEEAP Application Form Upload
    validateFileUpload('eteeap_form', 'completed ETEEAP Application Form', 10, $allowed_doc_types);
    
    // Validate Application Letter Upload
    validateFileUpload('application_letter', 'Application Letter', 5, $allowed_doc_types);
    
    // Validate CV Upload
    validateFileUpload('curriculum_vitae', 'Curriculum Vitae (CV)', 5, $allowed_doc_types);
    
    // Additional filename validation for application form
    if (isset($_FILES['eteeap_form']) && $_FILES['eteeap_form']['error'] === UPLOAD_ERR_OK) {
        $original_name = $_FILES['eteeap_form']['name'];
        if (!preg_match('/eteeap|application/i', $original_name)) {
            $errors[] = "Please ensure the ETEEAP form filename indicates this is an ETEEAP application form";
        }
    }
    
    // Check if email already exists
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = "Email already exists";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error occurred";
        }
    }
    
    // If no errors, create user with pending status
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Create user with 'pending' status (not 'active')
            $stmt = $pdo->prepare("
                INSERT INTO users (first_name, last_name, middle_name, email, phone, address, password, user_type, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'candidate', 'pending')
            ");
            
            $stmt->execute([
                $first_name,
                $last_name,
                $middle_name,
                $email,
                $phone,
                $address,
                $hashed_password
            ]);
            
            $user_id = $pdo->lastInsertId();
            
            // === Save program preferences (AFTER creating the user, BEFORE file uploads) ===
$stmt = $pdo->prepare("
    INSERT INTO user_program_preferences (user_id, preferred_program_id, secondary_program_id)
    VALUES (?, ?, ?)
");
$stmt->execute([
    (int)$user_id,
    (int)$preferred_program_id,
    $secondary_program_id !== '' ? (int)$secondary_program_id : null
]);

            // Handle multiple file uploads
            $upload_dir = '../uploads/application_forms/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $uploaded_files = [];
            
            // Files to process
            $files_to_upload = [
                'eteeap_form' => 'ETEEAP Application Form',
                'application_letter' => 'Application Letter', 
                'curriculum_vitae' => 'Curriculum Vitae'
            ];
            
            foreach ($files_to_upload as $file_key => $file_description) {
                if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES[$file_key];
                    $original_name = $file['name'];
                    $file_size = $file['size'];
                    
                    // Generate secure filename
                    $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
                    $stored_filename = $file_key . '_' . $user_id . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $stored_filename;
                    
                    // Move uploaded file
                    if (move_uploaded_file($file['tmp_name'], $file_path)) {
                        $uploaded_files[] = [
                            'user_id' => $user_id,
                            'file_type' => $file_key,
                            'file_description' => $file_description,
                            'original_filename' => $original_name,
                            'stored_filename' => $stored_filename,
                            'file_path' => $file_path,
                            'file_size' => $file_size
                        ];
                    } else {
                        throw new Exception("Failed to upload {$file_description}");
                    }
                }
            }
            
            // Save all uploaded files to database
            foreach ($uploaded_files as $file_data) {
                $stmt = $pdo->prepare("
                    INSERT INTO application_forms (
                        user_id, file_type, file_description, original_filename, 
                        stored_filename, file_path, file_size, upload_date, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'pending_review')
                ");
                $stmt->execute([
                    $file_data['user_id'],
                    $file_data['file_type'],
                    $file_data['file_description'],
                    $file_data['original_filename'],
                    $file_data['stored_filename'],
                    $file_data['file_path'],
                    $file_data['file_size']
                ]);
            }
            
            $pdo->commit();

            sendRegistrationNotification($email, $first_name . ' ' . $last_name);
notifyAdminNewRegistration($first_name . ' ' . $last_name, $email);
            
            $success_message = "Registration submitted successfully! Your application and all required documents are under review. You will be notified via email once your account is approved and you can log in.";
            
            // Clear form data
            $_POST = [];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Registration failed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - ETEEAP</title>
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
            padding: 3rem 0;
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
            padding: 2.5rem 2rem;
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

        .auth-header h2 {
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

        .card-body {
            padding: 2.5rem !important;
        }

        .form-label {
            color: #003d82;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 16px;
            border: 2px solid #e3f2fd;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            background: #f8fbff;
        }

        .form-control:focus, .form-select:focus {
            border-color: #0066cc;
            box-shadow: 0 0 0 0.25rem rgba(0, 102, 204, 0.15);
            background: white;
            outline: none;
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

        .btn-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
            border: none;
            color: white;
            border-radius: 10px;
            padding: 8px 16px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(23, 162, 184, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745, #218838);
            border: none;
            border-radius: 12px;
            padding: 10px 24px;
            font-weight: 600;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.3);
        }

        .btn-outline-success {
            border: 2px solid #28a745;
            color: #28a745;
            border-radius: 12px;
            padding: 10px 24px;
            font-weight: 600;
        }

        .btn-outline-success:hover {
            background: #28a745;
            color: white;
            transform: translateY(-2px);
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
            color: #01579b;
            border-left: 4px solid #0288d1;
        }

        .alert ul {
            margin: 0;
            padding-left: 1.5rem;
        }

        .file-upload-section {
            background: linear-gradient(145deg, #f5f9ff, #e8f4f8);
            border-radius: 16px;
            padding: 2rem;
            margin: 1.5rem 0;
            border: 2px solid #e3f2fd;
            box-shadow: 0 4px 15px rgba(0, 61, 130, 0.08);
        }

        .program-selection-section {
            background: linear-gradient(145deg, #ffffff, #f8fbff);
            border-radius: 16px;
            padding: 2rem;
            margin: 1.5rem 0;
            border: 2px solid #e3f2fd;
            box-shadow: 0 4px 15px rgba(0, 61, 130, 0.08);
        }

        .document-upload-card {
            border: 2px solid #e3f2fd;
            border-radius: 14px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            background: white;
            transition: all 0.3s ease;
        }

        .document-upload-card:hover {
            border-color: #0066cc;
            box-shadow: 0 6px 20px rgba(0, 102, 204, 0.12);
            transform: translateY(-2px);
        }

        .document-upload-card h6 {
            color: #003d82;
            margin-bottom: 1rem;
            font-weight: 600;
            font-size: 1.05rem;
        }

        .requirements-list {
            background: linear-gradient(145deg, #fff3e0, #ffe0b2);
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            border-left: 4px solid #f57c00;
        }

        .requirements-list h6 {
            color: #e65100;
            font-weight: 600;
        }

        .requirements-list ul {
            margin-bottom: 0;
        }

        .download-links {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin: 1rem 0;
        }

        .form-check-input:checked {
            background-color: #0066cc;
            border-color: #0066cc;
        }

        .form-check-label {
            color: #555;
            font-size: 0.95rem;
        }

        .text-muted {
            color: #666 !important;
            transition: color 0.3s ease;
        }

        .text-muted:hover {
            color: #0066cc !important;
            text-decoration: none;
        }

        .form-text {
            color: #666;
            font-size: 0.875rem;
        }

        @media (max-width: 768px) {
            .auth-header {
                padding: 2rem 1.5rem;
            }

            .auth-header h2 {
                font-size: 1.5rem;
            }

            .card-body {
                padding: 2rem !important;
            }

            .file-upload-section, .program-selection-section {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-10 col-lg-9">
                    <div class="auth-card">
                        <div class="auth-header">
                            <h2 class="mb-2">
                                <i class="fas fa-graduation-cap me-2"></i>
                                ETEEAP Registration
                            </h2>
                            <p class="mb-0">Create your account and upload all required documents</p>
                        </div>
                        
                        <div class="card-body">
                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Please fix the following errors:</strong>
                                    <ul class="mb-0 mt-2">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($success_message): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <strong>Success!</strong>
                                    <p class="mb-3 mt-2"><?php echo htmlspecialchars($success_message); ?></p>
                                    <div class="mt-3">
                                        <a href="login.php" class="btn btn-success">
                                            <i class="fas fa-sign-in-alt me-2"></i>Go to Login Page
                                        </a>
                                        <a href="../index.php" class="btn btn-outline-success ms-2">
                                            <i class="fas fa-home me-2"></i>Back to Homepage
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                            
                            <!-- Document Templates Download Section -->
                            <div class="alert alert-info">
                                <h6 class="mb-2"><i class="fas fa-download me-2"></i>Download Required Document Templates</h6>
                                <p class="mb-3">Before registering, download, complete, and upload ALL required documents.</p>
                                <div class="download-links">
                                    <a href="../auth/ETEEAP-Application-Form.docx" class="btn btn-info btn-sm" target="_blank">
                                        <i class="fas fa-file-pdf me-1"></i>Application Form
                                    </a>
                                    <a href="../auth/Application Letter-ETEEAP.docx" class="btn btn-info btn-sm" target="_blank">
                                        <i class="fas fa-file-word me-1"></i>Application Letter
                                    </a>
                                    <a href="../auth/Sample CV.docx" class="btn btn-info btn-sm" target="_blank">
                                        <i class="fas fa-file-word me-1"></i>CV Template
                                    </a>
                                </div>
                            </div>
                            
                            <form method="POST" action="" enctype="multipart/form-data">
                                <h5 class="mb-3 mt-4" style="color: #003d82;">
                                    <i class="fas fa-user-circle me-2"></i>Personal Information
                                </h5>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="first_name" class="form-label">
                                            <i class="fas fa-user me-2"></i>First Name *
                                        </label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" 
                                               placeholder="Enter your first name"
                                               value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" required>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="last_name" class="form-label">
                                            <i class="fas fa-user me-2"></i>Last Name *
                                        </label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" 
                                               placeholder="Enter your last name"
                                               value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" required>
                                    </div>
                                    
                                    <div class="col-12">
                                        <label for="middle_name" class="form-label">
                                            <i class="fas fa-user me-2"></i>Middle Name
                                        </label>
                                        <input type="text" class="form-control" id="middle_name" name="middle_name" 
                                               placeholder="Enter your middle name (optional)"
                                               value="<?php echo isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : ''; ?>">
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="email" class="form-label">
                                            <i class="fas fa-envelope me-2"></i>Email Address *
                                        </label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               placeholder="your.email@example.com"
                                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="phone" class="form-label">
                                            <i class="fas fa-phone me-2"></i>Phone Number
                                        </label>
                                        <input type="tel" class="form-control" id="phone" name="phone" 
                                               placeholder="+63 XXX XXX XXXX"
                                               value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                                    </div>
                                    
                                    <div class="col-12">
                                        <label for="address" class="form-label">
                                            <i class="fas fa-map-marker-alt me-2"></i>Address
                                        </label>
                                        <textarea class="form-control" id="address" name="address" rows="2" 
                                                  placeholder="Enter your complete address"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="password" class="form-label">
                                            <i class="fas fa-lock me-2"></i>Password *
                                        </label>
                                        <input type="password" class="form-control" id="password" name="password" 
                                               placeholder="Create a strong password" required>
                                        <div class="form-text">
                                            <i class="fas fa-info-circle me-1"></i>At least 6 characters
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="confirm_password" class="form-label">
                                            <i class="fas fa-lock me-2"></i>Confirm Password *
                                        </label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                               placeholder="Re-enter your password" required>
                                    </div>
                                </div>

                                <!-- Program Selection Section -->
                                <div class="program-selection-section mt-4">
                                    <h5 class="mb-3" style="color: #003d82;">
                                        <i class="fas fa-university me-2"></i>Program Selection *
                                    </h5>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="preferred_program" class="form-label">
                                                <i class="fas fa-star me-2 text-warning"></i>Preferred Program *
                                            </label>
                                            <select class="form-select" id="preferred_program" name="preferred_program" requireram" name="preferred_program" required>
                                                <option value="">Select your preferred program...</option>
                                                <?php foreach ($programs as $program): ?>
                                                    <option value="<?php echo $program['id']; ?>" 
                                                        <?php echo (isset($_POST['preferred_program']) && $_POST['preferred_program'] == $program['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($program['program_code']); ?> - <?php echo htmlspecialchars($program['program_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label for="secondary_program" class="form-label">
                                                <i class="fas fa-star-half-alt me-2 text-secondary"></i>Alternative Program (Optional)
                                            </label>
                                            <select class="form-select" id="secondary_program" name="secondary_program">
                                                <option value="">Select alternative program...</option>
                                                <?php foreach ($programs as $program): ?>
                                                    <option value="<?php echo $program['id']; ?>" 
                                                        <?php echo (isset($_POST['secondary_program']) && $_POST['secondary_program'] == $program['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($program['program_code']); ?> - <?php echo htmlspecialchars($program['program_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text">Choose a backup program in case your preferred program is full</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Required Documents Upload Section -->
                                <div class="file-upload-section mt-4">
                                    <h5 class="mb-3" style="color: #003d82;">
                                        <i class="fas fa-file-upload me-2"></i>
                                        Upload Required Documents *
                                    </h5>
                                    
                                    <!-- ETEEAP Application Form -->
                                    <div class="document-upload-card">
                                        <h6>
                                            <i class="fas fa-file-alt me-2 text-danger"></i>
                                            1. ETEEAP Application Form *
                                        </h6>
                                        <input type="file" class="form-control" id="eteeap_form" name="eteeap_form" 
                                               accept=".pdf,.doc,.docx" required>
                                        <div class="form-text mt-2">
                                            <i class="fas fa-info-circle me-1"></i>
                                            PDF, DOC, or DOCX format, maximum 10MB
                                        </div>
                                    </div>
                                    
                                    <!-- Application Letter -->
                                    <div class="document-upload-card">
                                        <h6>
                                            <i class="fas fa-file-alt me-2 text-primary"></i>
                                            2. Application Letter *
                                        </h6>
                                        <input type="file" class="form-control" id="application_letter" name="application_letter" 
                                               accept=".pdf,.doc,.docx" required>
                                        <div class="form-text mt-2">
                                            <i class="fas fa-info-circle me-1"></i>
                                            PDF, DOC, or DOCX format, maximum 5MB
                                        </div>
                                    </div>
                                    
                                    <!-- Curriculum Vitae -->
                                    <div class="document-upload-card">
                                        <h6>
                                            <i class="fas fa-file-alt me-2 text-success"></i>
                                            3. Curriculum Vitae (CV) *
                                        </h6>
                                        <input type="file" class="form-control" id="curriculum_vitae" name="curriculum_vitae" 
                                               accept=".pdf,.doc,.docx" required>
                                        <div class="form-text mt-2">
                                            <i class="fas fa-info-circle me-1"></i>
                                            PDF, DOC, or DOCX format, maximum 5MB
                                        </div>
                                    </div>
                                    
                                    <div class="requirements-list">
                                        <h6 class="mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Important Requirements:</h6>
                                        <ul class="small mb-0">
                                            <li>You must be at least 25 years old</li>
                                            <li>Must have at least 5 years of work experience in your field</li>
                                            <li>Must have a high school diploma or equivalent</li>
                                            <li>Must be proficient in your chosen field of study</li>
                                            <li>All information in the documents must be complete and accurate</li>
                                            <li>Documents can be uploaded in PDF, DOC, or DOCX format</li>
                                            <li>Use the provided DOCX templates as guide for content structure</li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="agree_terms" required>
                                    <label class="form-check-label" for="agree_terms">
                                        I certify that all information provided is true and complete. I understand that any false information may result in the rejection of my application. *
                                    </label>
                                </div>
                                
                                <div class="mt-4">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Registration & All Documents
                                    </button>
                                </div>
                            </form>
                            
                            <div class="text-center mt-4 pt-3" style="border-top: 2px solid #e3f2fd;">
                                <p class="mb-2 text-muted">Already have an approved account?</p>
                                <a href="login.php" class="btn btn-outline-primary">
                                    <i class="fas fa-sign-in-alt me-2"></i>Login Here
                                </a>
                            </div>
                            
                            <div class="text-center mt-3">
                                <a href="../index.php" class="text-muted">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Homepage
                                </a>
                            </div>
                            
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // File validation function
        function validateFile(input, maxSizeMB, allowedTypes, fileName) {
            const file = input.files[0];
            
            if (file) {
                // Check file size
                if (file.size > maxSizeMB * 1024 * 1024) {
                    alert(`${fileName} file size must be less than ${maxSizeMB}MB`);
                    input.value = '';
                    return false;
                }
                
                // Check file type
                if (!allowedTypes.includes(file.type)) {
                    const types = allowedTypes.map(type => {
                        if (type === 'application/pdf') return 'PDF';
                        if (type === 'application/msword') return 'DOC';
                        if (type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') return 'DOCX';
                        return type;
                    });
                    alert(`${fileName} must be in ${types.join(' or ')} format`);
                    input.value = '';
                    return false;
                }
                
                return true;
            }
            return false;
        }

        // ETEEAP Form validation
        document.getElementById('eteeap_form').addEventListener('change', function() {
            const allowedTypes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            
            if (validateFile(this, 10, allowedTypes, 'ETEEAP Application Form')) {
                const fileName = this.files[0].name.toLowerCase();
                
                // Suggest filename should contain eteeap or application
                if (!fileName.includes('eteeap') && !fileName.includes('application')) {
                    if (!confirm('We recommend the filename should indicate this is an ETEEAP application form. Continue anyway?')) {
                        this.value = '';
                        return;
                    }
                }
            }
        });

        // Application Letter validation
        document.getElementById('application_letter').addEventListener('change', function() {
            const allowedTypes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            validateFile(this, 5, allowedTypes, 'Application Letter');
        });

        // CV validation
        document.getElementById('curriculum_vitae').addEventListener('change', function() {
            const allowedTypes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            validateFile(this, 5, allowedTypes, 'Curriculum Vitae');
        });

        // Password strength and confirmation validation
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            
            if (password.length < 6) {
                this.classList.add('is-invalid');
                this.classList.remove('is-valid');
            } else {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            }
        });
        
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password === confirmPassword && password.length >= 6) {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            } else {
                this.classList.add('is-invalid');
                this.classList.remove('is-valid');
            }
        });
        
        // Form submission validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const agreeTerms = document.getElementById('agree_terms').checked;
            
            // Check all required file uploads
            const requiredFiles = [
                { id: 'eteeap_form', name: 'ETEEAP Application Form' },
                { id: 'application_letter', name: 'Application Letter' },
                { id: 'curriculum_vitae', name: 'Curriculum Vitae' }
            ];
            
            for (const fileCheck of requiredFiles) {
                const fileInput = document.getElementById(fileCheck.id);
                if (!fileInput.files || fileInput.files.length === 0) {
                    e.preventDefault();
                    alert(`Please upload your ${fileCheck.name}`);
                    return;
                }
            }
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
                return;
            }
            
            if (!agreeTerms) {
                e.preventDefault();
                alert('You must agree to the terms and conditions');
                return;
            }
        });

        // Program selection sync
        (function(){
            const preferred = document.getElementById('preferred_program');
            const secondary = document.getElementById('secondary_program');

            function syncSecondaryOptions() {
                const prefVal = preferred.value;
                Array.from(secondary.options).forEach(opt => {
                    opt.disabled = false;
                    opt.hidden = false;
                });
                if (prefVal) {
                    Array.from(secondary.options).forEach(opt => {
                        if (opt.value === prefVal && opt.value !== '') {
                            opt.disabled = true;
                            opt.hidden = true;
                        }
                    });
                    if (secondary.value === prefVal) {
                        secondary.value = '';
                    }
                }
            }

            window.addEventListener('DOMContentLoaded', syncSecondaryOptions);
            preferred.addEventListener('change', syncSecondaryOptions);
        })();
    </script>
</body>
</html>
