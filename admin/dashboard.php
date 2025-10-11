<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
// require_once 'includes/functions.php';

// Check if user is logged in and is admin/evaluator
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'evaluator'])) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

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

// Get dashboard statistics
$stats = [];
try {
    // Total applications
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications");
    $stats['total_applications'] = $stmt->fetch()['total'];
    
    // Pending applications (submitted but not evaluated)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE application_status IN ('submitted', 'under_review')");
    $stats['pending_applications'] = $stmt->fetch()['total'];
    
    // Completed applications
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE application_status IN ('qualified', 'partially_qualified', 'not_qualified')");
    $stats['completed_applications'] = $stmt->fetch()['total'];
    
    // Total candidates
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE user_type = 'candidate'");
    $stats['total_candidates'] = $stmt->fetch()['total'];
    
    // Documents uploaded
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM documents");
    $stats['total_documents'] = $stmt->fetch()['total'];
    
    // Average score
    $stmt = $pdo->query("SELECT AVG(total_score) as avg_score FROM applications WHERE total_score > 0");
    $avg_score = $stmt->fetch()['avg_score'];
    $stats['avg_score'] = $avg_score ? round($avg_score, 1) : 0;
    
} catch (PDOException $e) {
    $stats = [
        'total_applications' => 0,
        'pending_applications' => 0,
        'completed_applications' => 0,
        'total_candidates' => 0,
        'total_documents' => 0,
        'avg_score' => 0
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
        GROUP BY application_status
    ");
    $status_results = $stmt->fetchAll();
    foreach ($status_results as $result) {
        $status_data[$result['application_status']] = $result['count'];
    }
} catch (PDOException $e) {
    $status_data = [];
}

$monthly_data = [];
try {
    // raw counts from DB
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
        FROM applications
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['YYYY-MM' => count]

    // build last 6 months inclusive (oldest -> newest)
    for ($i = 5; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-$i months"));
        $label = date('M Y', strtotime("$ym-01"));
        $monthly_data[] = ['month' => $label, 'count' => isset($rows[$ym]) ? (int)$rows[$ym] : 0];
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
    <title>Admin Dashboard - ETEEAP</title>
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
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
        }

                /* Add to your existing <style> section */
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
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            height: 100%;
        }

.chart-container { height: 100%; max-height: 320px; overflow: hidden; }
#statusChart { height: 250px !important; }  /* siguraduhin pasok sa container */


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
        .status-draft { background-color: #e9ecef; color: #495057; }
        .status-submitted { background-color: #cff4fc; color: #055160; }
        .status-under_review { background-color: #fff3cd; color: #664d03; }
        .status-qualified { background-color: #d1e7dd; color: #0f5132; }
        .status-partially_qualified { background-color: #ffeaa7; color: #d63031; }
        .status-not_qualified { background-color: #f8d7da; color: #721c24; }
        .main-content {
            min-height: 100vh;
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

    <?php if ($user_type === 'admin'): ?>
        <a class="nav-link" href="users.php">
            <i class="fas fa-users me-2"></i>
            Manage Users
        </a>
        <a class="nav-link" href="programs.php">
            <i class="fas fa-graduation-cap me-2"></i>
            Manage Programs
        </a>
    <?php endif; ?>

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
            <div class="col-md-9 col-lg-10 main-content">
                <div class="p-4">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 class="mb-1">Dashboard</h2>
                            <p class="text-muted mb-0">Overview of ETEEAP applications and system statistics</p>
                        </div>
                        <div>
                            <span class="badge bg-primary fs-6">
                                <i class="fas fa-shield-alt me-1"></i>
                                <?php echo ucfirst($user_type); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Statistics Cards -->

                    
                    <div class="row g-4 mb-4">
                          <div class="col-md-6 col-lg-3">
                            <div class="stat-card">
                                <i class="fas fa-users fa-2x text-info mb-2"></i>
                                <div class="stat-number text-info"><?php echo number_format($stats['total_candidates']); ?></div>
                                <div class="stat-label">Total Candidates</div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="stat-card">
                                <i class="fas fa-file-alt fa-2x text-primary mb-2"></i>
                                <div class="stat-number text-primary"><?php echo number_format($stats['total_applications']); ?></div>
                                <div class="stat-label">Total Applications</div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="stat-card">
                                <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                                <div class="stat-number text-warning"><?php echo number_format($stats['pending_applications']); ?></div>
                                <div class="stat-label">Pending Review</div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="stat-card">
                                <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                <div class="stat-number text-success"><?php echo number_format($stats['completed_applications']); ?></div>
                                <div class="stat-label">Completed</div>
                            </div>
                        </div>
                      
                    </div>

                    <!-- Secondary Stats -->
                    <div class="row g-4 mb-4">
                        <!-- <div class="col-md-6 col-lg-3">
                            <div class="stat-card">
                                <i class="fas fa-folder fa-2x text-secondary mb-2"></i>
                                <div class="stat-number text-secondary"><?php echo number_format($stats['total_documents']); ?></div>
                                <div class="stat-label">Documents Uploaded</div>
                            </div>
                        </div> -->
                        <div class="col-md-6 col-lg-3">
                            <div class="stat-card">
                                <i class="fas fa-percentage fa-2x text-success mb-2"></i>
                                <div class="stat-number text-success"><?php echo $stats['avg_score']; ?>%</div>
                                <div class="stat-label">Average Score</div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="stat-card">
                                <i class="fas fa-calendar-day fa-2x text-primary mb-2"></i>
                                <div class="stat-number text-primary"><?php echo date('j'); ?></div>
                                <div class="stat-label"><?php echo date('F Y'); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="stat-card">
                                <i class="fas fa-bolt fa-2x text-warning mb-2"></i>
                                <div class="stat-number text-warning">Live</div>
                                <div class="stat-label">System Status</div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <!-- Recent Applications -->
                        <div class="col-lg-8">
                            <div class="table-container">
                                <div class="p-3 border-bottom">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">Recent Applications</h5>
                                        <a href="evaluate.php" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-eye me-1"></i>View All
                                        </a>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>Candidate</th>
                                                <th>Program</th>
                                                <th>Applied</th>
                                                <th>Status</th>
                                                <th>Score</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recent_applications)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">
                                                    <i class="fas fa-inbox fa-2x mb-2"></i><br>
                                                    No applications found
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
                                                    <a href="evaluate.php?id=<?php echo $app['id']; ?>" class="btn btn-sm btn-outline-primary">
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
                                <canvas id="statusChart" height="250"></canvas>
                                <?php else: ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-chart-pie fa-3x mb-3"></i>
                                    <p>No data available</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Trends -->
                    <?php if (!empty($monthly_data)): ?>
                    <div class="row g-4 mt-2">
                        <div class="col-12">
                            <div class="chart-container">
                                <h5 class="mb-3">Monthly Application Trends (Last 6 Months)</h5>
                                <canvas id="monthlyChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Quick Actions -->
                    <div class="row g-4 mt-2">
                        <div class="col-12">
                            <div class="stat-card">
                                <h5 class="mb-3">Quick Actions</h5>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <a href="evaluate.php?status=submitted" class="btn btn-warning w-100">
                                            <i class="fas fa-clipboard-check me-2"></i>
                                            Review Pending
                                            <div class="small">
                                                (<?php echo $stats['pending_applications']; ?> waiting)
                                            </div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="reports.php" class="btn btn-info w-100">
                                            <i class="fas fa-chart-bar me-2"></i>
                                            Generate Report
                                            <div class="small">View analytics</div>
                                        </a>
                                    </div>
                                    <?php if ($user_type === 'admin'): ?>
                                    <div class="col-md-3">
                                        <a href="users.php" class="btn btn-success w-100">
                                            <i class="fas fa-users me-2"></i>
                                            Manage Users
                                            <div class="small">(<?php echo $stats['total_candidates']; ?> candidates)</div>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="programs.php" class="btn btn-primary w-100">
                                            <i class="fas fa-graduation-cap me-2"></i>
                                            Manage Programs
                                            <div class="small">Configure system</div>
                                        </a>
                                    </div>
                                    <?php endif; ?>
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
const labels = [];
const counts = [];
const colors = [];
const colorMap = {
  draft:'#6c757d', submitted:'#0dcaf0', under_review:'#ffc107',
  qualified:'#198754', partially_qualified:'#fd7e14', not_qualified:'#dc3545'
};

for (const [k,v] of Object.entries(statusData)) {
  labels.push(k.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase()));
  counts.push(v);
  colors.push(colorMap[k] || '#6c757d');
}

const ctxStatus = document.getElementById('statusChart').getContext('2d');
new Chart(ctxStatus, {
  type: 'doughnut',
  data: { labels, datasets:[{ data: counts, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins:{ legend:{ position:'bottom', labels:{ usePointStyle:true, padding:16 } } }
  }
});

<?php endif; ?>

        
    <?php if (!empty($monthly_data)): ?>

const monthlyData = <?php echo json_encode($monthly_data); ?>;
const ctxMonthly = document.getElementById('monthlyChart').getContext('2d');
new Chart(ctxMonthly, {
  type: 'line',
  data: {
    labels: monthlyData.map(x => x.month),
    datasets: [{
      label: 'Applications',
      data: monthlyData.map(x => x.count),
      borderColor: '#667eea',
      backgroundColor: 'rgba(102,126,234,0.12)',
      fill: true, tension: 0.35, pointRadius: 3
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    plugins: { legend: { display: false } }
  }
});

<?php endif; ?>
    </script>
</body>
</html>