<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Check if user is logged in and is ced
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'ced') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

// Handle CED review submission
if ($_POST && isset($_POST['review_application'])) {
    $app_id = $_POST['application_id'];
    $action = $_POST['action']; // approve, revise, reject
    $ced_comments = trim($_POST['ced_comments']);
    
    if (empty($ced_comments)) {
        $errors[] = "Comments are required";
    }
    
    if (empty($errors)) {
        try {
            $new_status = '';
            switch ($action) {
                case 'approve':
                    $new_status = 'ced_approved';
                    break;
                case 'revise':
                    $new_status = 'ced_needs_revision';
                    break;
                case 'reject':
                    $new_status = 'ced_rejected';
                    break;
            }
            
            // Update application status and add CED comments
            $stmt = $pdo->prepare("
                UPDATE applications 
                SET application_status = ?, 
                    ced_comments = ?, 
                    ced_review_date = NOW(),
                    reviewed_by_ced = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_status, $ced_comments, $user_id, $app_id]);
            
            $success_message = "Application has been " . ($action === 'approve' ? 'approved' : ($action === 'revise' ? 'sent for revision' : 'rejected')) . " successfully!";
            
        } catch (PDOException $e) {
            $errors[] = "Failed to process review. Please try again.";
        }
    }
}

// Get dashboard statistics
$stats = [];
try {
    // Total applications approved by director (pending CED review)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE application_status = 'director_approved'");
    $stats['pending_review'] = $stmt->fetch()['total'];
    
    // Approved by CED
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE application_status = 'ced_approved'");
    $stats['approved'] = $stmt->fetch()['total'];
    
    // Needs revision
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE application_status = 'ced_needs_revision'");
    $stats['needs_revision'] = $stmt->fetch()['total'];
    
    // Rejected
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE application_status = 'ced_rejected'");
    $stats['rejected'] = $stmt->fetch()['total'];
    
} catch (PDOException $e) {
    $stats = [
        'pending_review' => 0,
        'approved' => 0,
        'needs_revision' => 0,
        'rejected' => 0
    ];
}

// Get applications for review
$applications = [];
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'pending';

