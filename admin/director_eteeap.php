<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Check if user is logged in and is director_eteeap
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'director_eteeap') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

// Get dashboard statistics using NEW approval workflow columns
$stats = [];
try {
    // Pending review (waiting for Director ETEEAP approval) - ONLY QUALIFIED
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM applications 
        WHERE evaluator_submitted_at IS NOT NULL 
        AND director_eteeap_status = 'pending'
        AND application_status = 'qualified'
    ");
    $stats['pending_review'] = $stmt->fetch()['total'];
    
    // Approved by director
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM applications 
        WHERE director_eteeap_status = 'approved'
    ");
    $stats['approved'] = $stmt->fetch()['total'];
    
    // Rejected by director
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM applications 
        WHERE director_eteeap_status = 'rejected'
    ");
    $stats['rejected'] = $stmt->fetch()['total'];
    
    // Total processed
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM applications 
        WHERE director_eteeap_status IN ('approved', 'rejected')
    ");
    $stats['total_processed'] = $stmt->fetch()['total'];
    
} catch (PDOException $e) {
    $stats = [
        'pending_review' => 0,
        'approved' => 0,
        'rejected' => 0,
        'total_processed' => 0
    ];
}

// Get applications for review using NEW approval workflow
$applications = [];
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'pending';

try {
    $where_clause = "";
    switch ($filter) {
        case 'pending':
            // ONLY show QUALIFIED applications that are pending approval
            // Exclude partially_qualified and not_qualified
            $where_clause = "WHERE a.evaluator_submitted_at IS NOT NULL 
                            AND a.director_eteeap_status = 'pending'
                            AND a.application_status = 'qualified'";
            break;
        case 'approved':
            $where_clause = "WHERE a.director_eteeap_status = 'approved'";
            break;
        case 'rejected':
            $where_clause = "WHERE a.director_eteeap_status = 'rejected'";
            break;
        default:
            $where_clause = "WHERE a.evaluator_submitted_at IS NOT NULL";
    }
    
    $stmt = $pdo->prepare("
        SELECT a.*, 
               p.program_name, p.program_code,
               CONCAT(u.first_name, ' ', u.last_name) as candidate_name,
               u.email as candidate_email,
               CONCAT(eval.first_name, ' ', eval.last_name) as evaluator_name,
               eval.email as evaluator_email
        FROM applications a 
        LEFT JOIN programs p ON a.program_id = p.id 
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN users eval ON a.evaluator_id = eval.id
        $where_clause
        ORDER BY 
            CASE 
                WHEN a.director_eteeap_status = 'pending' THEN 1
                WHEN a.director_eteeap_status = 'approved' THEN 2
                WHEN a.director_eteeap_status = 'rejected' THEN 3
            END,
            a.evaluator_submitted_at DESC
    ");
    $stmt->execute();
    $applications = $stmt->fetchAll();
} catch (PDOException $e) {
    $applications = [];
}

// Get bridging requirements for each application
function getBridgingRequirements($pdo, $application_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM bridging_requirements 
            WHERE application_id = ? 
            ORDER BY priority, subject_name
        ");
        $stmt->execute([$application_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Director ETEEAP Dashboard - ETEEAP</title>
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
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            padding: 0;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 1rem 1.5rem;
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
            border-left: 4px solid #3498db;
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2c3e50;
        }
        .stat-label {
            color: #6c757d;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .application-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .application-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-approved { background-color: #d1e7dd; color: #0f5132; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }
        .user-info {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .director-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .approval-timeline {
            position: relative;
            padding-left: 30px;
        }
        .approval-timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }
        .timeline-item {
            position: relative;
            padding-bottom: 1rem;
        }
        .timeline-icon {
            position: absolute;
            left: -24px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
        }
        .timeline-icon.pending {
            background: #ffc107;
            color: white;
        }
        .timeline-icon.approved {
            background: #198754;
            color: white;
        }
        .timeline-icon.rejected {
            background: #dc3545;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-0">
                <div class="user-info">
                    <p class="text-white mb-1 fw-semibold"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                    <span class="director-badge">Director ETEEAP</span>
                </div>
                
                <ul class="nav flex-column mt-3">
                    <li class="nav-item">
                        <a class="nav-link active" href="director_eteeap.php">
                            <i class="fas fa-home me-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="director_eteeap.php?filter=pending">
                            <i class="fas fa-clock me-2"></i> Pending Reviews
                            <?php if ($stats['pending_review'] > 0): ?>
                            <span class="badge bg-warning text-dark ms-2"><?php echo $stats['pending_review']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="director_eteeap.php?filter=approved">
                            <i class="fas fa-check-circle me-2"></i> Approved
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="director_eteeap.php?filter=rejected">
                            <i class="fas fa-times-circle me-2"></i> Rejected
                        </a>
                    </li>
                    <li class="nav-item mt-4">
                        <a class="nav-link" href="../auth/logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Main Content -->
            <div class="col-md-10 p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="mb-1">Director ETEEAP Dashboard</h2>
                        <p class="text-muted mb-0">Review and approve ETEEAP applications</p>
                    </div>
                    <div>
                        <span class="text-muted">
                            <i class="fas fa-calendar-alt me-2"></i>
                            <?php echo date('l, F j, Y'); ?>
                        </span>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo $error; ?></div>
                    <?php endforeach; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number text-warning"><?php echo $stats['pending_review']; ?></div>
                            <div class="stat-label">Pending Review</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number text-success"><?php echo $stats['approved']; ?></div>
                            <div class="stat-label">Approved</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number text-danger"><?php echo $stats['rejected']; ?></div>
                            <div class="stat-label">Rejected</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number text-primary"><?php echo $stats['total_processed']; ?></div>
                            <div class="stat-label">Total Processed</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <ul class="nav nav-tabs mb-4">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'pending' ? 'active' : ''; ?>" href="?filter=pending">
                            Pending (<?php echo $stats['pending_review']; ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'approved' ? 'active' : ''; ?>" href="?filter=approved">
                            Approved (<?php echo $stats['approved']; ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'rejected' ? 'active' : ''; ?>" href="?filter=rejected">
                            Rejected (<?php echo $stats['rejected']; ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" href="?filter=all">
                            All
                        </a>
                    </li>
                </ul>

                <!-- Applications List -->
                <div class="applications-container">
                    <?php if (empty($applications)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">No applications found</h5>
                            <p class="text-muted">Applications will appear here when evaluators submit them for review</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($applications as $app): 
                            $bridging = getBridgingRequirements($pdo, $app['id']);
                            $total_bridging_units = array_sum(array_column($bridging, 'units'));
                        ?>
                            <div class="application-card">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <h5 class="mb-1">
                                            <i class="fas fa-user me-2 text-primary"></i>
                                            <?php echo htmlspecialchars($app['candidate_name']); ?>
                                        </h5>
                                        <p class="text-muted small mb-1">
                                            <i class="fas fa-envelope me-1"></i>
                                            <?php echo htmlspecialchars($app['candidate_email']); ?>
                                        </p>
                                        <p class="text-muted small mb-1">
                                            <i class="fas fa-graduation-cap me-1"></i>
                                            <?php echo htmlspecialchars($app['program_code']); ?> - <?php echo htmlspecialchars($app['program_name']); ?>
                                        </p>
                                        <?php if ($app['evaluator_name']): ?>
                                        <p class="text-muted small mb-0">
                                            <i class="fas fa-user-check me-1"></i>
                                            Evaluated by: <?php echo htmlspecialchars($app['evaluator_name']); ?>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3 text-center">
                                        <p class="mb-1 text-muted small">Final Score</p>
                                        <?php if ($app['total_score'] > 0): ?>
                                            <h3 class="mb-1 text-primary"><?php echo number_format($app['total_score'], 1); ?>%</h3>
                                        <?php else: ?>
                                            <p class="text-muted">N/A</p>
                                        <?php endif; ?>
                                        <span class="status-badge status-<?php echo $app['application_status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $app['application_status'])); ?>
                                        </span>
                                        <?php if ($total_bridging_units > 0): ?>
                                        <p class="small text-muted mt-1 mb-0">
                                            <i class="fas fa-book me-1"></i><?php echo $total_bridging_units; ?> bridging units
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3 text-end">
                                        <p class="text-muted small mb-2">
                                            <i class="fas fa-calendar me-1"></i>
                                            Submitted: <?php echo date('M j, Y', strtotime($app['evaluator_submitted_at'])); ?>
                                        </p>
                                        <?php if ($app['director_eteeap_status'] === 'pending'): ?>
                                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#reviewModal<?php echo $app['id']; ?>">
                                            <i class="fas fa-eye me-1"></i>Review & Approve
                                        </button>
                                        <?php else: ?>
                                        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $app['id']; ?>">
                                            <i class="fas fa-eye me-1"></i>View Details
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Show approval status if already processed -->
                                <?php if ($app['director_eteeap_status'] !== 'pending'): ?>
                                    <hr class="my-3">
                                    <div class="alert alert-<?php echo $app['director_eteeap_status'] === 'approved' ? 'success' : 'danger'; ?> mb-0">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-<?php echo $app['director_eteeap_status'] === 'approved' ? 'check' : 'times'; ?>-circle me-2 fa-2x"></i>
                                            <div class="flex-grow-1">
                                                <strong>Status: <?php echo ucfirst($app['director_eteeap_status']); ?></strong>
                                                <?php if ($app['director_eteeap_approved_at']): ?>
                                                <p class="mb-0 small">
                                                    <?php echo ucfirst($app['director_eteeap_status']); ?> on <?php echo date('M j, Y g:i A', strtotime($app['director_eteeap_approved_at'])); ?>
                                                </p>
                                                <?php endif; ?>
                                                <?php if ($app['director_eteeap_remarks']): ?>
                                                <p class="mb-0 mt-2 small"><strong>Remarks:</strong> <?php echo nl2br(htmlspecialchars($app['director_eteeap_remarks'])); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Review Modal (for pending applications) -->
                            <?php if ($app['director_eteeap_status'] === 'pending'): ?>
                            <div class="modal fade" id="reviewModal<?php echo $app['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-xl">
                                    <div class="modal-content">
                                        <div class="modal-header bg-primary text-white">
                                            <h5 class="modal-title">
                                                <i class="fas fa-clipboard-check me-2"></i>
                                                Review Application - <?php echo htmlspecialchars($app['candidate_name']); ?>
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <!-- Application Summary -->
                                            <div class="row mb-4">
                                                <div class="col-md-6">
                                                    <h6 class="border-bottom pb-2">Candidate Information</h6>
                                                    <p><strong>Name:</strong> <?php echo htmlspecialchars($app['candidate_name']); ?></p>
                                                    <p><strong>Email:</strong> <?php echo htmlspecialchars($app['candidate_email']); ?></p>
                                                    <p><strong>Program:</strong> <?php echo htmlspecialchars($app['program_code']); ?> - <?php echo htmlspecialchars($app['program_name']); ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <h6 class="border-bottom pb-2">Evaluation Summary</h6>
                                                    <p><strong>Final Score:</strong> <span class="badge bg-primary fs-6"><?php echo number_format($app['total_score'], 1); ?>%</span></p>
                                                    <p><strong>Status:</strong> 
                                                        <span class="status-badge status-<?php echo $app['application_status']; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $app['application_status'])); ?>
                                                        </span>
                                                    </p>
                                                    <p><strong>Evaluated by:</strong> <?php echo htmlspecialchars($app['evaluator_name']); ?></p>
                                                    <p><strong>Submitted:</strong> <?php echo date('M j, Y g:i A', strtotime($app['evaluator_submitted_at'])); ?></p>
                                                </div>
                                            </div>

                                            <!-- Bridging Requirements -->
                                            <?php if (!empty($bridging)): ?>
                                            <div class="mb-4">
                                                <h6 class="border-bottom pb-2">Bridging Requirements (<?php echo $total_bridging_units; ?> units)</h6>
                                                <div class="table-responsive">
                                                    <table class="table table-sm">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th>Subject Code</th>
                                                                <th>Subject Name</th>
                                                                <th>Units</th>
                                                                <th>Priority</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($bridging as $subj): ?>
                                                            <tr>
                                                                <td><code><?php echo htmlspecialchars($subj['subject_code']); ?></code></td>
                                                                <td><?php echo htmlspecialchars($subj['subject_name']); ?></td>
                                                                <td><?php echo $subj['units']; ?></td>
                                                                <td>
                                                                    <span class="badge bg-<?php echo $subj['priority'] == 1 ? 'danger' : 'secondary'; ?>">
                                                                        <?php echo $subj['priority'] == 1 ? 'High' : 'Standard'; ?>
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <?php endif; ?>

                                            <!-- Evaluator's Recommendation -->
                                            <?php if ($app['recommendation']): ?>
                                            <div class="mb-4">
                                                <h6 class="border-bottom pb-2">Evaluator's Recommendation</h6>
                                                <div class="alert alert-info">
                                                    <i class="fas fa-comment-dots me-2"></i>
                                                    <?php echo nl2br(htmlspecialchars($app['recommendation'])); ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>

                                            <!-- Approval Form -->
                                            <form id="approvalForm<?php echo $app['id']; ?>" class="approval-form">
                                                <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                                                <input type="hidden" name="action" id="action<?php echo $app['id']; ?>" value="">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">
                                                        <i class="fas fa-comment me-2"></i>Director's Remarks
                                                    </label>
                                                    <textarea class="form-control" name="remarks" id="remarks<?php echo $app['id']; ?>" rows="4" 
                                                              placeholder="Enter your comments, feedback, or reasons for your decision..."></textarea>
                                                    <small class="text-muted">Your remarks will be visible to CED, VPAA, and the evaluator</small>
                                                </div>

                                                <div class="d-grid gap-2">
                                                    <button type="button" class="btn btn-success btn-lg" onclick="submitApproval(<?php echo $app['id']; ?>, 'approve')">
                                                        <i class="fas fa-check-circle me-2"></i>Approve Application
                                                    </button>
                                                    <button type="button" class="btn btn-danger btn-lg" onclick="submitApproval(<?php echo $app['id']; ?>, 'reject')">
                                                        <i class="fas fa-times-circle me-2"></i>Reject Application
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                <i class="fas fa-times me-2"></i>Close
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- View Modal (for processed applications) -->
                            <?php if ($app['director_eteeap_status'] !== 'pending'): ?>
                            <div class="modal fade" id="viewModal<?php echo $app['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">
                                                <i class="fas fa-file-alt me-2"></i>
                                                Application Details - <?php echo htmlspecialchars($app['candidate_name']); ?>
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <!-- Show full details here (same as review modal but read-only) -->
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <p><strong>Score:</strong> <?php echo number_format($app['total_score'], 1); ?>%</p>
                                                    <p><strong>Status:</strong> 
                                                        <span class="status-badge status-<?php echo $app['application_status']; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $app['application_status'])); ?>
                                                        </span>
                                                    </p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p><strong>Your Decision:</strong> 
                                                        <span class="badge bg-<?php echo $app['director_eteeap_status'] === 'approved' ? 'success' : 'danger'; ?>">
                                                            <?php echo ucfirst($app['director_eteeap_status']); ?>
                                                        </span>
                                                    </p>
                                                    <p><strong>Decision Date:</strong> <?php echo date('M j, Y g:i A', strtotime($app['director_eteeap_approved_at'])); ?></p>
                                                </div>
                                            </div>
                                            
                                            <?php if ($app['director_eteeap_remarks']): ?>
                                            <div class="alert alert-secondary">
                                                <strong>Your Remarks:</strong><br>
                                                <?php echo nl2br(htmlspecialchars($app['director_eteeap_remarks'])); ?>
                                            </div>
                                            <?php endif; ?>

                                            <!-- Approval Timeline -->
                                            <h6 class="border-bottom pb-2 mt-4">Approval Timeline</h6>
                                            <div class="approval-timeline">
                                                <div class="timeline-item">
                                                    <div class="timeline-icon approved">
                                                        <i class="fas fa-check"></i>
                                                    </div>
                                                    <strong>Director ETEEAP</strong> - <?php echo ucfirst($app['director_eteeap_status']); ?>
                                                    <br><small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($app['director_eteeap_approved_at'])); ?></small>
                                                </div>
                                                <div class="timeline-item">
                                                    <div class="timeline-icon <?php echo $app['ced_status'] === 'approved' ? 'approved' : 'pending'; ?>">
                                                        <i class="fas fa-<?php echo $app['ced_status'] === 'approved' ? 'check' : 'clock'; ?>"></i>
                                                    </div>
                                                    <strong>CED</strong> - <?php echo ucfirst($app['ced_status']); ?>
                                                    <?php if ($app['ced_approved_at']): ?>
                                                    <br><small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($app['ced_approved_at'])); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="timeline-item">
                                                    <div class="timeline-icon <?php echo $app['vpaa_status'] === 'approved' ? 'approved' : 'pending'; ?>">
                                                        <i class="fas fa-<?php echo $app['vpaa_status'] === 'approved' ? 'check' : 'clock'; ?>"></i>
                                                    </div>
                                                    <strong>VPAA</strong> - <?php echo ucfirst($app['vpaa_status']); ?>
                                                    <?php if ($app['vpaa_approved_at']): ?>
                                                    <br><small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($app['vpaa_approved_at'])); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function submitApproval(appId, action) {
            const remarks = document.getElementById('remarks' + appId).value;
            
            // Confirm action
            const confirmMsg = action === 'approve' 
                ? 'Are you sure you want to APPROVE this application? It will proceed to CED for review.' 
                : 'Are you sure you want to REJECT this application? The evaluator will need to revise it.';
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            // Show loading
            const modal = document.getElementById('reviewModal' + appId);
            const modalBody = modal.querySelector('.modal-body');
            modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3">Processing your decision...</p></div>';
            
            // Submit via AJAX
            const formData = new FormData();
            formData.append('application_id', appId);
            formData.append('action', action);
            formData.append('remarks', remarks);
            
            fetch('approval_action.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Close modal and reload page
                    bootstrap.Modal.getInstance(modal).hide();
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    location.reload();
                }
            })
            .catch(error => {
                alert('An error occurred. Please try again.');
                location.reload();
            });
        }
    </script>
</body>
</html>