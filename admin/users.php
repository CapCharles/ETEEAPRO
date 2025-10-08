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


// Handle user actions
if ($_POST) {
    if (isset($_POST['add_user'])) {
        // Add new user
        $first_name = sanitizeInput($_POST['first_name']);
        $last_name = sanitizeInput($_POST['last_name']);
        $middle_name = sanitizeInput($_POST['middle_name']);
        $email = sanitizeInput($_POST['email']);
        $phone = sanitizeInput($_POST['phone']);
        $address = sanitizeInput($_POST['address']);
        $user_type = $_POST['user_type'];
        $password = $_POST['password'];
        $status = $_POST['status'];
        
        // Validation
        if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($user_type)) {
            $errors[] = "All required fields must be filled";
        }
        
        if (!validateEmail($email)) {
            $errors[] = "Invalid email format";
        }
        
        $password_check = checkPasswordStrength($password);
        if (!$password_check['valid']) {
            $errors[] = $password_check['message'];
        }
        
        // Check if email exists
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
        
        // Create user
        if (empty($errors)) {
            try {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("
                    INSERT INTO users (first_name, last_name, middle_name, email, phone, address, password, user_type, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $first_name, $last_name, $middle_name, $email, $phone, 
                    $address, $hashed_password, $user_type, $status
                ]);
                
                $new_user_id = $pdo->lastInsertId();
                
                // Log activity
                logActivity($pdo, "user_created", $user_id, "users", $new_user_id, null, [
                    'email' => $email,
                    'user_type' => $user_type,
                    'status' => $status
                ]);
                
                redirectWithMessage('users.php', 'User created successfully!', 'success');
                
            } catch (PDOException $e) {
                $errors[] = "Failed to create user. Please try again.";
            }
        }
    }
    
    elseif (isset($_POST['update_user'])) {
        // Update existing user
        $edit_user_id = $_POST['user_id'];
        $first_name = sanitizeInput($_POST['first_name']);
        $last_name = sanitizeInput($_POST['last_name']);
        $middle_name = sanitizeInput($_POST['middle_name']);
        $email = sanitizeInput($_POST['email']);
        $phone = sanitizeInput($_POST['phone']);
        $address = sanitizeInput($_POST['address']);
        $user_type = $_POST['user_type'];
        $status = $_POST['status'];
        $program_id = isset($_POST['program_id']) ? trim($_POST['program_id']) : '';

        
        // Validation
        if (empty($first_name) || empty($last_name) || empty($email) || empty($user_type)) {
            $errors[] = "All required fields must be filled";
        }
        
        if (!validateEmail($email)) {
            $errors[] = "Invalid email format";
        }
        
        // Check if email exists for other users
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $edit_user_id]);
                if ($stmt->fetch()) {
                    $errors[] = "Email already exists";
                }
            } catch (PDOException $e) {
                $errors[] = "Database error occurred";
            }
        }
        
       if (empty($errors)) {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE users 
               SET first_name = ?, last_name = ?, middle_name = ?, email = ?, 
                   phone = ?, address = ?, user_type = ?, status = ?
             WHERE id = ?
        ");
        $stmt->execute([
            $first_name, $last_name, $middle_name, $email,
            $phone, $address, $user_type, $status, $edit_user_id
        ]);

        // sync evaluator_programs
        $del = $pdo->prepare("DELETE FROM evaluator_programs WHERE evaluator_id = ?");
        $del->execute([$edit_user_id]);

        if ($user_type === 'evaluator' && $program_id !== '') {
            $ins = $pdo->prepare("INSERT INTO evaluator_programs (evaluator_id, program_id) VALUES (?, ?)");
            $ins->execute([$edit_user_id, (int)$program_id]);
        }

        $pdo->commit();

        logActivity($pdo, "user_updated", $user_id, "users", $edit_user_id);
        redirectWithMessage('users.php', 'User updated successfully!', 'success');
    } catch (PDOException $e) {
        $pdo->rollBack();
        $errors[] = "Failed to update user. Please try again.";
    }
}

    }
    
    elseif (isset($_POST['reset_password'])) {
        // Reset user password
        $reset_user_id = $_POST['user_id'];
        $new_password = $_POST['new_password'];
        
        $password_check = checkPasswordStrength($new_password);
        if (!$password_check['valid']) {
            $errors[] = $password_check['message'];
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $reset_user_id]);
                
                // Log activity
                logActivity($pdo, "password_reset", $user_id, "users", $reset_user_id);
                
                redirectWithMessage('users.php', 'Password reset successfully!', 'success');
                
            } catch (PDOException $e) {
                $errors[] = "Failed to reset password. Please try again.";
            }
        }
    }
}

