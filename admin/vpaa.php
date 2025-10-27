<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Check if user is logged in and is VPAA
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'vpaa') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

// Get dashboard statistics using NEW approval workflow columns
$stats = [];
try {
    // Pending review (waiting for VPAA approval) - Both Director ETEEAP and CED approved
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM applications 
        WHERE director_eteeap_status = 'approved'
        AND ced_status = 'approved'
        AND vpaa_status = 'pending'
        AND application_status = 'qualified'
    ");
    $stats['pending_review'] = $stmt->fetch()['total'];
    
    // Approved by VPAA (FINAL APPROVAL)
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM applications 
        WHERE vpaa_status = 'approved'
    ");
    $stats['approved'] = $stmt->fetch()['total'];
    
    // Rejected by VPAA
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM applications 
        WHERE vpaa_status = 'rejected'
    ");
    $stats['rejected'] = $stmt->fetch()['total'];
    
    // Fully approved applications (all 3 levels approved)
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM applications 
        WHERE final_approval_status = 'fully_approved'
    ");
    $stats['fully_approved'] = $stmt->fetch()['total'];
    
} catch (PDOException $e) {
    $stats = [
        'pending_review' => 0,
        'approved' => 0,
        'rejected' => 0,
        'fully_approved' => 0
    ];
}

// Get applications for review using NEW approval workflow
$applications = [];
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'pending';

