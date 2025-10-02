<?php
/**
 * Enhanced Criteria Management Script
 * Implements the comprehensive ETEEAP scoring system
 */

session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../includes/functions.php';

requireAuth(['admin']);

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

// Handle bulk criteria import for ETEEAP programs
if ($_POST && isset($_POST['import_eteeap_criteria'])) {
    $program_id = $_POST['program_id'];
    
    if (empty($program_id)) {
        $errors[] = "Please select a program";
    } else {
        try {
            $pdo->beginTransaction();
            
            // ETEEAP Standard Criteria Structure
            $eteeap_criteria = [
                // Section 1: Educational Qualifications (30pts)
                [
                    'criteria_name' => 'High School Graduate',
                    'criteria_type' => 'education',
                    'max_score' => 10,
                    'weight' => 1.0,
                    'description' => 'High School Diploma or equivalent',
                    'section_number' => 1,
                    'subsection' => '1.1'
                ],
                [
                    'criteria_name' => 'Vocational/Technical Certificate',
                    'criteria_type' => 'education',
                    'max_score' => 17,
                    'weight' => 1.0,
                    'description' => 'Vocational or Technical certification',
                    'section_number' => 1,
                    'subsection' => '1.2'
                ],
                [
                    'criteria_name' => 'College Undergraduate',
                    'criteria_type' => 'education',
                    'max_score' => 30,
                    'weight' => 1.0,
                    'description' => 'College units completed',
                    'section_number' => 1,
                    'subsection' => '1.3'
                ],
                [
                    'criteria_name' => 'Non-BIT Related Degree Holder',
                    'criteria_type' => 'education',
                    'max_score' => 20,
                    'weight' => 1.0,
                    'description' => 'Bachelor\'s degree in other field',
                    'section_number' => 1,
                    'subsection' => '1.4'
                ],
                
                // Section 2: Work Experience (30pts)
                [
                    'criteria_name' => 'Entry Level Work Experience (1-5 years)',
                    'criteria_type' => 'work_experience',
                    'max_score' => 15,
                    'weight' => 2.0,
                    'description' => 'Entry level work experience with at least 1 year',
                    'section_number' => 2,
                    'subsection' => '2.1'
                ],
                [
                    'criteria_name' => 'School Learning Center Administrator',
                    'criteria_type' => 'work_experience',
                    'max_score' => 5,
                    'weight' => 2.0,
                    'description' => 'Administrative experience in educational technology',
                    'section_number' => 2,
                    'subsection' => '2.2'
                ],
                [
                    'criteria_name' => 'Training Supervisor',
                    'criteria_type' => 'work_experience',
                    'max_score' => 5,
                    'weight' => 2.0,
                    'description' => 'Training and supervision experience',
                    'section_number' => 2,
                    'subsection' => '2.3'
                ],
                [
                    'criteria_name' => 'Trainer/Learning Facilitator',
                    'criteria_type' => 'work_experience',
                    'max_score' => 5,
                    'weight' => 2.0,
                    'description' => 'Training and facilitation experience',
                    'section_number' => 2,
                    'subsection' => '2.4'
                ],
                
                // Section 3: Professional Development - Inventions/Innovations (15pts)
                [
                    'criteria_name' => 'Inventions/Innovations - Local Market',
                    'criteria_type' => 'portfolio',
                    'max_score' => 7,
                    'weight' => 1.5,
                    'description' => 'Local market innovations and inventions',
                    'section_number' => 3,
                    'subsection' => '3.1.1'
                ],
                [
                    'criteria_name' => 'Inventions/Innovations - National Market',
                    'criteria_type' => 'portfolio',
                    'max_score' => 8,
                    'weight' => 1.5,
                    'description' => 'National market innovations',
                    'section_number' => 3,
                    'subsection' => '3.1.2'
                ],
                [
                    'criteria_name' => 'Inventions/Innovations - International Market',
                    'criteria_type' => 'portfolio',
                    'max_score' => 9,
                    'weight' => 1.5,
                    'description' => 'International market innovations',
                    'section_number' => 3,
                    'subsection' => '3.1.3'
                ],
                
                // Section 3.2: Publications (15pts)
                [
                    'criteria_name' => 'Publications - Local Journals',
                    'criteria_type' => 'portfolio',
                    'max_score' => 4,
                    'weight' => 1.5,
                    'description' => 'Local publications and journals',
                    'section_number' => 3,
                    'subsection' => '3.2.1'
                ],
                [
                    'criteria_name' => 'Publications - National with ISBN',
                    'criteria_type' => 'portfolio',
                    'max_score' => 5,
                    'weight' => 1.5,
                    'description' => 'National publications with ISBN',
                    'section_number' => 3,
                    'subsection' => '3.2.2'
                ],
                [
                    'criteria_name' => 'Publications - International with Copyright',
                    'criteria_type' => 'portfolio',
                    'max_score' => 6,
                    'weight' => 1.5,
                    'description' => 'International publications with copyright',
                    'section_number' => 3,
                    'subsection' => '3.2.3'
                ],
                
                // Section 3.2: Extension Services (20pts)
                [
                    'criteria_name' => 'Consultancy Services - Local',
                    'criteria_type' => 'portfolio',
                    'max_score' => 5,
                    'weight' => 1.5,
                    'description' => 'Local consultancy in company, industry or factory',
                    'section_number' => 3,
                    'subsection' => '3.2.1'
                ],
                [
                    'criteria_name' => 'Consultancy Services - National',
                    'criteria_type' => 'portfolio',
                    'max_score' => 10,
                    'weight' => 1.5,
                    'description' => 'National consultancy outside school or organization',
                    'section_number' => 3,
                    'subsection' => '3.2.2'
                ],
                [
                    'criteria_name' => 'Consultancy Services - International',
                    'criteria_type' => 'portfolio',
                    'max_score' => 15,
                    'weight' => 1.5,
                    'description' => 'International consultancy does abroad',
                    'section_number' => 3,
                    'subsection' => '3.2.3'
                ],
                [
                    'criteria_name' => 'Resource Person - Local Level',
                    'criteria_type' => 'portfolio',
                    'max_score' => 6,
                    'weight' => 1.5,
                    'description' => 'Local level lecturer/speaker/resource person',
                    'section_number' => 3,
                    'subsection' => '3.2.4'
                ],
                [
                    'criteria_name' => 'Resource Person - National Level',
                    'criteria_type' => 'portfolio',
                    'max_score' => 8,
                    'weight' => 1.5,
                    'description' => 'National level lecturer/speaker/resource person',
                    'section_number' => 3,
                    'subsection' => '3.2.5'
                ],
                [
                    'criteria_name' => 'Resource Person - International Level',
                    'criteria_type' => 'portfolio',
                    'max_score' => 10,
                    'weight' => 1.5,
                    'description' => 'International level lecturer/speaker/resource person',
                    'section_number' => 3,
                    'subsection' => '3.2.6'
                ],
                [
                    'criteria_name' => 'Community Services - Trainer/Coordinator',
                    'criteria_type' => 'portfolio',
                    'max_score' => 3,
                    'weight' => 1.5,
                    'description' => 'Community services as trainer/coordinator/organizer',
                    'section_number' => 3,
                    'subsection' => '3.2.7'
                ],
                [
                    'criteria_name' => 'Community Services - Municipal Official',
                    'criteria_type' => 'portfolio',
                    'max_score' => 4,
                    'weight' => 1.5,
                    'description' => 'Community services as barangay/municipal official',
                    'section_number' => 3,
                    'subsection' => '3.2.8'
                ],
                [
                    'criteria_name' => 'Community Services - Project Manager',
                    'criteria_type' => 'portfolio',
                    'max_score' => 5,
                    'weight' => 1.5,
                    'description' => 'Community services as project director/project manager',
                    'section_number' => 3,
                    'subsection' => '3.2.9'
                ],
                
                // Section 4: Personal Professional Development (25pts)
                [
                    'criteria_name' => 'Training Program Coordination - Local',
                    'criteria_type' => 'training',
                    'max_score' => 6,
                    'weight' => 1.0,
                    'description' => 'Local level training coordination',
                    'section_number' => 4,
                    'subsection' => '4.1'
                ],
                [
                    'criteria_name' => 'Training Program Coordination - National',
                    'criteria_type' => 'training',
                    'max_score' => 8,
                    'weight' => 1.0,
                    'description' => 'National level training coordination',
                    'section_number' => 4,
                    'subsection' => '4.2'
                ],
                [
                    'criteria_name' => 'Training Program Coordination - International',
                    'criteria_type' => 'training',
                    'max_score' => 10,
                    'weight' => 1.0,
                    'description' => 'International level training coordination',
                    'section_number' => 4,
                    'subsection' => '4.3'
                ],
                [
                    'criteria_name' => 'Seminar/Workshop Participation - Local',
                    'criteria_type' => 'training',
                    'max_score' => 1,
                    'weight' => 1.0,
                    'description' => 'Local seminar/workshop participation',
                    'section_number' => 4,
                    'subsection' => '4.4'
                ],
                [
                    'criteria_name' => 'Seminar/Workshop Participation - National',
                    'criteria_type' => 'training',
                    'max_score' => 4,
                    'weight' => 1.0,
                    'description' => 'National seminar/workshop participation',
                    'section_number' => 4,
                    'subsection' => '4.5'
                ],
                [
                    'criteria_name' => 'Seminar/Workshop Participation - International',
                    'criteria_type' => 'training',
                    'max_score' => 5,
                    'weight' => 1.0,
                    'description' => 'International seminar/workshop participation',
                    'section_number' => 4,
                    'subsection' => '4.6'
                ],
                [
                    'criteria_name' => 'Professional Organization Membership - Local',
                    'criteria_type' => 'certification',
                    'max_score' => 3,
                    'weight' => 1.0,
                    'description' => 'Local professional organization membership',
                    'section_number' => 4,
                    'subsection' => '4.7'
                ],
                [
                    'criteria_name' => 'Professional Organization Membership - National',
                    'criteria_type' => 'certification',
                    'max_score' => 4,
                    'weight' => 1.0,
                    'description' => 'National professional organization membership',
                    'section_number' => 4,
                    'subsection' => '4.8'
                ],
                [
                    'criteria_name' => 'Professional Organization Membership - International',
                    'criteria_type' => 'certification',
                    'max_score' => 5,
                    'weight' => 1.0,
                    'description' => 'International professional organization membership',
                    'section_number' => 4,
                    'subsection' => '4.9'
                ],
                [
                    'criteria_name' => 'Scholarships - Local Non-Competitive',
                    'criteria_type' => 'certification',
                    'max_score' => 2.5,
                    'weight' => 1.0,
                    'description' => 'Local non-competitive scholarships',
                    'section_number' => 4,
                    'subsection' => '4.10'
                ],
                [
                    'criteria_name' => 'Scholarships - Local Competitive',
                    'criteria_type' => 'certification',
                    'max_score' => 3,
                    'weight' => 1.0,
                    'description' => 'Local competitive scholarships',
                    'section_number' => 4,
                    'subsection' => '4.11'
                ],
                [
                    'criteria_name' => 'Scholarships - National Non-Competitive',
                    'criteria_type' => 'certification',
                    'max_score' => 3.5,
                    'weight' => 1.0,
                    'description' => 'National non-competitive scholarships',
                    'section_number' => 4,
                    'subsection' => '4.12'
                ],
                [
                    'criteria_name' => 'Scholarships - National Competitive',
                    'criteria_type' => 'certification',
                    'max_score' => 4,
                    'weight' => 1.0,
                    'description' => 'National competitive scholarships',
                    'section_number' => 4,
                    'subsection' => '4.13'
                ],
                [
                    'criteria_name' => 'Scholarships - International Non-Competitive',
                    'criteria_type' => 'certification',
                    'max_score' => 4.5,
                    'weight' => 1.0,
                    'description' => 'International non-competitive scholarships',
                    'section_number' => 4,
                    'subsection' => '4.14'
                ],
                [
                    'criteria_name' => 'Scholarships - International Competitive',
                    'criteria_type' => 'certification',
                    'max_score' => 5,
                    'weight' => 1.0,
                    'description' => 'International competitive scholarships',
                    'section_number' => 4,
                    'subsection' => '4.15'
                ],
                
                // Section 5: Recognition, Awards and Eligibilities (15pts)
                [
                    'criteria_name' => 'Recognition/Awards - Local Level',
                    'criteria_type' => 'certification',
                    'max_score' => 6,
                    'weight' => 1.0,
                    'description' => 'Local level recognition and awards',
                    'section_number' => 5,
                    'subsection' => '5.1'
                ],
                [
                    'criteria_name' => 'Recognition/Awards - National Level',
                    'criteria_type' => 'certification',
                    'max_score' => 8,
                    'weight' => 1.0,
                    'description' => 'National level recognition and awards',
                    'section_number' => 5,
                    'subsection' => '5.2'
                ],
                [
                    'criteria_name' => 'Recognition/Awards - International Level',
                    'criteria_type' => 'certification',
                    'max_score' => 10,
                    'weight' => 1.0,
                    'description' => 'International level recognition and awards',
                    'section_number' => 5,
                    'subsection' => '5.3'
                ],
                [
                    'criteria_name' => 'CS Sub-Professional License',
                    'criteria_type' => 'certification',
                    'max_score' => 3,
                    'weight' => 1.0,
                    'description' => 'Computer Science Sub-Professional License',
                    'section_number' => 5,
                    'subsection' => '5.4'
                ],
                [
                    'criteria_name' => 'CS Professional License',
                    'criteria_type' => 'certification',
                    'max_score' => 4,
                    'weight' => 1.0,
                    'description' => 'Computer Science Professional License',
                    'section_number' => 5,
                    'subsection' => '5.5'
                ],
                [
                    'criteria_name' => 'PRC Licensure Exam',
                    'criteria_type' => 'certification',
                    'max_score' => 5,
                    'weight' => 1.0,
                    'description' => 'Professional Regulation Commission License',
                    'section_number' => 5,
                    'subsection' => '5.6'
                ],
                [
                    'criteria_name' => 'Driver\'s License',
                    'criteria_type' => 'certification',
                    'max_score' => 3,
                    'weight' => 1.0,
                    'description' => 'Valid Driver\'s License',
                    'section_number' => 5,
                    'subsection' => '5.7'
                ]
            ];
            
            // Insert each criteria
            foreach ($eteeap_criteria as $criteria) {
                $stmt = $pdo->prepare("
                    INSERT INTO assessment_criteria 
                    (program_id, criteria_name, criteria_type, max_score, weight, description, section_number, subsection, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                
                $stmt->execute([
                    $program_id,
                    $criteria['criteria_name'],
                    $criteria['criteria_type'],
                    $criteria['max_score'],
                    $criteria['weight'],
                    $criteria['description'],
                    $criteria['section_number'],
                    $criteria['subsection']
                ]);
            }
            
            $pdo->commit();
            logActivity($pdo, "eteeap_criteria_imported", $user_id, "assessment_criteria", $program_id);
            
            redirectWithMessage('programs.php', 'ETEEAP standard criteria imported successfully!', 'success');
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Failed to import criteria: " . $e->getMessage();
        }
    }
}

// Get programs for import dropdown
$programs = [];
try {
    $stmt = $pdo->query("SELECT * FROM programs WHERE status = 'active' ORDER BY program_name");
    $programs = $stmt->fetchAll();
} catch (PDOException $e) {
    $programs = [];
}

// Check for flash messages
$flash = getFlashMessage();
if ($flash) {
    if ($flash['type'] === 'success') {
        $success_message = $flash['message'];
    } else {
        $errors[] = $flash['message'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ETEEAP Criteria Management - ETEEAP</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            margin: 0; 
            padding-top: 0 !important;
        }
        .criteria-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: none;
        }
        .section-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            border-radius: 10px 10px 0 0;
            margin-bottom: 1rem;
        }
        .criteria-section {
            border-left: 4px solid #667eea;
            margin-bottom: 2rem;
            padding-left: 1rem;
        }
        .criteria-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-left: 3px solid #28a745;
        }

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
        .program-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: none;
            transition: transform 0.3s ease;
        }
        .program-card:hover {
            transform: translateY(-2px);
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: none;
            text-align: center;
        }
        .criteria-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-left: 4px solid #667eea;
        }
        .criteria-inactive {
            border-left-color: #dc3545;
            background: rgba(220, 53, 69, 0.05);
        }
        .progress {
            height: 8px;
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
                        <a class="nav-link" href="evaluate.php">
                            <i class="fas fa-clipboard-check me-2"></i>
                            Evaluate Applications
                        </a>
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar me-2"></i>
                            Reports
                        </a>
                        <a class="nav-link" href="users.php">
                            <i class="fas fa-users me-2"></i>
                            Manage Users
                        </a>
                        <a class="nav-link active" href="programs.php">
                            <i class="fas fa-graduation-cap me-2"></i>
                            Manage Programs
                        </a>
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

    <div class="container mt-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">ETEEAP Criteria Management</h2>
                <p class="text-muted mb-0">Import standardized ETEEAP evaluation criteria</p>
            </div>
            <a href="programs.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Programs
            </a>
        </div>

        <!-- Messages -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <!-- Import Form -->
        <div class="criteria-card p-4 mb-4">
            <h5 class="mb-3">
                <i class="fas fa-download me-2"></i>
                Import Standard ETEEAP Criteria
            </h5>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                This will import the complete ETEEAP evaluation criteria structure with 5 main sections and detailed scoring guidelines.
            </div>
            
            <form method="POST" action="">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label for="program_id" class="form-label">Select Program *</label>
                        <select class="form-select" id="program_id" name="program_id" required>
                            <option value="">Choose a program...</option>
                            <?php foreach ($programs as $program): ?>
                            <option value="<?php echo $program['id']; ?>">
                                <?php echo htmlspecialchars($program['program_name']); ?> 
                                (<?php echo htmlspecialchars($program['program_code']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" name="import_eteeap_criteria" class="btn btn-primary w-100"
                                onclick="return confirm('This will import all ETEEAP criteria. Continue?')">
                            <i class="fas fa-download me-2"></i>Import Criteria
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Criteria Preview -->
        <div class="criteria-card p-4">
            <h5 class="mb-4">
                <i class="fas fa-eye me-2"></i>
                ETEEAP Criteria Structure Preview
            </h5>
            
            <div class="criteria-section">
                <div class="section-header">
                    <h6 class="mb-0">Section 1: Educational Qualifications (30 points)</h6>
                </div>
                <div class="criteria-item">
                    <strong>1.1 High School Graduate (10 pts)</strong><br>
                    <small class="text-muted">High School Diploma or equivalent</small>
                </div>
                <div class="criteria-item">
                    <strong>1.2 Vocational/Technical Certificate (17 pts)</strong><br>
                    <small class="text-muted">Vocational or Technical certification</small>
                </div>
                <div class="criteria-item">
                    <strong>1.3 College Undergraduate (30 pts)</strong><br>
                    <small class="text-muted">College units completed</small>
                </div>
                <div class="criteria-item">
                    <strong>1.4 Non-BIT Related Degree Holder (20 pts)</strong><br>
                    <small class="text-muted">Bachelor's degree in other field</small>
                </div>
            </div>

            <div class="criteria-section">
                <div class="section-header">
                    <h6 class="mb-0">Section 2: Work Experience (30 points)</h6>
                </div>
                <div class="criteria-item">
                    <strong>2.1 Entry Level Work Experience (15 pts)</strong><br>
                    <small class="text-muted">1-5 years relevant work experience</small>
                </div>
                <div class="criteria-item">
                    <strong>2.2 School Learning Center Administrator (5 pts)</strong><br>
                    <small class="text-muted">Administrative experience in educational technology</small>
                </div>
                <div class="criteria-item">
                    <strong>2.3 Training Supervisor (5 pts)</strong><br>
                    <small class="text-muted">Training and supervision experience</small>
                </div>
                <div class="criteria-item">
                    <strong>2.4 Trainer/Learning Facilitator (5 pts)</strong><br>
                    <small class="text-muted">Training and facilitation experience</small>
                </div>
            </div>

            <div class="criteria-section">
                <div class="section-header">
                    <h6 class="mb-0">Section 3: Professional Development (20 points)</h6>
                </div>
                <div class="criteria-item">
                    <strong>3.1 Inventions/Innovations</strong><br>
                    <small class="text-muted">Local (7 pts), National (8 pts), International (9 pts)</small>
                </div>
                <div class="criteria-item">
                    <strong>3.2 Publications</strong><br>
                    <small class="text-muted">Local (4 pts), National (5 pts), International (6 pts)</small>
                </div>
                <div class="criteria-item">
                    <strong>3.3 Extension Services</strong><br>
                    <small class="text-muted">Consultancy, Resource Person, Community Services</small>
                </div>
            </div>

            <div class="criteria-section">
                <div class="section-header">
                    <h6 class="mb-0">Section 4: Personal Professional Development (25 points)</h6>
                </div>
                <div class="criteria-item">
                    <strong>4.1 Training Program Coordination</strong><br>
                    <small class="text-muted">Local (6 pts), National (8 pts), International (10 pts)</small>
                </div>
                <div class="criteria-item">
                    <strong>4.2 Seminar/Workshop Participation</strong><br>
                    <small class="text-muted">Local (1 pt), National (4 pts), International (5 pts)</small>
                </div>
                <div class="criteria-item">
                    <strong>4.3 Professional Organization Membership</strong><br>
                    <small class="text-muted">Local (3 pts), National (4 pts), International (5 pts)</small>
                </div>
                <div class="criteria-item">
                    <strong>4.4 Scholarships</strong><br>
                    <small class="text-muted">Various levels from Local Non-Competitive (2.5 pts) to International Competitive (5 pts)</small>
                </div>
            </div>

            <div class="criteria-section">
                <div class="section-header">
                    <h6 class="mb-0">Section 5: Recognition, Awards and Eligibilities (15 points)</h6>
                </div>
                <div class="criteria-item">
                    <strong>5.1 Recognition/Awards</strong><br>
                    <small class="text-muted">Local (6 pts), National (8 pts), International (10 pts)</small>
                </div>
                <div class="criteria-item">
                    <strong>5.2 Professional Licenses</strong><br>
                    <small class="text-muted">CS Sub-Professional (3 pts), CS Professional (4 pts), PRC License (5 pts)</small>
                </div>
                <div class="criteria-item">
                    <strong>5.3 Driver's License (3 pts)</strong><br>
                    <small class="text-muted">Valid Driver's License</small>
                </div>
            </div>

            <div class="alert alert-warning mt-4">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Total Points: 120</strong> - This comprehensive scoring system evaluates candidates across all major competency areas as per ETEEAP guidelines.
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>