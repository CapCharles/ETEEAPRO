<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

$sidebar_submitted_count = 0;
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM applications 
        WHERE application_status IN ('submitted', 'under_review')
    ");
    $sidebar_submitted_count = $stmt->fetch()['total'];
} catch (PDOException $e) {
    $sidebar_submitted_count = 0;
}

// Get subjects from database for bridging recommendations
$predefined_subjects = [];
if (!empty($current_application['program_id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                subject_code as code, 
                subject_name as name, 
                units,
                year_level,
                semester,
                1 as priority
            FROM subjects 
            WHERE program_id = ? AND status = 'active'
            ORDER BY year_level DESC, semester DESC, subject_name
        ");
        $stmt->execute([$current_application['program_id']]);
        $predefined_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching subjects: " . $e->getMessage());
        $predefined_subjects = [];
    }
}


$sidebar_pending_count = 0;
try {
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT u.id) as total 
        FROM users u
        INNER JOIN application_forms af ON u.id = af.user_id
        WHERE (u.application_form_status IS NULL OR u.application_form_status = 'pending' OR u.application_form_status NOT IN ('approved', 'rejected'))
    ");
    $sidebar_pending_count = $stmt->fetch()['total'];
} catch (PDOException $e) {
    $sidebar_pending_count = 0;
}

function getPendingReviewsCount($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT u.id) as total 
            FROM users u
            INNER JOIN application_forms af ON u.id = af.user_id
            WHERE (u.application_form_status IS NULL OR u.application_form_status = 'pending' 
                   OR u.application_form_status NOT IN ('approved', 'rejected'))
        ");
        return (int)$stmt->fetch()['total'];
    } catch (PDOException $e) {
        error_log("Error getting pending reviews count: " . $e->getMessage());
        return 0;
    }
}

// Create system_config table if it doesn't exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_config (
            id INT PRIMARY KEY AUTO_INCREMENT,
            config_key VARCHAR(255) UNIQUE NOT NULL,
            config_value TEXT,
            config_description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    // Table creation failed, but continue
}

// Handle settings update
if ($_POST && isset($_POST['update_settings'])) {
    $settings = [
        'system_name' => sanitizeInput($_POST['system_name']),
        'system_description' => sanitizeInput($_POST['system_description']),
        'contact_email' => sanitizeInput($_POST['contact_email']),
        'contact_phone' => sanitizeInput($_POST['contact_phone']),
        'max_file_size' => intval($_POST['max_file_size']),
        'allowed_file_types' => sanitizeInput($_POST['allowed_file_types']),
        'auto_evaluation' => isset($_POST['auto_evaluation']) ? '1' : '0',
        'email_notifications' => isset($_POST['email_notifications']) ? '1' : '0',
        'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0',
        'backup_enabled' => isset($_POST['backup_enabled']) ? '1' : '0',
        'session_timeout' => intval($_POST['session_timeout']),
        'password_expiry_days' => intval($_POST['password_expiry_days']),
        'max_login_attempts' => intval($_POST['max_login_attempts']),
        'scoring_threshold_qualified' => floatval($_POST['scoring_threshold_qualified']),
        'scoring_threshold_partial' => floatval($_POST['scoring_threshold_partial'])
    ];
    
    // Validation
    if (empty($settings['system_name'])) {
        $errors[] = "System name is required";
    }
    
    if (!empty($settings['contact_email']) && !validateEmail($settings['contact_email'])) {
        $errors[] = "Invalid contact email format";
    }
    
    if ($settings['max_file_size'] < 1) {
        $errors[] = "Maximum file size must be at least 1MB";
    }
    
    if ($settings['session_timeout'] < 5) {
        $errors[] = "Session timeout must be at least 5 minutes";
    }
    
    if ($settings['scoring_threshold_qualified'] <= $settings['scoring_threshold_partial']) {
        $errors[] = "Qualified threshold must be higher than partial qualification threshold";
    }
    
    // Save settings
    if (empty($errors)) {
        try {
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO system_config (config_key, config_value) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
                ");
                $stmt->execute([$key, $value]);
            }
            
            // Log activity
            logActivity($pdo, "settings_updated", $user_id);
            
            redirectWithMessage('settings.php', 'Settings updated successfully!', 'success');
            
        } catch (PDOException $e) {
            $errors[] = "Failed to save settings. Please try again.";
        }
    }
}

