<?php
/**
 * STEP 4: Create this new file
 * File: candidates/recommendations.php
 * 
 * This shows prescriptive recommendations to candidates
 */

session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../includes/prescriptive_engine.php';

// Check if user is logged in and is a candidate
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'candidate') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user's latest application
$stmt = $pdo->prepare("
    SELECT a.*, p.program_name, p.program_code
    FROM applications a 
    LEFT JOIN programs p ON a.program_id = p.id 
    WHERE a.user_id = ? 
    ORDER BY a.created_at DESC 
    LIMIT 1
");
$stmt->execute([$user_id]);
$application = $stmt->fetch();

// Get prescriptive data
$engine = new PrescriptiveEngine($pdo);
$prediction = null;
$recommendations = [];
$gaps = [];

if ($application) {
    $prediction = $engine->getPrediction($application['id']);
    $recommendations = $engine->getRecommendations($application['id']);
    $gaps = $engine->getSkillGaps($application['id']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Recommendations - ETEEAP</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; margin: 0; padding-top: 0 !important; }
        .prediction-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .recommendation-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
        }
        .priority-critical { border-left-color: #dc3545; }
        .priority-high { border-left-color: #ffc107; }
        .priority-medium { border-left-color: #0dcaf0; }
        .progress-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
            margin: 0 auto;
        }
        .gap-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.5rem;
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
                        <a class="nav-link" href="assessment.php">
                            <i class="fas fa-clipboard-check me-1"></i>Assessment
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="recommendations.php">
                            <i class="fas fa-lightbulb me-1"></i>Recommendations
                        </a>
                    </li>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
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
        <!-- No Application -->
        <div class="text-center py-5">
            <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
            <h3>No Application Found</h3>
            <p class="text-muted">Create an application first to see recommendations.</p>
            <a href="upload.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Create Application
            </a>
        </div>
        
        <?php else: ?>
        
        <!-- Prediction Card -->
        <?php if ($prediction): ?>
        <div class="prediction-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="mb-3">
                        <i class="fas fa-chart-line me-2"></i>
                        Your Success Prediction
                    </h3>
                    <h5><?php echo htmlspecialchars($application['program_name']); ?></h5>
                    <p class="mb-2">
                        <strong>Qualification Probability:</strong> 
                        <?php echo ($prediction['qualification_probability'] * 100); ?>%
                    </p>
                    <p class="mb-2">
                        <strong>Predicted Score:</strong> 
                        <?php echo $prediction['predicted_score']; ?> / 100
                    </p>
                    <p class="mb-0">
                        <strong>Risk Level:</strong> 
                        <span class="badge bg-<?php 
                            echo $prediction['risk_level'] === 'low' ? 'success' : 
                                ($prediction['risk_level'] === 'medium' ? 'warning' : 'danger'); 
                        ?>">
                            <?php echo ucfirst($prediction['risk_level']); ?>
                        </span>
                    </p>
                </div>
                <div class="col-md-4 text-center">
                    <div class="progress-circle bg-white text-dark">
                        <?php echo round($prediction['qualification_probability'] * 100); ?>%
                    </div>
                    <small class="d-block mt-2">Success Rate</small>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Skill Gaps -->
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                            Skill Gaps (<?php echo count($gaps); ?>)
                        </h5>
                        
                        <?php if (empty($gaps)): ?>
                        <p class="text-muted">No skill gaps identified!</p>
                        <?php else: ?>
                        <?php foreach ($gaps as $gap): ?>
                        <div class="gap-item">
                            <strong><?php echo htmlspecialchars($gap['skill_name']); ?></strong>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small class="text-muted">
                                    Current: <?php echo $gap['current_level']; ?>% → 
                                    Required: <?php echo $gap['required_level']; ?>%
                                </small>
                                <span class="badge bg-<?php 
                                    echo $gap['priority'] === 'critical' ? 'danger' : 
                                        ($gap['priority'] === 'high' ? 'warning' : 'info'); 
                                ?>">
                                    <?php echo ucfirst($gap['priority']); ?>
                                </span>
                            </div>
                            <div class="progress mt-2" style="height: 5px;">
                                <div class="progress-bar bg-success" 
                                     style="width: <?php echo $gap['current_level']; ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recommendations -->
            <div class="col-lg-8">
                <h4 class="mb-3">
                    <i class="fas fa-tasks me-2"></i>
                    Your Personalized Action Plan
                </h4>
                
                <?php if (empty($recommendations)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    No recommendations yet. Upload documents to get personalized suggestions!
                </div>
                
                <?php else: ?>
                <?php foreach ($recommendations as $index => $rec): ?>
                <div class="recommendation-card priority-<?php echo $gaps[$index]['priority'] ?? 'medium'; ?>">
                    <div class="row">
                        <div class="col-md-9">
                            <div class="d-flex align-items-start mb-2">
                                <span class="badge bg-primary me-2">#<?php echo $rec['priority_rank']; ?></span>
                                <div>
                                    <h5 class="mb-1"><?php echo htmlspecialchars($rec['title']); ?></h5>
                                    <p class="text-muted mb-2"><?php echo htmlspecialchars($rec['description']); ?></p>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-3 mb-2">
                                <small>
                                    <i class="fas fa-chart-line text-success me-1"></i>
                                    Impact: <strong>+<?php echo $rec['estimated_impact']; ?> points</strong>
                                </small>
                                <small>
                                    <i class="fas fa-clock text-primary me-1"></i>
                                    Duration: <strong><?php echo $rec['estimated_duration_days']; ?> days</strong>
                                </small>
                                <small>
                                    <i class="fas fa-dollar-sign text-warning me-1"></i>
                                    Cost: <strong><?php echo $rec['estimated_cost'] > 0 ? '$' . $rec['estimated_cost'] : 'Free'; ?></strong>
                                </small>
                            </div>
                            
                            <?php if ($rec['resource_url']): ?>
                            <a href="<?php echo htmlspecialchars($rec['resource_url']); ?>" 
                               target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-external-link-alt me-1"></i>View Resource
                            </a>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3 text-center">
                            <span class="badge bg-<?php 
                                echo $rec['recommendation_type'] === 'certification' ? 'warning' : 
                                    ($rec['recommendation_type'] === 'course' ? 'info' : 'success'); 
                            ?> mb-2">
                                <?php echo ucfirst($rec['recommendation_type']); ?>
                            </span>
                            <div class="mt-3">
                                <button class="btn btn-sm btn-success w-100 mb-1" 
                                        onclick="markAsCompleted(<?php echo $rec['id']; ?>)">
                                    <i class="fas fa-check me-1"></i>Mark Done
                                </button>
                                <button class="btn btn-sm btn-outline-secondary w-100">
                                    <i class="fas fa-eye me-1"></i>Details
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Summary -->
                <?php if (!empty($recommendations)): ?>
                <div class="card mt-4">
                    <div class="card-body">
                        <h6><i class="fas fa-chart-bar me-2"></i>Summary</h6>
                        <p class="mb-2">
                            <strong>Total Expected Improvement:</strong> 
                            +<?php echo array_sum(array_column($recommendations, 'estimated_impact')); ?> points
                        </p>
                        <p class="mb-2">
                            <strong>Estimated Timeline:</strong> 
                            <?php echo array_sum(array_column($recommendations, 'estimated_duration_days')); ?> days
                            (<?php echo round(array_sum(array_column($recommendations, 'estimated_duration_days')) / 7, 1); ?> weeks)
                        </p>
                        <p class="mb-0">
                            <strong>Total Investment:</strong> 
                            $<?php echo array_sum(array_column($recommendations, 'estimated_cost')); ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php endif; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function markAsCompleted(recId) {
            if (confirm('Mark this recommendation as completed?')) {
                // Add AJAX call here to update status
                fetch('../api/update_recommendation.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id: recId, status: 'completed'})
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Marked as completed!');
                        location.reload();
                    }
                })
                .catch(error => console.error('Error:', error));
            }
        }
    </script>
</body>
</html>