// Handle user deletion
if (isset($_GET['delete'])) {
    $delete_user_id = $_GET['delete'];
    
    // Don't allow deleting self
    if ($delete_user_id == $user_id) {
        redirectWithMessage('users.php', 'Cannot delete your own account!', 'error');
    }
    
    try {
        // Get user info before deletion for logging
        $stmt = $pdo->prepare("SELECT email, user_type FROM users WHERE id = ?");
        $stmt->execute([$delete_user_id]);
        $user_to_delete = $stmt->fetch();
        
        if ($user_to_delete) {
            // Check if user has applications
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM applications WHERE user_id = ?");
            $stmt->execute([$delete_user_id]);
            $app_count = $stmt->fetch()['count'];
            
            if ($app_count > 0) {
                redirectWithMessage('users.php', 'Cannot delete user with existing applications. Deactivate instead.', 'warning');
            }
            
            // Delete user
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$delete_user_id]);
            
            // Log activity
            logActivity($pdo, "user_deleted", $user_id, "users", $delete_user_id, [
                'email' => $user_to_delete['email'],
                'user_type' => $user_to_delete['user_type']
            ]);
            
            redirectWithMessage('users.php', 'User deleted successfully!', 'success');
        }
    } catch (PDOException $e) {
        redirectWithMessage('users.php', 'Failed to delete user. Please try again.', 'error');
    }
}

// Get users with pagination and filtering
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build where clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(CONCAT(first_name, ' ', last_name) LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($role_filter)) {
    $where_conditions[] = "user_type = ?";
    $params[] = $role_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count for pagination
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users $where_clause");
    $stmt->execute($params);
    $total_users = $stmt->fetch()['total'];
    $total_pages = ceil($total_users / $per_page);
} catch (PDOException $e) {
    $total_users = 0;
    $total_pages = 1;
}

// Get users
$users = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.*, 
               COUNT(a.id) as application_count,
               MAX(a.created_at) as last_application
        FROM users u
        LEFT JOIN applications a ON u.id = a.user_id
        $where_clause
        GROUP BY u.id
        ORDER BY u.created_at DESC
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $users = [];
}

