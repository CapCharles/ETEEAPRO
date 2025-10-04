<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
// require_once 'includes/functions.php';

// Check if user is logged in and is a candidate
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'candidate') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$application_id = isset($_GET['id']) ? $_GET['id'] : null;

// Get user's applications
try {
    if ($application_id) {
        // Get specific application
        $stmt = $pdo->prepare("
            SELECT a.*, p.program_name, p.program_code, 
                   u.first_name as evaluator_first_name, u.last_name as evaluator_last_name
            FROM applications a 
            LEFT JOIN programs p ON a.program_id = p.id 
            LEFT JOIN users u ON a.evaluator_id = u.id
            WHERE a.id = ? AND a.user_id = ?
        ");
        $stmt->execute([$application_id, $user_id]);
        $application = $stmt->fetch();
        
        if (!$application) {
            header('Location: assessment.php');
            exit();
        }
    } else {
        // Get latest application
        $stmt = $pdo->prepare("
            SELECT a.*, p.program_name, p.program_code,
                   u.first_name as evaluator_first_name, u.last_name as evaluator_last_name
            FROM applications a 
            LEFT JOIN programs p ON a.program_id = p.id 
            LEFT JOIN users u ON a.evaluator_id = u.id
            WHERE a.user_id = ? 
            ORDER BY a.created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $application = $stmt->fetch();
    }
    
    // Get all user applications for sidebar
    $stmt = $pdo->prepare("
        SELECT a.*, p.program_name, p.program_code 
        FROM applications a 
        LEFT JOIN programs p ON a.program_id = p.id 
        WHERE a.user_id = ? 
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $all_applications = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $application = null;
    $all_applications = [];
}

// Get assessment criteria and evaluations if application exists
$criteria_evaluations = [];
$documents = [];
if ($application) {
    try {
        // Get assessment criteria with evaluations
        $stmt = $pdo->prepare("
            SELECT ac.*, e.score, e.max_score, e.comments, e.evaluation_date
            FROM assessment_criteria ac
            LEFT JOIN evaluations e ON ac.id = e.criteria_id AND e.application_id = ?
            WHERE ac.program_id = ? AND ac.status = 'active'
            ORDER BY ac.criteria_type, ac.criteria_name
        ");
        $stmt->execute([$application['id'], $application['program_id']]);
        $criteria_evaluations = $stmt->fetchAll();
        
        // Get uploaded documents
        $stmt = $pdo->prepare("
            SELECT * FROM documents 
            WHERE application_id = ? 
            ORDER BY document_type, upload_date
        ");
        $stmt->execute([$application['id']]);
        $documents = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        $criteria_evaluations = [];
        $documents = [];
    }
}




?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Results - ETEEAP</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
             body { margin: 0; padding-top: 0 !important; }
        .assessment-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
        }
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .status-draft { background-color: #e9ecef; color: #495057; }
        .status-submitted { background-color: #cff4fc; color: #055160; }
        .status-under_review { background-color: #fff3cd; color: #664d03; }
        .status-qualified { background-color: #d1e7dd; color: #0f5132; }
        .status-partially_qualified { background-color: #ffeaa7; color: #d63031; }
        .status-not_qualified { background-color: #f8d7da; color: #721c24; }
        
        .score-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
            margin: 0 auto;
        }
        .score-excellent { background: linear-gradient(135deg, #00b894, #00cec9); color: white; }
        .score-good { background: linear-gradient(135deg, #fdcb6e, #e17055); color: white; }
        .score-fair { background: linear-gradient(135deg, #fd79a8, #fdcb6e); color: white; }
        .score-poor { background: linear-gradient(135deg, #fd79a8, #e84393); color: white; }
        .score-pending { background: #e9ecef; color: #6c757d; }
        
        .criteria-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid #dee2e6;
        }
        .criteria-evaluated {
            border-left-color: #28a745;
            background: rgba(40, 167, 69, 0.05);
        }
        .criteria-pending {
            border-left-color: #ffc107;
            background: rgba(255, 193, 7, 0.05);
        }
        
        .progress-bar-custom {
            height: 8px;
            border-radius: 4px;
        }
        
        .timeline-item {
            position: relative;
            padding-left: 2rem;
            margin-bottom: 1.5rem;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #007bff;
        }
        .timeline-item::after {
            content: '';
            position: absolute;
            left: 5px;
            top: 12px;
            width: 2px;
            height: calc(100% - 12px);
            background: #dee2e6;
        }
        .timeline-item:last-child::after {
            display: none;
        }
        
        .document-preview {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }
        .document-preview:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../index.php">
                <i class="fas fa-graduation-cap me-2"></i>ETEEAP
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="profile.php">
                            <i class="fas fa-user me-1"></i>Profile
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="upload.php">
                            <i class="fas fa-upload me-1"></i>Upload Documents
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="assessment.php">
                            <i class="fas fa-clipboard-check me-1"></i>Assessment
                        </a>
                    </li>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i>
                            <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?php if (!$application): ?>
        <!-- No Applications -->
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="assessment-card p-5 text-center">
                    <i class="fas fa-clipboard-list fa-4x text-muted mb-4"></i>
                    <h3>No Applications Found</h3>
                    <p class="text-muted mb-4">You haven't submitted any applications for assessment yet.</p>
                    <a href="upload.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Create Your First Application
                    </a>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="row g-4">
            <!-- Main Assessment Content -->
            <div class="col-lg-8">
                <!-- Application Overview -->
                <div class="assessment-card p-4 mb-4">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4 class="mb-2"><?php echo htmlspecialchars($application['program_name']); ?></h4>
                            <p class="text-muted mb-2">
                                <i class="fas fa-calendar me-1"></i>
                                Applied: <?php echo date('F j, Y', strtotime($application['created_at'])); ?>
                            </p>
                            <?php if ($application['submission_date']): ?>
                            <p class="text-muted mb-2">
                                <i class="fas fa-paper-plane me-1"></i>
                                Submitted: <?php echo date('F j, Y', strtotime($application['submission_date'])); ?>
                            </p>
                            <?php endif; ?>
                            <?php if ($application['evaluation_date']): ?>
                            <p class="text-muted mb-0">
                                <i class="fas fa-check-circle me-1"></i>
                                Evaluated: <?php echo date('F j, Y', strtotime($application['evaluation_date'])); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <div class="mb-2">
                                <span class="status-badge status-<?php echo $application['application_status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $application['application_status'])); ?>
                                </span>
                            </div>
                            
                            <!-- Overall Score -->
                            <?php if ($application['total_score'] > 0): ?>
                            <div class="score-circle <?php 
                                if ($application['total_score'] >= 90) echo 'score-excellent';
                                elseif ($application['total_score'] >= 75) echo 'score-good';
                                elseif ($application['total_score'] >= 60) echo 'score-fair';
                                elseif ($application['total_score'] >= 40) echo 'score-poor';
                                else echo 'score-pending';
                            ?>">
                                <?php echo $application['total_score']; ?>%
                            </div>
                            <div class="text-center mt-2">
                                <small class="text-muted">Overall Score</small>
                            </div>
                            <?php else: ?>
                            <div class="score-circle score-pending">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="text-center mt-2">
                                <small class="text-muted">Pending</small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Assessment Criteria -->
                <div class="assessment-card p-4 mb-4">
                    <h5 class="mb-4">
                        <i class="fas fa-clipboard-check me-2"></i>
                        Assessment Breakdown
                    </h5>
                    
                    <?php if (empty($criteria_evaluations)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-hourglass-half fa-2x text-muted mb-3"></i>
                        <p class="text-muted">Assessment criteria not yet available</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($criteria_evaluations as $criteria): ?>
                    <div class="criteria-item <?php echo $criteria['score'] !== null ? 'criteria-evaluated' : 'criteria-pending'; ?>">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h6 class="mb-1"><?php echo htmlspecialchars($criteria['criteria_name']); ?></h6>
                                <p class="text-muted mb-2 small"><?php echo htmlspecialchars($criteria['description']); ?></p>
                                
                                <?php if ($criteria['score'] !== null): ?>
                                <div class="mb-2">
                                    <div class="progress progress-bar-custom">
                                        <div class="progress-bar bg-<?php echo $criteria['score'] >= ($criteria['max_score'] * 0.7) ? 'success' : ($criteria['score'] >= ($criteria['max_score'] * 0.5) ? 'warning' : 'danger'); ?>" 
                                             style="width: <?php echo ($criteria['score'] / $criteria['max_score']) * 100; ?>%"></div>
                                    </div>
                                    <small class="text-muted">
                                        Score: <?php echo $criteria['score']; ?> / <?php echo $criteria['max_score']; ?>
                                        (<?php echo number_format(($criteria['score'] / $criteria['max_score']) * 100, 1); ?>%)
                                    </small>
                                </div>
                                
                                <?php if ($criteria['comments']): ?>
                                <div class="alert alert-info small mb-0">
                                    <strong>Evaluator Comments:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($criteria['comments'])); ?>
                                </div>
                                <?php endif; ?>
                                <?php else: ?>
                                <div class="text-muted small">
                                    <i class="fas fa-clock me-1"></i>
                                    Awaiting evaluation
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <div class="badge bg-secondary">
                                    <?php echo ucfirst(str_replace('_', ' ', $criteria['criteria_type'])); ?>
                                </div>
                                <?php if ($criteria['score'] !== null): ?>
                                <div class="mt-2">
                                    <span class="h5 text-<?php echo $criteria['score'] >= ($criteria['max_score'] * 0.7) ? 'success' : ($criteria['score'] >= ($criteria['max_score'] * 0.5) ? 'warning' : 'danger'); ?>">
                                        <?php echo number_format(($criteria['score'] / $criteria['max_score']) * 100, 0); ?>%
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                
                                    
                <!-- Recommendation -->
                <?php if ($application['recommendation']): ?>
                <div class="assessment-card p-4 mb-4">
                    <h5 class="mb-3">
                        <i class="fas fa-lightbulb me-2"></i>
                        Evaluator's Recommendation
                    </h5>
                    <div class="alert alert-<?php echo $application['application_status'] === 'qualified' ? 'success' : ($application['application_status'] === 'partially_qualified' ? 'warning' : 'info'); ?>">
                        <?php echo nl2br(htmlspecialchars($application['recommendation'])); ?>
                    </div>
                    <?php if ($application['evaluator_first_name']): ?>
                    <div class="text-end">
                        <small class="text-muted">
                            Evaluated by: <?php echo htmlspecialchars($application['evaluator_first_name'] . ' ' . $application['evaluator_last_name']); ?>
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <!-- Curriculum Status Breakdown -->
<?php 
// Only show if evaluated and has a program
if ($application['application_status'] !== 'draft' && !empty($application['program_code'])): 
    
    // Helper function to get passed subjects (copy from evaluate.php)
    function getPassedSubjects($documents, $programCode) {
        $curriculum = [];
        
        $PC = strtoupper($programCode);
        if (strpos($PC, 'BSED') !== false) {
            $curriculum = [
                ['name' => 'Educational Psychology', 'keywords' => ['psychology', 'edpsy']],
                ['name' => 'Foundations of Education 1', 'keywords' => ['foe1', 'foundations', 'education 1']],
                ['name' => 'Foundations of Education 2', 'keywords' => ['foe2', 'foundations', 'education 2']],
                ['name' => 'Principles and Methods of Teaching', 'keywords' => ['pmt', 'teaching methods', 'principles']],
                ['name' => 'Educational Measurement and Evaluation', 'keywords' => ['measurement', 'evaluation', 'eme']],
                ['name' => 'Prep. And Utilization of Instr\'l Materials', 'keywords' => ['instructional materials', 'puim']],
                ['name' => 'Teaching Strategies for the Major Fields', 'keywords' => ['teaching strategies', 'tsmf']],
                ['name' => 'Guidance and Counseling with SPED', 'keywords' => ['guidance', 'counseling', 'sped', 'gcs']],
                ['name' => 'School Administration and Supervision', 'keywords' => ['administration', 'supervision', 'sas']],
                ['name' => 'Observation and Participation', 'keywords' => ['observation', 'participation', 'op']],
                ['name' => 'Introduction to Educational Research', 'keywords' => ['research', 'ier']],
                ['name' => 'Professional Ethics', 'keywords' => ['ethics', 'pe']],
                ['name' => 'Student Teaching', 'keywords' => ['student teaching', 'st', 'practicum']],
                ['name' => 'Teaching Strategies 3', 'keywords' => ['strategies 3', 'ts3']],
            ];
        } elseif (strpos($PC, 'BEED') !== false) {
            $curriculum = [
                ['name' => 'Educational Psychology', 'keywords' => ['psychology', 'edpsy']],
                ['name' => 'Foundations of Education 1', 'keywords' => ['foe1', 'foundations', 'education 1']],
                ['name' => 'Foundations of Education 2', 'keywords' => ['foe2', 'foundations', 'education 2']],
                ['name' => 'Principles and Methods of Teaching', 'keywords' => ['pmt', 'teaching methods', 'principles']],
                ['name' => 'Educational Measurement and Evaluation', 'keywords' => ['measurement', 'evaluation', 'eme']],
                ['name' => 'Prep. And Utilization of Instr\'l Materials', 'keywords' => ['instructional materials', 'puim']],
                ['name' => 'Teaching Strategies for the Major Fields (BEEd)', 'keywords' => ['teaching strategies', 'tsmf', 'beed']],
                ['name' => 'Guidance and Counseling with SPED', 'keywords' => ['guidance', 'counseling', 'sped', 'gcs']],
                ['name' => 'School Administration and Supervision', 'keywords' => ['administration', 'supervision', 'sas']],
                ['name' => 'Observation and Participation', 'keywords' => ['observation', 'participation', 'op']],
                ['name' => 'Introduction to Educational Research', 'keywords' => ['research', 'ier']],
                ['name' => 'Professional Ethics', 'keywords' => ['ethics', 'pe']],
                ['name' => 'Student Teaching', 'keywords' => ['student teaching', 'st', 'practicum']],
                ['name' => 'Teaching Strategies 2', 'keywords' => ['strategies 2', 'ts2']],
            ];
        }
        
        $passed = [];
        foreach ($curriculum as $subject) {
            foreach ($documents as $doc) {
                $filename = strtolower($doc['original_filename']);
                $desc = strtolower($doc['description'] ?? '');
                
                foreach ($subject['keywords'] as $keyword) {
                    if (strpos($filename, $keyword) !== false || strpos($desc, $keyword) !== false) {
                        $evidence = [];
                        if (strpos($filename, 'transcript') !== false || strpos($filename, 'tor') !== false) {
                            $evidence[] = 'TOR';
                        }
                        if (strpos($filename, 'certificate') !== false || strpos($filename, 'cert') !== false) {
                            $evidence[] = 'Certificate';
                        }
                        if (strpos($filename, 'diploma') !== false) {
                            $evidence[] = 'Diploma';
                        }
                        if (!$evidence) $evidence[] = pathinfo($doc['original_filename'], PATHINFO_EXTENSION);
                        
                        $passed[$subject['name']] = implode(', ', $evidence);
                        break 2;
                    }
                }
            }
        }
        
        return ['curriculum' => $curriculum, 'passed' => $passed];
    }
    
    // Get curriculum status
 $curriculumStatus = getPassedSubjects($documents, $current_application['program_code'] ?? '');
$curriculumSubjects = $curriculumStatus['curriculum'];
$passedSubjects = $curriculumStatus['passed'];

// Get bridging requirements from database
$bridging_requirements = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM bridging_requirements
        WHERE application_id = ?
        ORDER BY priority ASC, subject_name ASC
    ");
    $stmt->execute([$current_application['id']]);
    $bridging_requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bridging_requirements = [];
}

// Create list of bridging subject names for filtering
$bridgingSubjectNames = array_column($bridging_requirements, 'subject_name');
?>
<div class="assessment-card p-4 mb-4">
    <h5 class="mb-4">
        <i class="fas fa-graduation-cap me-2"></i>
        Your Curriculum Status for <?php echo htmlspecialchars($application['program_code']); ?>
    </h5>
    
    <!-- Passed/Credited Subjects -->
 <div class="col-lg-6">
        <div class="assessment-card p-4">
            <h5 class="mb-3">
                <i class="fas fa-check-circle text-success me-2"></i>
                Credited Subjects (Prior Learning Recognition)
            </h5>
            
            <?php if (empty($curriculumSubjects)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-graduation-cap fa-2x mb-3"></i>
                    <p>No curriculum data available</p>
                </div>
            <?php else: ?>
                <?php 
                // Filter only credited subjects (NOT in bridging requirements)
                $creditedSubjects = array_filter($curriculumSubjects, function($subject) use ($bridgingSubjectNames) {
                    return !in_array($subject['name'], $bridgingSubjectNames);
                });
                ?>
                
                <?php if (empty($creditedSubjects)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No subjects credited yet. Complete evaluation to see credited subjects.
                    </div>
                <?php else: ?>
                    <table class="table table-sm table-bordered">
                        <thead class="table-success">
                            <tr>
                                <th style="width: 70%;">Subject Name</th>
                                <th style="width: 30%;">Evidence</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($creditedSubjects as $subject): ?>
                            <tr>
                                <td>
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <strong><?php echo htmlspecialchars($subject['name']); ?></strong>
                                </td>
                                <td class="small text-muted">
                                    <?php 
                                    if (isset($passedSubjects[$subject['name']])) {
                                        echo htmlspecialchars($passedSubjects[$subject['name']]);
                                    } else {
                                        echo 'ETEEAP Credit';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="mt-3 alert alert-success small">
                        <strong><i class="fas fa-trophy me-1"></i> Total Credited:</strong> 
                        <?php echo count($creditedSubjects); ?> subjects recognized through prior learning assessment
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- TABLE 2: REQUIRED BRIDGING COURSES -->
    <div class="col-lg-6">
        <div class="assessment-card p-4">
            <h5 class="mb-3">
                <i class="fas fa-graduation-cap text-primary me-2"></i>
                Required Bridging Courses
            </h5>
            
            <?php if (empty($bridging_requirements)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-hourglass-half me-2"></i>
                    <strong>Pending Evaluation</strong>
                    <p class="mb-0 mt-2 small">Bridging requirements will be determined after the evaluation is completed by the evaluator.</p>
                </div>
            <?php else: ?>
                <table class="table table-sm table-bordered">
                    <thead class="table-primary">
                        <tr>
                            <th style="width: 50%;">Subject Name</th>
                            <th style="width: 20%;">Code</th>
                            <th style="width: 15%;">Units</th>
                            <th style="width: 15%;">Priority</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $totalUnits = 0;
                        foreach ($bridging_requirements as $req): 
                            $totalUnits += (int)$req['units'];
                            $priorityClass = '';
                            $priorityText = '';
                            switch((int)$req['priority']) {
                                case 1:
                                    $priorityClass = 'danger';
                                    $priorityText = 'High';
                                    break;
                                case 2:
                                    $priorityClass = 'warning';
                                    $priorityText = 'Medium';
                                    break;
                                default:
                                    $priorityClass = 'secondary';
                                    $priorityText = 'Low';
                            }
                        ?>
                        <tr>
                            <td>
                                <i class="fas fa-book text-primary me-2"></i>
                                <strong><?php echo htmlspecialchars($req['subject_name']); ?></strong>
                            </td>
                            <td class="text-muted small">
                                <?php echo htmlspecialchars($req['subject_code']); ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info"><?php echo (int)$req['units']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $priorityClass; ?>">
                                    <?php echo $priorityText; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="2" class="text-end">Total Required Units:</th>
                            <th class="text-center">
                                <span class="badge bg-primary"><?php echo $totalUnits; ?></span>
                            </th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
                
                <div class="mt-3 alert alert-primary small">
                    <strong><i class="fas fa-info-circle me-1"></i> Next Steps:</strong> 
                    Enroll in these <?php echo count($bridging_requirements); ?> bridging courses (<?php echo $totalUnits; ?> units total) to complete your degree.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php 
    endif; // end curriculum subjects check
endif; // end draft status check
?>

                <!-- Uploaded Documents -->
                <div class="assessment-card p-4">
                    <h5 class="mb-3">
                        <i class="fas fa-file-alt me-2"></i>
                        Submitted Documents (<?php echo count($documents); ?>)
                    </h5>
                    
                    <?php if (empty($documents)): ?>
                    <div class="text-center py-3">
                        <i class="fas fa-folder-open fa-2x text-muted mb-2"></i>
                        <p class="text-muted">No documents submitted</p>
                    </div>
                    <?php else: ?>
                    <div class="row g-2">
                        <?php foreach ($documents as $doc): ?>
                        <div class="col-md-6">
                            <div class="document-preview">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-file-pdf fa-2x text-danger me-3"></i>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1 small"><?php echo htmlspecialchars($doc['original_filename']); ?></h6>
                                        <div class="mb-1">
                                            <span class="badge bg-primary small">
                                                <?php echo ucfirst(str_replace('_', ' ', $doc['document_type'])); ?>
                                            </span>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo number_format($doc['file_size'] / 1024, 1); ?> KB
                                        </small>
                                    </div>
                                </div>
                                <?php if ($doc['description']): ?>
                                <div class="mt-2 small text-muted">
                                    <?php echo htmlspecialchars($doc['description']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Application Timeline -->
                <div class="assessment-card p-4 mb-4">
                    <h6 class="mb-3">
                        <i class="fas fa-history me-2"></i>
                        Application Timeline
                    </h6>
                    
                    <div class="timeline-item">
                        <div class="fw-semibold">Application Created</div>
                        <small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($application['created_at'])); ?></small>
                    </div>
                    
                    <?php if ($application['submission_date']): ?>
                    <div class="timeline-item">
                        <div class="fw-semibold">Application Submitted</div>
                        <small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($application['submission_date'])); ?></small>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($application['evaluation_date']): ?>
                    <div class="timeline-item">
                        <div class="fw-semibold">Assessment Completed</div>
                        <small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($application['evaluation_date'])); ?></small>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($application['application_status'] === 'draft'): ?>
                    <div class="timeline-item">
                        <div class="text-muted">Awaiting Submission</div>
                        <small class="text-muted">Complete your documents and submit</small>
                    </div>
                    <?php elseif ($application['application_status'] === 'submitted'): ?>
                    <div class="timeline-item">
                        <div class="text-muted">Under Review</div>
                        <small class="text-muted">Your application is being evaluated</small>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- My Applications -->
                <?php if (count($all_applications) > 1): ?>
                <div class="assessment-card p-4 mb-4">
                    <h6 class="mb-3">
                        <i class="fas fa-list me-2"></i>
                        My Applications
                    </h6>
                    
                    <?php foreach ($all_applications as $app): ?>
                    <div class="d-flex align-items-center mb-3 <?php echo $app['id'] == $application['id'] ? 'bg-light rounded p-2' : ''; ?>">
                        <div class="flex-grow-1">
                            <a href="assessment.php?id=<?php echo $app['id']; ?>" 
                               class="text-decoration-none <?php echo $app['id'] == $application['id'] ? 'fw-bold' : ''; ?>">
                                <?php echo htmlspecialchars($app['program_code']); ?>
                            </a>
                            <div class="small text-muted">
                                <?php echo date('M j, Y', strtotime($app['created_at'])); ?>
                            </div>
                        </div>
                        <div>
                            <span class="badge bg-<?php 
                                echo $app['application_status'] === 'qualified' ? 'success' : 
                                    ($app['application_status'] === 'submitted' ? 'warning' : 'secondary'); 
                            ?> small">
                                <?php echo ucfirst($app['application_status']); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="assessment-card p-4">
                    <h6 class="mb-3">
                        <i class="fas fa-bolt me-2"></i>
                        Quick Actions
                    </h6>
                    
                    <div class="d-grid gap-2">
                        <a href="upload.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-plus me-1"></i>New Application
                        </a>
                        
                        <?php if ($application['application_status'] === 'draft'): ?>
                        <a href="upload.php" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-upload me-1"></i>Upload Documents
                        </a>
                        <?php endif; ?>
                        
                        <a href="profile.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-user me-1"></i>View Profile
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh page every 30 seconds if status is under review
        <?php if ($application && $application['application_status'] === 'under_review'): ?>
        setTimeout(function() {
            location.reload();
        }, 30000);
        <?php endif; ?>
    </script>
</body>
</html>