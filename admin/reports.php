<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin/evaluator
requireAuth(['admin', 'evaluator']);

$user_type = $_SESSION['user_type'];

// Date range for reports (default to last 30 days)
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$program_filter = $_GET['program'] ?? '';
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
// Get comprehensive statistics
$stats = [];
try {
    // Basic counts
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications");
    $stats['total_applications'] = $stmt->fetch()['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE user_type = 'candidate'");
    $stats['total_candidates'] = $stmt->fetch()['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM documents");
    $stats['total_documents'] = $stmt->fetch()['total'];
    
    // Applications in date range
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total FROM applications 
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$start_date, $end_date]);
    $stats['period_applications'] = $stmt->fetch()['total'];
    
    // Average processing time (from submission to evaluation)
    $stmt = $pdo->query("
        SELECT AVG(DATEDIFF(evaluation_date, submission_date)) as avg_days
        FROM applications 
        WHERE submission_date IS NOT NULL AND evaluation_date IS NOT NULL
    ");
    $avg_processing = $stmt->fetch()['avg_days'];
    $stats['avg_processing_days'] = $avg_processing ? round($avg_processing, 1) : 0;
    
    // Success rate
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_evaluated,
            SUM(CASE WHEN application_status IN ('qualified', 'partially_qualified') THEN 1 ELSE 0 END) as successful
        FROM applications 
        WHERE application_status IN ('qualified', 'partially_qualified', 'not_qualified')
    ");
    $success_data = $stmt->fetch();
    $stats['success_rate'] = $success_data['total_evaluated'] > 0 ? 
        round(($success_data['successful'] / $success_data['total_evaluated']) * 100, 1) : 0;
    
    // Average score
    $stmt = $pdo->query("SELECT AVG(total_score) as avg_score FROM applications WHERE total_score > 0");
    $avg_score = $stmt->fetch()['avg_score'];
    $stats['avg_score'] = $avg_score ? round($avg_score, 1) : 0;
    
} catch (PDOException $e) {
    $stats = array_fill_keys([
        'total_applications', 'total_candidates', 'total_documents', 
        'period_applications', 'avg_processing_days', 'success_rate', 'avg_score'
    ], 0);
}

// Get status distribution
$status_distribution = [];
try {
    $stmt = $pdo->query("
        SELECT application_status, COUNT(*) as count
        FROM applications 
        GROUP BY application_status
        ORDER BY count DESC
    ");
    while ($row = $stmt->fetch()) {
        $status_distribution[] = [
            'status' => getStatusDisplayName($row['application_status']),
            'count' => $row['count'],
            'color' => getStatusColor($row['application_status'])
        ];
    }
} catch (PDOException $e) {
    $status_distribution = [];
}

// Get program statistics
$program_stats = [];
try {
    $stmt = $pdo->query("
        SELECT 
            p.program_name,
            p.program_code,
            COUNT(a.id) as total_applications,
            AVG(CASE WHEN a.total_score > 0 THEN a.total_score END) as avg_score,
            COUNT(CASE WHEN a.application_status = 'qualified' THEN 1 END) as qualified_count,
            COUNT(CASE WHEN a.application_status = 'partially_qualified' THEN 1 END) as partial_count
        FROM programs p
        LEFT JOIN applications a ON p.id = a.program_id
        GROUP BY p.id, p.program_name, p.program_code
        ORDER BY total_applications DESC
    ");
    $program_stats = $stmt->fetchAll();
} catch (PDOException $e) {
    $program_stats = [];
}

// Get monthly trends (last 12 months)
$monthly_trends = [];
try {
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as applications,
            COUNT(CASE WHEN application_status IN ('qualified', 'partially_qualified') THEN 1 END) as successful
        FROM applications 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    while ($row = $stmt->fetch()) {
        $monthly_trends[] = [
            'month' => date('M Y', strtotime($row['month'] . '-01')),
            'applications' => $row['applications'],
            'successful' => $row['successful'],
            'success_rate' => $row['applications'] > 0 ? round(($row['successful'] / $row['applications']) * 100, 1) : 0
        ];
    }
} catch (PDOException $e) {
    $monthly_trends = [];
}

// Get top documents by type
$document_stats = [];
try {
    $stmt = $pdo->query("
        SELECT 
            document_type,
            COUNT(*) as count,
            AVG(file_size) as avg_size
        FROM documents 
        GROUP BY document_type
        ORDER BY count DESC
    ");
    while ($row = $stmt->fetch()) {
        $document_stats[] = [
            'type' => getDocumentTypeDisplayName($row['document_type']),
            'count' => $row['count'],
            'avg_size' => formatFileSize($row['avg_size'])
        ];
    }
} catch (PDOException $e) {
    $document_stats = [];
}

// Get evaluator performance (for admins only)
$evaluator_stats = [];
if ($user_type === 'admin') {
    try {
        $stmt = $pdo->query("
            SELECT 
                CONCAT(u.first_name, ' ', u.last_name) as evaluator_name,
                COUNT(a.id) as applications_evaluated,
                AVG(a.total_score) as avg_score_given,
                AVG(DATEDIFF(a.evaluation_date, a.submission_date)) as avg_processing_days
            FROM users u
            LEFT JOIN applications a ON u.id = a.evaluator_id
            WHERE u.user_type IN ('admin', 'evaluator')
            GROUP BY u.id, u.first_name, u.last_name
            HAVING applications_evaluated > 0
            ORDER BY applications_evaluated DESC
        ");
        $evaluator_stats = $stmt->fetchAll();
    } catch (PDOException $e) {
        $evaluator_stats = [];
    }
}

// Helper function for status colors
function getStatusColor($status) {
    $colors = [
        'draft' => '#6c757d',
        'submitted' => '#0dcaf0',
        'under_review' => '#ffc107',
        'qualified' => '#198754',
        'partially_qualified' => '#fd7e14',
        'not_qualified' => '#dc3545'
    ];
    return $colors[$status] ?? '#6c757d';
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - ETEEAP</title>
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

        .report-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: none;
            transition: transform 0.3s ease;
        }
        .report-card:hover {
            transform: translateY(-2px);
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: none;
            height: 100%;
            text-align: center;
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
    /* dati: height: 100%; */
.chart-container {
  background: white;
  border-radius: 15px;
  padding: 1.5rem;
  box-shadow: 0 3px 10px rgba(0,0,0,0.1);
  height: auto;            /* ✅ wag fixed/100% */
}

/* kontrolin ang aktwal na taas ng canvas */
.chart-container canvas {
  display: block;
  width: 100% !important;
  height: 320px !important; /* ✅ pili ka 260–360px */
  max-height: 360px;
}

        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        .progress {
            height: 8px;
        }

        /* Utility */
.page-break { break-before: page; }

/* Default canvas height for screen already set in your CSS */

@media print {
  /* Hide non-report UI */
  .sidebar,
  .filter-card,
  .dropdown,
  .btn,
  nav,
  .d-flex.gap-2 { display: none !important; }

  /* Expand main area */
  .col-md-9, .col-lg-10 { width: 100% !important; }

  /* Clean card look on paper */
  .report-card, .stat-card, .chart-container {
    box-shadow: none !important;
    border: 1px solid #ddd !important;
    break-inside: avoid;
    page-break-inside: avoid;
    background: #fff !important;
  }

  /* Charts height for print */
  .chart-container canvas { max-height: 300px !important; }

  /* Show print header/footer */
  #print-header, #print-footer { display: block !important; }

  /* Page margins and color fidelity */
  @page { size: A4 portrait; margin: 16mm; }
  body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

  /* Tables */
  table.table { border-collapse: collapse !important; }
  table.table th, table.table td { border: 1px solid #e5e7eb !important; }
}
#print-report .print-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 10px;
  font-size: 12px;
}
#print-report .print-table th,
#print-report .print-table td {
  border: 1px solid #e5e7eb;
  padding: 6px 8px;
  vertical-align: top;
}
#print-report .print-table thead th {
  background: #f3f4f6;
  font-weight: 700;
}
#print-report .muted { color: #6b7280; }

/* Show/Hide logic for printing */
@media print {
  #screen-report { display: none !important; }  /* hide app UI */
  #print-report  { display: block !important; } /* show tabular */
  @page { size: A4 portrait; margin: 14mm; }
  body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
    </style>
</head>
<body>

<!-- PRINT-ONLY HEADER -->
<!-- ===================== PRINT-ONLY TABULAR REPORT ====================== -->
<div id="print-report" class="d-none d-print-block">
  <!-- Header -->
     <div class="container-fluid">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;">
    <!-- Optional logo -->
    <!-- <img src="../assets/img/logo.png" alt="Logo" style="height:40px;"> -->
    <div>
      <h2 style="margin:0;">ETEEAP Reports &amp; Analytics</h2>
      <div style="font-size:12px;color:#444;line-height:1.4;">
        Date Range:
        <?php
          $pr_s = $start_date ? date('m/d/Y', strtotime($start_date)) : '—';
          $pr_e = $end_date   ? date('m/d/Y', strtotime($end_date))   : '—';
          echo htmlspecialchars("$pr_s to $pr_e");
        ?><br>
        Generated at: <?php echo date('Y-m-d H:i:s'); ?><br>
        Prepared by: <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>
      </div>
    </div>
  </div>
  <hr style="margin:8px 0 14px 0;">

  <!-- Summary KPIs -->
  <h4 style="margin:0 0 6px;">Summary</h4>
  <table class="print-table">
    <tbody>
      <tr>
        <th>Total Applications</th>
        <td><?php echo (int)$stats['total_applications']; ?></td>
        <th>In Selected Period</th>
        <td><?php echo (int)$stats['period_applications']; ?></td>
      </tr>
      <tr>
        <th>Total Candidates</th>
        <td><?php echo (int)$stats['total_candidates']; ?></td>
        <th>Total Documents</th>
        <td><?php echo (int)$stats['total_documents']; ?></td>
      </tr>
      <tr>
        <th>Success Rate</th>
        <td><?php echo number_format($stats['success_rate'],1); ?>%</td>
        <th>Avg Processing Days</th>
        <td><?php echo number_format($stats['avg_processing_days'],1); ?></td>
      </tr>
      <tr>
        <th>Average Score</th>
        <td><?php echo number_format($stats['avg_score'],1); ?>%</td>
        <th>Current Period</th>
        <td><?php echo date('F Y'); ?></td>
      </tr>
    </tbody>
  </table>

  <!-- Status Distribution -->
  <h4 style="margin:16px 0 6px;">Application Status Distribution</h4>
  <table class="print-table">
    <thead>
      <tr>
        <th style="width:40%;">Status</th>
        <th style="width:20%;">Count</th>
        <th style="width:40%;">% of Total</th>
      </tr>
    </thead>
    <tbody>
    <?php
      $__total = max(1, (int)$stats['total_applications']);
      if (!empty($status_distribution)):
        foreach ($status_distribution as $row):
          $cnt = (int)$row['count'];
          $pct = round(($cnt / $__total) * 100, 1);
    ?>
      <tr>
        <td><?php echo htmlspecialchars($row['status']); ?></td>
        <td><?php echo $cnt; ?></td>
        <td><?php echo $pct; ?>%</td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="3" class="muted">No data</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <!-- Monthly Trends -->
  <h4 style="margin:16px 0 6px;">Monthly Trends</h4>
  <table class="print-table">
    <thead>
      <tr>
        <th>Month</th>
        <th>Applications</th>
        <th>Successful</th>
        <th>Success Rate</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!empty($monthly_trends)): foreach ($monthly_trends as $m): ?>
      <tr>
        <td><?php echo htmlspecialchars($m['month']); ?></td>
        <td><?php echo (int)$m['applications']; ?></td>
        <td><?php echo (int)$m['successful']; ?></td>
        <td><?php echo number_format($m['success_rate'],1); ?>%</td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="4" class="muted">No data (last 12 months)</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <!-- Program Performance -->
  <h4 style="margin:16px 0 6px;">Program Performance</h4>
  <table class="print-table">
    <thead>
      <tr>
        <th>Program Code</th>
        <th>Program Name</th>
        <th>Applications</th>
        <th>Avg Score</th>
        <th>Qualified</th>
        <th>Partial</th>
        <th>Success Rate</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!empty($program_stats)): foreach ($program_stats as $p): 
        $apps = (int)$p['total_applications'];
        $succ = $apps > 0 ? round((($p['qualified_count'] + $p['partial_count'])/$apps)*100, 1) : 0;
    ?>
      <tr>
        <td><?php echo htmlspecialchars($p['program_code']); ?></td>
        <td><?php echo htmlspecialchars($p['program_name']); ?></td>
        <td><?php echo $apps; ?></td>
        <td><?php echo $p['avg_score'] ? number_format($p['avg_score'],1).'%' : '—'; ?></td>
        <td><?php echo (int)$p['qualified_count']; ?></td>
        <td><?php echo (int)$p['partial_count']; ?></td>
        <td><?php echo $succ; ?>%</td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="7" class="muted">No program data</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <!-- Document Statistics -->
  <h4 style="margin:16px 0 6px;">Document Upload Statistics</h4>
  <table class="print-table">
    <thead>
      <tr>
        <th>Document Type</th>
        <th>Count</th>
        <th>Average Size</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!empty($document_stats)): foreach ($document_stats as $d): ?>
      <tr>
        <td><?php echo htmlspecialchars($d['type']); ?></td>
        <td><?php echo (int)$d['count']; ?></td>
        <td><?php echo htmlspecialchars($d['avg_size']); ?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="3" class="muted">No document data</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <!-- Evaluator Performance (admin only) -->
  <?php if ($user_type === 'admin'): ?>
  <h4 style="margin:16px 0 6px;">Evaluator Performance</h4>
  <table class="print-table">
    <thead>
      <tr>
        <th>Evaluator</th>
        <th>Applications</th>
        <th>Avg Score Given</th>
        <th>Avg Processing Days</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!empty($evaluator_stats)): foreach ($evaluator_stats as $ev): ?>
      <tr>
        <td><?php echo htmlspecialchars($ev['evaluator_name']); ?></td>
        <td><?php echo (int)$ev['applications_evaluated']; ?></td>
        <td><?php echo $ev['avg_score_given'] ? number_format($ev['avg_score_given'],1).'%' : '—'; ?></td>
        <td><?php echo $ev['avg_processing_days'] ? number_format($ev['avg_processing_days'],1) : '—'; ?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="4" class="muted">No evaluator data</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <div style="margin-top:10px;font-size:11px;color:#666;text-align:center;">
    <hr style="margin:8px 0 6px 0;">
    Confidential – For internal use only
      </div>
  </div>
</div>
<!-- =================== END PRINT-ONLY TABULAR REPORT ==================== -->


<!-- PRINT-ONLY FOOTER -->
<div id="print-footer" class="d-none" style="font-size:12px;color:#666;text-align:center;margin-top:10px;">
  <hr style="margin:8px 0 6px 0;">
  Confidential – For internal use only
</div>

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
            <div class="col-md-9 col-lg-10">
                <div class="p-4">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 class="mb-1">Reports & Analytics</h2>
                            <p class="text-muted mb-0">Comprehensive insights into ETEEAP system performance</p>
                        </div>
                        <div class="d-flex gap-2">
       <button class="btn btn-secondary" onclick="window.print()">
  <i class="fas fa-table me-1"></i>Print
</button>
                            <button class="btn btn-primary" onclick="exportData()">
                                <i class="fas fa-download me-1"></i>Export Data
                            </button>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="filter-card">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="program" class="form-label">Program Filter</label>
                                <select class="form-select" id="program" name="program">
                                    <option value="">All Programs</option>
                                    <?php foreach ($program_stats as $program): ?>
                                    <option value="<?php echo $program['program_code']; ?>" <?php echo $program_filter === $program['program_code'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($program['program_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter me-1"></i>Filter
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Key Metrics -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-6 col-lg-3">
                            <div class="stat-card">
                                <i class="fas fa-file-alt fa-2x text-primary mb-3"></i>
                                <div class="stat-number text-primary"><?php echo formatNumberShort($stats['total_applications']); ?></div>
                                <div class="stat-label">Total Applications</div>
                                <small class="text-muted">
                                    <?php echo $stats['period_applications']; ?> in selected period
                                </small>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="stat-card">
                                <i class="fas fa-users fa-2x text-success mb-3"></i>
                                <div class="stat-number text-success"><?php echo formatNumberShort($stats['total_candidates']); ?></div>
                                <div class="stat-label">Total Candidates</div>
                                <small class="text-muted">Registered users</small>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="stat-card">
                                <i class="fas fa-percentage fa-2x text-info mb-3"></i>
                                <div class="stat-number text-info"><?php echo $stats['success_rate']; ?>%</div>
                                <div class="stat-label">Success Rate</div>
                                <small class="text-muted">Qualified + Partially Qualified</small>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="stat-card">
                                <i class="fas fa-clock fa-2x text-warning mb-3"></i>
                                <div class="stat-number text-warning"><?php echo $stats['avg_processing_days']; ?></div>
                                <div class="stat-label">Avg Processing Days</div>
                                <small class="text-muted">From submission to evaluation</small>
                            </div>
                        </div>
                    </div>

                    <!-- Secondary Metrics -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-4">
                            <div class="stat-card">
                                <i class="fas fa-star fa-2x text-success mb-3"></i>
                                <div class="stat-number text-success"><?php echo $stats['avg_score']; ?>%</div>
                                <div class="stat-label">Average Score</div>
                                <div class="mt-2">
                                    <?php 
                                    $grade = getScoreGrade($stats['avg_score']);
                                    echo '<span class="badge bg-' . $grade['class'] . '">' . $grade['text'] . '</span>';
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card">
                                <i class="fas fa-folder fa-2x text-info mb-3"></i>
                                <div class="stat-number text-info"><?php echo formatNumberShort($stats['total_documents']); ?></div>
                                <div class="stat-label">Documents Uploaded</div>
                                <small class="text-muted">All file submissions</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card">
                                <i class="fas fa-calendar fa-2x text-primary mb-3"></i>
                                <div class="stat-number text-primary"><?php echo date('j'); ?></div>
                                <div class="stat-label"><?php echo date('F Y'); ?></div>
                                <small class="text-muted">Current Period</small>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4 mb-4">
                        <!-- Status Distribution Chart -->
                        <div class="col-lg-6">
                            <div class="chart-container">
                                <h5 class="mb-3">Application Status Distribution</h5>
                                <?php if (!empty($status_distribution)): ?>
                                <canvas id="statusChart" height="300"></canvas>
                                <?php else: ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-chart-pie fa-3x mb-3"></i>
                                    <p>No data available</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Monthly Trends -->
                        <div class="col-lg-6">
                            <div class="chart-container">
                                <h5 class="mb-3">Application Trends (Last 12 Months)</h5>
                                <?php if (!empty($monthly_trends)): ?>
                                <canvas id="trendsChart" height="300"></canvas>
                                <?php else: ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-chart-line fa-3x mb-3"></i>
                                    <p>No data available</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Program Statistics -->
                    <div class="row g-4 mb-4">
                        <div class="col-12">
                            <div class="report-card p-4">
                                <h5 class="mb-4">Program Performance</h5>
                                <?php if (!empty($program_stats)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>Program</th>
                                                <th>Applications</th>
                                                <th>Avg Score</th>
                                                <th>Qualified</th>
                                                <th>Partial</th>
                                                <th>Success Rate</th>
                                                <th>Performance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($program_stats as $program): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($program['program_code']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($program['program_name']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="fw-bold"><?php echo $program['total_applications']; ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($program['avg_score']): ?>
                                                        <span class="text-primary fw-bold"><?php echo round($program['avg_score'], 1); ?>%</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success"><?php echo $program['qualified_count']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-warning"><?php echo $program['partial_count']; ?></span>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $success_rate = $program['total_applications'] > 0 ? 
                                                        round((($program['qualified_count'] + $program['partial_count']) / $program['total_applications']) * 100, 1) : 0;
                                                    ?>
                                                    <span class="fw-bold"><?php echo $success_rate; ?>%</span>
                                                </td>
                                                <td>
                                                    <div class="progress" style="width: 100px;">
                                                        <div class="progress-bar bg-<?php echo $success_rate >= 75 ? 'success' : ($success_rate >= 50 ? 'warning' : 'danger'); ?>" 
                                                             style="width: <?php echo $success_rate; ?>%"></div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-graduation-cap fa-3x mb-3"></i>
                                    <p>No program data available</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Document Statistics & Evaluator Performance -->
                    <div class="row g-4">
                        <!-- Document Statistics -->
                        <div class="col-lg-6">
                            <div class="report-card p-4">
                                <h5 class="mb-4">Document Upload Statistics</h5>
                                <?php if (!empty($document_stats)): ?>
                                <div class="row g-3">
                                    <?php foreach ($document_stats as $doc): ?>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                            <div>
                                                <div class="fw-semibold"><?php echo $doc['type']; ?></div>
                                                <small class="text-muted">Avg size: <?php echo $doc['avg_size']; ?></small>
                                            </div>
                                            <div class="text-end">
                                                <div class="fw-bold text-primary"><?php echo $doc['count']; ?></div>
                                                <small class="text-muted">files</small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-file-alt fa-3x mb-3"></i>
                                    <p>No document data available</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Evaluator Performance (Admin only) -->
                        <?php if ($user_type === 'admin' && !empty($evaluator_stats)): ?>
                        <div class="col-lg-6">
                            <div class="report-card p-4">
                                <h5 class="mb-4">Evaluator Performance</h5>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>Evaluator</th>
                                                <th>Apps</th>
                                                <th>Avg Score</th>
                                                <th>Avg Days</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($evaluator_stats as $eval): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($eval['evaluator_name']); ?></td>
                                                <td><span class="badge bg-primary"><?php echo $eval['applications_evaluated']; ?></span></td>
                                                <td>
                                                    <?php if ($eval['avg_score_given']): ?>
                                                        <?php echo round($eval['avg_score_given'], 1); ?>%
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($eval['avg_processing_days']): ?>
                                                        <?php echo round($eval['avg_processing_days'], 1); ?> days
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
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Status Distribution Chart
        <?php if (!empty($status_distribution)): ?>
        const statusData = <?php echo json_encode($status_distribution); ?>;
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: statusData.map(item => item.status),
                datasets: [{
                    data: statusData.map(item => item.count),
                    backgroundColor: statusData.map(item => item.color),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Monthly Trends Chart
        <?php if (!empty($monthly_trends)): ?>
        const trendsData = <?php echo json_encode($monthly_trends); ?>;
        new Chart(document.getElementById('trendsChart'), {
            type: 'line',
            data: {
                labels: trendsData.map(item => item.month),
                datasets: [
                    {
                        label: 'Applications',
                        data: trendsData.map(item => item.applications),
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Successful',
                        data: trendsData.map(item => item.successful),
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        fill: false,
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
                    },
                    tooltip: {
                        callbacks: {
                            afterLabel: function(context) {
                                if (context.datasetIndex === 0) {
                                    const dataIndex = context.dataIndex;
                                    const successRate = trendsData[dataIndex].success_rate;
                                    return `Success Rate: ${successRate}%`;
                                }
                                return '';
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Export Data Function
        function exportData() {
            const exportData = {
                stats: <?php echo json_encode($stats); ?>,
                status_distribution: <?php echo json_encode($status_distribution); ?>,
                program_stats: <?php echo json_encode($program_stats); ?>,
                monthly_trends: <?php echo json_encode($monthly_trends); ?>,
                document_stats: <?php echo json_encode($document_stats); ?>,
                <?php if ($user_type === 'admin'): ?>
                evaluator_stats: <?php echo json_encode($evaluator_stats); ?>,
                <?php endif; ?>
                generated_at: '<?php echo date('Y-m-d H:i:s'); ?>',
                date_range: '<?php echo $start_date; ?> to <?php echo $end_date; ?>'
            };

            // Create downloadable JSON file
            const dataStr = JSON.stringify(exportData, null, 2);
            const dataUri = 'data:application/json;charset=utf-8,'+ encodeURIComponent(dataStr);
            
            const exportFileDefaultName = 'eteeap_report_<?php echo date('Y-m-d'); ?>.json';
            
            const linkElement = document.createElement('a');
            linkElement.setAttribute('href', dataUri);
            linkElement.setAttribute('download', exportFileDefaultName);
            linkElement.click();
        }

        // Auto-refresh every 5 minutes for live data
        setTimeout(function() {
            location.reload();
        }, 300000);

        // Print styles
        const style = document.createElement('style');
        style.textContent = `
            @media print {
                .sidebar, .btn, .dropdown { display: none !important; }
                .col-md-9, .col-lg-10 { width: 100% !important; }
                .report-card, .stat-card, .chart-container {
                    break-inside: avoid;
                    box-shadow: none !important;
                    border: 1px solid #ddd;
                }
                .chart-container canvas {
                    max-height: 300px !important;
                }
            }
        `;
        document.head.appendChild(style);


        // Convert canvases to images para crisp sa papel
function replaceCanvasesWithImages() {
  const replaced = [];
  document.querySelectorAll('canvas').forEach(cv => {
    try {
      // Ensure white background
      const tmp = document.createElement('canvas');
      tmp.width = cv.width; tmp.height = cv.height;
      const ctx = tmp.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, tmp.width, tmp.height);
      ctx.drawImage(cv, 0, 0);

      const dataURL = tmp.toDataURL('image/png');
      const img = new Image();
      img.src = dataURL;
      img.style.maxWidth = '100%';
      img.style.height = 'auto';
      img.width = cv.width;
      img.height = cv.height;

      cv.dataset.printBackup = '1';
      cv.style.display = 'none';
      cv.parentNode.insertBefore(img, cv.nextSibling);
      replaced.push({ canvas: cv, image: img });
    } catch(e) { /* ignore */ }
  });
  return replaced;
}

function restoreCanvases(replaced) {
  replaced.forEach(({canvas, image}) => {
    if (image && image.parentNode) image.parentNode.removeChild(image);
    canvas.style.display = '';
    delete canvas.dataset.printBackup;
  });
}

let _printSwap = [];

function printReport() {
  _printSwap = replaceCanvasesWithImages();
  // Small delay para sure loaded images
  setTimeout(() => {
    window.print();
    // Fallback restore kung walang afterprint event
    setTimeout(() => restoreCanvases(_printSwap), 1200);
  }, 200);
}

window.addEventListener('beforeprint', () => { _printSwap = replaceCanvasesWithImages(); });
window.addEventListener('afterprint',  () => { restoreCanvases(_printSwap); _printSwap = []; });
    </script>
</body>
</html>