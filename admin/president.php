<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Check if user is logged in and is President
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'president') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Get executive dashboard statistics
$stats = [];
try {
    // Total applications
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications");
    $stats['total_applications'] = $stmt->fetch()['total'];
    
    // Qualified applications
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE application_status = 'qualified'");
    $stats['qualified_applications'] = $stmt->fetch()['total'];
    
    // Success rate (qualified / total evaluated)
    $stmt = $pdo->query("
        SELECT COUNT(*) as evaluated 
        FROM applications 
        WHERE application_status IN ('qualified', 'partially_qualified', 'not_qualified')
    ");
    $evaluated = $stmt->fetch()['evaluated'];
    $stats['success_rate'] = $evaluated > 0 ? round(($stats['qualified_applications'] / $evaluated) * 100, 1) : 0;
    
    // Pending applications
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
    
    // Total evaluators
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE user_type = 'evaluator' AND status = 'active'");
    $stats['total_evaluators'] = $stmt->fetch()['total'];
    
    // This month's applications
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM applications 
        WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
        AND YEAR(created_at) = YEAR(CURRENT_DATE())
    ");
    $stats['this_month_applications'] = $stmt->fetch()['total'];
    
    // Last month's applications for comparison
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM applications 
        WHERE MONTH(created_at) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
        AND YEAR(created_at) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
    ");
    $last_month = $stmt->fetch()['total'];
    $stats['month_change'] = $last_month > 0 ? round((($stats['this_month_applications'] - $last_month) / $last_month) * 100, 1) : 0;
    
} catch (PDOException $e) {
    $stats = [
        'total_applications' => 0,
        'qualified_applications' => 0,
        'success_rate' => 0,
        'pending_applications' => 0,
        'total_candidates' => 0,
        'avg_score' => 0,
        'total_programs' => 0,
        'total_evaluators' => 0,
        'this_month_applications' => 0,
        'month_change' => 0
    ];
}

// Get applications by status for overview
$status_overview = [];
try {
    $stmt = $pdo->query("
        SELECT application_status, COUNT(*) as count 
        FROM applications 
        WHERE application_status NOT IN ('draft')
        GROUP BY application_status
        ORDER BY count DESC
    ");
    $status_overview = $stmt->fetchAll();
} catch (PDOException $e) {
    $status_overview = [];
}

// Get program performance summary
$program_performance = [];
try {
    $stmt = $pdo->query("
        SELECT 
            p.program_name,
            p.program_code,
            COUNT(a.id) as total_applications,
            SUM(CASE WHEN a.application_status = 'qualified' THEN 1 ELSE 0 END) as qualified,
            SUM(CASE WHEN a.application_status IN ('qualified', 'partially_qualified', 'not_qualified') THEN 1 ELSE 0 END) as evaluated,
            AVG(CASE WHEN a.total_score > 0 THEN a.total_score ELSE NULL END) as avg_score
        FROM programs p 
        LEFT JOIN applications a ON p.id = a.program_id
        WHERE p.status = 'active'
        GROUP BY p.id, p.program_name, p.program_code
        HAVING total_applications > 0
        ORDER BY total_applications DESC
        LIMIT 10
    ");
    $program_performance = $stmt->fetchAll();
} catch (PDOException $e) {
    $program_performance = [];
}

// Get yearly trends
$yearly_data = [];
try {
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as total,
            SUM(CASE WHEN application_status = 'qualified' THEN 1 ELSE 0 END) as qualified
        FROM applications 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            AND application_status NOT IN ('draft')
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $yearly_results = $stmt->fetchAll();
    foreach ($yearly_results as $result) {
        $yearly_data[] = [
            'month' => date('M Y', strtotime($result['month'] . '-01')),
            'total' => $result['total'],
            'qualified' => $result['qualified']
        ];
    }
} catch (PDOException $e) {
    $yearly_data = [];
}

// Get department/college distribution
$department_stats = [];
try {
    $stmt = $pdo->query("
        SELECT 
            p.department,
            COUNT(a.id) as total_applications,
            SUM(CASE WHEN a.application_status = 'qualified' THEN 1 ELSE 0 END) as qualified
        FROM applications a
        LEFT JOIN programs p ON a.program_id = p.id
        WHERE a.application_status NOT IN ('draft')
        GROUP BY p.department
        HAVING p.department IS NOT NULL
        ORDER BY total_applications DESC
    ");
    $department_stats = $stmt->fetchAll();
} catch (PDOException $e) {
    $department_stats = [];
}

// Get recent activity summary
$recent_summary = [];
try {
    $stmt = $pdo->query("
        SELECT 
            DATE(created_at) as activity_date,
            COUNT(*) as applications,
            SUM(CASE WHEN application_status = 'qualified' THEN 1 ELSE 0 END) as qualified
        FROM applications 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND application_status NOT IN ('draft')
        GROUP BY DATE(created_at)
        ORDER BY activity_date DESC
    ");
    $recent_summary = $stmt->fetchAll();
} catch (PDOException $e) {
    $recent_summary = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>President Dashboard - ETEEAP</title>
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
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, #134e5e 0%, #71b280 100%);
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
        .stat-trend {
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }
        .trend-up {
            color: #198754;
        }
        .trend-down {
            color: #dc3545;
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
        .main-content {
            padding: 2rem;
        }
        .page-title {
            color: #2c3e50;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .page-subtitle {
            color: #6c757d;
            font-size: 1rem;
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
        .president-badge {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .executive-summary {
            background: linear-gradient(135deg, #134e5e 0%, #71b280 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .executive-summary h4 {
            font-weight: 700;
            margin-bottom: 1rem;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        .summary-item:last-child {
            border-bottom: none;
        }
        .performance-indicator {
            display: inline-block;
            width: 50px;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        .performance-fill {
            height: 100%;
            background: linear-gradient(90deg, #71b280, #134e5e);
            transition: width 0.3s ease;
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
                    <p class="user-role"><span class="president-badge">President</span></p>
                </div>
                
                <ul class="nav flex-column mt-3">
                    <li class="nav-item">
                        <a class="nav-link active" href="president.php">
                            <i class="fas fa-chart-line"></i> Executive Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="president_reports.php">
                            <i class="fas fa-file-chart"></i> Strategic Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="president_programs.php">
                            <i class="fas fa-graduation-cap"></i> Program Overview
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="president_analytics.php">
                            <i class="fas fa-analytics"></i> Analytics & Insights
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
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h1 class="page-title">Executive Dashboard</h1>
                            <p class="page-subtitle">Comprehensive overview of ETEEAP performance and metrics</p>
                        </div>
                        <div>
                            <span class="text-muted">
                                <i class="fas fa-calendar-alt me-2"></i>
                                <?php echo date('l, F j, Y'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Executive Summary Box -->
                    <div class="executive-summary">
                        <h4><i class="fas fa-briefcase me-2"></i>Executive Summary</h4>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="summary-item">
                                    <span>Success Rate:</span>
                                    <strong><?php echo $stats['success_rate']; ?>%</strong>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-item">
                                    <span>Avg. Score:</span>
                                    <strong><?php echo $stats['avg_score']; ?>%</strong>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-item">
                                    <span>This Month:</span>
                                    <strong><?php echo $stats['this_month_applications']; ?> apps</strong>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="summary-item">
                                    <span>Growth:</span>
                                    <strong class="<?php echo $stats['month_change'] >= 0 ? 'trend-up' : 'trend-down'; ?>">
                                        <?php echo $stats['month_change'] >= 0 ? '+' : ''; ?><?php echo $stats['month_change']; ?>%
                                    </strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Key Metrics Cards -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-file-alt stat-icon text-primary"></i>
                                <div class="stat-number text-primary"><?php echo $stats['total_applications']; ?></div>
                                <p class="stat-label">Total Applications</p>
                                <div class="stat-trend <?php echo $stats['month_change'] >= 0 ? 'trend-up' : 'trend-down'; ?>">
                                    <i class="fas fa-arrow-<?php echo $stats['month_change'] >= 0 ? 'up' : 'down'; ?> me-1"></i>
                                    <?php echo abs($stats['month_change']); ?>% from last month
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-check-circle stat-icon text-success"></i>
                                <div class="stat-number text-success"><?php echo $stats['qualified_applications']; ?></div>
                                <p class="stat-label">Qualified</p>
                                <div class="stat-trend text-muted">
                                    <?php echo $stats['success_rate']; ?>% success rate
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-user-graduate stat-icon text-info"></i>
                                <div class="stat-number text-info"><?php echo $stats['total_candidates']; ?></div>
                                <p class="stat-label">Total Candidates</p>
                                <div class="stat-trend text-muted">
                                    Across <?php echo $stats['total_programs']; ?> programs
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-star stat-icon text-warning"></i>
                                <div class="stat-number text-warning"><?php echo $stats['avg_score']; ?>%</div>
                                <p class="stat-label">Average Score</p>
                                <div class="stat-trend text-muted">
                                    System-wide performance
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Secondary Metrics -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-clock stat-icon text-secondary"></i>
                                <div class="stat-number text-secondary"><?php echo $stats['pending_applications']; ?></div>
                                <p class="stat-label">Pending Review</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-graduation-cap stat-icon text-primary"></i>
                                <div class="stat-number text-primary"><?php echo $stats['total_programs']; ?></div>
                                <p class="stat-label">Active Programs</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-users stat-icon text-success"></i>
                                <div class="stat-number text-success"><?php echo $stats['total_evaluators']; ?></div>
                                <p class="stat-label">Active Evaluators</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-calendar-check stat-icon text-info"></i>
                                <div class="stat-number text-info"><?php echo $stats['this_month_applications']; ?></div>
                                <p class="stat-label">This Month</p>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="row g-4 mb-4">
                        <!-- Yearly Trends -->
                        <div class="col-lg-8">
                            <div class="chart-container">
                                <h5 class="mb-3">Application & Qualification Trends (12 Months)</h5>
                                <?php if (!empty($yearly_data)): ?>
                                <div id="yearlyChart" style="height: 350px;"></div>
                                <?php else: ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-chart-line fa-3x mb-3"></i>
                                    <p>No trend data available</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Status Overview -->
                        <div class="col-lg-4">
                            <div class="chart-container">
                                <h5 class="mb-3">Current Status Distribution</h5>
                                <?php if (!empty($status_overview)): ?>
                                <div class="mt-4">
                                    <?php foreach ($status_overview as $status): ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between mb-1">
                                            <small class="text-muted"><?php echo ucfirst(str_replace('_', ' ', $status['application_status'])); ?></small>
                                            <small class="fw-bold"><?php echo $status['count']; ?></small>
                                        </div>
                                        <div class="performance-indicator">
                                            <div class="performance-fill" style="width: <?php echo ($status['count'] / $stats['total_applications']) * 100; ?>%"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-chart-pie fa-3x mb-3"></i>
                                    <p>No data available</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Program Performance Table -->
                    <?php if (!empty($program_performance)): ?>
                    <div class="row g-4 mb-4">
                        <div class="col-12">
                            <div class="table-container">
                                <div class="p-3 border-bottom">
                                    <h5 class="mb-0">Program Performance Summary</h5>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Program</th>
                                                <th>Code</th>
                                                <th>Applications</th>
                                                <th>Evaluated</th>
                                                <th>Qualified</th>
                                                <th>Success Rate</th>
                                                <th>Avg. Score</th>
                                                <th>Performance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($program_performance as $prog): ?>
                                            <?php 
                                                $prog_success_rate = $prog['evaluated'] > 0 ? round(($prog['qualified'] / $prog['evaluated']) * 100, 1) : 0;
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($prog['program_name']); ?></td>
                                                <td><span class="badge bg-primary"><?php echo htmlspecialchars($prog['program_code']); ?></span></td>
                                                <td><strong><?php echo $prog['total_applications']; ?></strong></td>
                                                <td><?php echo $prog['evaluated']; ?></td>
                                                <td class="text-success fw-bold"><?php echo $prog['qualified']; ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $prog_success_rate >= 70 ? 'success' : ($prog_success_rate >= 50 ? 'warning' : 'danger'); ?>">
                                                        <?php echo $prog_success_rate; ?>%
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($prog['avg_score']): ?>
                                                        <span class="fw-bold text-info"><?php echo round($prog['avg_score'], 1); ?>%</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="performance-indicator">
                                                        <div class="performance-fill" style="width: <?php echo $prog_success_rate; ?>%"></div>
                                                    </div>
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

                    <!-- Department Statistics -->
                    <?php if (!empty($department_stats)): ?>
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <div class="table-container">
                                <div class="p-3 border-bottom">
                                    <h5 class="mb-0">Department/College Distribution</h5>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Department</th>
                                                <th>Applications</th>
                                                <th>Qualified</th>
                                                <th>Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($department_stats as $dept): ?>
                                            <?php 
                                                $dept_rate = $dept['total_applications'] > 0 ? round(($dept['qualified'] / $dept['total_applications']) * 100, 1) : 0;
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($dept['department'] ?: 'Not Specified'); ?></td>
                                                <td><strong><?php echo $dept['total_applications']; ?></strong></td>
                                                <td class="text-success"><?php echo $dept['qualified']; ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $dept_rate >= 70 ? 'success' : ($dept_rate >= 50 ? 'warning' : 'secondary'); ?>">
                                                        <?php echo $dept_rate; ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Activity -->
                        <div class="col-md-6">
                            <div class="table-container">
                                <div class="p-3 border-bottom">
                                    <h5 class="mb-0">Recent Activity (Last 7 Days)</h5>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Applications</th>
                                                <th>Qualified</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($recent_summary)): ?>
                                                <?php foreach ($recent_summary as $day): ?>
                                                <tr>
                                                    <td><?php echo date('M j, Y', strtotime($day['activity_date'])); ?></td>
                                                    <td><strong><?php echo $day['applications']; ?></strong></td>
                                                    <td class="text-success"><?php echo $day['qualified']; ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="text-center text-muted py-3">No recent activity</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Quick Actions -->
                    <div class="row g-4">
                        <div class="col-12">
                            <div class="stat-card">
                                <h5 class="mb-3">Executive Actions</h5>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <a href="president_reports.php" class="btn btn-primary w-100">
                                            <i class="fas fa-file-chart me-2"></i>
                                            Strategic Reports
                                            <div class="small">Comprehensive analysis</div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="president_programs.php" class="btn btn-success w-100">
                                            <i class="fas fa-graduation-cap me-2"></i>
                                            Program Overview
                                            <div class="small">View all programs</div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="president_analytics.php" class="btn btn-info w-100">
                                            <i class="fas fa-analytics me-2"></i>
                                            Deep Analytics
                                            <div class="small">Advanced insights</div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="president_export.php" class="btn btn-secondary w-100">
                                            <i class="fas fa-download me-2"></i>
                                            Export Data
                                            <div class="small">Download reports</div>
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
        // Yearly Trends Chart
        <?php if (!empty($yearly_data)): ?>
        const yearlyData = <?php echo json_encode($yearly_data); ?>;
        new Chart(document.getElementById('yearlyChart'), {
            type: 'line',
            data: {
                labels: yearlyData.map(item => item.month),
                datasets: [
                    {
                        label: 'Total Applications',
                        data: yearlyData.map(item => item.total),
                        borderColor: '#134e5e',
                        backgroundColor: 'rgba(19, 78, 94, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Qualified',
                        data: yearlyData.map(item => item.qualified),
                        borderColor: '#71b280',
                        backgroundColor: 'rgba(113, 178, 128, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
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
                },
                plugins: {
                    legend: {
                        position: 'top'
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>