// Get user statistics
$user_stats = [];
try {
    $stmt = $pdo->query("
        SELECT 
            user_type,
            status,
            COUNT(*) as count
        FROM users 
        GROUP BY user_type, status
        ORDER BY user_type, status
    ");
    while ($row = $stmt->fetch()) {
        $user_stats[$row['user_type']][$row['status']] = $row['count'];
    }
} catch (PDOException $e) {
    $user_stats = [];
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
    <title>User Management - ETEEAP</title>
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
        .user-card {
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
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-active { background-color: #d1e7dd; color: #0f5132; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .status-pending { background-color: #fff3cd; color: #664d03; }
        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .role-admin { background-color: #dc3545; color: white; }
        .role-evaluator { background-color: #fd7e14; color: white; }
        .role-candidate { background-color: #0dcaf0; color: white; }

 

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
                            <h2 class="mb-1">User Management</h2>
                            <p class="text-muted mb-0">Manage system users and their permissions</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                            <i class="fas fa-user-plus me-1"></i>Add New User
                        </button>
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

                    <!-- User Statistics -->
                    <div class="row g-4 mb-4">
                        <?php 
                        $total_all = 0;
                        foreach ($user_stats as $role => $statuses) {
                            foreach ($statuses as $status => $count) {
                                $total_all += $count;
                            }
                        }
                        ?>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-users fa-2x text-primary mb-2"></i>
                                <div class="h3 text-primary"><?php echo formatNumberShort($total_all); ?></div>
                                <div class="text-muted">Total Users</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-user-shield fa-2x text-danger mb-2"></i>
                                <div class="h3 text-danger"><?php echo ($user_stats['admin']['active'] ?? 0) + ($user_stats['evaluator']['active'] ?? 0); ?></div>
                                <div class="text-muted">Staff Members</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-user-graduate fa-2x text-info mb-2"></i>
                                <div class="h3 text-info"><?php echo array_sum($user_stats['candidate'] ?? []); ?></div>
                                <div class="text-muted">Candidates</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-user-check fa-2x text-success mb-2"></i>
                                <div class="h3 text-success">
                                    <?php 
                                    $active_count = 0;
                                    foreach ($user_stats as $role => $statuses) {
                                        $active_count += $statuses['active'] ?? 0;
                                    }
                                    echo $active_count;
                                    ?>
                                </div>
                                <div class="text-muted">Active Users</div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="user-card p-4 mb-4">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label for="search" class="form-label">Search Users</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Name or email...">
                            </div>
                            <div class="col-md-2">
                                <label for="role" class="form-label">Role</label>
                                <select class="form-select" id="role" name="role">
                                    <option value="">All Roles</option>
                                    <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    <option value="evaluator" <?php echo $role_filter === 'evaluator' ? 'selected' : ''; ?>>Evaluator</option>
                                    <option value="candidate" <?php echo $role_filter === 'candidate' ? 'selected' : ''; ?>>Candidate</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-1"></i>Filter
                                </button>
                            </div>
                            <div class="col-md-2">
                                <a href="users.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-times me-1"></i>Clear
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Users Table -->
                    <div class="user-card">
                        <div class="p-3 border-bottom">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Users (<?php echo number_format($total_users); ?>)</h5>
                                <small class="text-muted">Page <?php echo $page; ?> of <?php echo $total_pages; ?></small>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Applications</th>
                                        <th>Joined</th>
                                        <th>Last Activity</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5 text-muted">
                                            <i class="fas fa-users fa-3x mb-3"></i><br>
                                            No users found
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="user-avatar me-3">
                                                    <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold">
                                                        <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                        <?php if ($user['id'] == $user_id): ?>
                                                        <small class="badge bg-warning ms-1">You</small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($user['email']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="role-badge role-<?php echo $user['user_type']; ?>">
                                                <?php echo ucfirst($user['user_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $user['status']; ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($user['application_count'] > 0): ?>
                                                <span class="badge bg-primary"><?php echo $user['application_count']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo formatDate($user['created_at']); ?>
                                        </td>
                                        <td>
                                            <?php if ($user['last_application']): ?>
                                                <?php echo timeAgo($user['last_application']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">No activity</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                                        type="button" data-bs-toggle="dropdown">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <button class="dropdown-item" onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                                            <i class="fas fa-edit me-2"></i>Edit User
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button class="dropdown-item" onclick="resetPassword('<?php echo $user['id']; ?>', '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')">
                                                            <i class="fas fa-key me-2"></i>Reset Password
                                                        </button>
                                                    </li>
                                                    <?php if ($user['id'] != $user_id): ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <a class="dropdown-item text-danger" 
                                                           href="users.php?delete=<?php echo $user['id']; ?>"
                                                           onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                                            <i class="fas fa-trash me-2"></i>Delete User
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="p-3 border-top">
                            <?php
                            $pagination_params = [];
                            if ($search) $pagination_params['search'] = $search;
                            if ($role_filter) $pagination_params['role'] = $role_filter;
                            if ($status_filter) $pagination_params['status'] = $status_filter;
                            
                            echo generatePagination($page, $total_pages, 'users.php', $pagination_params);
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

 <!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <!-- SINGLE FORM ONLY -->
      <form id="addUserForm" method="POST" action="add_user_process.php">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="fas fa-user-plus me-2"></i>Add New User
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label for="add_first_name" class="form-label">First Name *</label>
              <input type="text" class="form-control" id="add_first_name" name="first_name" required>
            </div>
            <div class="col-md-4">
              <label for="add_last_name" class="form-label">Last Name *</label>
              <input type="text" class="form-control" id="add_last_name" name="last_name" required>
            </div>
            <div class="col-md-4">
              <label for="add_middle_name" class="form-label">Middle Name</label>
              <input type="text" class="form-control" id="add_middle_name" name="middle_name">
            </div>

            <div class="col-md-6">
              <label for="add_email" class="form-label">Email Address *</label>
              <input type="email" class="form-control" id="add_email" name="email" required>
            </div>
            <div class="col-md-6">
              <label for="add_phone" class="form-label">Phone Number</label>
              <input type="tel" class="form-control" id="add_phone" name="phone">
            </div>

            <div class="col-12">
              <label for="add_address" class="form-label">Address</label>
              <textarea class="form-control" id="add_address" name="address" rows="2"></textarea>
            </div>

            <div class="col-md-4">
              <label for="add_user_type" class="form-label">User Role *</label>
              <select class="form-select" id="add_user_type" name="user_type" required>
                <option value="">Select Role</option>
                <option value="candidate">Candidate</option>
                <option value="evaluator">Evaluator</option>
                <option value="admin">Administrator</option>
              </select>
            </div>

            <!-- Program Dropdown (hidden by default) -->
            <div class="col-md-8" id="programSelectWrapper" style="display:none;">
              <label class="form-label">Assign Program</label>
          <select name="program_id" id="program_id" class="form-select" size="1">
  <option value="">-- Select Program --</option>
  <?php
    $programs = $pdo->query("SELECT id, program_name, program_code FROM programs")->fetchAll();
    foreach ($programs as $p) {
      echo '<option value="'.$p['id'].'">'.htmlspecialchars($p['program_code'].' - '.$p['program_name']).'</option>';
    }
  ?>
</select>

            </div>

            <div class="col-md-4">
              <label for="add_status" class="form-label">Status *</label>
              <select class="form-select" id="add_status" name="status" required>
                <option value="active">Active</option>
                <option value="pending">Pending</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-4">
              <label for="add_password" class="form-label">Password *</label>
              <input type="password" class="form-control" id="add_password" name="password" required>
              <div class="form-text">Minimum 6 characters</div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_user" class="btn btn-primary">
            <i class="fas fa-save me-1"></i>Create User
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-edit me-2"></i>Edit User
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" id="edit_user_id" name="user_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="edit_first_name" class="form-label">First Name *</label>
                                <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_last_name" class="form-label">Last Name *</label>
                                <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_middle_name" class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="edit_middle_name" name="middle_name">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="edit_email" class="form-label">Email Address *</label>
                                <input type="email" class="form-control" id="edit_email" name="email" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="edit_phone" name="phone">
                            </div>
                            
                            <div class="col-12">
                                <label for="edit_address" class="form-label">Address</label>
                                <textarea class="form-control" id="edit_address" name="address" rows="2"></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="edit_user_type" class="form-label">User Role *</label>
                                <select class="form-select" id="edit_user_type" name="user_type" required>
                                    <option value="candidate">Candidate</option>
                                    <option value="evaluator">Evaluator</option>
                                    <option value="admin">Administrator</option>
                                </select>
                            </div>
<!-- SHOW ONLY IF Evaluator -->
<div class="col-md-6" id="editProgramSelectWrapper" style="display:none;">
  <label for="edit_program_id" class="form-label">Assign Program</label>
  <select name="program_id" id="edit_program_id" class="form-select" size="1">
    <option value="">-- Select Program --</option>
    <?php
      // reuse $pdo
      $programs = $pdo->query("SELECT id, program_name, program_code FROM programs ORDER BY program_code")->fetchAll();
      foreach ($programs as $p) {
        echo '<option value="'.$p['id'].'">'.htmlspecialchars($p['program_code'].' - '.$p['program_name']).'</option>';
      }
    ?>
  </select>
</div>

                            
                            <div class="col-md-6">
                                <label for="edit_status" class="form-label">Status *</label>
                                <select class="form-select" id="edit_status" name="status" required>
                                    <option value="active">Active</option>
                                    <option value="pending">Pending</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_user" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Update User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-key me-2"></i>Reset Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" id="reset_user_id" name="user_id">
                    <div class="modal-body">
                        <p>Reset password for: <strong id="reset_user_name"></strong></p>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password *</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                            <div class="form-text">Minimum 6 characters with letters and numbers</div>
                        </div>
                        <div class="alert alert-warning small">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            The user will need to use this new password to log in.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reset_password" class="btn btn-warning">
                            <i class="fas fa-key me-1"></i>Reset Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
function toggleEditProgram() {
  const roleSel = document.getElementById('edit_user_type');
  const wrap = document.getElementById('editProgramSelectWrapper');
  if (!roleSel || !wrap) return;
  wrap.style.display = (roleSel.value === 'evaluator') ? 'block' : 'none';
  if (roleSel.value !== 'evaluator') {
    const sel = document.getElementById('edit_program_id');
    if (sel) sel.value = '';
  }
}
document.getElementById('edit_user_type').onchange = toggleEditProgram;

// Get assigned program ids of a user
async function fetchUserPrograms(userId) {
  try {
    const res = await fetch('get_user_programs.php?user_id=' + encodeURIComponent(userId));
    if (!res.ok) return [];
    return await res.json(); // ["2","5",...]
  } catch (e) {
    return [];
  }
}


// Preselect in dropdown (single-select)
async function preselectEditProgram(userId) {
  const select = document.getElementById('edit_program_id');
  if (!select) return;
  for (const opt of select.options) opt.selected = false;

  const assigned = await fetchUserPrograms(userId);
  if (assigned.length) {
    const first = String(assigned[0]);
    for (const opt of select.options) {
      if (opt.value === first) { opt.selected = true; break; }
    }
  } else {
    select.value = '';
  }
}

// REPLACE your editUser with this async version
async function editUser(user) {
  document.getElementById('edit_user_id').value = user.id;
  document.getElementById('edit_first_name').value = user.first_name || '';
  document.getElementById('edit_last_name').value = user.last_name || '';
  document.getElementById('edit_middle_name').value = user.middle_name || '';
  document.getElementById('edit_email').value = user.email || '';
  document.getElementById('edit_phone').value = user.phone || '';
  document.getElementById('edit_address').value = user.address || '';
  document.getElementById('edit_user_type').value = user.user_type || '';
  document.getElementById('edit_status').value = user.status || '';

  toggleEditProgram();                               // show/hide based on role
  document.getElementById('edit_user_type').onchange = toggleEditProgram;

  if (document.getElementById('edit_user_type').value === 'evaluator') {
    await preselectEditProgram(user.id);            // preselect current assignment
  }

  new bootstrap.Modal(document.getElementById('editUserModal')).show();
}
        function resetPassword(userId, userName) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_user_name').textContent = userName;
            document.getElementById('new_password').value = '';
            
            new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
        }


function toggleProgramSelect() {
  const roleSelect = document.getElementById('add_user_type');
  const programWrapper = document.getElementById('programSelectWrapper');
  if (!roleSelect || !programWrapper) return;
  programWrapper.style.display = (roleSelect.value === 'evaluator') ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', function () {
  // initial
  toggleProgramSelect();

  // on change
  const roleSelect = document.getElementById('add_user_type');
  roleSelect && roleSelect.addEventListener('change', toggleProgramSelect);

  // when modal opens, recheck (in case default value changes)
  const addUserModalEl = document.getElementById('addUserModal');
  if (addUserModalEl) {
    addUserModalEl.addEventListener('shown.bs.modal', toggleProgramSelect);
  }

  // Generate password buttons (kept from your code)
  const addPasswordField = document.getElementById('add_password');
  if (addPasswordField && !document.getElementById('genPassBtnAdd')) {
    const generateBtnAdd = document.createElement('button');
    generateBtnAdd.id = 'genPassBtnAdd';
    generateBtnAdd.type = 'button';
    generateBtnAdd.className = 'btn btn-outline-secondary btn-sm mt-1';
    generateBtnAdd.innerHTML = '<i class="fas fa-random me-1"></i>Generate';
    generateBtnAdd.onclick = function() {
      addPasswordField.value = generatePassword();
    };
    addPasswordField.parentNode.appendChild(generateBtnAdd);
  }
});

function generatePassword() {
  const length = 8;
  const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
  let password = "";
  for (let i = 0; i < length; i++) {
    password += charset.charAt(Math.floor(Math.random() * charset.length));
  }
  return password;
}

    
    </script>
</body>
</html>