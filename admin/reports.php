<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin/evaluator
requireAuth(['admin', 'evaluator']);

$user_type = $_SESSION['user_type'];

// Date range for reports (default to last 30 days)

// ============= GET FILTERS =============
// Optional: siguraduhin ang tamang timezone
date_default_timezone_set('Asia/Manila');

$today = date('Y-m-d');

// Use empty() instead of ??
$start_date = !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date   = !empty($_GET['end_date'])   ? $_GET['end_date']   : $today;

// sanitize format (YYYY-MM-DD) at cap sa today
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date))   $end_date   = $today;

if ($end_date > $today) $end_date = $today;                      // wag lampas today
if ($start_date > $end_date) $start_date = date('Y-m-d', strtotime("$end_date -30 days")); // safety

$program_filter = $_GET['program'] ?? '';

// ============= BUILD WHERE CLAUSE FOR FILTERS =============
$where_conditions = ["1=1"];
$filter_params = [];

// Add date filter
if (!empty($start_date) && !empty($end_date)) {
    $where_conditions[] = "DATE(a.created_at) BETWEEN ? AND ?";
    $filter_params[] = $start_date;
    $filter_params[] = $end_date;
}

// Add program filter
if (!empty($program_filter)) {
    $where_conditions[] = "p.program_code = ?";
    $filter_params[] = $program_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// ============= SIDEBAR COUNTS (UNFILTERED) =============
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
        WHERE (u.application_form_status IS NULL OR u.application_form_status = 'pending' 
               OR u.application_form_status NOT IN ('approved', 'rejected'))
    ");
    $sidebar_pending_count = $stmt->fetch()['total'];
} catch (PDOException $e) {
    $sidebar_pending_count = 0;
}