// Handle system actions
if ($_POST && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    switch ($action) {
      
                case 'clear_logs':
            try {
                // Clear old system logs (keep last 30 days)
                $stmt = $pdo->prepare("DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
                $stmt->execute();
                $deleted_count = $stmt->rowCount();
                
                logActivity($pdo, "logs_cleared", $user_id, null, null, null, ['deleted_count' => $deleted_count]);
                
                redirectWithMessage('settings.php', "Cleared $deleted_count old log entries.", 'success');
                
            } catch (PDOException $e) {
                $errors[] = "Failed to clear logs.";
            }
            break;
            
        case 'backup_database':
            try {
                // Create database backup (simplified version)
                $backup_file = 'eteeap_backup_' . date('Y-m-d_H-i-s') . '.sql';
                $backup_path = '../backups/' . $backup_file;
                
                // Create backups directory if it doesn't exist
                if (!file_exists('../backups/')) {
                    mkdir('../backups/', 0755, true);
                }
                
                // Simple backup (in production, use mysqldump)
                $tables = ['users', 'programs', 'applications', 'assessment_criteria', 'evaluations', 'documents', 'system_logs', 'system_config'];
                $backup_content = "-- ETEEAP Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
                
                foreach ($tables as $table) {
                    try {
                        $stmt = $pdo->query("SELECT * FROM $table");
                        $rows = $stmt->fetchAll();
                        
                        if (!empty($rows)) {
                            $backup_content .= "-- Table: $table\n";
                            foreach ($rows as $row) {
                                $columns = array_keys($row);
                                $values = array_map(function($v) { return $v === null ? 'NULL' : "'" . addslashes($v) . "'"; }, array_values($row));
                                $backup_content .= "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
                            }
                            $backup_content .= "\n";
                        }
                    } catch (PDOException $e) {
                        // Skip table if error
                    }
                }
                
                file_put_contents($backup_path, $backup_content);
                
                logActivity($pdo, "database_backup_created", $user_id, null, null, null, ['backup_file' => $backup_file]);
                
                redirectWithMessage('settings.php', "Database backup created: $backup_file", 'success');
                
            } catch (Exception $e) {
                $errors[] = "Failed to create database backup.";
            }
            break;
            
        case 'reset_statistics':
            try {
                // Reset application statistics (for demo purposes)
                $stmt = $pdo->prepare("UPDATE applications SET total_score = 0, recommendation = NULL, evaluation_date = NULL WHERE application_status NOT IN ('draft', 'submitted')");
                $stmt->execute();
                
                $stmt = $pdo->prepare("DELETE FROM evaluations");
                $stmt->execute();
                
                logActivity($pdo, "statistics_reset", $user_id);
                
                redirectWithMessage('settings.php', 'Application statistics have been reset.', 'success');
                
            } catch (PDOException $e) {
                $errors[] = "Failed to reset statistics.";
            }
            break;
    }
}

// Get current settings
$current_settings = [];
try {
    $stmt = $pdo->query("SELECT config_key, config_value FROM system_config");
    while ($row = $stmt->fetch()) {
        $current_settings[$row['config_key']] = $row['config_value'];
    }
} catch (PDOException $e) {
    // No settings yet
}

// Default settings
$default_settings = [
    'system_name' => 'ETEEAP Assessment Platform',
    'system_description' => 'Expanded Tertiary Education Equivalency and Accreditation Program',
    'contact_email' => 'admin@eteeap.edu',
    'contact_phone' => '',
    'max_file_size' => '5',
    'allowed_file_types' => 'pdf,jpg,jpeg,png',
    'auto_evaluation' => '0',
    'email_notifications' => '1',
    'maintenance_mode' => '0',
    'backup_enabled' => '1',
    'session_timeout' => '30',
    'password_expiry_days' => '90',
    'max_login_attempts' => '5',
    'scoring_threshold_qualified' => '75',
    'scoring_threshold_partial' => '60'
];

$settings = array_merge($default_settings, $current_settings);

// Get system statistics
$system_stats = [];
try {
    // Database size (approximate)
    $stmt = $pdo->query("
        SELECT 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS db_size_mb
        FROM information_schema.tables 
        WHERE table_schema = DATABASE()
    ");
    $result = $stmt->fetch();
    $system_stats['db_size'] = $result['db_size_mb'] . ' MB';
    
    // Log entries count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM system_logs");
    $system_stats['log_count'] = number_format($stmt->fetch()['count']);
    
    // Recent activity
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM system_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $system_stats['recent_activity'] = number_format($stmt->fetch()['count']);
    
    // Storage used (approximate)
    $upload_path = '../uploads/documents/';
    $total_size = 0;
    if (is_dir($upload_path)) {
        $files = glob($upload_path . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $total_size += filesize($file);
            }
        }
    }
    $system_stats['storage_used'] = formatFileSize($total_size);
    
    // System uptime (server uptime approximation)
    $uptime_file = '/proc/uptime';
    if (file_exists($uptime_file)) {
        $uptime = floatval(file_get_contents($uptime_file));
        $days = floor($uptime / 86400);
        $hours = floor(($uptime % 86400) / 3600);
        $system_stats['uptime'] = $days . 'd ' . $hours . 'h';
    } else {
        $system_stats['uptime'] = 'Unknown';
    }
    
} catch (Exception $e) {
    $system_stats = [
        'db_size' => 'Unknown',
        'log_count' => '0',
        'recent_activity' => '0',
        'storage_used' => 'Unknown',
        'uptime' => 'Unknown'
    ];
}

// Check for flash messages
$flash = getFlashMessage();
if ($flash) {
    if ($flash['type'] === 'success') {
        $success_message = $flash['message'];
    } else {
        $errors[] = $flash['message'];
    }
}
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
        }
             body { margin: 0; padding-top: 0 !important; }
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
        .sidebar .nav-link:hover {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.2);
        }

        .sidebar .nav-link .badge {
    font-size: 0.65rem;
    padding: 0.25em 0.5em;
     font-weight: 900;
}

