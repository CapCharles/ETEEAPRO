<?php
// director_api.php - Director ETEEAP API Endpoints
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Database connection
require_once '../config/database.php';

// Helper function to get current user from token
function getCurrentUser($db) {
    $headers = getallheaders();
    $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;
    
    if (!$token) {
        return null;
    }
    
    // Simple token validation - in production, use JWT
    $stmt = $db->prepare("SELECT id, email, first_name, last_name, user_type FROM users WHERE id = ? AND status = 'active'");
    $userId = (int)$token; // For now, token is just user ID - use proper JWT in production!
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

// Check authentication and authorization
$user = getCurrentUser($db);
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($user['user_type'] !== 'director_eteeap' && $user['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Director ETEEAP role required.']);
    exit;
}

// Route handling
$requestUri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Remove query string
$path = parse_url($requestUri, PHP_URL_PATH);
$path = str_replace('/director_api.php', '', $path);

// =====================================================
// GET /applications - Get all applications for director
// =====================================================
if ($method === 'GET' && preg_match('/^\/applications$/', $path)) {
    $status = isset($_GET['status']) ? $_GET['status'] : 'awaiting_director';
    
    $query = "
        SELECT 
            a.id,
            a.user_id,
            a.program_id,
            a.application_status,
            a.current_step,
            a.submission_date,
            a.evaluation_date,
            a.total_score,
            a.recommendation,
            a.can_reapply,
            a.created_at,
            a.updated_at,
            CONCAT(u.first_name, ' ', u.last_name) AS applicant_name,
            u.email,
            u.phone,
            p.program_name,
            CONCAT(e.first_name, ' ', e.last_name) AS evaluator_name
        FROM applications a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN programs p ON a.program_id = p.id
        LEFT JOIN users e ON a.evaluator_id = e.id
    ";
    
    if ($status !== 'all') {
        $query .= " WHERE a.application_status = ?";
        $stmt = $db->prepare($query . " ORDER BY a.submission_date DESC");
        $stmt->bind_param("s", $status);
    } else {
        $stmt = $db->prepare($query . " ORDER BY a.submission_date DESC");
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $applications = [];
    
    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }
    
    echo json_encode($applications);
    exit;
}