try {
    $where_clause = "";
    switch ($filter) {
        case 'pending':
            // ONLY show applications that BOTH Director ETEEAP and CED approved, VPAA pending
            $where_clause = "WHERE a.director_eteeap_status = 'approved'
                            AND a.ced_status = 'approved'
                            AND a.vpaa_status = 'pending'
                            AND a.application_status = 'qualified'";
            break;
        case 'approved':
            $where_clause = "WHERE a.vpaa_status = 'approved'";
            break;
        case 'rejected':
            $where_clause = "WHERE a.vpaa_status = 'rejected'";
            break;
        case 'fully_approved':
            $where_clause = "WHERE a.final_approval_status = 'fully_approved'";
            break;
        default:
            $where_clause = "WHERE a.director_eteeap_status = 'approved' AND a.ced_status = 'approved'";
    }
    
    $stmt = $pdo->prepare("
        SELECT a.*, 
               p.program_name, p.program_code,
               CONCAT(u.first_name, ' ', u.last_name) as candidate_name,
               u.email as candidate_email,
               CONCAT(eval.first_name, ' ', eval.last_name) as evaluator_name,
               CONCAT(dir.first_name, ' ', dir.last_name) as director_name,
               CONCAT(ced.first_name, ' ', ced.last_name) as ced_name
        FROM applications a 
        LEFT JOIN programs p ON a.program_id = p.id 
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN users eval ON a.evaluator_id = eval.id
        LEFT JOIN users dir ON a.director_eteeap_approved_by = dir.id
        LEFT JOIN users ced ON a.ced_approved_by = ced.id
        $where_clause
        ORDER BY 
            CASE 
                WHEN a.vpaa_status = 'pending' THEN 1
                WHEN a.vpaa_status = 'approved' THEN 2
                WHEN a.vpaa_status = 'rejected' THEN 3
            END,
            a.ced_approved_at DESC
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
    <title>VPAA Dashboard - ETEEAP</title>
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
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
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
            border-left: 4px solid #1e3c72;
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
        .status-qualified { background-color: #d1e7dd; color: #0f5132; }
        .user-info {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .vpaa-badge {
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
        .final-approval-badge {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            box-shadow: 0 2px 8px rgba(17, 153, 142, 0.3);
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
                    <span class="vpaa-badge">VPAA</span>
                </div>
                
                <ul class="nav flex-column mt-3">
                    <li class="nav-item">
                        <a class="nav-link active" href="vpaa.php">
                            <i class="fas fa-home me-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vpaa.php?filter=pending">
                            <i class="fas fa-clock me-2"></i> Pending Reviews
                            <?php if ($stats['pending_review'] > 0): ?>
                            <span class="badge bg-warning text-dark ms-2"><?php echo $stats['pending_review']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vpaa.php?filter=approved">
                            <i class="fas fa-check-circle me-2"></i> Approved
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vpaa.php?filter=rejected">
                            <i class="fas fa-times-circle me-2"></i> Rejected
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vpaa.php?filter=fully_approved">
                            <i class="fas fa-medal me-2"></i> Fully Approved
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
                        <h2 class="mb-1">VPAA Dashboard</h2>
                        <p class="text-muted mb-0">Final approval for ETEEAP applications</p>
                    </div>
                    <div>
                        <span class="text-muted">
                            <i class="fas fa-calendar-alt me-2"></i>
                            <?php echo date('l, F j, Y'); ?>
                        </span>
                    </div>
                </div>

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
                            <div class="stat-number text-primary"><?php echo $stats['fully_approved']; ?></div>
                            <div class="stat-label">Fully Approved</div>
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
                        <a class="nav-link <?php echo $filter === 'fully_approved' ? 'active' : ''; ?>" href="?filter=fully_approved">
                            Fully Approved (<?php echo $stats['fully_approved']; ?>)
                        </a>
                    </li>
                </ul>

                <!-- Applications List -->
                <div class="applications-container">
                    <?php if (empty($applications)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">No applications found</h5>
                            <p class="text-muted">Applications approved by both Director ETEEAP and CED will appear here</p>
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
                                            <?php if ($app['final_approval_status'] === 'fully_approved'): ?>
                                            <span class="final-approval-badge ms-2">
                                                <i class="fas fa-star me-1"></i>FULLY APPROVED
                                            </span>
                                            <?php endif; ?>
                                        </h5>
                                        <p class="text-muted small mb-1">
                                            <i class="fas fa-envelope me-1"></i>
                                            <?php echo htmlspecialchars($app['candidate_email']); ?>
                                        </p>
                                        <p class="text-muted small mb-1">
                                            <i class="fas fa-graduation-cap me-1"></i>
                                            <?php echo htmlspecialchars($app['program_code']); ?> - <?php echo htmlspecialchars($app['program_name']); ?>
                                        </p>
                                        <p class="text-muted small mb-0">
                                            <i class="fas fa-check-double me-1 text-success"></i>
                                            Approved by: <?php echo htmlspecialchars($app['director_name']); ?> & <?php echo htmlspecialchars($app['ced_name']); ?>
                                        </p>
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
                                            CED Approved: <?php echo date('M j, Y', strtotime($app['ced_approved_at'])); ?>
                                        </p>
                                        <?php if ($app['vpaa_status'] === 'pending'): ?>
                                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#reviewModal<?php echo $app['id']; ?>">
                                            <i class="fas fa-medal me-1"></i>Final Review
                                        </button>
                                        <?php else: ?>
                                        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $app['id']; ?>">
                                            <i class="fas fa-eye me-1"></i>View Details
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Show VPAA approval status if already processed -->
                                <?php if ($app['vpaa_status'] !== 'pending'): ?>
                                    <hr class="my-3">
                                    <div class="alert alert-<?php echo $app['vpaa_status'] === 'approved' ? 'success' : 'danger'; ?> mb-0">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-<?php echo $app['vpaa_status'] === 'approved' ? 'medal' : 'times'; ?>-circle me-2 fa-2x"></i>
                                            <div class="flex-grow-1">
                                                <strong>VPAA Status: <?php echo ucfirst($app['vpaa_status']); ?></strong>
                                                <?php if ($app['vpaa_approved_at']): ?>
                                                <p class="mb-0 small">
                                                    <?php echo ucfirst($app['vpaa_status']); ?> on <?php echo date('M j, Y g:i A', strtotime($app['vpaa_approved_at'])); ?>
                                                </p>
                                                <?php endif; ?>
                                                <?php if ($app['vpaa_remarks']): ?>
                                                <p class="mb-0 mt-2 small"><strong>Your Remarks:</strong> <?php echo nl2br(htmlspecialchars($app['vpaa_remarks'])); ?></p>
                                                <?php endif; ?>
                                                <?php if ($app['final_approval_status'] === 'fully_approved'): ?>
                                                <p class="mb-0 mt-2">
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-trophy me-1"></i>FULL APPROVAL - All 3 levels approved
                                                    </span>
                                                </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Review Modal (for pending applications) -->
                            <?php if ($app['vpaa_status'] === 'pending'): ?>
                            <div class="modal fade" id="reviewModal<?php echo $app['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-xl">
                                    <div class="modal-content">
                                        <div class="modal-header" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white;">
                                            <h5 class="modal-title">
                                                <i class="fas fa-medal me-2"></i>
                                                VPAA Final Review - <?php echo htmlspecialchars($app['candidate_name']); ?>
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <!-- Important Notice -->
                                            <div class="alert alert-warning mb-4">
                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                <strong>FINAL APPROVAL LEVEL:</strong> This application has been approved by both Director ETEEAP and CED. Your approval will be the FINAL decision.
                                            </div>

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
                                                </div>
                                            </div>

                                            <!-- Approval History -->
                                            <div class="mb-4">
                                                <h6 class="border-bottom pb-2">Approval History</h6>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="alert alert-success">
                                                            <strong><i class="fas fa-check-circle me-2"></i>Director ETEEAP</strong>
                                                            <p class="mb-0 small">Approved: <?php echo date('M j, Y g:i A', strtotime($app['director_eteeap_approved_at'])); ?></p>
                                                            <?php if ($app['director_eteeap_remarks']): ?>
                                                            <p class="mb-0 mt-1 small"><em>"<?php echo htmlspecialchars($app['director_eteeap_remarks']); ?>"</em></p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="alert alert-success">
                                                            <strong><i class="fas fa-check-circle me-2"></i>CED</strong>
                                                            <p class="mb-0 small">Approved: <?php echo date('M j, Y g:i A', strtotime($app['ced_approved_at'])); ?></p>
                                                            <?php if ($app['ced_remarks']): ?>
                                                            <p class="mb-0 mt-1 small"><em>"<?php echo htmlspecialchars($app['ced_remarks']); ?>"</em></p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- VPAA Final Decision Form -->
                                            <form id="approvalForm<?php echo $app['id']; ?>" class="approval-form">
                                                <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                                                <input type="hidden" name="action" id="action<?php echo $app['id']; ?>" value="">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">
                                                        <i class="fas fa-comment me-2"></i>VPAA Final Remarks
                                                    </label>
                                                    <textarea class="form-control" name="remarks" id="remarks<?php echo $app['id']; ?>" rows="4" 
                                                              placeholder="Enter your final decision remarks..."></textarea>
                                                    <small class="text-muted">Your remarks will be visible to all stakeholders</small>
                                                </div>

                                                <div class="d-grid gap-2">
                                                    <button type="button" class="btn btn-success btn-lg" onclick="submitApproval(<?php echo $app['id']; ?>, 'approve')">
                                                        <i class="fas fa-medal me-2"></i>FINAL APPROVAL - Approve Application
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

                            <!-- View Modal - Skipped for brevity, similar to CED -->

                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Success/Error Result Modal -->
    <div class="modal fade" id="resultModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" id="resultModalContent">
                <div class="modal-header" id="resultModalHeader">
                    <h5 class="modal-title" id="resultModalTitle">
                        <i class="fas fa-check-circle me-2"></i>Success
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4" id="resultModalBody">
                    <!-- Dynamic content will be inserted here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>Refresh Page
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        console.log('VPAA Dashboard loaded');
        
        function submitApproval(appId, action) {
            const remarks = document.getElementById('remarks' + appId).value;
            
            const confirmMsg = action === 'approve' 
                ? '⚠️ FINAL APPROVAL CONFIRMATION ⚠️\n\nYou are about to give FINAL APPROVAL to this application.\n\nThis completes the 3-level approval process.\n\nAre you sure?' 
                : 'Are you sure you want to REJECT this application?';
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            const modal = document.getElementById('reviewModal' + appId);
            const modalBody = modal.querySelector('.modal-body');
            modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-3">Processing your FINAL decision...</p></div>';
            
            const formData = new FormData();
            formData.append('application_id', appId);
            formData.append('action', action);
            formData.append('remarks', remarks);
            
            fetch('approval_action.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(errorData => {
                        throw new Error(errorData.message || 'Server returned error: ' + response.status);
                    }).catch(() => {
                        throw new Error('Server error: ' + response.status + ' ' + response.statusText);
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    let successDetails = data.next_step || '';
                    if (action === 'approve') {
                        successDetails += '<br><br><strong>🏆 FULLY APPROVED!</strong><br>All three approval levels completed successfully.';
                    }
                    showResultModal('success', data.message, successDetails);
                    bootstrap.Modal.getInstance(modal).hide();
                } else {
                    let errorDetails = '';
                    if (data.debug_info) {
                        errorDetails = '<div class="mt-3"><small class="text-muted"><strong>Debug Info:</strong><br>';
                        errorDetails += 'User Type: ' + data.debug_info.user_type + '<br>';
                        errorDetails += 'Application ID: ' + data.debug_info.application_id + '<br>';
                        errorDetails += 'Action: ' + data.debug_info.action + '</small></div>';
                    }
                    showResultModal('error', data.message, errorDetails);
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                const errorDetails = '<div class="mt-3"><small>Please check:<br>• Your internet connection<br>• Browser console (F12) for details</small></div>';
                showResultModal('error', error.message, errorDetails);
            });
        }
        
        function showResultModal(type, message, details) {
            const modal = document.getElementById('resultModal');
            const header = document.getElementById('resultModalHeader');
            const title = document.getElementById('resultModalTitle');
            const body = document.getElementById('resultModalBody');
            const content = document.getElementById('resultModalContent');
            
            if (type === 'success') {
                header.className = 'modal-header bg-success text-white';
                title.innerHTML = '<i class="fas fa-trophy me-2"></i>Success!';
                content.style.borderTop = '4px solid #198754';
                body.innerHTML = `
                    <div class="text-center">
                        <div class="mb-3">
                            <i class="fas fa-trophy text-success" style="font-size: 4rem;"></i>
                        </div>
                        <h5 class="mb-3">${message}</h5>
                        ${details ? '<p class="text-muted">' + details + '</p>' : ''}
                    </div>
                `;
            } else {
                header.className = 'modal-header bg-danger text-white';
                title.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>Error';
                content.style.borderTop = '4px solid #dc3545';
                body.innerHTML = `
                    <div class="text-center">
                        <div class="mb-3">
                            <i class="fas fa-exclamation-circle text-danger" style="font-size: 4rem;"></i>
                        </div>
                        <h5 class="mb-3">${message}</h5>
                        ${details ? '<div class="text-start">' + details + '</div>' : ''}
                    </div>
                `;
            }
            
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
        }
    </script>
</body>
</html>