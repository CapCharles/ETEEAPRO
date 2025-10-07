<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

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
// Handle program actions
if ($_POST) {
    if (isset($_POST['add_program'])) {
        // Add new program
        $program_name = sanitizeInput($_POST['program_name']);
        $program_code = sanitizeInput($_POST['program_code']);
        $description = sanitizeInput($_POST['description']);
        $requirements = sanitizeInput($_POST['requirements']);
        $status = $_POST['status'];
        $apply_template = isset($_POST['apply_template']) ? $_POST['apply_template'] : null;
        
        // Validation
        if (empty($program_name) || empty($program_code)) {
            $errors[] = "Program name and code are required";
        }
        
        // Check if program code exists
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM programs WHERE program_code = ?");
                $stmt->execute([$program_code]);
                if ($stmt->fetch()) {
                    $errors[] = "Program code already exists";
                }
            } catch (PDOException $e) {
                $errors[] = "Database error occurred: " . $e->getMessage();
            }
        }
        
        // Create program
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    INSERT INTO programs (program_name, program_code, description, requirements, status)
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([$program_name, $program_code, $description, $requirements, $status]);
                
                $program_id = $pdo->lastInsertId();
                
                // Apply template if selected
                if ($apply_template && $apply_template !== 'none') {
                    try {
                        $template_id = null;
                        
                        if ($apply_template === 'default') {
                            // Get default template ID
                            $stmt = $pdo->prepare("SELECT id FROM program_templates WHERE is_default = 1 LIMIT 1");
                            $stmt->execute();
                            $template = $stmt->fetch();
                            
                            if ($template) {
                                $template_id = $template['id'];
                            } else {
                                throw new Exception("Default template not found");
                            }
                        } else {
                            $template_id = intval($apply_template);
                        }
                        
                        if ($template_id) {
                            // Verify template exists
                            $stmt = $pdo->prepare("SELECT id FROM program_templates WHERE id = ?");
                            $stmt->execute([$template_id]);
                            if (!$stmt->fetch()) {
                                throw new Exception("Selected template not found");
                            }
                            
                            // Call stored procedure to apply template
                            $stmt = $pdo->prepare("CALL ApplyTemplateToProgram(?, ?)");
                            $stmt->execute([$program_id, $template_id]);
                            
                            // Verify criteria were added
                            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM assessment_criteria WHERE program_id = ?");
                            $stmt->execute([$program_id]);
                            $criteria_count = $stmt->fetch()['count'];
                            
                            if ($criteria_count > 0) {
                                $success_message = "Program created successfully with template applied! ($criteria_count criteria added)";
                            } else {
                                $success_message = "Program created successfully, but no criteria were added from template.";
                            }
                        }
                    } catch (Exception $e) {
                        // Template application failed, but program was created
                        $success_message = "Program created successfully, but template application failed: " . $e->getMessage();
                    }
                } else {
                    $success_message = "Program created successfully!";
                }
                
                // Log activity
                logActivity($pdo, "program_created", $user_id, "programs", $program_id, null, [
                    'program_name' => $program_name,
                    'program_code' => $program_code,
                    'status' => $status,
                    'template_applied' => $apply_template ?? 'none'
                ]);
                
                $pdo->commit();
                redirectWithMessage('programs.php', $success_message, 'success');
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = "Failed to create program: " . $e->getMessage();
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = "Error: " . $e->getMessage();
            }
        }
    }
    
    elseif (isset($_POST['update_program'])) {
        // Update existing program
        $program_id = $_POST['program_id'];
        $program_name = sanitizeInput($_POST['program_name']);
        $program_code = sanitizeInput($_POST['program_code']);
        $description = sanitizeInput($_POST['description']);
        $requirements = sanitizeInput($_POST['requirements']);
        $status = $_POST['status'];
        
        // Validation
        if (empty($program_name) || empty($program_code)) {
            $errors[] = "Program name and code are required";
        }
        
        // Check if program code exists for other programs
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM programs WHERE program_code = ? AND id != ?");
                $stmt->execute([$program_code, $program_id]);
                if ($stmt->fetch()) {
                    $errors[] = "Program code already exists";
                }
            } catch (PDOException $e) {
                $errors[] = "Database error occurred";
            }
        }
        
        // Update program
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE programs 
                    SET program_name = ?, program_code = ?, description = ?, requirements = ?, status = ?
                    WHERE id = ?
                ");
                
                $stmt->execute([$program_name, $program_code, $description, $requirements, $status, $program_id]);
                
                // Log activity
                logActivity($pdo, "program_updated", $user_id, "programs", $program_id);
                
                redirectWithMessage('programs.php', 'Program updated successfully!', 'success');
                
            } catch (PDOException $e) {
                $errors[] = "Failed to update program. Please try again.";
            }
        }
    }
    
    elseif (isset($_POST['add_criteria'])) {
        // Add assessment criteria
        $program_id = $_POST['program_id'];
        $criteria_name = sanitizeInput($_POST['criteria_name']);
        $criteria_type = $_POST['criteria_type'];
        $max_score = intval($_POST['max_score']);
        $weight = floatval($_POST['weight']);
        $description = sanitizeInput($_POST['description']);
        $requirements = sanitizeInput($_POST['requirements']);
        
        // Validation
        if (empty($criteria_name) || empty($criteria_type) || $max_score <= 0 || $weight <= 0) {
            $errors[] = "All criteria fields are required and must be valid";
        }
        
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO assessment_criteria (program_id, criteria_name, criteria_type, max_score, weight, description, requirements)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([$program_id, $criteria_name, $criteria_type, $max_score, $weight, $description, $requirements]);
                
                $criteria_id = $pdo->lastInsertId();
                
                // Log activity
                logActivity($pdo, "criteria_created", $user_id, "assessment_criteria", $criteria_id);
                
                redirectWithMessage('programs.php', 'Assessment criteria added successfully!', 'success');
                
            } catch (PDOException $e) {
                $errors[] = "Failed to add criteria. Please try again.";
            }
        }
    }
    elseif (isset($_POST['update_criteria'])) {
    // Update existing assessment criteria
    $criteria_id = $_POST['criteria_id'];
    $criteria_name = sanitizeInput($_POST['criteria_name']);
    $criteria_type = $_POST['criteria_type'];
    $max_score = intval($_POST['max_score']);
    $weight = floatval($_POST['weight']);
    $description = sanitizeInput($_POST['description']);
    $requirements = sanitizeInput($_POST['requirements']);
    
    // Validation
    if (empty($criteria_name) || empty($criteria_type) || $max_score <= 0 || $weight <= 0) {
        $errors[] = "All criteria fields are required and must be valid";
    }
    
    // Check if criteria has evaluations - if so, only allow certain updates
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM evaluations WHERE criteria_id = ?");
            $stmt->execute([$criteria_id]);
            $eval_count = $stmt->fetch()['count'];
            
            if ($eval_count > 0) {
                // Get current criteria to compare scores
                $stmt = $pdo->prepare("SELECT max_score, weight FROM assessment_criteria WHERE id = ?");
                $stmt->execute([$criteria_id]);
                $current_criteria = $stmt->fetch();
                
                if ($current_criteria && ($current_criteria['max_score'] != $max_score || $current_criteria['weight'] != $weight)) {
                    $errors[] = "Cannot change max score or weight for criteria with existing evaluations. Only name, description, and requirements can be updated.";
                    // Reset to original values
                    $max_score = $current_criteria['max_score'];
                    $weight = $current_criteria['weight'];
                }
            }
        } catch (PDOException $e) {
            $errors[] = "Database error occurred while checking evaluations";
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE assessment_criteria 
                SET criteria_name = ?, criteria_type = ?, max_score = ?, weight = ?, description = ?, requirements = ?
                WHERE id = ?
            ");
            
            $stmt->execute([$criteria_name, $criteria_type, $max_score, $weight, $description, $requirements, $criteria_id]);
            
            // Log activity
            logActivity($pdo, "criteria_updated", $user_id, "assessment_criteria", $criteria_id);
            
            redirectWithMessage('programs.php', 'Assessment criteria updated successfully!', 'success');
            
        } catch (PDOException $e) {
            $errors[] = "Failed to update criteria. Please try again.";
        }
    }
}
    
    elseif (isset($_POST['apply_template_to_existing'])) {
        // Apply template to existing program
        $program_id = $_POST['program_id'];
        $template_id = $_POST['template_id'];
        $replace_existing = isset($_POST['replace_existing']);
        
        if (empty($template_id) || $template_id === 'none') {
            $errors[] = "Please select a template to apply";
        } else {
            try {
                $pdo->beginTransaction();
                
                // If replace existing, delete current criteria first
                if ($replace_existing) {
                    // Check if any criteria have evaluations
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as count 
                        FROM assessment_criteria ac 
                        JOIN evaluations e ON ac.id = e.criteria_id 
                        WHERE ac.program_id = ?
                    ");
                    $stmt->execute([$program_id]);
                    $eval_count = $stmt->fetch()['count'];
                    
                    if ($eval_count > 0) {
                        $errors[] = "Cannot replace criteria that have existing evaluations";
                    } else {
                        // Delete existing criteria
                        $stmt = $pdo->prepare("DELETE FROM assessment_criteria WHERE program_id = ?");
                        $stmt->execute([$program_id]);
                    }
                }
                
                if (empty($errors)) {
                    // Apply template
                    if ($template_id === 'default') {
                        $stmt = $pdo->prepare("SELECT id FROM program_templates WHERE is_default = 1 LIMIT 1");
                        $stmt->execute();
                        $template = $stmt->fetch();
                        $template_id = $template['id'] ?? null;
                    }
                    
                    if ($template_id) {
                        $stmt = $pdo->prepare("CALL ApplyTemplateToProgram(?, ?)");
                        $stmt->execute([$program_id, $template_id]);
                        
                        // Verify criteria were added
                        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM assessment_criteria WHERE program_id = ?");
                        $stmt->execute([$program_id]);
                        $criteria_count = $stmt->fetch()['count'];
                        
                        $pdo->commit();
                        redirectWithMessage('programs.php', "Template applied successfully! ($criteria_count criteria added)", 'success');
                    } else {
                        $errors[] = "Template not found";
                    }
                }
                
                if (!empty($errors)) {
                    $pdo->rollBack();
                }
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = "Failed to apply template: " . $e->getMessage();
            }
        }
    }
}

