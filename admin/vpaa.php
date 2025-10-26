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
$user_type = $_SESSION['user_type'];

// Get dashboard statistics
$stats = [];
try {
    // Total applications
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications");
    $stats['total_applications'] = $stmt->fetch()['total'];
    
    // Qualified applications
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE application_status = 'qualified'");
    $stats['qualified_applications'] = $stmt->fetch()['total'];
    
    // Partially qualified applications
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE application_status = 'partially_qualified'");
    $stats['partially_qualified_applications'] = $stmt->fetch()['total'];
    
    // Not qualified applications
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE application_status = 'not_qualified'");
    $stats['not_qualified_applications'] = $stmt->fetch()['total'];
    
    // Pending applications (submitted but not evaluated)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE application_status IN ('submitted', 'under_review')");
    $stats['pending_applications'] = $stmt->fetch()['total'];
    
    // Total candidates
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE user_type = 'candidate'");
    $stats['total_candidates'] = $stmt->fetch()['total'];
    
    // Average score
    $stmt = $pdo->query("SELECT AVG(total_score) as avg_score FROM applications WHERE total_score > 0");
    $avg_score = $stmt->fetch()['avg_score'];
    $stats['avg_score'] = $avg_score ? round($avg_score, 1) : 0;
    
    // Total programs
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM programs WHERE status = 'active'");
    $stats['total_programs'] = $stmt->fetch()['total'];
    
} catch (PDOException $e) {
    $stats = [
        'total_applications' => 0,
        'qualified_applications' => 0,
        'partially_qualified_applications' => 0,
        'not_qualified_applications' => 0,
        'pending_applications' => 0,
        'total_candidates' => 0,
        'avg_score' => 0,
        'total_programs' => 0
    ];
}

// Get recent applications
$recent_applications = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, p.program_name, p.program_code,
               CONCAT(u.first_name, ' ', u.last_name) as candidate_name,
               u.email as candidate_email
        FROM applications a 
        LEFT JOIN programs p ON a.program_id = p.id 
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.application_status NOT IN ('draft')
        ORDER BY a.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_applications = $stmt->fetchAll();
} catch (PDOException $e) {
    $recent_applications = [];
}