// =====================================================
// GET /applications/{id} - Get single application details
// =====================================================
if ($method === 'GET' && preg_match('/^\/applications\/(\d+)$/', $path, $matches)) {
    $appId = (int)$matches[1];
    
    // Get application details
    $stmt = $db->prepare("
        SELECT 
            a.*,
            CONCAT(u.first_name, ' ', u.last_name) AS applicant_name,
            u.email,
            u.phone,
            u.address,
            p.program_name,
            CONCAT(e.first_name, ' ', e.last_name) AS evaluator_name
        FROM applications a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN programs p ON a.program_id = p.id
        LEFT JOIN users e ON a.evaluator_id = e.id
        WHERE a.id = ?
    ");
    $stmt->bind_param("i", $appId);
    $stmt->execute();
    $application = $stmt->get_result()->fetch_assoc();
    
    if (!$application) {
        http_response_code(404);
        echo json_encode(['error' => 'Application not found']);
        exit;
    }
    
    // Get evaluation criteria
    $stmt = $db->prepare("
        SELECT 
            ev.id,
            ac.criteria_name,
            ev.score,
            ac.max_score,
            ev.comments,
            ev.created_at
        FROM evaluations ev
        JOIN assessment_criteria ac ON ev.criteria_id = ac.id
        WHERE ev.application_id = ?
        ORDER BY ac.sort_order
    ");
    $stmt->bind_param("i", $appId);
    $stmt->execute();
    $result = $stmt->get_result();
    $criteria = [];
    
    while ($row = $result->fetch_assoc()) {
        $criteria[] = $row;
    }
    
    $application['evaluation_criteria'] = $criteria;
    
    // Get approval history
    $stmt = $db->prepare("
        SELECT 
            approver_role,
            approver_name,
            action,
            remarks,
            approved_at
        FROM application_approvals
        WHERE application_id = ?
        ORDER BY approved_at ASC
    ");
    $stmt->bind_param("i", $appId);
    $stmt->execute();
    $result = $stmt->get_result();
    $approvals = [];
    
    while ($row = $result->fetch_assoc()) {
        $approvals[] = $row;
    }
    
    $application['approval_history'] = $approvals;
    
    echo json_encode($application);
    exit;
}

// =====================================================
// PATCH /applications/{id}/approve - Approve application
// =====================================================
if ($method === 'PATCH' && preg_match('/^\/applications\/(\d+)\/approve$/', $path, $matches)) {
    $appId = (int)$matches[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $remarks = isset($input['remarks']) ? $input['remarks'] : '';
    
    // Get application
    $stmt = $db->prepare("SELECT * FROM applications WHERE id = ?");
    $stmt->bind_param("i", $appId);
    $stmt->execute();
    $application = $stmt->get_result()->fetch_assoc();
    
    if (!$application) {
        http_response_code(404);
        echo json_encode(['error' => 'Application not found']);
        exit;
    }
    
    // Check if application is in correct status
    if ($application['application_status'] !== 'awaiting_director') {
        http_response_code(400);
        echo json_encode([
            'error' => 'Application is not in the correct status for Director approval',
            'current_status' => $application['application_status']
        ]);
        exit;
    }
    
    $db->begin_transaction();
    
    try {
        // Add approval record
        $approverName = $user['first_name'] . ' ' . $user['last_name'];
        $stmt = $db->prepare("
            INSERT INTO application_approvals 
            (application_id, approver_role, approver_id, approver_name, action, remarks)
            VALUES (?, 'director_eteeap', ?, ?, 'approved', ?)
        ");
        $stmt->bind_param("iiss", $appId, $user['id'], $approverName, $remarks);
        $stmt->execute();
        
        // Update application status to next step (CED)
        $stmt = $db->prepare("
            UPDATE applications 
            SET application_status = 'awaiting_ced',
                current_step = 'ced',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("i", $appId);
        $stmt->execute();
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Application approved successfully and forwarded to CED'
        ]);
    } catch (Exception $e) {
        $db->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to approve application: ' . $e->getMessage()]);
    }
    
    exit;
}

// =====================================================
// PATCH /applications/{id}/reject - Reject application
// =====================================================
if ($method === 'PATCH' && preg_match('/^\/applications\/(\d+)\/reject$/', $path, $matches)) {
    $appId = (int)$matches[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $remarks = isset($input['remarks']) ? trim($input['remarks']) : '';
    
    if (empty($remarks)) {
        http_response_code(400);
        echo json_encode(['error' => 'Remarks are required for rejection']);
        exit;
    }
    
    // Get application
    $stmt = $db->prepare("SELECT * FROM applications WHERE id = ?");
    $stmt->bind_param("i", $appId);
    $stmt->execute();
    $application = $stmt->get_result()->fetch_assoc();
    
    if (!$application) {
        http_response_code(404);
        echo json_encode(['error' => 'Application not found']);
        exit;
    }
    
    // Check if application is in correct status
    if ($application['application_status'] !== 'awaiting_director') {
        http_response_code(400);
        echo json_encode([
            'error' => 'Application is not in the correct status for Director review',
            'current_status' => $application['application_status']
        ]);
        exit;
    }
    
    $db->begin_transaction();
    
    try {
        // Add rejection record
        $approverName = $user['first_name'] . ' ' . $user['last_name'];
        $stmt = $db->prepare("
            INSERT INTO application_approvals 
            (application_id, approver_role, approver_id, approver_name, action, remarks)
            VALUES (?, 'director_eteeap', ?, ?, 'rejected', ?)
        ");
        $stmt->bind_param("iiss", $appId, $user['id'], $approverName, $remarks);
        $stmt->execute();
        
        // Update application status to rejected
        $stmt = $db->prepare("
            UPDATE applications 
            SET application_status = 'rejected',
                current_step = 'completed',
                can_reapply = 1,
                completed_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("i", $appId);
        $stmt->execute();
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Application rejected'
        ]);
    } catch (Exception $e) {
        $db->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to reject application: ' . $e->getMessage()]);
    }
    
    exit;
}

// =====================================================
// GET /stats - Get dashboard statistics
// =====================================================
if ($method === 'GET' && preg_match('/^\/stats$/', $path)) {
    // Awaiting director approval
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM applications WHERE application_status = 'awaiting_director'");
    $stmt->execute();
    $awaiting = $stmt->get_result()->fetch_assoc()['count'];
    
    // Approved by director
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT application_id) as count 
        FROM application_approvals 
        WHERE approver_role = 'director_eteeap' AND action = 'approved'
    ");
    $stmt->execute();
    $approved = $stmt->get_result()->fetch_assoc()['count'];
    
    // Rejected by director
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT application_id) as count 
        FROM application_approvals 
        WHERE approver_role = 'director_eteeap' AND action = 'rejected'
    ");
    $stmt->execute();
    $rejected = $stmt->get_result()->fetch_assoc()['count'];
    
    // Total applications
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM applications");
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['count'];
    
    echo json_encode([
        'awaiting' => (int)$awaiting,
        'approved' => (int)$approved,
        'rejected' => (int)$rejected,
        'total' => (int)$total
    ]);
    exit;
}

// If no route matched
http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Director ETEEAP Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .header h1 {
            color: #333;
            margin-bottom: 10px;
        }

        .header p {
            color: #666;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .stat-card .label {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .stat-card .value {
            font-size: 36px;
            font-weight: bold;
        }

        .stat-card.awaiting .value { color: #f59e0b; }
        .stat-card.approved .value { color: #10b981; }
        .stat-card.rejected .value { color: #ef4444; }
        .stat-card.total .value { color: #3b82f6; }

        .filters {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .filter-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            background: #e5e7eb;
            color: #374151;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .filter-btn:hover {
            background: #d1d5db;
        }

        .filter-btn.active {
            background: #3b82f6;
            color: white;
        }

        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f9fafb;
        }

        th {
            padding: 15px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 15px;
            border-top: 1px solid #e5e7eb;
        }

        tr:hover {
            background: #f9fafb;
        }

        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .badge.awaiting { background: #fef3c7; color: #92400e; }
        .badge.qualified { background: #d1fae5; color: #065f46; }
        .badge.rejected { background: #fee2e2; color: #991b1b; }
        .badge.not-qualified { background: #e5e7eb; color: #374151; }
        .badge.partial { background: #fed7aa; color: #9a3412; }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        .btn-view {
            background: #3b82f6;
            color: white;
        }

        .btn-view:hover {
            background: #2563eb;
        }

        .btn-approve {
            background: #10b981;
            color: white;
            margin-right: 10px;
        }

        .btn-approve:hover {
            background: #059669;
        }

        .btn-reject {
            background: #ef4444;
            color: white;
        }

        .btn-reject:hover {
            background: #dc2626;
        }

        .btn-cancel {
            background: #e5e7eb;
            color: #374151;
        }

        .btn-cancel:hover {
            background: #d1d5db;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 10px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
        }

        .modal-header {
            padding: 20px 30px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }

        .modal-header h2 {
            color: #111827;
        }

        .close-btn {
            font-size: 28px;
            color: #9ca3af;
            cursor: pointer;
            border: none;
            background: none;
        }

        .close-btn:hover {
            color: #6b7280;
        }

        .modal-body {
            padding: 30px;
        }

        .info-section {
            margin-bottom: 30px;
        }

        .info-section h3 {
            color: #111827;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
        }

        .info-item .label {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .info-item .value {
            font-weight: 500;
            color: #111827;
        }

        .score-display {
            font-size: 32px;
            font-weight: bold;
            color: #3b82f6;
            margin: 10px 0;
        }

        .criteria-list {
            list-style: none;
            margin-top: 10px;
        }

        .criteria-item {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            background: white;
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .approval-item {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .approval-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .remarks-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 15px;
            font-family: inherit;
            resize: vertical;
        }

        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
            background: white;
        }

        .loading {
            text-align: center;
            padding: 50px;
            color: #6b7280;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3b82f6;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .no-data {
            text-align: center;
            padding: 50px;
            color: #6b7280;
        }

        .score-badge {
            font-weight: 600;
        }

        .score-badge.high { color: #10b981; }
        .score-badge.medium { color: #f59e0b; }
        .score-badge.low { color: #ef4444; }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>Director ETEEAP Dashboard</h1>
            <p>Review and approve ETEEAP applications</p>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card awaiting">
                <div class="label">Awaiting Approval</div>
                <div class="value" id="stat-awaiting">0</div>
            </div>
            <div class="stat-card approved">
                <div class="label">Approved by You</div>
                <div class="value" id="stat-approved">0</div>
            </div>
            <div class="stat-card rejected">
                <div class="label">Rejected by You</div>
                <div class="value" id="stat-rejected">0</div>
            </div>
            <div class="stat-card total">
                <div class="label">Total Applications</div>
                <div class="value" id="stat-total">0</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <button class="filter-btn active" data-filter="awaiting_director">Awaiting My Approval</button>
            <button class="filter-btn" data-filter="awaiting_ced">At CED</button>
            <button class="filter-btn" data-filter="awaiting_vpaa">At VPAA</button>
            <button class="filter-btn" data-filter="awaiting_president">At President</button>
            <button class="filter-btn" data-filter="qualified">Qualified</button>
            <button class="filter-btn" data-filter="rejected">Rejected</button>
            <button class="filter-btn" data-filter="all">All</button>
        </div>

        <!-- Table -->
        <div class="table-container">
            <div id="loading" class="loading">
                <div class="spinner"></div>
                <p>Loading applications...</p>
            </div>
            <div id="no-data" class="no-data" style="display: none;">
                <p>No applications found for this filter</p>
            </div>
            <table id="applications-table" style="display: none;">
                <thead>
                    <tr>
                        <th>Applicant</th>
                        <th>Program</th>
                        <th>Score</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal -->
    <div id="modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Application Details</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modal-body">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer" id="modal-footer">
                <!-- Buttons will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        // CONFIGURATION - Update these values
        const API_URL = 'http://localhost/eteeap/director_api.php';  // Update to your API URL
        const TOKEN = '13';  // Replace with actual user ID or JWT token (admin user id from DB)
        
        let currentFilter = 'awaiting_director';
        let currentApp = null;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadApplications();
            loadStats();
            setupFilters();
        });

        // Setup filter buttons
        function setupFilters() {
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    currentFilter = this.dataset.filter;
                    loadApplications();
                });
            });
        }

        // Load applications
        async function loadApplications() {
            showLoading();
            try {
                const response = await fetch(`${API_URL}/applications?status=${currentFilter}`, {
                    headers: {
                        'Authorization': `Bearer ${TOKEN}`
                    }
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.error || 'Failed to fetch applications');
                }

                const applications = await response.json();
                displayApplications(applications);
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to load applications: ' + error.message);
                document.getElementById('loading').style.display = 'none';
                document.getElementById('no-data').style.display = 'block';
                document.getElementById('no-data').innerHTML = `<p>${error.message}</p>`;
            }
        }

        // Load statistics
        async function loadStats() {
            try {
                const response = await fetch(`${API_URL}/stats`, {
                    headers: {
                        'Authorization': `Bearer ${TOKEN}`
                    }
                });

                if (!response.ok) throw new Error('Failed to fetch stats');

                const stats = await response.json();
                document.getElementById('stat-awaiting').textContent = stats.awaiting;
                document.getElementById('stat-approved').textContent = stats.approved;
                document.getElementById('stat-rejected').textContent = stats.rejected;
                document.getElementById('stat-total').textContent = stats.total;
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Display applications in table
        function displayApplications(applications) {
            const tbody = document.getElementById('table-body');
            const table = document.getElementById('applications-table');
            const noData = document.getElementById('no-data');
            const loading = document.getElementById('loading');

            loading.style.display = 'none';
            
            if (applications.length === 0) {
                table.style.display = 'none';
                noData.style.display = 'block';
                return;
            }

            noData.style.display = 'none';
            table.style.display = 'table';

            tbody.innerHTML = applications.map(app => `
                <tr>
                    <td>
                        <div style="font-weight: 500;">${app.applicant_name || 'N/A'}</div>
                        <div style="font-size: 13px; color: #6b7280;">${app.email || ''}</div>
                    </td>
                    <td>${app.program_name || 'N/A'}</td>
                    <td>
                        ${app.total_score > 0 ? 
                            `<span class="score-badge ${getScoreClass(app.total_score)}">${app.total_score}%</span>` :
                            '<span style="color: #9ca3af;">Not evaluated</span>'
                        }
                    </td>
                    <td>
                        <span class="badge ${getStatusClass(app.application_status)}">${formatStatus(app.application_status)}</span>
                    </td>
                    <td>${formatDate(app.submission_date)}</td>
                    <td>
                        <button class="btn btn-view" onclick="viewApplication(${app.id})">View Details</button>
                    </td>
                </tr>
            `).join('');
        }

        // View application details
        async function viewApplication(id) {
            try {
                const response = await fetch(`${API_URL}/applications/${id}`, {
                    headers: {
                        'Authorization': `Bearer ${TOKEN}`
                    }
                });

                if (!response.ok) throw new Error('Failed to fetch application');

                currentApp = await response.json();
                showApplicationModal(currentApp);
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to load application details');
            }
        }

        // Show application modal
        function showApplicationModal(app) {
            const modalBody = document.getElementById('modal-body');
            const modalFooter = document.getElementById('modal-footer');

            // Modal body content
            modalBody.innerHTML = `
                <div class="info-section">
                    <h3>Applicant Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="label">Name</div>
                            <div class="value">${app.applicant_name || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="label">Email</div>
                            <div class="value">${app.email || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="label">Program Applied</div>
                            <div class="value">${app.program_name || 'N/A'}</div>
                        </div>
                        <div class="info-item">
                            <div class="label">Contact Number</div>
                            <div class="value">${app.phone || 'N/A'}</div>
                        </div>
                    </div>
                </div>

                ${app.total_score > 0 ? `
                <div class="info-section">
                    <h3>Evaluation Results</h3>
                    <div style="background: #f9fafb; padding: 20px; border-radius: 8px;">
                        <div style="margin-bottom: 15px;">
                            <div class="label">Evaluated By</div>
                            <div class="value">${app.evaluator_name || 'N/A'}</div>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <div class="label">Total Score</div>
                            <div class="score-display">${app.total_score}%</div>
                            <p style="font-size: 13px; color: #6b7280;">
                                ${app.total_score < 48 ? '❌ NOT QUALIFIED (0-47%)' :
                                  app.total_score <= 60 ? '⚠️ PARTIALLY QUALIFIED (48-60%)' :
                                  '✅ Qualified for Panel Approval (61-100%)'}
                            </p>
                        </div>
                        ${app.evaluation_criteria && app.evaluation_criteria.length > 0 ? `
                        <div>
                            <div class="label" style="margin-bottom: 10px;">Evaluation Criteria</div>
                            <ul class="criteria-list">
                                ${app.evaluation_criteria.map(c => `
                                    <li class="criteria-item">
                                        <span>${c.criteria_name}</span>
                                        <strong>${c.score}/${c.max_score}</strong>
                                    </li>
                                `).join('')}
                            </ul>
                        </div>
                        ` : ''}
                        ${app.recommendation ? `
                        <div style="margin-top: 15px;">
                            <div class="label">Overall Remarks</div>
                            <p style="font-size: 13px; margin-top: 5px; white-space: pre-wrap;">${app.recommendation}</p>
                        </div>
                        ` : ''}
                    </div>
                </div>
                ` : ''}

                ${app.approval_history && app.approval_history.length > 0 ? `
                <div class="info-section">
                    <h3>Approval History</h3>
                    ${app.approval_history.map(approval => `
                        <div class="approval-item">
                            <div class="approval-header">
                                <div>
                                    <div style="font-weight: 500;">${formatStatus(approval.approver_role)}</div>
                                    <div style="font-size: 13px; color: #6b7280;">${approval.approver_name}</div>
                                </div>
                                <span class="badge ${approval.action === 'approved' ? 'qualified' : 'rejected'}">
                                    ${approval.action.toUpperCase()}
                                </span>
                            </div>
                            ${approval.remarks ? `<p style="font-size: 13px; color: #6b7280; margin-top: 10px;">${approval.remarks}</p>` : ''}
                            <p style="font-size: 12px; color: #9ca3af; margin-top: 5px;">${formatDate(approval.approved_at)}</p>
                        </div>
                    `).join('')}
                </div>
                ` : ''}
            `;

            // Modal footer with action buttons
            if (app.application_status === 'awaiting_director') {
                modalFooter.innerHTML = `
                    <textarea id="remarks" class="remarks-input" rows="3" placeholder="Enter your remarks (Optional for approval, Required for rejection)"></textarea>
                    <div style="display: flex; gap: 10px; width: 100%; justify-content: flex-end;">
                        <button class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                        <button class="btn btn-reject" onclick="rejectApplication()">Reject</button>
                        <button class="btn btn-approve" onclick="approveApplication()">Approve & Forward to CED</button>
                    </div>
                `;
            } else {
                modalFooter.innerHTML = `
                    <button class="btn btn-cancel" onclick="closeModal()">Close</button>
                `;
            }

            document.getElementById('modal').classList.add('show');
        }

        // Approve application
        async function approveApplication() {
            if (!confirm('Are you sure you want to approve this application? It will be forwarded to CED for next approval.')) {
                return;
            }

            const remarks = document.getElementById('remarks')?.value || '';

            try {
                const response = await fetch(`${API_URL}/applications/${currentApp.id}/approve`, {
                    method: 'PATCH',
                    headers: {
                        'Authorization': `Bearer ${TOKEN}`,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ remarks })
                });

                const result = await response.json();

                if (!response.ok) {
                    throw new Error(result.error || 'Failed to approve');
                }

                alert('Application approved successfully! Moving to CED for next approval.');
                closeModal();
                loadApplications();
                loadStats();
            } catch (error) {
                console.error('Error:', error);
                alert(error.message || 'Failed to approve application');
            }
        }

        // Reject application
        async function rejectApplication() {
            const remarks = document.getElementById('remarks')?.value || '';

            if (!remarks.trim()) {
                alert('Please provide remarks for rejection');
                return;
            }

            if (!confirm('Are you sure you want to reject this application?')) {
                return;
            }

            try {
                const response = await fetch(`${API_URL}/applications/${currentApp.id}/reject`, {
                    method: 'PATCH',
                    headers: {
                        'Authorization': `Bearer ${TOKEN}`,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ remarks })
                });

                const result = await response.json();

                if (!response.ok) {
                    throw new Error(result.error || 'Failed to reject');
                }

                alert('Application rejected');
                closeModal();
                loadApplications();
                loadStats();
            } catch (error) {
                console.error('Error:', error);
                alert(error.message || 'Failed to reject application');
            }
        }

        // Close modal
        function closeModal() {
            document.getElementById('modal').classList.remove('show');
            currentApp = null;
        }

        // Helper functions
        function showLoading() {
            document.getElementById('loading').style.display = 'block';
            document.getElementById('applications-table').style.display = 'none';
            document.getElementById('no-data').style.display = 'none';
        }

        function getScoreClass(score) {
            if (score >= 61) return 'high';
            if (score >= 48) return 'medium';
            return 'low';
        }

        function getStatusClass(status) {
            const classes = {
                'awaiting_director': 'awaiting',
                'awaiting_ced': 'awaiting',
                'awaiting_vpaa': 'awaiting',
                'awaiting_president': 'awaiting',
                'awaiting_registrar': 'awaiting',
                'qualified': 'qualified',
                'rejected': 'rejected',
                'not_qualified': 'not-qualified',
                'partially_qualified': 'partial'
            };
            return classes[status] || '';
        }

        function formatStatus(status) {
            return status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }

        function formatDate(date) {
            if (!date) return 'N/A';
            return new Date(date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }
    </script>
</body>
</html>