// Handle program deletion
if (isset($_GET['delete_program'])) {
    $delete_program_id = $_GET['delete_program'];
    
    try {
        // Check if program has applications
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM applications WHERE program_id = ?");
        $stmt->execute([$delete_program_id]);
        $app_count = $stmt->fetch()['count'];
        
        if ($app_count > 0) {
            redirectWithMessage('programs.php', 'Cannot delete program with existing applications. Deactivate instead.', 'warning');
        }
        
        // Delete program and its criteria
        $pdo->beginTransaction();
        
        // Delete assessment criteria first
        $stmt = $pdo->prepare("DELETE FROM assessment_criteria WHERE program_id = ?");
        $stmt->execute([$delete_program_id]);
        
        // Delete program
        $stmt = $pdo->prepare("DELETE FROM programs WHERE id = ?");
        $stmt->execute([$delete_program_id]);
        
        $pdo->commit();
        
        // Log activity
        logActivity($pdo, "program_deleted", $user_id, "programs", $delete_program_id);
        
        redirectWithMessage('programs.php', 'Program deleted successfully!', 'success');
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        redirectWithMessage('programs.php', 'Failed to delete program. Please try again.', 'error');
    }
}

// Handle criteria deletion
if (isset($_GET['delete_criteria'])) {
    $delete_criteria_id = $_GET['delete_criteria'];
    
    try {
        // Check if criteria has evaluations
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM evaluations WHERE criteria_id = ?");
        $stmt->execute([$delete_criteria_id]);
        $eval_count = $stmt->fetch()['count'];
        
        if ($eval_count > 0) {
            redirectWithMessage('programs.php', 'Cannot delete criteria with existing evaluations. Deactivate instead.', 'warning');
        }
        
        // Delete criteria
        $stmt = $pdo->prepare("DELETE FROM assessment_criteria WHERE id = ?");
        $stmt->execute([$delete_criteria_id]);
        
        // Log activity
        logActivity($pdo, "criteria_deleted", $user_id, "assessment_criteria", $delete_criteria_id);
        
        redirectWithMessage('programs.php', 'Assessment criteria deleted successfully!', 'success');
        
    } catch (PDOException $e) {
        redirectWithMessage('programs.php', 'Failed to delete criteria. Please try again.', 'error');
    }
}


