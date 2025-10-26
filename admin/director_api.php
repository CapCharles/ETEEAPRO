<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    try {
        $pdo->beginTransaction();
        
        $allow_applications = isset($_POST['allow_applications']) ? '1' : '0';
        $restriction_message = trim($_POST['application_restriction_message']);
        
        // Update allow_applications setting
        $stmt = $pdo->prepare("
            UPDATE system_settings 
            SET setting_value = ?, updated_by = ?, updated_at = NOW() 
            WHERE setting_key = 'allow_applications'
        ");
        $stmt->execute([$allow_applications, $user_id]);
        
        // Update restriction message
        $stmt = $pdo->prepare("
            UPDATE system_settings 
            SET setting_value = ?, updated_by = ?, updated_at = NOW() 
            WHERE setting_key = 'application_restriction_message'
        ");
        $stmt->execute([$restriction_message, $user_id]);
        
        $pdo->commit();
        
        $_SESSION['success_message'] = "Settings updated successfully!";
        header('Location: settings.php');
        exit();
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Failed to update settings. Please try again.";
    }
}

// Get current settings
$settings = [];
try {
    $stmt = $pdo->query("SELECT * FROM system_settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row;
    }
} catch (PDOException $e) {
    $settings = [];
}

$allow_applications = isset($settings['allow_applications']) ? $settings['allow_applications']['setting_value'] : '1';
$restriction_message = isset($settings['application_restriction_message']) ? $settings['application_restriction_message']['setting_value'] : 'Application submission is currently closed.';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - ETEEAP</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            margin: 0;
            padding-top: 0 !important;
        }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 0;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 1rem 1.5rem;
            border-radius: 0;
            transition: all 0.3s ease;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .main-content {
            padding: 0;
        }
        .page-header {
            background: white;
            padding: 1.5rem 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .settings-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        .form-switch {
            padding-left: 2.5em;
        }
        .form-switch .form-check-input {
            width: 3em;
            height: 1.5em;
            cursor: pointer;
        }
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        .status-active {
            background-color: #198754;
            animation: pulse 2s infinite;
        }
        .status-inactive {
            background-color: #dc3545;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0 sidebar">
                <div class="text-center py-4">
                    <h4 class="text-white">
                        <i class="fas fa-user-shield"></i> Admin Panel
                    </h4>
                    <p class="text-white-50 small mb-0"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                </div>
                
                <nav class="nav flex-column">
                    <a class="nav-link" href="dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a class="nav-link" href="evaluate.php">
                        <i class="fas fa-clipboard-check me-2"></i>Applications
                    </a>
                    <a class="nav-link" href="users.php">
                        <i class="fas fa-users me-2"></i>Users
                    </a>
                    <a class="nav-link" href="programs.php">
                        <i class="fas fa-graduation-cap me-2"></i>Programs
                    </a>
                    <a class="nav-link" href="reports.php">
                        <i class="fas fa-chart-bar me-2"></i>Reports
                    </a>
                    <a class="nav-link active" href="settings.php">
                        <i class="fas fa-cog me-2"></i>Settings
                    </a>
                    <a class="nav-link" href="../auth/logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </nav>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 px-0 main-content">
                <!-- Header -->
                <div class="page-header">
                    <div class="container-fluid px-4">
                        <div class="row align-items-center">
                            <div class="col">
                                <h2 class="mb-0">
                                    <i class="fas fa-cog text-primary me-2"></i>
                                    System Settings
                                </h2>
                                <p class="text-muted mb-0">Configure application submission controls</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Content Area -->
                <div class="container-fluid p-4">
                    <!-- Success/Error Messages -->
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php 
                            echo htmlspecialchars($_SESSION['success_message']); 
                            unset($_SESSION['success_message']);
                            ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php 
                            echo htmlspecialchars($_SESSION['error_message']); 
                            unset($_SESSION['error_message']);
                            ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Current Status Card -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="settings-card">
                                <h5 class="mb-3">
                                    <i class="fas fa-info-circle text-info me-2"></i>
                                    Current Status
                                </h5>
                                <div class="d-flex align-items-center">
                                    <span class="status-indicator <?php echo $allow_applications == '1' ? 'status-active' : 'status-inactive'; ?>"></span>
                                    <div>
                                        <h6 class="mb-0">Application Submission</h6>
                                        <p class="text-muted mb-0">
                                            <?php echo $allow_applications == '1' ? 'Currently OPEN' : 'Currently CLOSED'; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="settings-card">
                                <h5 class="mb-3">
                                    <i class="fas fa-clock text-warning me-2"></i>
                                    Last Updated
                                </h5>
                                <p class="mb-0">
                                    <?php 
                                    if (isset($settings['allow_applications']['updated_at'])) {
                                        echo date('F j, Y g:i A', strtotime($settings['allow_applications']['updated_at']));
                                    } else {
                                        echo 'Never';
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Settings Form -->
                    <div class="settings-card">
                        <h5 class="mb-4">
                            <i class="fas fa-sliders-h text-primary me-2"></i>
                            Application Control Settings
                        </h5>
                        
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-12">
                                    <!-- Enable/Disable Applications -->
                                    <div class="mb-4">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" 
                                                   id="allow_applications" name="allow_applications" 
                                                   <?php echo $allow_applications == '1' ? 'checked' : ''; ?>
                                                   onchange="toggleRestrictionMessage()">
                                            <label class="form-check-label" for="allow_applications">
                                                <strong>Allow New Application Submissions</strong>
                                                <p class="text-muted small mb-0">
                                                    When enabled, candidates can submit new applications. 
                                                    When disabled, the submission form will show the restriction message below.
                                                </p>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Restriction Message -->
                                    <div class="mb-4" id="restriction_message_container">
                                        <label for="application_restriction_message" class="form-label">
                                            <i class="fas fa-comment-alt me-1"></i>
                                            <strong>Restriction Message</strong>
                                        </label>
                                        <textarea class="form-control" 
                                                  id="application_restriction_message" 
                                                  name="application_restriction_message" 
                                                  rows="3" 
                                                  placeholder="Enter the message to display when applications are closed..."><?php echo htmlspecialchars($restriction_message); ?></textarea>
                                        <small class="form-text text-muted">
                                            This message will be displayed to candidates when application submission is disabled.
                                        </small>
                                    </div>

                                    <!-- Info Box -->
                                    <div class="alert alert-info" role="alert">
                                        <i class="fas fa-lightbulb me-2"></i>
                                        <strong>How it works:</strong>
                                        <ul class="mb-0 mt-2">
                                            <li>When disabled, candidates will not be able to submit new applications</li>
                                            <li>Existing draft applications can still be edited but not submitted</li>
                                            <li>The restriction message will be shown on the assessment page</li>
                                            <li>Only administrators can enable/disable this setting</li>
                                        </ul>
                                    </div>

                                    <!-- Submit Button -->
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <button type="submit" name="update_settings" class="btn btn-primary btn-lg px-5">
                                            <i class="fas fa-save me-2"></i>Save Settings
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <input type="hidden" name="update_settings" value="1">
                        </form>
                    </div>

                    <!-- Usage Guidelines -->
                    <div class="settings-card">
                        <h5 class="mb-3">
                            <i class="fas fa-question-circle text-success me-2"></i>
                            Usage Guidelines
                        </h5>
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="fas fa-check-circle text-success me-2"></i>When to Enable</h6>
                                <ul>
                                    <li>During regular admission periods</li>
                                    <li>When accepting new applications</li>
                                    <li>After system maintenance</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-times-circle text-danger me-2"></i>When to Disable</h6>
                                <ul>
                                    <li>Outside of admission periods</li>
                                    <li>During system maintenance</li>
                                    <li>When processing current applications</li>
                                    <li>During evaluation period</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleRestrictionMessage() {
            const checkbox = document.getElementById('allow_applications');
            const container = document.getElementById('restriction_message_container');
            const textarea = document.getElementById('application_restriction_message');
            
            if (checkbox.checked) {
                container.style.opacity = '0.5';
                textarea.disabled = true;
            } else {
                container.style.opacity = '1';
                textarea.disabled = false;
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleRestrictionMessage();
        });
    </script>
</body>
</html>