// ============= GET FILTERED STATISTICS =============
$stats = [];
try {
    // Total applications (FILTERED)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM applications a 
        LEFT JOIN programs p ON a.program_id = p.id 
        $where_clause
    ");
    $stmt->execute($filter_params);
    $stats['total_applications'] = $stmt->fetch()['total'];
    
    // Total candidates (filtered by date only)
    $candidate_where = "WHERE u.user_type = 'candidate'";
    $candidate_params = [];
    if (!empty($start_date) && !empty($end_date)) {
        $candidate_where .= " AND DATE(u.created_at) BETWEEN ? AND ?";
        $candidate_params = [$start_date, $end_date];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users u $candidate_where");
    $stmt->execute($candidate_params);
    $stats['total_candidates'] = $stmt->fetch()['total'];
    
    // Documents (FILTERED)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM documents d
        INNER JOIN applications a ON d.application_id = a.id
        LEFT JOIN programs p ON a.program_id = p.id
        $where_clause
    ");
    $stmt->execute($filter_params);
    $stats['total_documents'] = $stmt->fetch()['total'];
    
    // Applications in date range (already filtered)
    $stats['period_applications'] = $stats['total_applications'];
    
    // Average processing time (FILTERED)
    $stmt = $pdo->prepare("
        SELECT AVG(DATEDIFF(a.evaluation_date, a.submission_date)) as avg_days
        FROM applications a
        LEFT JOIN programs p ON a.program_id = p.id
        $where_clause
        AND a.submission_date IS NOT NULL 
        AND a.evaluation_date IS NOT NULL
    ");
    $stmt->execute($filter_params);
    $avg_processing = $stmt->fetch()['avg_days'];
    $stats['avg_processing_days'] = $avg_processing ? round($avg_processing, 1) : 0;
    
    // Success rate (FILTERED)
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_evaluated,
            SUM(CASE WHEN a.application_status IN ('qualified', 'partially_qualified') THEN 1 ELSE 0 END) as successful
        FROM applications a
        LEFT JOIN programs p ON a.program_id = p.id
        $where_clause
        AND a.application_status IN ('qualified', 'partially_qualified', 'not_qualified')
    ");
    $stmt->execute($filter_params);
    $success_data = $stmt->fetch();
    $stats['success_rate'] = $success_data['total_evaluated'] > 0 ? 
        round(($success_data['successful'] / $success_data['total_evaluated']) * 100, 1) : 0;
    
    // Average score (FILTERED)
    $stmt = $pdo->prepare("
        SELECT AVG(a.total_score) as avg_score 
        FROM applications a
        LEFT JOIN programs p ON a.program_id = p.id
        $where_clause
        AND a.total_score > 0
    ");
    $stmt->execute($filter_params);
    $avg_score = $stmt->fetch()['avg_score'];
    $stats['avg_score'] = $avg_score ? round($avg_score, 1) : 0;
    
} catch (PDOException $e) {
    $stats = array_fill_keys([
        'total_applications', 'total_candidates', 'total_documents', 
        'period_applications', 'avg_processing_days', 'success_rate', 'avg_score'
    ], 0);
}

// ============= STATUS DISTRIBUTION (FILTERED) =============
$status_distribution = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.application_status, COUNT(*) as count
        FROM applications a
        LEFT JOIN programs p ON a.program_id = p.id
        $where_clause
        GROUP BY a.application_status
        ORDER BY count DESC
    ");
    $stmt->execute($filter_params);
    while ($row = $stmt->fetch()) {
        $status_distribution[] = [
            'status' => getStatusDisplayName($row['application_status']),
            'count' => (int)$row['count'],
            'color' => getStatusColor($row['application_status'])
        ];
    }
} catch (PDOException $e) {
    $status_distribution = [];
}

// ============= PROGRAM STATISTICS (FILTERED) =============
$program_stats = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.program_name,
            p.program_code,
            COUNT(a.id) as total_applications,
            AVG(CASE WHEN a.total_score > 0 THEN a.total_score END) as avg_score,
            COUNT(CASE WHEN a.application_status = 'qualified' THEN 1 END) as qualified_count,
            COUNT(CASE WHEN a.application_status = 'partially_qualified' THEN 1 END) as partial_count
        FROM programs p
        LEFT JOIN applications a ON p.id = a.program_id
        $where_clause
        GROUP BY p.id, p.program_name, p.program_code
        HAVING total_applications > 0
        ORDER BY total_applications DESC
    ");
    $stmt->execute($filter_params);
    $program_stats = $stmt->fetchAll();
} catch (PDOException $e) {
    $program_stats = [];
}


// ============= MONTHLY TRENDS (FILTERED, 6 months default) =============
$monthly_trends = [];
try {
    $monthly_where_conditions = [];
    $monthly_params = [];

    if (!empty($start_date) && !empty($end_date)) {
        // STRICTLY gamitin lang ang user range
        $monthly_where_conditions[] = "DATE(a.created_at) BETWEEN ? AND ?";
        $monthly_params[] = $start_date;
        $monthly_params[] = $end_date;
    } else {
        // Default: last 6 months (inclusive ng current month)
        $monthly_where_conditions[] = "a.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
    }

    if (!empty($program_filter)) {
        $monthly_where_conditions[] = "p.program_code = ?";
        $monthly_params[] = $program_filter;
    }

    $monthly_where = "WHERE " . implode(" AND ", $monthly_where_conditions);

    // Raw rows per month
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(a.created_at, '%Y-%m') AS ym,
            COUNT(*) AS applications,
            COUNT(CASE WHEN a.application_status IN ('qualified','partially_qualified') THEN 1 END) AS successful
        FROM applications a
        LEFT JOIN programs p ON a.program_id = p.id
        $monthly_where
        GROUP BY DATE_FORMAT(a.created_at, '%Y-%m')
        ORDER BY ym ASC
    ");
    $stmt->execute($monthly_params);
    $rows = $stmt->fetchAll(PDO::FETCH_UNIQUE); // ['YYYY-MM' => ['applications'=>x,'successful'=>y]]

    // Build month range
    if (!empty($start_date) && !empty($end_date)) {
        $startYm = date('Y-m', strtotime($start_date));
        $endYm   = date('Y-m', strtotime($end_date));
    } else {
        $startYm = date('Y-m', strtotime('-5 months'));
        $endYm   = date('Y-m'); // current month
    }

    $start = new DateTime($startYm . '-01');
    $end   = new DateTime($endYm . '-01');

    while ($start <= $end) {
        $ym    = $start->format('Y-m');
        $label = $start->format('M Y');
        $apps  = isset($rows[$ym]['applications']) ? (int)$rows[$ym]['applications'] : 0;
        $succ  = isset($rows[$ym]['successful'])   ? (int)$rows[$ym]['successful']   : 0;

        $monthly_trends[] = [
            'month'        => $label,
            'applications' => $apps,
            'successful'   => $succ,
            'success_rate' => $apps > 0 ? round(($succ / $apps) * 100, 1) : 0
        ];
        $start->modify('+1 month');
    }
} catch (PDOException $e) {
    $monthly_trends = [];
}


// ============= DOCUMENT STATISTICS (FILTERED) =============
$document_stats = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            d.document_type,
            COUNT(*) as count,
            AVG(d.file_size) as avg_size
        FROM documents d
        INNER JOIN applications a ON d.application_id = a.id
        LEFT JOIN programs p ON a.program_id = p.id
        $where_clause
        GROUP BY d.document_type
        ORDER BY count DESC
    ");
    $stmt->execute($filter_params);
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

// ============= EVALUATOR PERFORMANCE (FILTERED, ADMIN ONLY) =============
$evaluator_stats = [];
if ($user_type === 'admin') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                CONCAT(u.first_name, ' ', u.last_name) as evaluator_name,
                COUNT(a.id) as applications_evaluated,
                AVG(a.total_score) as avg_score_given,
                AVG(DATEDIFF(a.evaluation_date, a.submission_date)) as avg_processing_days
            FROM users u
            LEFT JOIN applications a ON u.id = a.evaluator_id
            LEFT JOIN programs p ON a.program_id = p.id
            $where_clause
            AND u.user_type IN ('admin', 'evaluator')
            GROUP BY u.id, u.first_name, u.last_name
            HAVING applications_evaluated > 0
            ORDER BY applications_evaluated DESC
        ");
        $stmt->execute($filter_params);
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

// // ====== DASHBOARD FILTERS (shared with Reports) ======
// $start_date     = $_GET['start_date'] ?? '';   // leave blank by default (no filter)
// $end_date       = $_GET['end_date'] ?? '';
// $program_filter = $_GET['program']    ?? '';

// $where_conditions = ["1=1"];
// $params = [];

// // Date range
// if (!empty($start_date) && !empty($end_date)) {
//     $where_conditions[] = "DATE(a.created_at) BETWEEN ? AND ?";
//     $params[] = $start_date;
//     $params[] = $end_date;
// }

// // Program filter
// if (!empty($program_filter)) {
//     $where_conditions[] = "p.program_code = ?";
//     $params[] = $program_filter;
// }

// // Final WHERE for application-based queries
// $app_where = "WHERE " . implode(" AND ", $where_conditions);

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
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-number { font-size: 2rem; font-weight: 700; }
        .stat-label { color: #6c757d; font-size: 0.875rem; }
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        .chart-container canvas { height: 320px !important; max-height: 360px; }
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        @media print {
            #screen-report { display: none !important; }
            #print-report { display: block !important; }
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

<div id="screen-report" class="d-print-none">
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
                                <i class="fas fa-print me-1"></i>Print
                            </button>
                            <button class="btn btn-primary" onclick="exportData()">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="filter-card">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" 
                                       value="<?php echo htmlspecialchars($start_date); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="end_date" class="form-label">End Date</label>
                              <input type="date" class="form-control" id="end_date" name="end_date"
       value="<?php echo htmlspecialchars($end_date); ?>"
       max="<?php echo $today; ?>">

                            </div>
                            <div class="col-md-3">
                                <label for="program" class="form-label">Program</label>
                                <select class="form-select" id="program" name="program">
                                    <option value="">All Programs</option>
                                    <?php 
                                    // Get all programs for dropdown
                                    try {
                                        $all_programs = $pdo->query("SELECT DISTINCT program_code, program_name FROM programs ORDER BY program_name");
                                        while ($prog = $all_programs->fetch()):
                                    ?>
                                    <option value="<?php echo htmlspecialchars($prog['program_code']); ?>" 
                                            <?php echo $program_filter === $prog['program_code'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($prog['program_name']); ?>
                                    </option>
                                    <?php endwhile; } catch (PDOException $e) {} ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter me-1"></i>Filter
                                </button>
                            </div>
                            <div class="col-md-1">
                                <a href="reports.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-times"></i>
                                </a>
                            </div>
                        </form>
                        
                        <?php if (!empty($program_filter) || $start_date !== date('Y-m-d', strtotime('-30 days'))): ?>
                        <div class="mt-3">
                            <span class="badge bg-info"><i class="fas fa-info-circle me-1"></i>Filtered Results</span>
                            <?php if (!empty($program_filter)): ?>
                            <span class="badge bg-primary">Program: <?php echo htmlspecialchars($program_filter); ?></span>
                            <?php endif; ?>
                            <span class="badge bg-secondary">
                                <?php echo date('M j, Y', strtotime($start_date)); ?> - <?php echo date('M j, Y', strtotime($end_date)); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Stats Cards -->
               <div class="row g-4 mb-4 align-items-stretch">
                  <div class="col-12 col-sm-6 col-lg-3 d-flex">
    <div class="stat-card h-100 w-100">
      <div class="stat-icon"><i class="fas fa-users fa-2x text-success"></i></div>
      <div class="stat-number text-success"><?php echo $stats['total_candidates']; ?></div>
      <div class="stat-label">Candidates</div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-lg-3 d-flex">
    <div class="stat-card h-100 w-100">
      <div class="stat-icon"><i class="fas fa-file-alt fa-2x text-primary"></i></div>
      <div class="stat-number text-primary"><?php echo $stats['total_applications']; ?></div>
      <div class="stat-label">Applications</div>
    </div>
  </div>


  <div class="col-12 col-sm-6 col-lg-3 d-flex">
    <div class="stat-card h-100 w-100">
      <div class="stat-icon"><i class="fas fa-percentage fa-2x text-info"></i></div>
      <div class="stat-number text-info"><?php echo $stats['success_rate']; ?>%</div>
      <div class="stat-label">Success Rate</div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-lg-3 d-flex">
    <div class="stat-card h-100 w-100">
      <div class="stat-icon"><i class="fas fa-star fa-2x text-warning"></i></div>
      <div class="stat-number text-warning"><?php echo $stats['avg_score']; ?>%</div>
      <div class="stat-label">Avg Score</div>
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
                               <h5 class="mb-3">
  Application Trends (<?php echo !empty($start_date)&&!empty($end_date) ? 
    date('M Y', strtotime($start_date)).' – '.date('M Y', strtotime($end_date)) : 'Last 6 Months'; ?>)
</h5>

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



        document.addEventListener("DOMContentLoaded", function() {
  const endInput = document.getElementById("end_date");
  if (endInput && !endInput.value) {
    endInput.value = new Date().toISOString().split('T')[0];
  }
});
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