elseif (isset($_POST['add_subject'])) {
    // Add new subject - SIMPLIFIED VERSION
    $program_id = intval($_POST['subject_program_id']);
    $subject_code = sanitizeInput($_POST['subject_code']);
    $subject_name = sanitizeInput($_POST['subject_name']);
    $units = intval($_POST['subject_units']);
    
    // Validation
    if (empty($subject_code) || empty($subject_name) || $units <= 0) {
        $errors[] = "Subject code, name, and units are required";
    }
    
    // Check if subject code exists in this program
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM subjects WHERE program_id = ? AND subject_code = ?");
            $stmt->execute([$program_id, $subject_code]);
            if ($stmt->fetch()) {
                $errors[] = "Subject code already exists in this program";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error occurred";
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO subjects (program_id, subject_code, subject_name, units, status)
                VALUES (?, ?, ?, ?, 'active')
            ");
            
            $stmt->execute([$program_id, $subject_code, $subject_name, $units]);
            
            logActivity($pdo, "subject_created", $user_id, "subjects", $pdo->lastInsertId());
            redirectWithMessage('programs.php', 'Subject created successfully!', 'success');
            
        } catch (PDOException $e) {
            $errors[] = "Failed to create subject: " . $e->getMessage();
        }
    }
}

elseif (isset($_POST['update_subject'])) {
    // Update subject - SIMPLIFIED VERSION
    $subject_id = intval($_POST['subject_id']);
    $subject_code = sanitizeInput($_POST['subject_code']);
    $subject_name = sanitizeInput($_POST['subject_name']);
    $units = intval($_POST['subject_units']);
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE subjects 
                SET subject_code = ?, subject_name = ?, units = ?
                WHERE id = ?
            ");
            
            $stmt->execute([$subject_code, $subject_name, $units, $subject_id]);
            
            logActivity($pdo, "subject_updated", $user_id, "subjects", $subject_id);
            redirectWithMessage('programs.php', 'Subject updated successfully!', 'success');
            
        } catch (PDOException $e) {
            $errors[] = "Failed to update subject: " . $e->getMessage();
        }
    }
}