try {
    $where_clause = "";
    switch ($filter) {
        case 'pending':
            $where_clause = "WHERE a.application_status = 'director_approved'";
            break;
        case 'approved':
            $where_clause = "WHERE a.application_status = 'ced_approved'";
            break;
        case 'revision':
            $where_clause = "WHERE a.application_status = 'ced_needs_revision'";
            break;
        case 'rejected':
            $where_clause = "WHERE a.application_status = 'ced_rejected'";
            break;
        default:
            $where_clause = "WHERE a.application_status IN ('director_approved', 'ced_approved', 'ced_needs_revision', 'ced_rejected')";
    }
    
    $stmt = $pdo->prepare("
        SELECT a.*, 
               p.program_name, p.program_code,
               CONCAT(u.first_name, ' ', u.last_name) as candidate_name,
               u.email as candidate_email,
               CONCAT(eval.first_name, ' ', eval.last_name) as evaluator_name,
               CONCAT(dir.first_name, ' ', dir.last_name) as director_name
        FROM applications a 
        LEFT JOIN programs p ON a.program_id = p.id 
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN users eval ON a.evaluator_id = eval.id
        LEFT JOIN users dir ON a.reviewed_by_director = dir.id
        $where_clause
        ORDER BY a.updated_at DESC
    ");
    $stmt->execute();
    $applications = $stmt->fetchAll();
} catch (PDOException $e) {
    $applications = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CED Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            margin: 0;
            padding-top: 0 !important;
        }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #134e5e 0%, #71b280 100%);
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
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: none;
            height: 100%;
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .stat-label {
            color: #6c757d;
            font-size: 0.875rem;
            margin-bottom: 0;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-director_approved { background-color: #fff3cd; color: #664d03; }
        .status-ced_approved { background-color: #d1e7dd; color: #0f5132; }
        .status-ced_needs_revision { background-color: #f8d7da; color: #721c24; }
        .status-ced_rejected { background-color: #e9ecef; color: #495057; }
        .main-content {
            padding: 2rem;
        }
        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .filter-tabs {
            margin-bottom: 1.5rem;
        }
        .filter-tabs .btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .application-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        .application-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .timeline-item {
            border-left: 3px solid #dee2e6;
            padding-left: 1rem;
            margin-bottom: 1rem;
            position: relative;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -7px;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #0d6efd;
            border: 2px solid white;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <i class="fas fa-user-graduate fa-3x text-white mb-3"></i>
                        <h5 class="text-white">College of Education Dean</h5>
                        <p class="text-white-50 small mb-0"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                    </div>
                    
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="ced.php">
                                <i class="fas fa-home me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="ced.php?filter=pending">
                                <i class="fas fa-clock me-2"></i>Pending Review
                                <?php if ($stats['pending_review'] > 0): ?>
                                <span class="badge bg-warning text-dark"><?php echo $stats['pending_review']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="ced.php?filter=approved">
                                <i class="fas fa-check-circle me-2"></i>Approved
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="ced.php?filter=revision">
                                <i class="fas fa-edit me-2"></i>Needs Revision
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="ced.php?filter=rejected">
                                <i class="fas fa-times-circle me-2"></i>Rejected
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reports.php">
                                <i class="fas fa-chart-bar me-2"></i>Reports
                            </a>
                        </li>
                        <li class="nav-item mt-3">
                            <a class="nav-link" href="profile.php">
                                <i class="fas fa-user me-2"></i>Profile
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="settings.php">
                                <i class="fas fa-cog me-2"></i>Settings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../auth/logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content">
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1">
                                <i class="fas fa-university me-2 text-success"></i>
                                College of Education Dean Dashboard
                            </h2>
                            <p class="text-muted mb-0">Review applications approved by Director ETEEAP</p>
                        </div>
                        <div class="text-end">
                            <small class="text-muted">
                                <i class="fas fa-calendar me-1"></i>
                                <?php echo date('F j, Y'); ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Alerts -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card text-center">
                            <div class="stat-number text-warning"><?php echo $stats['pending_review']; ?></div>
                            <p class="stat-label">Pending Review</p>
                            <i class="fas fa-clock fa-2x text-warning opacity-50"></i>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card text-center">
                            <div class="stat-number text-success"><?php echo $stats['approved']; ?></div>
                            <p class="stat-label">Approved</p>
                            <i class="fas fa-check-circle fa-2x text-success opacity-50"></i>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card text-center">
                            <div class="stat-number text-danger"><?php echo $stats['needs_revision']; ?></div>
                            <p class="stat-label">Needs Revision</p>
                            <i class="fas fa-edit fa-2x text-danger opacity-50"></i>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card text-center">
                            <div class="stat-number text-secondary"><?php echo $stats['rejected']; ?></div>
                            <p class="stat-label">Rejected</p>
                            <i class="fas fa-times-circle fa-2x text-secondary opacity-50"></i>
                        </div>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <div class="filter-tabs">
                    <a href="ced.php?filter=pending" class="btn btn-sm <?php echo $filter === 'pending' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        <i class="fas fa-clock me-1"></i>Pending
                    </a>
                    <a href="ced.php?filter=approved" class="btn btn-sm <?php echo $filter === 'approved' ? 'btn-success' : 'btn-outline-success'; ?>">
                        <i class="fas fa-check me-1"></i>Approved
                    </a>
                    <a href="ced.php?filter=revision" class="btn btn-sm <?php echo $filter === 'revision' ? 'btn-danger' : 'btn-outline-danger'; ?>">
                        <i class="fas fa-edit me-1"></i>Needs Revision
                    </a>
                    <a href="ced.php?filter=rejected" class="btn btn-sm <?php echo $filter === 'rejected' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">
                        <i class="fas fa-times me-1"></i>Rejected
                    </a>
                    <a href="ced.php" class="btn btn-sm <?php echo empty($filter) || $filter === 'all' ? 'btn-dark' : 'btn-outline-dark'; ?>">
                        <i class="fas fa-list me-1"></i>All
                    </a>
                </div>

                <!-- Applications List -->
                <div class="bg-white rounded-3 shadow-sm p-4">
                    <h5 class="mb-4">
                        <i class="fas fa-list me-2"></i>
                        Applications for Review
                    </h5>

                    <?php if (empty($applications)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-folder-open fa-3x mb-3"></i>
                            <p>No applications found</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($applications as $app): ?>
                            <div class="application-card">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <h6 class="mb-1">
                                            <i class="fas fa-user me-2 text-primary"></i>
                                            <?php echo htmlspecialchars($app['candidate_name']); ?>
                                        </h6>
                                        <p class="text-muted small mb-1">
                                            <i class="fas fa-envelope me-1"></i>
                                            <?php echo htmlspecialchars($app['candidate_email']); ?>
                                        </p>
                                        <p class="text-muted small mb-0">
                                            <i class="fas fa-graduation-cap me-1"></i>
                                            <?php echo htmlspecialchars($app['program_code']); ?> - <?php echo htmlspecialchars($app['program_name']); ?>
                                        </p>
                                        <?php if ($app['director_name']): ?>
                                        <p class="text-muted small mb-0">
                                            <i class="fas fa-user-shield me-1"></i>
                                            Approved by Director: <?php echo htmlspecialchars($app['director_name']); ?>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3 text-center">
                                        <p class="mb-1 text-muted small">Score</p>
                                        <?php if ($app['total_score'] > 0): ?>
                                            <h4 class="mb-0 text-primary"><?php echo number_format($app['total_score'], 1); ?>%</h4>
                                        <?php else: ?>
                                            <p class="text-muted">N/A</p>
                                        <?php endif; ?>
                                        <span class="status-badge status-<?php echo $app['application_status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $app['application_status'])); ?>
                                        </span>
                                    </div>
                                    <div class="col-md-3 text-end">
                                        <p class="text-muted small mb-2">
                                            <i class="fas fa-calendar me-1"></i>
                                            <?php echo date('M j, Y', strtotime($app['updated_at'])); ?>
                                        </p>
                                        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#reviewModal<?php echo $app['id']; ?>">
                                            <i class="fas fa-eye me-1"></i>Review
                                        </button>
                                    </div>
                                </div>

                                <?php if ($app['ced_comments']): ?>
                                    <hr class="my-3">
                                    <div class="alert alert-success mb-0">
                                        <strong><i class="fas fa-comment me-2"></i>CED's Comments:</strong>
                                        <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($app['ced_comments'])); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Review Modal -->
                            <div class="modal fade" id="reviewModal<?php echo $app['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-xl">
                                    <div class="modal-content">
                                        <div class="modal-header bg-success text-white">
                                            <h5 class="modal-title">
                                                <i class="fas fa-clipboard-check me-2"></i>
                                                CED Review - <?php echo htmlspecialchars($app['candidate_name']); ?>
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                                                
                                                <div class="row">
                                                    <!-- Left Column -->
                                                    <div class="col-md-6">
                                                        <!-- Application Details -->
                                                        <div class="mb-4">
                                                            <h6 class="border-bottom pb-2 mb-3 text-success">
                                                                <i class="fas fa-info-circle me-2"></i>Application Details
                                                            </h6>
                                                            <p><strong>Candidate:</strong> <?php echo htmlspecialchars($app['candidate_name']); ?></p>
                                                            <p><strong>Email:</strong> <?php echo htmlspecialchars($app['candidate_email']); ?></p>
                                                            <p><strong>Program:</strong> <?php echo htmlspecialchars($app['program_code']); ?></p>
                                                            <p><strong>Score:</strong> 
                                                                <span class="badge bg-primary"><?php echo number_format($app['total_score'], 1); ?>%</span>
                                                            </p>
                                                            <p><strong>Status:</strong> 
                                                                <span class="status-badge status-<?php echo $app['application_status']; ?>">
                                                                    <?php echo ucfirst(str_replace('_', ' ', $app['application_status'])); ?>
                                                                </span>
                                                            </p>
                                                        </div>

                                                        <!-- Review Timeline -->
                                                        <div class="mb-4">
                                                            <h6 class="border-bottom pb-2 mb-3 text-success">
                                                                <i class="fas fa-history me-2"></i>Review Timeline
                                                            </h6>
                                                            <?php if ($app['recommendation']): ?>
                                                            <div class="timeline-item">
                                                                <strong class="text-primary">Faculty Expert Evaluation</strong>
                                                                <p class="text-muted small mb-0">By: <?php echo htmlspecialchars($app['evaluator_name']); ?></p>
                                                                <p class="text-muted small"><?php echo $app['evaluation_date'] ? date('M j, Y', strtotime($app['evaluation_date'])) : 'N/A'; ?></p>
                                                            </div>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($app['director_comments']): ?>
                                                            <div class="timeline-item">
                                                                <strong class="text-info">Director ETEEAP Review</strong>
                                                                <p class="text-muted small mb-0">By: <?php echo htmlspecialchars($app['director_name']); ?></p>
                                                                <p class="text-muted small"><?php echo $app['director_review_date'] ? date('M j, Y', strtotime($app['director_review_date'])) : 'N/A'; ?></p>
                                                            </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>

                                                    <!-- Right Column -->
                                                    <div class="col-md-6">
                                                        <!-- Faculty Recommendation -->
                                                        <?php if ($app['recommendation']): ?>
                                                        <div class="mb-3">
                                                            <h6 class="border-bottom pb-2 mb-3 text-success">
                                                                <i class="fas fa-user-check me-2"></i>Faculty Expert Recommendation
                                                            </h6>
                                                            <div class="alert alert-secondary">
                                                                <?php echo nl2br(htmlspecialchars($app['recommendation'])); ?>
                                                            </div>
                                                        </div>
                                                        <?php endif; ?>

                                                        <!-- Director Comments -->
                                                        <?php if ($app['director_comments']): ?>
                                                        <div class="mb-3">
                                                            <h6 class="border-bottom pb-2 mb-3 text-success">
                                                                <i class="fas fa-user-shield me-2"></i>Director's Comments
                                                            </h6>
                                                            <div class="alert alert-info">
                                                                <?php echo nl2br(htmlspecialchars($app['director_comments'])); ?>
                                                            </div>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <hr>

                                                <!-- CED Comments -->
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">
                                                        <i class="fas fa-comment me-2"></i>CED's Comments & Assessment *
                                                    </label>
                                                    <textarea class="form-control" name="ced_comments" rows="5" required 
                                                              placeholder="Provide your comprehensive assessment, feedback, and recommendations..."><?php echo $app['ced_comments'] ?? ''; ?></textarea>
                                                    <small class="text-muted">As the College of Education Dean, provide your professional assessment of this application</small>
                                                </div>

                                                <!-- Action Buttons -->
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Decision *</label>
                                                    <div class="d-grid gap-2">
                                                        <button type="submit" name="review_application" value="1" onclick="this.form.action.value='approve'" 
                                                                class="btn btn-success btn-lg">
                                                            <i class="fas fa-check-circle me-2"></i>Approve Application
                                                            <small class="d-block">Forward to VPAA for final review</small>
                                                        </button>
                                                        <button type="submit" name="review_application" value="1" onclick="this.form.action.value='revise'" 
                                                                class="btn btn-warning btn-lg">
                                                            <i class="fas fa-edit me-2"></i>Request Revision
                                                            <small class="d-block">Send back for corrections</small>
                                                        </button>
                                                        <button type="submit" name="review_application" value="1" onclick="this.form.action.value='reject'" 
                                                                class="btn btn-danger btn-lg">
                                                            <i class="fas fa-times-circle me-2"></i>Reject Application
                                                            <small class="d-block">Decline this application</small>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="action" value="">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                    <i class="fas fa-times me-2"></i>Cancel
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>