// Get applications by status for chart
$status_data = [];
try {
    $stmt = $pdo->query("
        SELECT application_status, COUNT(*) as count 
        FROM applications 
        WHERE application_status NOT IN ('draft')
        GROUP BY application_status
    ");
    $status_results = $stmt->fetchAll();
    foreach ($status_results as $result) {
        $status_data[$result['application_status']] = $result['count'];
    }
} catch (PDOException $e) {
    $status_data = [];
}

// Get program statistics
$program_stats = [];
try {
    $stmt = $pdo->query("
        SELECT 
            p.program_name,
            p.program_code,
            COUNT(a.id) as total_applications,
            SUM(CASE WHEN a.application_status = 'qualified' THEN 1 ELSE 0 END) as qualified,
            AVG(CASE WHEN a.total_score > 0 THEN a.total_score ELSE NULL END) as avg_score
        FROM programs p 
        LEFT JOIN applications a ON p.id = a.program_id
        WHERE p.status = 'active'
        GROUP BY p.id, p.program_name, p.program_code
        ORDER BY total_applications DESC
        LIMIT 5
    ");
    $program_stats = $stmt->fetchAll();
} catch (PDOException $e) {
    $program_stats = [];
}

// Get monthly application trends
$monthly_data = [];
try {
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count
        FROM applications 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            AND application_status NOT IN ('draft')
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $monthly_results = $stmt->fetchAll();
    foreach ($monthly_results as $result) {
        $monthly_data[] = [
            'month' => date('M Y', strtotime($result['month'] . '-01')),
            'count' => $result['count']
        ];
    }
} catch (PDOException $e) {
    $monthly_data = [];
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
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        body { margin: 0; padding-top: 0 !important; }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
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
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
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
            transform: translateY(-2px);
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
        .stat-icon {
            font-size: 2rem;
            opacity: 0.3;
            position: absolute;
            right: 1.5rem;
            top: 1.5rem;
        }
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            height: 100%;
        }
        .table-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-submitted { background-color: #cff4fc; color: #055160; }
        .status-under_review { background-color: #fff3cd; color: #664d03; }
        .status-qualified { background-color: #d1e7dd; color: #0f5132; }
        .status-partially_qualified { background-color: #ffeaa7; color: #d63031; }
        .status-not_qualified { background-color: #f8d7da; color: #721c24; }
        .main-content {
            padding: 2rem;
        }
        .page-title {
            color: #2c3e50;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }
        .user-info {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .user-info .user-name {
            color: white;
            font-weight: 600;
            margin: 0;
        }
        .user-info .user-role {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.875rem;
            margin: 0;
        }
        .vpaa-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-0">
                <div class="user-info">
                    <p class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                    <p class="user-role"><span class="vpaa-badge">VPAA</span></p>
                </div>
                
                <ul class="nav flex-column mt-3">
                    <li class="nav-item">
                        <a class="nav-link active" href="vpaa.php">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="view_applications.php">
                            <i class="fas fa-file-alt"></i> View Applications
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="view_reports.php">
                            <i class="fas fa-chart-bar"></i> Reports & Analytics
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="view_programs.php">
                            <i class="fas fa-graduation-cap"></i> Programs Overview
                        </a>
                    </li>
                    <li class="nav-item mt-4">
                        <a class="nav-link" href="../auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Main Content -->
            <div class="col-md-10 main-content">
                <div class="container-fluid">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h1 class="page-title">VPAA Dashboard</h1>
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
                            <div class="stat-card position-relative">
                                <i class="fas fa-file-alt stat-icon text-primary"></i>
                                <div class="stat-number text-primary"><?php echo $stats['total_applications']; ?></div>
                                <p class="stat-label">Total Applications</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card position-relative">
                                <i class="fas fa-check-circle stat-icon text-success"></i>
                                <div class="stat-number text-success"><?php echo $stats['qualified_applications']; ?></div>
                                <p class="stat-label">Qualified</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card position-relative">
                                <i class="fas fa-clock stat-icon text-warning"></i>
                                <div class="stat-number text-warning"><?php echo $stats['pending_applications']; ?></div>
                                <p class="stat-label">Pending Review</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card position-relative">
                                <i class="fas fa-percentage stat-icon text-info"></i>
                                <div class="stat-number text-info"><?php echo $stats['avg_score']; ?>%</div>
                                <p class="stat-label">Average Score</p>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Stats Row -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="stat-card position-relative">
                                <i class="fas fa-user-graduate stat-icon text-secondary"></i>
                                <div class="stat-number text-secondary"><?php echo $stats['total_candidates']; ?></div>
                                <p class="stat-label">Total Candidates</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card position-relative">
                                <i class="fas fa-graduation-cap stat-icon text-purple"></i>
                                <div class="stat-number text-primary"><?php echo $stats['total_programs']; ?></div>
                                <p class="stat-label">Active Programs</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card position-relative">
                                <i class="fas fa-star-half-alt stat-icon text-warning"></i>
                                <div class="stat-number text-warning"><?php echo $stats['partially_qualified_applications']; ?></div>
                                <p class="stat-label">Partially Qualified</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card position-relative">
                                <i class="fas fa-times-circle stat-icon text-danger"></i>
                                <div class="stat-number text-danger"><?php echo $stats['not_qualified_applications']; ?></div>
                                <p class="stat-label">Not Qualified</p>
                            </div>
                        </div>
                    </div>

                    <!-- Charts and Tables -->
                    <div class="row g-4">
                        <!-- Recent Applications Table -->
                        <div class="col-lg-8">
                            <div class="table-container">
                                <div class="p-3 border-bottom">
                                    <h5 class="mb-0">Recent Applications</h5>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Candidate</th>
                                                <th>Program</th>
                                                <th>Date Applied</th>
                                                <th>Status</th>
                                                <th>Score</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recent_applications)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">
                                                    <i class="fas fa-inbox fa-2x mb-2"></i>
                                                    <p>No applications found</p>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($recent_applications as $app): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($app['candidate_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($app['candidate_email']); ?></small>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($app['program_code']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($app['program_name']); ?></small>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($app['created_at'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $app['application_status']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $app['application_status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($app['total_score'] > 0): ?>
                                                        <span class="fw-bold text-primary"><?php echo $app['total_score']; ?>%</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="view_application_detail.php?id=<?php echo $app['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye me-1"></i>View
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Application Status Chart -->
                        <div class="col-lg-4">
                            <div class="chart-container">
                                <h5 class="mb-3">Application Status Distribution</h5>
                                <?php if (!empty($status_data)): ?>
                                <div id="statusChart" style="height: 250px;"></div>
                                <?php else: ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-chart-pie fa-3x mb-3"></i>
                                    <p>No data available</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Program Statistics -->
                    <?php if (!empty($program_stats)): ?>
                    <div class="row g-4 mt-2">
                        <div class="col-12">
                            <div class="table-container">
                                <div class="p-3 border-bottom">
                                    <h5 class="mb-0">Program Statistics (Top 5)</h5>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Program</th>
                                                <th>Code</th>
                                                <th>Total Applications</th>
                                                <th>Qualified</th>
                                                <th>Average Score</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($program_stats as $prog): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($prog['program_name']); ?></td>
                                                <td><span class="badge bg-primary"><?php echo htmlspecialchars($prog['program_code']); ?></span></td>
                                                <td><?php echo $prog['total_applications']; ?></td>
                                                <td><?php echo $prog['qualified']; ?></td>
                                                <td>
                                                    <?php if ($prog['avg_score']): ?>
                                                        <span class="fw-bold text-info"><?php echo round($prog['avg_score'], 1); ?>%</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Monthly Trends -->
                    <?php if (!empty($monthly_data)): ?>
                    <div class="row g-4 mt-2">
                        <div class="col-12">
                            <div class="chart-container">
                                <h5 class="mb-3">Application Trends (Last 12 Months)</h5>
                                <div id="monthlyChart" style="height: 300px;"></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Quick Links -->
                    <div class="row g-4 mt-2">
                        <div class="col-12">
                            <div class="stat-card">
                                <h5 class="mb-3">Quick Access</h5>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <a href="view_applications.php?status=qualified" class="btn btn-success w-100">
                                            <i class="fas fa-check-circle me-2"></i>
                                            View Qualified
                                            <div class="small">(<?php echo $stats['qualified_applications']; ?> applications)</div>
                                        </a>
                                    </div>
                                    <div class="col-md-4">
                                        <a href="view_applications.php?status=under_review" class="btn btn-warning w-100">
                                            <i class="fas fa-clock me-2"></i>
                                            Pending Review
                                            <div class="small">(<?php echo $stats['pending_applications']; ?> applications)</div>
                                        </a>
                                    </div>
                                    <div class="col-md-4">
                                        <a href="view_reports.php" class="btn btn-info w-100">
                                            <i class="fas fa-chart-line me-2"></i>
                                            View Reports
                                            <div class="small">Detailed analytics</div>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Status Distribution Chart
        <?php if (!empty($status_data)): ?>
        const statusData = <?php echo json_encode($status_data); ?>;
        const statusLabels = [];
        const statusCounts = [];
        const statusColors = [];
        
        const colorMap = {
            'submitted': '#0dcaf0',
            'under_review': '#ffc107',
            'qualified': '#198754',
            'partially_qualified': '#fd7e14',
            'not_qualified': '#dc3545'
        };
        
        for (const [status, count] of Object.entries(statusData)) {
            statusLabels.push(status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()));
            statusCounts.push(count);
            statusColors.push(colorMap[status] || '#6c757d');
        }
        
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusCounts,
                    backgroundColor: statusColors
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Monthly Trends Chart
        <?php if (!empty($monthly_data)): ?>
        const monthlyData = <?php echo json_encode($monthly_data); ?>;
        new Chart(document.getElementById('monthlyChart'), {
            type: 'line',
            data: {
                labels: monthlyData.map(item => item.month),
                datasets: [{
                    label: 'Applications',
                    data: monthlyData.map(item => item.count),
                    borderColor: '#1e3c72',
                    backgroundColor: 'rgba(30, 60, 114, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>