// Handle subject deletion
if (isset($_GET['delete_subject'])) {
    $delete_subject_id = intval($_GET['delete_subject']);
    
    try {
        $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->execute([$delete_subject_id]);
        
        logActivity($pdo, "subject_deleted", $user_id, "subjects", $delete_subject_id);
        redirectWithMessage('programs.php', 'Subject deleted successfully!', 'success');
        
    } catch (PDOException $e) {
        redirectWithMessage('programs.php', 'Failed to delete subject.', 'error');
    }
}
// Get available templates
$templates = [];
try {
    $stmt = $pdo->query("
        SELECT pt.*, COUNT(tc.id) as criteria_count
        FROM program_templates pt
        LEFT JOIN template_criteria tc ON pt.id = tc.template_id
        GROUP BY pt.id
        ORDER BY pt.is_default DESC, pt.template_name
    ");
    $templates = $stmt->fetchAll();
} catch (PDOException $e) {
    $templates = [];
    $errors[] = "Failed to load templates: " . $e->getMessage();
}

// Get programs with statistics
$programs = [];
try {
    $stmt = $pdo->query("
        SELECT p.*, 
               COUNT(a.id) as application_count,
               COUNT(CASE WHEN a.application_status = 'qualified' THEN 1 END) as qualified_count,
               COUNT(CASE WHEN a.application_status = 'partially_qualified' THEN 1 END) as partial_count,
               AVG(CASE WHEN a.total_score > 0 THEN a.total_score END) as avg_score
        FROM programs p
        LEFT JOIN applications a ON p.id = a.program_id
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $programs = $stmt->fetchAll();
} catch (PDOException $e) {
    $programs = [];
}

// Get assessment criteria for all programs
$criteria_by_program = [];
try {
    $stmt = $pdo->query("
        SELECT ac.*, p.program_name,
               COUNT(e.id) as evaluation_count
        FROM assessment_criteria ac
        LEFT JOIN programs p ON ac.program_id = p.id
        LEFT JOIN evaluations e ON ac.id = e.criteria_id
        GROUP BY ac.id
        ORDER BY ac.program_id, ac.section_number, ac.sort_order, ac.criteria_name
    ");
    while ($row = $stmt->fetch()) {
        $criteria_by_program[$row['program_id']][] = $row;
    }
} catch (PDOException $e) {
    $criteria_by_program = [];
}


// Get subjects for all programs
$subjects_by_program = [];
try {
    $stmt = $pdo->query("
        SELECT s.* FROM subjects s
        ORDER BY s.program_id, s.subject_code
    ");
    while ($row = $stmt->fetch()) {
        $subjects_by_program[$row['program_id']][] = $row;
    }
} catch (PDOException $e) {
    $subjects_by_program = [];
}

// Debug: Check if templates exist
if (empty($templates)) {
    $errors[] = "Warning: No templates found in the database. Please run the template setup script.";
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

<!-- Rest of your HTML code remains the same -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Program Management - ETEEAP</title>
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
        .template-info {
            background: #e3f2fd;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
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

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="p-4">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 class="mb-1">Program Management</h2>
                            <p class="text-muted mb-0">Manage ETEEAP programs and assessment criteria</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                            <i class="fas fa-plus me-1"></i>Add New Program
                        </button>
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

                    <!-- Program Statistics -->
                    <div class="row g-4 mb-4">
                        <?php 
                        $total_programs = count($programs);
                        $active_programs = count(array_filter($programs, function($p) { return $p['status'] === 'active'; }));
                        $total_applications = array_sum(array_column($programs, 'application_count'));
                        $avg_success_rate = 0;
                        $success_count = 0;
                        foreach ($programs as $program) {
                            if ($program['application_count'] > 0) {
                                $program_success = (($program['qualified_count'] + $program['partial_count']) / $program['application_count']) * 100;
                                $avg_success_rate += $program_success;
                                $success_count++;
                            }
                        }
                        $avg_success_rate = $success_count > 0 ? round($avg_success_rate / $success_count, 1) : 0;
                        ?>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-graduation-cap fa-2x text-primary mb-2"></i>
                                <div class="h3 text-primary"><?php echo $total_programs; ?></div>
                                <div class="text-muted">Total Programs</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                <div class="h3 text-success"><?php echo $active_programs; ?></div>
                                <div class="text-muted">Active Programs</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-file-alt fa-2x text-info mb-2"></i>
                                <div class="h3 text-info"><?php echo $total_applications; ?></div>
                                <div class="text-muted">Total Applications</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-percentage fa-2x text-warning mb-2"></i>
                                <div class="h3 text-warning"><?php echo $avg_success_rate; ?>%</div>
                                <div class="text-muted">Avg Success Rate</div>
                            </div>
                        </div>
                    </div>

                    <!-- Programs List -->
                    <div class="row g-4">
                        <?php if (empty($programs)): ?>
                        <div class="col-12">
                            <div class="program-card p-5 text-center">
                                <i class="fas fa-graduation-cap fa-4x text-muted mb-4"></i>
                                <h3>No Programs Found</h3>
                                <p class="text-muted mb-4">Create your first ETEEAP program to get started.</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                                    <i class="fas fa-plus me-2"></i>Add New Program
                                </button>
                            </div>
                        </div>
                        <?php else: ?>
                        <?php foreach ($programs as $program): ?>
                        <div class="col-lg-6">
                            <div class="program-card">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="mb-1"><?php echo htmlspecialchars($program['program_code']); ?></h5>
                                        <small class="text-muted"><?php echo htmlspecialchars($program['program_name']); ?></small>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-<?php echo $program['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($program['status']); ?>
                                        </span>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li>
                                                    <button class="dropdown-item" onclick="editProgram(<?php echo htmlspecialchars(json_encode($program)); ?>)">
                                                        <i class="fas fa-edit me-2"></i>Edit Program
                                                    </button>
                                                </li>
                                                <li>
    <button class="dropdown-item" onclick="manageSubjects('<?php echo $program['id']; ?>', '<?php echo htmlspecialchars($program['program_name']); ?>')">
        <i class="fas fa-book me-2"></i>Manage Subjects
    </button>
</li>
                                                <li>
                                                    <button class="dropdown-item" onclick="manageCriteria('<?php echo $program['id']; ?>', '<?php echo htmlspecialchars($program['program_name']); ?>')">
                                                        <i class="fas fa-list-check me-2"></i>Manage Criteria
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item text-warning" onclick="applyTemplateToExisting('<?php echo $program['id']; ?>', '<?php echo htmlspecialchars($program['program_name']); ?>')">
                                                        <i class="fas fa-magic me-2"></i>Apply Template
                                                    </button>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item text-danger" 
                                                       href="programs.php?delete_program=<?php echo $program['id']; ?>"
                                                       onclick="return confirm('Are you sure you want to delete this program? This action cannot be undone.')">
                                                        <i class="fas fa-trash me-2"></i>Delete Program
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="card-body">
                                    <?php if ($program['description']): ?>
                                    <p class="text-muted mb-3"><?php echo nl2br(htmlspecialchars($program['description'])); ?></p>
                                    <?php endif; ?>
                                    
                                    <!-- Program Statistics -->
                                    <div class="row g-3 mb-3">
                                        <div class="col-6">
                                            <div class="text-center">
                                                <div class="h5 text-primary mb-0"><?php echo $program['application_count']; ?></div>
                                                <small class="text-muted">Applications</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="text-center">
                                                <div class="h5 text-success mb-0">
                                                    <?php echo $program['qualified_count'] + $program['partial_count']; ?>
                                                </div>
                                                <small class="text-muted">Qualified</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Success Rate -->
                                    <?php if ($program['application_count'] > 0): ?>
                                    <?php 
                                    $success_rate = (($program['qualified_count'] + $program['partial_count']) / $program['application_count']) * 100;
                                    ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <small class="text-muted">Success Rate</small>
                                            <small class="text-muted"><?php echo round($success_rate, 1); ?>%</small>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar bg-<?php echo $success_rate >= 75 ? 'success' : ($success_rate >= 50 ? 'warning' : 'danger'); ?>" 
                                                 style="width: <?php echo $success_rate; ?>%"></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Average Score -->
                                    <?php if ($program['avg_score']): ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">Average Score</small>
                                            <span class="badge bg-primary"><?php echo round($program['avg_score'], 1); ?>%</span>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Assessment Criteria Count -->
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">Assessment Criteria</small>
                                        <span class="badge bg-info">
                                            <?php echo count($criteria_by_program[$program['id']] ?? []); ?> criteria
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

<div class="modal fade" id="manageSubjectsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-book me-2"></i>Manage Subjects - <span id="subjects_program_name"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Existing Subjects -->
                    <div class="col-lg-8">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">Program Subjects</h6>
                            <span class="badge bg-primary" id="subject_count_badge">0 subjects</span>
                        </div>
                        <div id="subjects_list">
                            <!-- Subjects will be loaded here -->
                        </div>
                    </div>
                    
                    <!-- Add New Subject Form -->
                    <div class="col-lg-4">
                        <h6 class="mb-3">Add New Subject</h6>
                        <form method="POST" action="">
                            <input type="hidden" id="subject_program_id" name="subject_program_id">
                            
                            <div class="mb-3">
                                <label class="form-label">Subject Code *</label>
                                <input type="text" class="form-control" name="subject_code" placeholder="e.g., CS101" required>
                                <div class="form-text">Unique code for this subject</div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Subject Name *</label>
                                <input type="text" class="form-control" name="subject_name" placeholder="e.g., Introduction to Programming" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Units *</label>
                                <input type="number" class="form-control" name="subject_units" min="1" max="10" value="3" required>
                                <div class="form-text">Credit units (1-10)</div>
                            </div>
                            
                            <button type="submit" name="add_subject" class="btn btn-primary w-100">
                                <i class="fas fa-plus me-1"></i>Add Subject
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Simplified Edit Subject Modal -->
<div class="modal fade" id="editSubjectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>Edit Subject
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" id="edit_subject_id" name="subject_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Subject Code *</label>
                        <input type="text" class="form-control" id="edit_subject_code" name="subject_code" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Subject Name *</label>
                        <input type="text" class="form-control" id="edit_subject_name" name="subject_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Units *</label>
                        <input type="number" class="form-control" id="edit_subject_units" name="subject_units" min="1" max="10" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_subject" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Update Subject
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


    <!-- Add Program Modal -->
    <div class="modal fade" id="addProgramModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Add New Program
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="add_program_name" class="form-label">Program Name *</label>
                                <input type="text" class="form-control" id="add_program_name" name="program_name" required>
                            </div>
                            <div class="col-md-4">
                                <label for="add_program_code" class="form-label">Program Code *</label>
                                <input type="text" class="form-control" id="add_program_code" name="program_code" required>
                                <div class="form-text">e.g., BSIT, BSBA</div>
                            </div>
                            
                            <div class="col-12">
                                <label for="add_description" class="form-label">Description</label>
                                <textarea class="form-control" id="add_description" name="description" rows="3" 
                                          placeholder="Brief description of the program..."></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label for="add_requirements" class="form-label">Requirements</label>
                                <textarea class="form-control" id="add_requirements" name="requirements" rows="4" 
                                          placeholder="List program requirements and eligibility criteria..."></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="add_status" class="form-label">Status *</label>
                                <select class="form-select" id="add_status" name="status" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            
                            <!-- Template Selection -->
                            <div class="col-12">
                                <hr>
                                <h6 class="mb-3">
                                    <i class="fas fa-magic me-2"></i>Assessment Criteria Template
                                </h6>
                                <div class="template-info">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Choose a template to automatically create standardized assessment criteria for this program.
                                    </small>
                                </div>
                                
                                <label for="apply_template" class="form-label">Apply Template</label>
                                <select class="form-select" id="apply_template" name="apply_template">
                                    <option value="none">No Template - Create Empty Program</option>
                                    <?php foreach ($templates as $template): ?>
                                    <option value="<?php echo $template['id']; ?>" 
                                            <?php echo $template['is_default'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($template['template_name']); ?>
                                        <?php if ($template['is_default']): ?>
                                            (Default)
                                        <?php endif; ?>
                                        - <?php echo $template['criteria_count']; ?> criteria
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <div class="form-text">
                                    <small>
                                        <i class="fas fa-lightbulb me-1"></i>
                                        <strong>Recommended:</strong> Use the default ETEEAP template for standardized evaluation criteria.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_program" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Create Program
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Program Modal -->
    <div class="modal fade" id="editProgramModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Program
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" id="edit_program_id" name="program_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="edit_program_name" class="form-label">Program Name *</label>
                                <input type="text" class="form-control" id="edit_program_name" name="program_name" required>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_program_code" class="form-label">Program Code *</label>
                                <input type="text" class="form-control" id="edit_program_code" name="program_code" required>
                            </div>
                            
                            <div class="col-12">
                                <label for="edit_description" class="form-label">Description</label>
                                <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label for="edit_requirements" class="form-label">Requirements</label>
                                <textarea class="form-control" id="edit_requirements" name="requirements" rows="4"></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="edit_status" class="form-label">Status *</label>
                                <select class="form-select" id="edit_status" name="status" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_program" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Update Program
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Apply Template to Existing Program Modal -->
    <div class="modal fade" id="applyTemplateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-magic me-2"></i>Apply Template to <span id="template_program_name"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" id="template_program_id" name="program_id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Apply Assessment Template</strong><br>
                            This will add standardized assessment criteria to the program.
                        </div>
                        
                        <div class="mb-3">
                            <label for="template_id" class="form-label">Select Template *</label>
                            <select class="form-select" id="template_id" name="template_id" required>
                                <option value="none">Select a template...</option>
                                <?php foreach ($templates as $template): ?>
                                <option value="<?php echo $template['id']; ?>" 
                                        <?php echo $template['is_default'] ? 'data-default="true"' : ''; ?>>
                                    <?php echo htmlspecialchars($template['template_name']); ?>
                                    <?php if ($template['is_default']): ?>
                                        (Default - Recommended)
                                    <?php endif; ?>
                                    - <?php echo $template['criteria_count']; ?> criteria
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="replace_existing" name="replace_existing">
                            <label class="form-check-label" for="replace_existing">
                                Replace existing criteria
                            </label>
                            <div class="form-text">
                                <small class="text-warning">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    Warning: This will delete all current assessment criteria (if they have no evaluations).
                                </small>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning">
                            <small>
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                <strong>Note:</strong> Criteria with existing evaluations cannot be replaced.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="apply_template_to_existing" class="btn btn-primary">
                            <i class="fas fa-magic me-1"></i>Apply Template
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Manage Criteria Modal -->
    <div class="modal fade" id="manageCriteriaModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-list-check me-2"></i>Manage Assessment Criteria - <span id="criteria_program_name"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <!-- Existing Criteria -->
                        <div class="col-lg-8">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0">Current Assessment Criteria</h6>
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="showApplyTemplateModal()">
                                    <i class="fas fa-magic me-1"></i>Apply Template
                                </button>
                            </div>
                            <div id="criteria_list">
                                <!-- Criteria will be loaded here -->
                            </div>
                        </div>
                        
                        <!-- Add New Criteria -->
                        <div class="col-lg-4">
                            <h6 class="mb-3">Add New Criteria</h6>
                            <form method="POST" action="">
                                <input type="hidden" id="criteria_program_id" name="program_id">
                                <div class="mb-3">
                                    <label for="criteria_name" class="form-label">Criteria Name *</label>
                                    <input type="text" class="form-control form-control-sm" id="criteria_name" name="criteria_name" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="criteria_type" class="form-label">Type *</label>
                                    <select class="form-select form-select-sm" id="criteria_type" name="criteria_type" required>
                                        <option value="">Select Type</option>
                                        <option value="education">Education</option>
                                        <option value="work_experience">Work Experience</option>
                                        <option value="training">Training</option>
                                        <option value="certification">Certification</option>
                                        <option value="skills">Skills</option>
                                        <option value="portfolio">Portfolio</option>
                                    </select>
                                </div>
                                
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label for="max_score" class="form-label">Max Score *</label>
                                        <input type="number" class="form-control form-control-sm" id="max_score" name="max_score" min="1" max="100" value="10" required>
                                    </div>
                                    <div class="col-6">
                                        <label for="weight" class="form-label">Weight *</label>
                                        <input type="number" class="form-control form-control-sm" id="weight" name="weight" min="0.1" max="10" step="0.1" value="1.0" required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="criteria_description" class="form-label">Description</label>
                                    <textarea class="form-control form-control-sm" id="criteria_description" name="description" rows="2"></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="criteria_requirements" class="form-label">Requirements</label>
                                    <textarea class="form-control form-control-sm" id="criteria_requirements" name="requirements" rows="2"></textarea>
                                </div>
                                
                                <button type="submit" name="add_criteria" class="btn btn-primary btn-sm w-100">
                                    <i class="fas fa-plus me-1"></i>Add Criteria
                                </button>
                            </form>
                        </div>
                        <!-- Edit Criteria Modal -->
    <div class="modal fade" id="editCriteriaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Assessment Criteria
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" id="edit_criteria_id" name="criteria_id">
                    <div class="modal-body">
                        <div id="edit_criteria_warning" class="alert alert-warning" style="display: none;">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This criteria has existing evaluations. Max score and weight cannot be changed.
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_criteria_name" class="form-label">Criteria Name *</label>
                            <input type="text" class="form-control" id="edit_criteria_name" name="criteria_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_criteria_type" class="form-label">Type *</label>
                            <select class="form-select" id="edit_criteria_type" name="criteria_type" required>
                                <option value="">Select Type</option>
                                <option value="education">Education</option>
                                <option value="work_experience">Work Experience</option>
                                <option value="training">Training</option>
                                <option value="certification">Certification</option>
                                <option value="skills">Skills</option>
                                <option value="portfolio">Portfolio</option>
                            </select>
                        </div>
                        
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="edit_max_score" class="form-label">Max Score *</label>
                                <input type="number" class="form-control" id="edit_max_score" name="max_score" min="1" max="100" required>
                            </div>
                            <div class="col-6">
                                <label for="edit_weight" class="form-label">Weight *</label>
                                <input type="number" class="form-control" id="edit_weight" name="weight" min="0.1" max="10" step="0.1" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_criteria_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_criteria_description" name="description" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_criteria_requirements" class="form-label">Requirements</label>
                            <textarea class="form-control" id="edit_criteria_requirements" name="requirements" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_criteria" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Update Criteria
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function editProgram(program) {
            document.getElementById('edit_program_id').value = program.id;
            document.getElementById('edit_program_name').value = program.program_name || '';
            document.getElementById('edit_program_code').value = program.program_code || '';
            document.getElementById('edit_description').value = program.description || '';
            document.getElementById('edit_requirements').value = program.requirements || '';
            document.getElementById('edit_status').value = program.status || '';
            
            new bootstrap.Modal(document.getElementById('editProgramModal')).show();
        }

        function manageCriteria(programId, programName) {
            document.getElementById('criteria_program_id').value = programId;
            document.getElementById('criteria_program_name').textContent = programName;
            
            // Store current program info for template application
            window.currentProgramId = programId;
            window.currentProgramName = programName;
            
            // Load existing criteria
            loadCriteria(programId);
            
            new bootstrap.Modal(document.getElementById('manageCriteriaModal')).show();
        }

        function applyTemplateToExisting(programId, programName) {
            document.getElementById('template_program_id').value = programId;
            document.getElementById('template_program_name').textContent = programName;
            
            // Pre-select default template
            const templateSelect = document.getElementById('template_id');
            const defaultOption = templateSelect.querySelector('option[data-default="true"]');
            if (defaultOption) {
                defaultOption.selected = true;
            }
            
            new bootstrap.Modal(document.getElementById('applyTemplateModal')).show();
        }

        function showApplyTemplateModal() {
            // Close criteria modal and show template modal
            const criteriaModal = bootstrap.Modal.getInstance(document.getElementById('manageCriteriaModal'));
            criteriaModal.hide();
            
            // Use stored program info
            if (window.currentProgramId && window.currentProgramName) {
                applyTemplateToExisting(window.currentProgramId, window.currentProgramName);
            }
        }

  function editCriteria(criteria) {
    // Populate the edit form
    document.getElementById('edit_criteria_id').value = criteria.id;
    document.getElementById('edit_criteria_name').value = criteria.criteria_name || '';
    document.getElementById('edit_criteria_type').value = criteria.criteria_type || '';
    document.getElementById('edit_max_score').value = criteria.max_score || '';
    document.getElementById('edit_weight').value = criteria.weight || '';
    document.getElementById('edit_criteria_description').value = criteria.description || '';
    document.getElementById('edit_criteria_requirements').value = criteria.requirements || '';
    
    // Show warning if criteria has evaluations
    const warningDiv = document.getElementById('edit_criteria_warning');
    if (criteria.evaluation_count > 0) {
        warningDiv.style.display = 'block';
        // Disable score and weight fields
        document.getElementById('edit_max_score').disabled = true;
        document.getElementById('edit_weight').disabled = true;
    } else {
        warningDiv.style.display = 'none';
        document.getElementById('edit_max_score').disabled = false;
        document.getElementById('edit_weight').disabled = false;
    }
    
    new bootstrap.Modal(document.getElementById('editCriteriaModal')).show();
}

function loadCriteria(programId) {
    const criteriaList = document.getElementById('criteria_list');
    const criteria = <?php echo json_encode($criteria_by_program); ?>;
    
    const programCriteria = criteria[programId] || [];
    
    if (programCriteria.length === 0) {
        criteriaList.innerHTML = `
            <div class="text-center py-4 text-muted">
                <i class="fas fa-list-check fa-2x mb-2"></i>
                <p>No assessment criteria defined</p>
                <button type="button" class="btn btn-sm btn-primary" onclick="showApplyTemplateModal()">
                    <i class="fas fa-magic me-1"></i>Apply Default Template
                </button>
            </div>`;
        return;
    }
    
    let html = '';
    let currentSection = '';
    
    programCriteria.forEach(function(criterion) {
        // Group by section if available
        if (criterion.section_number && criterion.section_number !== currentSection) {
            currentSection = criterion.section_number;
            html += `<div class="fw-bold text-primary mt-3 mb-2">Section ${currentSection}</div>`;
        }
        
        html += `
            <div class="criteria-item ${criterion.status === 'inactive' ? 'criteria-inactive' : ''}">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <h6 class="mb-1">
                            ${criterion.subsection ? '<small class="text-muted">' + criterion.subsection + '</small><br>' : ''}
                            ${criterion.criteria_name}
                        </h6>
                        <div class="mb-2">
                            <span class="badge bg-secondary me-1">${criterion.criteria_type.replace('_', ' ')}</span>
                            <span class="badge bg-info me-1">Max: ${criterion.max_score}</span>
                            <span class="badge bg-warning me-1">Weight: ${criterion.weight}x</span>
                            <span class="badge bg-${criterion.status === 'active' ? 'success' : 'danger'}">${criterion.status}</span>
                            ${criterion.criteria_level ? '<span class="badge bg-primary me-1">' + criterion.criteria_level + '</span>' : ''}
                        </div>
                        ${criterion.description ? `<p class="small text-muted mb-1">${criterion.description}</p>` : ''}
                        ${criterion.evaluation_count > 0 ? `<small class="text-muted"><i class="fas fa-clipboard-check me-1"></i>${criterion.evaluation_count} evaluations</small>` : ''}
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                        <ul class="dropdown-menu">
                            <li>
                                <button class="dropdown-item" onclick="editCriteria(${JSON.stringify(criterion).replace(/"/g, '&quot;')})">
                                    <i class="fas fa-edit me-2"></i>Edit Criteria
                                </button>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="programs.php?delete_criteria=${criterion.id}" onclick="return confirm('Are you sure you want to delete this criteria?')">
                                <i class="fas fa-trash me-2"></i>Delete
                            </a></li>
                        </ul>
                    </div>
                </div>
            </div>
        `;
    });
    
    criteriaList.innerHTML = html;
}
function manageSubjects(programId, programName) {
    document.getElementById('subject_program_id').value = programId;
    document.getElementById('subjects_program_name').textContent = programName;
    
    loadSubjects(programId);
    
    new bootstrap.Modal(document.getElementById('manageSubjectsModal')).show();
}

function loadSubjects(programId) {
    const subjectsList = document.getElementById('subjects_list');
    const subjects = <?php echo json_encode($subjects_by_program); ?>;
    
    const programSubjects = subjects[programId] || [];
    
    // Update count badge
    const countBadge = document.getElementById('subject_count_badge');
    if (countBadge) {
        countBadge.textContent = programSubjects.length + ' subject' + (programSubjects.length !== 1 ? 's' : '');
    }
    
    if (programSubjects.length === 0) {
        subjectsList.innerHTML = `
            <div class="text-center py-5 text-muted">
                <i class="fas fa-book-open fa-3x mb-3"></i>
                <p class="mb-0">No subjects defined yet</p>
                <small>Add subjects using the form on the right</small>
            </div>`;
        return;
    }
    
    // Calculate total units
    const totalUnits = programSubjects.reduce((sum, subject) => sum + parseInt(subject.units), 0);
    
    let html = `
        <div class="alert alert-info mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <span><i class="fas fa-calculator me-2"></i><strong>Total Units:</strong> ${totalUnits}</span>
                <span><strong>Total Subjects:</strong> ${programSubjects.length}</span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th width="20%">Code</th>
                        <th width="50%">Subject Name</th>
                        <th width="15%" class="text-center">Units</th>
                        <th width="15%" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    programSubjects.forEach(function(subject) {
        html += `
            <tr>
                <td><span class="badge bg-primary">${subject.subject_code}</span></td>
                <td>${subject.subject_name}</td>
                <td class="text-center"><strong>${subject.units}</strong></td>
                <td class="text-center">
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" onclick='editSubject(${JSON.stringify(subject)})' title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <a href="programs.php?delete_subject=${subject.id}" class="btn btn-outline-danger" 
                           onclick="return confirm('Delete subject: ${subject.subject_code} - ${subject.subject_name}?')" title="Delete">
                            <i class="fas fa-trash"></i>
                        </a>
                    </div>
                </td>
            </tr>
        `;
    });
    
    html += `
                </tbody>
            </table>
        </div>
    `;
    
    subjectsList.innerHTML = html;
}

function editSubject(subject) {
    document.getElementById('edit_subject_id').value = subject.id;
    document.getElementById('edit_subject_code').value = subject.subject_code || '';
    document.getElementById('edit_subject_name').value = subject.subject_name || '';
    document.getElementById('edit_subject_units').value = subject.units || 3;
    
    new bootstrap.Modal(document.getElementById('editSubjectModal')).show();
}


        // Clear form when modal is hidden
        document.getElementById('manageCriteriaModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('criteria_name').value = '';
            document.getElementById('criteria_type').value = '';
            document.getElementById('max_score').value = '10';
            document.getElementById('weight').value = '1.0';
            document.getElementById('criteria_description').value = '';
            document.getElementById('criteria_requirements').value = '';
        });

        // Template preview functionality
        document.getElementById('apply_template').addEventListener('change', function() {
            const templateInfo = document.querySelector('.template-info');
            if (this.value === 'none') {
                templateInfo.innerHTML = `
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Program will be created without assessment criteria. You can add them manually later.
                    </small>`;
            } else {
                const selectedOption = this.options[this.selectedIndex];
                const criteriaCount = selectedOption.text.match(/(\d+) criteria/);
                templateInfo.innerHTML = `
                    <small class="text-success">
                        <i class="fas fa-check-circle me-1"></i>
                        <strong>${selectedOption.text}</strong> will be applied.
                        ${criteriaCount ? criteriaCount[1] + ' assessment criteria will be automatically created.' : ''}
                    </small>`;
            }
        });

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            // Program name validation
            const programNameInputs = document.querySelectorAll('input[name="program_name"]');
            programNameInputs.forEach(input => {
                input.addEventListener('input', function() {
                    if (this.value.length < 3) {
                        this.classList.add('is-invalid');
                    } else {
                        this.classList.remove('is-invalid');
                    }
                });
            });

            // Program code validation (uppercase, no spaces)
            const programCodeInputs = document.querySelectorAll('input[name="program_code"]');
            programCodeInputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                    if (this.value.length < 2) {
                        this.classList.add('is-invalid');
                    } else {
                        this.classList.remove('is-invalid');
                    }
                });
            });

            // Trigger initial template info update
            const applyTemplateSelect = document.getElementById('apply_template');
            if (applyTemplateSelect) {
                applyTemplateSelect.dispatchEvent(new Event('change'));
            }
        });

        console.log('ETEEAP Program Management System with Templates initialized successfully');
    </script>
</body>
</html>