.sidebar .nav-link:hover .badge {
    background-color: #ffc107 !important;
}

.sidebar .nav-link.active .badge {
    background-color: #fff !important;
    color: #667eea !important;
}

        .settings-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: none;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: none;
            text-align: center;
        }
        .setting-group {
            border-left: 4px solid #667eea;
            padding-left: 1rem;
            margin-bottom: 2rem;
        }
        .setting-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .danger-zone {
            border-left: 4px solid #dc3545;
            background: rgba(220, 53, 69, 0.05);
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #667eea;
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar">
                <div class="p-3">
                    <h4 class="text-white mb-4">
                        <i class="fas fa-graduation-cap me-2"></i>
                        ETEEAP Admin
                    </h4>
                    
                    <nav class="nav flex-column">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i>
                            Dashboard
                        </a>
                        <a class="nav-link" href="application-reviews.php">
        <i class="fas fa-file-signature me-2"></i>
        Application Reviews
            <?php if ($sidebar_pending_count > 0): ?>
        <span class="badge bg-warning rounded-pill float-end"><?php echo $sidebar_pending_count; ?></span>
        <?php endif; ?>
    </a>

    <a class="nav-link" href="evaluate.php">
        <i class="fas fa-clipboard-check me-2"></i>
        Evaluate Applications
             <?php if ($sidebar_submitted_count > 0): ?>
        <span class="badge bg-warning rounded-pill float-end"><?php echo $sidebar_submitted_count; ?></span>
        <?php endif; ?>
    </a>
                       
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar me-2"></i>
                            Reports
                        </a>
                        <a class="nav-link" href="users.php">
                            <i class="fas fa-users me-2"></i>
                            Manage Users
                        </a>
                        <a class="nav-link" href="programs.php">
                            <i class="fas fa-graduation-cap me-2"></i>
                            Manage Programs
                        </a>
                        <a class="nav-link" href="settings.php">
                            <i class="fas fa-cog me-2"></i>
                            Settings
                        </a>
                    </nav>
                </div>
                
                <div class="mt-auto p-3">
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" 
                           id="dropdownUser" data-bs-toggle="dropdown">
                            <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-2" 
                                 style="width: 32px; height: 32px;">
                                <i class="fas fa-user text-dark"></i>
                            </div>
                            <span class="small"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark text-small shadow">
                            <li><a class="dropdown-item" href="../candidates/profile.php">Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../auth/logout.php">Sign out</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="p-4">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 class="mb-1">System Settings</h2>
                            <p class="text-muted mb-0">Configure system parameters and preferences</p>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-info" onclick="location.reload()">
                                <i class="fas fa-sync me-1"></i>Refresh
                            </button>
                        </div>
                    </div>

                    <!-- Messages -->
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

                    <!-- System Statistics -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-database fa-2x text-primary mb-2"></i>
                                <div class="h5 text-primary"><?php echo $system_stats['db_size']; ?></div>
                                <div class="text-muted">Database Size</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-list-alt fa-2x text-info mb-2"></i>
                                <div class="h5 text-info"><?php echo $system_stats['log_count']; ?></div>
                                <div class="text-muted">Log Entries</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-hdd fa-2x text-warning mb-2"></i>
                                <div class="h5 text-warning"><?php echo $system_stats['storage_used']; ?></div>
                                <div class="text-muted">Storage Used</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-clock fa-2x text-success mb-2"></i>
                                <div class="h5 text-success"><?php echo $system_stats['uptime']; ?></div>
                                <div class="text-muted">System Uptime</div>
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="">
                        <div class="row g-4">
                            <!-- General Settings -->
                            <div class="col-lg-6">
                                <div class="settings-card p-4">
                                    <div class="setting-group">
                                        <h5 class="mb-3">
                                            <i class="fas fa-cog me-2"></i>General Settings
                                        </h5>
                                        
                                        <div class="setting-item">
                                            <label for="system_name" class="form-label fw-bold">System Name</label>
                                            <input type="text" class="form-control" id="system_name" name="system_name" 
                                                   value="<?php echo htmlspecialchars($settings['system_name']); ?>">
                                            <div class="form-text">Display name for the system</div>
                                        </div>
                                        
                                        <div class="setting-item">
                                            <label for="system_description" class="form-label fw-bold">System Description</label>
                                            <textarea class="form-control" id="system_description" name="system_description" rows="2"><?php echo htmlspecialchars($settings['system_description']); ?></textarea>
                                            <div class="form-text">Brief description of the system</div>
                                        </div>
                                        
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="setting-item">
                                                    <label for="contact_email" class="form-label fw-bold">Contact Email</label>
                                                    <input type="email" class="form-control" id="contact_email" name="contact_email" 
                                                           value="<?php echo htmlspecialchars($settings['contact_email']); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="setting-item">
                                                    <label for="contact_phone" class="form-label fw-bold">Contact Phone</label>
                                                    <input type="tel" class="form-control" id="contact_phone" name="contact_phone" 
                                                           value="<?php echo htmlspecialchars($settings['contact_phone']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- File Upload Settings -->
                                    <div class="setting-group">
                                        <h6 class="mb-3">
                                            <i class="fas fa-upload me-2"></i>File Upload Settings
                                        </h6>
                                        
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="setting-item">
                                                    <label for="max_file_size" class="form-label fw-bold">Max File Size (MB)</label>
                                                    <input type="number" class="form-control" id="max_file_size" name="max_file_size" 
                                                           min="1" max="100" value="<?php echo htmlspecialchars($settings['max_file_size']); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="setting-item">
                                                    <label for="allowed_file_types" class="form-label fw-bold">Allowed File Types</label>
                                                    <input type="text" class="form-control" id="allowed_file_types" name="allowed_file_types" 
                                                           value="<?php echo htmlspecialchars($settings['allowed_file_types']); ?>">
                                                    <div class="form-text">Comma separated: pdf,jpg,png</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Scoring Settings -->
                                    <div class="setting-group">
                                        <h6 class="mb-3">
                                            <i class="fas fa-star me-2"></i>Scoring Thresholds
                                        </h6>
                                        
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="setting-item">
                                                    <label for="scoring_threshold_qualified" class="form-label fw-bold">Qualified Threshold (%)</label>
                                                    <input type="number" class="form-control" id="scoring_threshold_qualified" name="scoring_threshold_qualified" 
                                                           min="0" max="100" step="0.1" value="<?php echo $settings['scoring_threshold_qualified']; ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="setting-item">
                                                    <label for="scoring_threshold_partial" class="form-label fw-bold">Partial Qualified Threshold (%)</label>
                                                    <input type="number" class="form-control" id="scoring_threshold_partial" name="scoring_threshold_partial" 
                                                           min="0" max="100" step="0.1" value="<?php echo $settings['scoring_threshold_partial']; ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- System Features & Security -->
                            <div class="col-lg-6">
                                <div class="settings-card p-4">
                                    <div class="setting-group">
                                        <h5 class="mb-3">
                                            <i class="fas fa-toggle-on me-2"></i>System Features
                                        </h5>
                                        
                                        <div class="setting-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="fw-bold">Auto Evaluation</div>
                                                <small class="text-muted">Automatically process evaluations when submitted</small>
                                            </div>
                                            <label class="switch">
                                                <input type="checkbox" name="auto_evaluation" <?php echo $settings['auto_evaluation'] ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                        
                                        <div class="setting-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="fw-bold">Email Notifications</div>
                                                <small class="text-muted">Send email notifications for status updates</small>
                                            </div>
                                            <label class="switch">
                                                <input type="checkbox" name="email_notifications" <?php echo $settings['email_notifications'] ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                        
                                        <div class="setting-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="fw-bold">Maintenance Mode</div>
                                                <small class="text-muted">Put system in maintenance mode</small>
                                            </div>
                                            <label class="switch">
                                                <input type="checkbox" name="maintenance_mode" <?php echo $settings['maintenance_mode'] ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                        
                                        <div class="setting-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="fw-bold">Automatic Backups</div>
                                                <small class="text-muted">Enable automatic database backups</small>
                                            </div>
                                            <label class="switch">
                                                <input type="checkbox" name="backup_enabled" <?php echo $settings['backup_enabled'] ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Security Settings -->
                                    <div class="setting-group">
                                        <h6 class="mb-3">
                                            <i class="fas fa-shield-alt me-2"></i>Security Settings
                                        </h6>
                                        
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="setting-item">
                                                    <label for="session_timeout" class="form-label fw-bold">Session Timeout (minutes)</label>
                                                    <input type="number" class="form-control" id="session_timeout" name="session_timeout" 
                                                           min="5" max="480" value="<?php echo $settings['session_timeout']; ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="setting-item">
                                                    <label for="max_login_attempts" class="form-label fw-bold">Max Login Attempts</label>
                                                    <input type="number" class="form-control" id="max_login_attempts" name="max_login_attempts" 
                                                           min="3" max="10" value="<?php echo $settings['max_login_attempts']; ?>">
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="setting-item">
                                                    <label for="password_expiry_days" class="form-label fw-bold">Password Expiry (days)</label>
                                                    <input type="number" class="form-control" id="password_expiry_days" name="password_expiry_days" 
                                                           min="30" max="365" value="<?php echo $settings['password_expiry_days']; ?>">
                                                    <div class="form-text">Set to 0 to disable password expiry</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- System Actions -->
                                    <div class="setting-group danger-zone">
                                        <h6 class="mb-3 text-danger">
                                            <i class="fas fa-exclamation-triangle me-2"></i>System Actions
                                        </h6>
                                        
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <button type="button" class="btn btn-outline-info btn-sm w-100" onclick="performAction('backup_database')">
                                                    <i class="fas fa-download me-1"></i>Backup Database
                                                </button>
                                            </div>
                                            <div class="col-md-6">
                                                <button type="button" class="btn btn-outline-warning btn-sm w-100" onclick="performAction('clear_logs')">
                                                    <i class="fas fa-trash me-1"></i>Clear Old Logs
                                                </button>
                                            </div>
                                            <div class="col-12">
                                                <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="performAction('reset_statistics')">
                                                    <i class="fas fa-refresh me-1"></i>Reset Statistics
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="alert alert-warning mt-3 small mb-0">
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            <strong>Warning:</strong> Some actions cannot be undone. Use with caution.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Save Settings -->
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="settings-card p-4 text-center">
                                    <button type="submit" name="update_settings" class="btn btn-primary btn-lg px-5">
                                        <i class="fas fa-save me-2"></i>Save All Settings
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-lg px-5 ms-3" onclick="location.reload()">
                                        <i class="fas fa-times me-2"></i>Cancel Changes
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Form (Hidden) -->
    <form id="actionForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" id="actionInput">
    </form>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function performAction(action) {
            const actionNames = {
                'backup_database': 'create a database backup',
                'clear_logs': 'clear old system logs',
                'reset_statistics': 'reset all application statistics'
            };
            
            const actionName = actionNames[action] || action;
            
            if (confirm(`Are you sure you want to ${actionName}? This action may take a few moments.`)) {
                document.getElementById('actionInput').value = action;
                document.getElementById('actionForm').submit();
            }
        }
        
        // Auto-save indication
        let hasChanges = false;
        document.querySelectorAll('input, textarea, select').forEach(element => {
            element.addEventListener('change', function() {
                hasChanges = true;
            });
        });
        
        // Warn before leaving if there are unsaved changes
        window.addEventListener('beforeunload', function(e) {
            if (hasChanges) {
                <?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

// Create system_config table if it doesn't exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_config (
            id INT PRIMARY KEY AUTO_INCREMENT,
            config_key VARCHAR(255) UNIQUE NOT NULL,
            config_value TEXT,
            config_description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    // Table creation failed, but continue
}

// Handle settings update
if ($_POST && isset($_POST['update_settings'])) {
    $settings = [
        'system_name' => sanitizeInput($_POST['system_name']),
        'system_description' => sanitizeInput($_POST['system_description']),
        'contact_email' => sanitizeInput($_POST['contact_email']),
        'contact_phone' => sanitizeInput($_POST['contact_phone']),
        'max_file_size' => intval($_POST['max_file_size']),
        'allowed_file_types' => sanitizeInput($_POST['allowed_file_types']),
        'auto_evaluation' => isset($_POST['auto_evaluation']) ? '1' : '0',
        'email_notifications' => isset($_POST['email_notifications']) ? '1' : '0',
        'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0',
        'backup_enabled' => isset($_POST['backup_enabled']) ? '1' : '0',
        'session_timeout' => intval($_POST['session_timeout']),
        'password_expiry_days' => intval($_POST['password_expiry_days']),
        'max_login_attempts' => intval($_POST['max_login_attempts']),
        'scoring_threshold_qualified' => floatval($_POST['scoring_threshold_qualified']),
        'scoring_threshold_partial' => floatval($_POST['scoring_threshold_partial'])
    ];
    
    // Validation
    if (empty($settings['system_name'])) {
        $errors[] = "System name is required";
    }
    
    if (!empty($settings['contact_email']) && !validateEmail($settings['contact_email'])) {
        $errors[] = "Invalid contact email format";
    }
    
    if ($settings['max_file_size'] < 1) {
        $errors[] = "Maximum file size must be at least 1MB";
    }
    
    if ($settings['session_timeout'] < 5) {
        $errors[] = "Session timeout must be at least 5 minutes";
    }
    
    if ($settings['scoring_threshold_qualified'] <= $settings['scoring_threshold_partial']) {
        $errors[] = "Qualified threshold must be higher than partial qualification threshold";
    }
    
    // Save settings
    if (empty($errors)) {
        try {
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO system_config (config_key, config_value) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
                ");
                $stmt->execute([$key, $value]);
            }
            
            // Log activity
            logActivity($pdo, "settings_updated", $user_id);
            
            redirectWithMessage('settings.php', 'Settings updated successfully!', 'success');
            
        } catch (PDOException $e) {
            $errors[] = "Failed to save settings. Please try again.";
        }
    }
}