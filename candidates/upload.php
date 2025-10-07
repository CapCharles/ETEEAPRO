<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';



// Check if user is logged in and is a candidate
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'candidate') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$errors = [];

// Get available programs
try {
    $stmt = $pdo->prepare("SELECT * FROM programs WHERE status = 'active' ORDER BY program_name");
    $stmt->execute();
    $programs = $stmt->fetchAll();
} catch (PDOException $e) {
    $programs = [];
}

// Get user's current application
$current_application = null;
$assessment_criteria = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, p.program_name 
        FROM applications a 
        LEFT JOIN programs p ON a.program_id = p.id 
        WHERE a.user_id = ? AND a.application_status IN ('draft', 'submitted')
        ORDER BY a.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $current_application = $stmt->fetch();

    // If we have an application, get the assessment criteria
    if ($current_application) {
        $stmt = $pdo->prepare("
            SELECT * FROM assessment_criteria 
            WHERE program_id = ? AND status = 'active'
            ORDER BY section_number, subsection, criteria_name
        ");
        $stmt->execute([$current_application['program_id']]);
        $assessment_criteria = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    // No current application
}

// Handle new application creation
if ($_POST && isset($_POST['create_application'])) {
    $program_id = $_POST['program_id'];
    
    if (empty($program_id)) {
        $errors[] = "Please select a program";
    }
    
    if ($current_application) {
        $errors[] = "You already have an active application. Please complete or submit it first.";
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO applications (user_id, program_id, application_status) 
                VALUES (?, ?, 'draft')
            ");
            $stmt->execute([$user_id, $program_id]);
            
            $application_id = $pdo->lastInsertId();
            $success_message = "Application created successfully! You can now upload documents for each assessment criteria.";
            
            // Refresh current application and criteria
            $stmt = $pdo->prepare("
                SELECT a.*, p.program_name 
                FROM applications a 
                LEFT JOIN programs p ON a.program_id = p.id 
                WHERE a.id = ?
            ");
            $stmt->execute([$application_id]);
            $current_application = $stmt->fetch();

            $stmt = $pdo->prepare("
                SELECT * FROM assessment_criteria 
                WHERE program_id = ? AND status = 'active'
                ORDER BY section_number, subsection, criteria_name
            ");
            $stmt->execute([$current_application['program_id']]);
            $assessment_criteria = $stmt->fetchAll();
            
        } catch (PDOException $e) {
            $errors[] = "Failed to create application. Please try again.";
        }
    }
}

// Handle hierarchical file upload with detailed specifications
if ($_POST && isset($_POST['upload_hierarchical_document'])) {
    $criteria_id   = $_POST['criteria_id'] ?? null;
    $document_type = $_POST['document_type'] ?? 'portfolio';
    $description   = trim($_POST['description'] ?? '');
    $application_id = $current_application['id'];

    // === collect ALL hierarchical fields here ===
    $hierarchical_data = [];

    // Section 1 - Education
    if (isset($_POST['education_level']))  $hierarchical_data['education_level']  = $_POST['education_level'];
    if (isset($_POST['scholarship_type'])) $hierarchical_data['scholarship_type'] = $_POST['scholarship_type'];

    // Section 2 - Work Experience
    if (isset($_POST['years_experience'])) $hierarchical_data['years_experience'] = $_POST['years_experience'];
    if (isset($_POST['experience_role']))  $hierarchical_data['experience_role']  = $_POST['experience_role'];

    // Section 3 - Publications
    if (isset($_POST['authors_count']))     $hierarchical_data['authors_count']    = $_POST['authors_count'];
    if (isset($_POST['circulation_level'])) $hierarchical_data['circulation_level'] = $_POST['circulation_level']; // radio na ito
    if (isset($_POST['publication_type']))  $hierarchical_data['publication_type']  = $_POST['publication_type'];

    // Inventions/Innovations
    if (isset($_POST['patent_status']))          $hierarchical_data['patent_status']          = $_POST['patent_status'];
    if (!empty($_POST['acceptability_levels']))  $hierarchical_data['acceptability_levels']   = (array)$_POST['acceptability_levels'];
    if (isset($_POST['invention_type']))         $hierarchical_data['invention_type']         = $_POST['invention_type'];

    // Extension/Outreach
    if (!empty($_POST['service_levels'])) $hierarchical_data['service_levels'] = (array)$_POST['service_levels'];
    if (isset($_POST['extension_type']))  $hierarchical_data['extension_type'] = $_POST['extension_type'];

    // Section 4 - Professional Development
    if (isset($_POST['coordination_level']))  $hierarchical_data['coordination_level']  = $_POST['coordination_level'];
    if (isset($_POST['participation_level'])) $hierarchical_data['participation_level'] = $_POST['participation_level'];
    if (isset($_POST['membership_level']))    $hierarchical_data['membership_level']    = $_POST['membership_level'];
    if (isset($_POST['scholarship_level']))    $hierarchical_data['scholarship_level']    = $_POST['scholarship_level'];

    // Section 5 - Recognition & Others
    if (isset($_POST['recognition_level'])) $hierarchical_data['recognition_level'] = $_POST['recognition_level'];
    if (isset($_POST['eligibility_type']))  $hierarchical_data['eligibility_type']  = $_POST['eligibility_type'];

    // For extension services
    if (isset($_POST['service_levels']) && is_array($_POST['service_levels'])) {
        $hierarchical_data['service_levels'] = $_POST['service_levels'];
    }
    if (isset($_POST['extension_type'])) {
        $hierarchical_data['extension_type'] = $_POST['extension_type'];
    }
    
if (empty($hierarchical_data['circulation_level'])
    && !empty($hierarchical_data['circulation_levels'])
    && is_array($hierarchical_data['circulation_levels'])) {

    // pick highest among local < national < international
    $rank = ['local'=>1,'national'=>2,'international'=>3];
    usort($hierarchical_data['circulation_levels'], function($a,$b) use ($rank){
        return ($rank[$b] ?? 0) <=> ($rank[$a] ?? 0);
    });
    $hierarchical_data['circulation_level'] = $hierarchical_data['circulation_levels'][0];
}

// type casting para siguradong tamang types sa evaluator
if (isset($hierarchical_data['years_experience'])) $hierarchical_data['years_experience'] = (int)$hierarchical_data['years_experience'];
if (isset($hierarchical_data['authors_count']))    $hierarchical_data['authors_count']    = (int)$hierarchical_data['authors_count'];
if (isset($hierarchical_data['scholarship_level'])) $hierarchical_data['scholarship_level'] = (float)$hierarchical_data['scholarship_level'];
    

    if (empty($criteria_id)) {
        $errors[] = "Invalid criteria selection";
    }
    
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Please select a file to upload";
    } else {
        $file = $_FILES['document'];
        $file_size = $file['size'];
        $file_type = $file['type'];
        $original_name = $file['name'];
        
        if ($file_size > 5 * 1024 * 1024) {
            $errors[] = "File size must be less than 5MB";
        }
        
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Only PDF, JPG, and PNG files are allowed";
        }
    }
    
 if (empty($errors)) {
        try {
            $upload_dir = '../uploads/documents/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

            $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
            $stored_filename = $user_id . '_' . $application_id . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $stored_filename;

            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                // save desc + JSON
                $enhanced_description = $description;
                if (!empty($hierarchical_data)) {
                    $enhanced_description .= "\n\nHierarchical Data: " . json_encode($hierarchical_data);
                }
                $hier_json = !empty($hierarchical_data) ? json_encode($hierarchical_data) : null;

                $stmt = $pdo->prepare("
                    INSERT INTO documents (
                        application_id, document_type, original_filename, stored_filename,
                        file_path, file_size, mime_type, description, criteria_id, hierarchical_data
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $application_id, $document_type, $original_name, $stored_filename,
                    $file_path, $file_size, $file_type, $enhanced_description, $criteria_id, $hier_json
                ]);

                $success_message = 'Document uploaded successfully with detailed specifications!';
            } else {
                $errors[] = 'Failed to upload file. Please try again.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Database error occurred while saving document.';
        }
    }
}
// Handle basic file upload (existing functionality)
if ($_POST && isset($_POST['upload_document'])) {
    $criteria_id = $_POST['criteria_id'] ?? null;
    $document_type = $_POST['document_type'] ?? 'portfolio';
    $description = trim($_POST['description'] ?? '');
    $application_id = $current_application['id'];
    
    if (empty($criteria_id)) {
        $errors[] = "Invalid criteria selection";
    }
    
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Please select a file to upload";
    } else {
        $file = $_FILES['document'];
        $file_size = $file['size'];
        $file_type = $file['type'];
        $original_name = $file['name'];
        
        if ($file_size > 5 * 1024 * 1024) {
            $errors[] = "File size must be less than 5MB";
        }
        
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Only PDF, JPG, and PNG files are allowed";
        }
    }
    
    if (empty($errors)) {
        try {
            $upload_dir = '../uploads/documents/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
            $stored_filename = $user_id . '_' . $application_id . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $stored_filename;
            
            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                $stmt = $pdo->prepare("
                    INSERT INTO documents (application_id, document_type, original_filename, stored_filename, file_path, file_size, mime_type, description, criteria_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $application_id,
                    $document_type,
                    $original_name,
                    $stored_filename,
                    $file_path,
                    $file_size,
                    $file_type,
                    $description,
                    $criteria_id
                ]);
                
               
   $success_message = 'Document uploaded successfully with detailed specifications!';
   $success_criteria_id = $criteria_id;
        } else {
            $errors[] = 'Failed to upload file. Please try again.';
        }
    } catch (PDOException $e) {
        $errors[] = 'Database error occurred while saving document.';
    }
      
           
    }
}

// Get uploaded documents with criteria info
$uploaded_documents = [];
$documents_by_criteria = [];
if ($current_application) {
    try {
        $stmt = $pdo->prepare("
            SELECT d.*, ac.criteria_name, ac.section_number
            FROM documents d
            LEFT JOIN assessment_criteria ac ON d.criteria_id = ac.id
            WHERE d.application_id = ? 
            ORDER BY d.upload_date DESC
        ");
        $stmt->execute([$current_application['id']]);
        $uploaded_documents = $stmt->fetchAll();
        
        // Group documents by criteria
        foreach ($uploaded_documents as $doc) {
            if ($doc['criteria_id']) {
                $documents_by_criteria[$doc['criteria_id']][] = $doc;
            }
        }
    } catch (PDOException $e) {
        // No documents
    }
}

// Handle document deletion
if ($_GET && isset($_GET['delete']) && $current_application) {
    $doc_id = $_GET['delete'];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND application_id = ?");
        $stmt->execute([$doc_id, $current_application['id']]);
        $document = $stmt->fetch();
        
        if ($document) {
            if (file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }
            
            $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
            $stmt->execute([$doc_id]);
            
            $success_message = "Document deleted successfully!";
            
            // Refresh documents list
            header('Location: upload.php');
            exit();
        }
    } catch (PDOException $e) {
        $errors[] = "Failed to delete document.";
    }
}

// Handle application submission
if ($_POST && isset($_POST['submit_application']) && $current_application) {
    if (count($uploaded_documents) < 1) {
        $errors[] = "Please upload at least one document before submitting.";
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE applications 
                SET application_status = 'submitted', submission_date = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$current_application['id']]);

            // Redirect diretso sa assessment page
            header("Location: assessment.php");
            exit();
            
        } catch (PDOException $e) {
            $errors[] = "Failed to submit application. Please try again.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Documents - ETEEAP</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            margin: 0; 
            padding-top: 0 !important;
        }
        .upload-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
        }
        .criteria-section {
            border-left: 4px solid #667eea;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .criteria-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            position: relative;
        }
        .criteria-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102,126,234,0.15);
        }
        .criteria-with-docs {
            border-left: 4px solid #28a745;
            background: rgba(40, 167, 69, 0.02);
        }
        .upload-zone {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            background: #f8f9fa;
            margin-top: 1rem;
            transition: all 0.3s ease;
        }
        .upload-zone:hover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
        }
        .upload-zone.collapsed {
            display: none;
        }
        .document-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        .section-header {
            font-weight: bold;
            color: #667eea;
            border-bottom: 2px solid #667eea;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
        .upload-btn {
            position: absolute;
            top: 1rem;
            right: 1rem;
        }
        .hierarchical-upload-section {
            background: #e3f2fd;
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
            display: none;
        }
        .hierarchical-upload-section.show {
            display: block;
        }
        .level-option {
            margin-bottom: 0.5rem;
        }
        .points-calculator {
            background: #fff3e0;
            border: 1px solid #ff9800;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
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
                        <a class="nav-link active" href="upload.php">
                            <i class="fas fa-upload me-1"></i>Upload Documents
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="assessment.php">
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
  
        <div class="row g-4">
            <!-- Main Content -->
            <div class="col-lg-9">
                <?php if (!$current_application): ?>
                <!-- Create New Application -->
                <div class="upload-card p-4">
                    <h4 class="mb-4">
                        <i class="fas fa-plus-circle me-2 text-primary"></i>
                        Create New Application
                    </h4>
                    
                    <form method="POST" action="">
                        <div class="mb-4">
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
                        
                        <button type="submit" name="create_application" class="btn btn-primary">
                            <i class="fas fa-rocket me-2"></i>Create Application
                        </button>
                    </form>
                </div>
                <?php else: ?>

                <!-- Assessment Criteria with Hierarchical Upload Options -->
                <div class="upload-card p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="mb-0">
                            <i class="fas fa-clipboard-list me-2 text-primary"></i>
                            Assessment Criteria & Document Upload
                        </h4>
                        <span class="badge bg-primary fs-6">
                            <?php echo htmlspecialchars($current_application['program_name']); ?>
                        </span>
                    </div>

                    <?php if ($current_application['application_status'] !== 'draft'): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Application has been submitted. Documents are view-only.
                    </div>
                    <?php endif; ?>

                    <?php if (empty($assessment_criteria)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-clipboard-question fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No assessment criteria available</p>
                    </div>
                    <?php else: ?>
                    <?php 
                    $grouped_criteria = [];
                    foreach ($assessment_criteria as $criteria) {
                        $grouped_criteria[$criteria['section_number']][] = $criteria;
                    }
                    ?>
                    
                    <?php foreach ($grouped_criteria as $section_num => $section_criteria): ?>
                    <div class="criteria-section">
                        <div class="section-header">
                            Section <?php echo $section_num; ?>: 
                            <?php 
                            $section_titles = [
                                1 => 'Educational Qualifications',
                                2 => 'Work Experience', 
                                3 => 'Professional Achievement',
                                4 => 'Professional Development',
                                5 => 'Recognition & Others'
                            ];
                            echo $section_titles[$section_num] ?? 'Additional Criteria';
                            ?>
                        </div>
                        
                        <?php foreach ($section_criteria as $criteria): ?>
                        <?php $doc_count = count($documents_by_criteria[$criteria['id']] ?? []); ?>
                        <div class="criteria-card <?php echo $doc_count > 0 ? 'criteria-with-docs' : ''; ?>">
                            
                            <!-- Upload buttons -->
                            <?php if ($current_application['application_status'] === 'draft'): ?>
                            <div class="upload-btn">
                                <?php 
                          
$is_hierarchical = (
    stripos($criteria['criteria_name'], 'Invention') !== false ||
    stripos($criteria['criteria_name'], 'Innovation') !== false ||
    stripos($criteria['criteria_name'], 'Publication') !== false ||
    stripos($criteria['criteria_name'], 'Journal') !== false ||
    stripos($criteria['criteria_name'], 'Training Module') !== false ||
    stripos($criteria['criteria_name'], 'Book') !== false ||
    stripos($criteria['criteria_name'], 'Teaching Module') !== false ||
    stripos($criteria['criteria_name'], 'Workbook') !== false ||
    stripos($criteria['criteria_name'], 'Reading Kit') !== false ||
    stripos($criteria['criteria_name'], 'Early Literacy') !== false ||
    stripos($criteria['criteria_name'], 'Consultanc') !== false ||
    stripos($criteria['criteria_name'], 'Lecturer') !== false ||
    stripos($criteria['criteria_name'], 'Speaker') !== false ||
    stripos($criteria['criteria_name'], 'Community Service') !== false ||
    stripos($criteria['criteria_name'], 'Education') !== false ||
    stripos($criteria['criteria_name'], 'Degree') !== false ||
    stripos($criteria['criteria_name'], 'Work Experience') !== false ||
    stripos($criteria['criteria_name'], 'Experience') !== false ||
    stripos($criteria['criteria_name'], 'Training Program') !== false ||
    stripos($criteria['criteria_name'], 'Coordination') !== false ||
    stripos($criteria['criteria_name'], 'Seminar') !== false ||
    stripos($criteria['criteria_name'], 'Workshop') !== false ||
    stripos($criteria['criteria_name'], 'Participation') !== false ||
    stripos($criteria['criteria_name'], 'Membership') !== false ||
    stripos($criteria['criteria_name'], 'Professional Organization') !== false ||
    stripos($criteria['criteria_name'], 'Scholarship') !== false ||
    stripos($criteria['criteria_name'], 'Recognition') !== false ||
    stripos($criteria['criteria_name'], 'Award') !== false ||
    stripos($criteria['criteria_name'], 'Eligibilit') !== false ||
    in_array($criteria['section_number'], [1,2,4,5], true)
);



                                ?>
                                
                                <?php if ($is_hierarchical): ?>
                                <button class="btn btn-success btn-sm me-1" 
                                        onclick="showHierarchicalUpload(<?php echo $criteria['id']; ?>, '<?php echo htmlspecialchars($criteria['criteria_name']); ?>')">
                                    <i class="fas fa-sitemap me-1"></i>Detailed Upload
                                </button>
                                <?php endif; ?>
                                
                                <!-- <button class="btn btn-primary btn-sm" 
                                        onclick="showUploadForm(<?php echo $criteria['id']; ?>, '<?php echo htmlspecialchars($criteria['criteria_name']); ?>')">
                                    <i class="fas fa-upload me-1"></i>Quick Upload
                                </button> -->
                            </div>
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-8">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($criteria['criteria_name']); ?></h6>
                                    
                                    <?php if ($criteria['description']): ?>
                                    <p class="text-muted small mb-2"><?php echo htmlspecialchars($criteria['description']); ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex gap-2 flex-wrap mb-2">
                                        <span class="badge bg-secondary small">
                                            <?php echo ucfirst(str_replace('_', ' ', $criteria['criteria_type'])); ?>
                                        </span>
                                        <span class="badge bg-info small">
                                            Max: <?php echo $criteria['max_score']; ?> pts
                                        </span>
                                        <span class="badge bg-warning text-dark small">
                                            Weight: <?php echo $criteria['weight']; ?>x
                                        </span>
                                        <?php if ($criteria['criteria_level'] != 'local'): ?>
                                        <span class="badge bg-primary small">
                                            <?php echo ucfirst($criteria['criteria_level']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($criteria['requirements']): ?>
                                    <details class="small">
                                        <summary class="text-primary" style="cursor: pointer;">View Requirements</summary>
                                        <div class="mt-2 p-2 bg-light rounded">
                                            <?php echo nl2br(htmlspecialchars($criteria['requirements'])); ?>
                                        </div>
                                    </details>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Hierarchical Upload Section -->
                            <div class="hierarchical-upload-section" id="hierarchical-<?php echo $criteria['id']; ?>">
                                <h6 class="text-primary mb-3">
                                    <i class="fas fa-sitemap me-2"></i>Detailed Specification Upload
                                </h6>
                                
                                <form method="POST" action="" enctype="multipart/form-data">
                                    <input type="hidden" name="criteria_id" value="<?php echo $criteria['id']; ?>">
                                    <input type="hidden" name="document_type" value="portfolio">
                                    
                                    <!-- Dynamic hierarchical options based on criteria type -->
                                    <?php if (strpos($criteria['criteria_name'], 'Invention') !== false || strpos($criteria['criteria_name'], 'Innovation') !== false): ?>
                                    <!-- Invention/Innovation Options -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Patent Status: <span class="text-danger">*</span></label>
                                            <div class="form-check">
                                                <input type="radio" class="form-check-input" name="patent_status" id="no_patent_<?php echo $criteria['id']; ?>" value="no_patent" required>
                                                <label class="form-check-label" for="no_patent_<?php echo $criteria['id']; ?>">
                                                    <i class="fas fa-lightbulb me-2 text-warning"></i>No Patent
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <input type="radio" class="form-check-input" name="patent_status" id="patented_<?php echo $criteria['id']; ?>" value="patented" required>
                                                <label class="form-check-label" for="patented_<?php echo $criteria['id']; ?>">
                                                    <i class="fas fa-certificate me-2 text-success"></i>Patented
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Market Acceptability: <span class="text-danger">*</span></label>
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" name="acceptability_levels[]" id="local_<?php echo $criteria['id']; ?>" value="local">
                                                <label class="form-check-label" for="local_<?php echo $criteria['id']; ?>">
                                                    <i class="fas fa-map-marker-alt me-2 text-primary"></i>Local Market
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" name="acceptability_levels[]" id="national_<?php echo $criteria['id']; ?>" value="national">
                                                <label class="form-check-label" for="national_<?php echo $criteria['id']; ?>">
                                                    <i class="fas fa-flag me-2 text-info"></i>National Market
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" name="acceptability_levels[]" id="international_<?php echo $criteria['id']; ?>" value="international">
                                                <label class="form-check-label" for="international_<?php echo $criteria['id']; ?>">
                                                    <i class="fas fa-globe me-2 text-success"></i>International Market
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="invention_type" value="<?php echo strpos($criteria['criteria_name'], 'Innovation') !== false ? 'innovation' : 'invention'; ?>">
                                    
                                
                                    <!-- Publication Options -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Circulation Level: <span class="text-danger">*</span></label>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="circulation_levels[]" id="local_circ_<?php echo $criteria['id']; ?>" value="local">
                                                    <label class="form-check-label" for="local_circ_<?php echo $criteria['id']; ?>">
                                                        <i class="fas fa-map-marker-alt me-2 text-primary"></i>Local Circulation
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="circulation_levels[]" id="national_circ_<?php echo $criteria['id']; ?>" value="national">
                                                    <label class="form-check-label" for="national_circ_<?php echo $criteria['id']; ?>">
                                                        <i class="fas fa-flag me-2 text-info"></i>National w/ ISBN
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="circulation_levels[]" id="international_circ_<?php echo $criteria['id']; ?>" value="international">
                                                    <label class="form-check-label" for="international_circ_<?php echo $criteria['id']; ?>">
                                                        <i class="fas fa-globe me-2 text-success"></i>International w/ Copyright
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="publication_type" value="<?php 
                                        if (strpos($criteria['criteria_name'], 'Journal') !== false) echo 'journal';
                                        elseif (strpos($criteria['criteria_name'], 'Training Module') !== false) echo 'training_module';
                                        elseif (strpos($criteria['criteria_name'], 'Book') !== false) echo 'book';
                                        else echo 'publication';
                                    ?>">

                                    <?php elseif (strpos($criteria['criteria_name'], 'Teaching Modules') !== false): ?>
<!-- Teaching Modules Options -->
<div class="mb-3">
    <label class="form-label fw-bold">Circulation Level: <span class="text-danger">*</span></label>
    <div class="row">
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="circulation_level" id="local_teaching_<?php echo $criteria['id']; ?>" value="local" required>
                <label class="form-check-label" for="local_teaching_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-map-marker-alt me-2 text-primary"></i>Local Circulation
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="circulation_level" id="national_teaching_<?php echo $criteria['id']; ?>" value="national" required>
                <label class="form-check-label" for="national_teaching_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-flag me-2 text-info"></i>National w/ ISBN
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="circulation_level" id="international_teaching_<?php echo $criteria['id']; ?>" value="international" required>
                <label class="form-check-label" for="international_teaching_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-globe me-2 text-success"></i>International w/ Copyright
                </label>
            </div>
        </div>
    </div>
</div>
<input type="hidden" name="publication_type" value="teaching_module">

<?php elseif (strpos($criteria['criteria_name'], 'Workbooks') !== false || strpos($criteria['criteria_name'], 'Reading Kits') !== false): ?>
<!-- Workbooks/Reading Kits Options -->
<div class="mb-3">
    <label class="form-label fw-bold">Circulation Level: <span class="text-danger">*</span></label>
    <div class="row">
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="circulation_level" id="local_workbook_<?php echo $criteria['id']; ?>" value="local" required>
                <label class="form-check-label" for="local_workbook_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-map-marker-alt me-2 text-primary"></i>Local Circulation
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="circulation_level" id="national_workbook_<?php echo $criteria['id']; ?>" value="national" required>
                <label class="form-check-label" for="national_workbook_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-flag me-2 text-info"></i>National w/ ISBN
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="circulation_level" id="international_workbook_<?php echo $criteria['id']; ?>" value="international" required>
                <label class="form-check-label" for="international_workbook_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-globe me-2 text-success"></i>International w/ Copyright
                </label>
            </div>
        </div>
    </div>
</div>
<input type="hidden" name="publication_type" value="<?php echo strpos($criteria['criteria_name'], 'Workbooks') !== false ? 'workbook' : 'reading_kit'; ?>">

<?php elseif (strpos($criteria['criteria_name'], 'Early Literacy') !== false): ?>
<!-- Early Literacy/Numeracy Outreach Options -->
<div class="mb-3">
    <label class="form-label fw-bold">Outreach Level: <span class="text-danger">*</span></label>
    <div class="row">
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="circulation_level" id="local_literacy_<?php echo $criteria['id']; ?>" value="local" required>
                <label class="form-check-label" for="local_literacy_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-home me-2 text-primary"></i>Local Community
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="circulation_level" id="national_literacy_<?php echo $criteria['id']; ?>" value="national" required>
                <label class="form-check-label" for="national_literacy_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-flag me-2 text-info"></i>National Program
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="circulation_level" id="international_literacy_<?php echo $criteria['id']; ?>" value="international" required>
                <label class="form-check-label" for="international_literacy_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-globe me-2 text-success"></i>International Initiative
                </label>
            </div>
        </div>
    </div>
</div>
<input type="hidden" name="publication_type" value="literacy_outreach">
                                    
                                    <?php elseif (strpos($criteria['criteria_name'], 'Consultanc') !== false): ?>
                                    <!-- Consultancy Options -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Service Level: <span class="text-danger">*</span></label>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="service_levels[]" id="local_cons_<?php echo $criteria['id']; ?>" value="local">
                                                    <label class="form-check-label" for="local_cons_<?php echo $criteria['id']; ?>">
                                                        <i class="fas fa-building me-2 text-primary"></i>Local (In Company/Industry)
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="service_levels[]" id="national_cons_<?php echo $criteria['id']; ?>" value="national">
                                                    <label class="form-check-label" for="national_cons_<?php echo $criteria['id']; ?>">
                                                        <i class="fas fa-university me-2 text-info"></i>National (Outside School/Org)
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="service_levels[]" id="international_cons_<?php echo $criteria['id']; ?>" value="international">
                                                    <label class="form-check-label" for="international_cons_<?php echo $criteria['id']; ?>">
                                                        <i class="fas fa-globe me-2 text-success"></i>International/Multi-national
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="extension_type" value="consultancy">
                                    
                                    <?php elseif (strpos($criteria['criteria_name'], 'Lecturer') !== false || strpos($criteria['criteria_name'], 'Speaker') !== false): ?>
                                    <!-- Lecturer/Speaker Options -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Speaking Level: <span class="text-danger">*</span></label>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="service_levels[]" id="local_speak_<?php echo $criteria['id']; ?>" value="local">
                                                    <label class="form-check-label" for="local_speak_<?php echo $criteria['id']; ?>">
                                                        <i class="fas fa-microphone me-2 text-primary"></i>Local Level
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="service_levels[]" id="national_speak_<?php echo $criteria['id']; ?>" value="national">
                                                    <label class="form-check-label" for="national_speak_<?php echo $criteria['id']; ?>">
                                                        <i class="fas fa-flag me-2 text-info"></i>National Level
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="service_levels[]" id="international_speak_<?php echo $criteria['id']; ?>" value="international">
                                                    <label class="form-check-label" for="international_speak_<?php echo $criteria['id']; ?>">
                                                        <i class="fas fa-globe me-2 text-success"></i>International Level
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="extension_type" value="lecturer">
                                    
                                    <?php elseif (strpos($criteria['criteria_name'], 'Community Service') !== false): ?>
                                    <!-- Community Service Options -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Service Type: <span class="text-danger">*</span></label>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="service_levels[]" id="trainer_<?php echo $criteria['id']; ?>" value="trainer">
                                                    <label class="form-check-label" for="trainer_<?php echo $criteria['id']; ?>">
                                                        <i class="fas fa-chalkboard-teacher me-2 text-primary"></i>Trainer/Coordinator
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="service_levels[]" id="official_<?php echo $criteria['id']; ?>" value="official">
                                                    <label class="form-check-label" for="official_<?php echo $criteria['id']; ?>">
                                                        <i class="fas fa-landmark me-2 text-info"></i>Barangay/Municipal Official
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="service_levels[]" id="manager_<?php echo $criteria['id']; ?>" value="manager">
                                                    <label class="form-check-label" for="manager_<?php echo $criteria['id']; ?>">
                                                        <i class="fas fa-users-cog me-2 text-success"></i>Project Manager
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="extension_type" value="community">
                            
                                    <?php elseif ($criteria['section_number'] == 1): ?>
<!-- Section 1 - Education Options -->
<div class="mb-3">
    <label class="form-label fw-bold">Education Level: <span class="text-danger">*</span></label>
    <div class="row">
        <div class="col-md-6">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="education_level" id="hs_<?php echo $criteria['id']; ?>" value="high_school" required>
                <label class="form-check-label" for="hs_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-school me-2 text-primary"></i>High School Graduate
                </label>
            </div>
            <div class="form-check">
                <input type="radio" class="form-check-input" name="education_level" id="voc_<?php echo $criteria['id']; ?>" value="vocational" required>
                <label class="form-check-label" for="voc_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-tools me-2 text-info"></i>Vocational Course
                </label>
            </div>
            <div class="form-check">
                <input type="radio" class="form-check-input" name="education_level" id="tech_<?php echo $criteria['id']; ?>" value="technical" required>
                <label class="form-check-label" for="tech_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-cogs me-2 text-warning"></i>Technical Course
                </label>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="education_level" id="undergrad_<?php echo $criteria['id']; ?>" value="undergraduate" required>
                <label class="form-check-label" for="undergrad_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-university me-2 text-success"></i>Undergraduate Degree
                </label>
            </div>
            <div class="form-check">
                <input type="radio" class="form-check-input" name="education_level" id="non_ed_<?php echo $criteria['id']; ?>" value="non_education" required>
                <label class="form-check-label" for="non_ed_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-graduation-cap me-2 text-danger"></i>Non-Education Degree
                </label>
            </div>
        </div>
    </div>
</div>


<?php elseif ($criteria['section_number'] == 2): ?>
<!-- Section 2 - Work Experience Options -->
<div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label fw-bold">Years of Experience: <span class="text-danger">*</span></label>
        <input type="number" class="form-control" name="years_experience" id="years_<?php echo $criteria['id']; ?>" 
               min="5" max="50" required placeholder="Minimum 5 years">
        <div class="form-text">Minimum 5 years required</div>
    </div>
    <div class="col-md-6">
        <label class="form-label fw-bold">Experience Role: <span class="text-danger">*</span></label>
        <div class="form-check">
            <input type="radio" class="form-check-input" name="experience_role" id="admin_<?php echo $criteria['id']; ?>" value="administrator" required>
            <label class="form-check-label" for="admin_<?php echo $criteria['id']; ?>">
                <i class="fas fa-user-tie me-2 text-primary"></i>School/Learning Center Administrator (5 pts)
            </label>
        </div>
        <div class="form-check">
            <input type="radio" class="form-check-input" name="experience_role" id="supervisor_<?php echo $criteria['id']; ?>" value="supervisor" required>
            <label class="form-check-label" for="supervisor_<?php echo $criteria['id']; ?>">
                <i class="fas fa-users me-2 text-info"></i>Training Supervisor (3 pts)
            </label>
        </div>
        <div class="form-check">
            <input type="radio" class="form-check-input" name="experience_role" id="trainer_<?php echo $criteria['id']; ?>" value="trainer" required>
            <label class="form-check-label" for="trainer_<?php echo $criteria['id']; ?>">
                <i class="fas fa-chalkboard-teacher me-2 text-success"></i>Trainer/Lecturer/Preacher (2 pts)
            </label>
        </div>
        <div class="form-check">
            <input type="radio" class="form-check-input" name="experience_role" id="sunday_<?php echo $criteria['id']; ?>" value="sunday_school" required>
            <label class="form-check-label" for="sunday_<?php echo $criteria['id']; ?>">
                <i class="fas fa-pray me-2 text-warning"></i>Sunday School Tutor (1 pt)
            </label>
        </div>
        <div class="form-check">
            <input type="radio" class="form-check-input" name="experience_role" id="daycare_<?php echo $criteria['id']; ?>" value="daycare" required>
            <label class="form-check-label" for="daycare_<?php echo $criteria['id']; ?>">
                <i class="fas fa-baby me-2 text-danger"></i>Day Care Tutor (1 pt)
            </label>
        </div>
    </div>
</div>

<?php elseif ($criteria['section_number'] == 4): ?>
<!-- Section 4 - Professional Development Options -->
<?php if (strpos($criteria['criteria_name'], 'Training Program') !== false || strpos($criteria['criteria_name'], 'Coordination') !== false): ?>
<div class="mb-3">
    <label class="form-label fw-bold">Coordination Level: <span class="text-danger">*</span></label>
    <div class="row">
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="coordination_level" id="coord_local_<?php echo $criteria['id']; ?>" value="local" required>
                <label class="form-check-label" for="coord_local_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-map-marker-alt me-2 text-primary"></i>Local Level (6 pts)
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="coordination_level" id="coord_national_<?php echo $criteria['id']; ?>" value="national" required>
                <label class="form-check-label" for="coord_national_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-flag me-2 text-info"></i>National Level (8 pts)
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="coordination_level" id="coord_intl_<?php echo $criteria['id']; ?>" value="international" required>
                <label class="form-check-label" for="coord_intl_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-globe me-2 text-success"></i>International Level (10 pts)
                </label>
            </div>
        </div>
    </div>
</div>

<?php elseif (strpos($criteria['criteria_name'], 'Seminar') !== false || strpos($criteria['criteria_name'], 'Workshop') !== false || strpos($criteria['criteria_name'], 'Participation') !== false): ?>
<div class="mb-3">
    <label class="form-label fw-bold">Participation Level: <span class="text-danger">*</span></label>
    <div class="row">
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="participation_level" id="part_local_<?php echo $criteria['id']; ?>" value="local" required>
                <label class="form-check-label" for="part_local_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-map-marker-alt me-2 text-primary"></i>Local Level (3 pts)
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="participation_level" id="part_national_<?php echo $criteria['id']; ?>" value="national" required>
                <label class="form-check-label" for="part_national_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-flag me-2 text-info"></i>National Level (4 pts)
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="participation_level" id="part_intl_<?php echo $criteria['id']; ?>" value="international" required>
                <label class="form-check-label" for="part_intl_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-globe me-2 text-success"></i>International Level (5 pts)
                </label>
            </div>
        </div>
    </div>
</div>

<?php elseif (strpos($criteria['criteria_name'], 'Membership') !== false || strpos($criteria['criteria_name'], 'Professional Organization') !== false): ?>
<div class="mb-3">
    <label class="form-label fw-bold">Membership Level: <span class="text-danger">*</span></label>
    <div class="row">
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="membership_level" id="memb_local_<?php echo $criteria['id']; ?>" value="local" required>
                <label class="form-check-label" for="memb_local_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-users me-2 text-primary"></i>Local Organization (3 pts)
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="membership_level" id="memb_national_<?php echo $criteria['id']; ?>" value="national" required>
                <label class="form-check-label" for="memb_national_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-flag me-2 text-info"></i>National Organization (4 pts)
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="membership_level" id="memb_intl_<?php echo $criteria['id']; ?>" value="international" required>
                <label class="form-check-label" for="memb_intl_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-globe me-2 text-success"></i>International Organization (5 pts)
                </label>
            </div>
        </div>
    </div>
</div>

<?php elseif (strpos($criteria['criteria_name'], 'Scholarship') !== false): ?>
<div class="mb-3">
    <label class="form-label fw-bold">Scholarship Level: <span class="text-danger">*</span></label>
    <div class="row">
        <div class="col-md-6">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="scholarship_level" id="schol_2_5_<?php echo $criteria['id']; ?>" value="2.5" required>
                <label class="form-check-label" for="schol_2_5_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-star me-2 text-muted"></i>2.5 Level
                </label>
            </div>
            <div class="form-check">
                <input type="radio" class="form-check-input" name="scholarship_level" id="schol_3_<?php echo $criteria['id']; ?>" value="3" required>
                <label class="form-check-label" for="schol_3_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-star me-2 text-primary"></i>3.0 Level
                </label>
            </div>
            <div class="form-check">
                <input type="radio" class="form-check-input" name="scholarship_level" id="schol_3_5_<?php echo $criteria['id']; ?>" value="3.5" required>
                <label class="form-check-label" for="schol_3_5_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-star me-2 text-info"></i>3.5 Level
                </label>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="scholarship_level" id="schol_4_<?php echo $criteria['id']; ?>" value="4" required>
                <label class="form-check-label" for="schol_4_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-star me-2 text-warning"></i>4.0 Level
                </label>
            </div>
            <div class="form-check">
                <input type="radio" class="form-check-input" name="scholarship_level" id="schol_4_5_<?php echo $criteria['id']; ?>" value="4.5" required>
                <label class="form-check-label" for="schol_4_5_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-star me-2 text-success"></i>4.5 Level
                </label>
            </div>
            <div class="form-check">
                <input type="radio" class="form-check-input" name="scholarship_level" id="schol_5_<?php echo $criteria['id']; ?>" value="5" required>
                <label class="form-check-label" for="schol_5_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-star me-2 text-danger"></i>5.0 Level
                </label>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php elseif ($criteria['section_number'] == 5): ?>
<!-- Section 5 - Recognition & Others Options -->
<?php if (strpos($criteria['criteria_name'], 'Recognition') !== false || strpos($criteria['criteria_name'], 'Award') !== false): ?>
<div class="mb-3">
    <label class="form-label fw-bold">Recognition Level: <span class="text-danger">*</span></label>
    <div class="row">
        <div class="col-md-6">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="recognition_level" id="recog_local_<?php echo $criteria['id']; ?>" value="local" required>
                <label class="form-check-label" for="recog_local_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-trophy me-2 text-warning"></i>Local Recognition (6 pts)
                </label>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="recognition_level" id="recog_national_<?php echo $criteria['id']; ?>" value="national" required>
                <label class="form-check-label" for="recog_national_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-medal me-2 text-success"></i>National Recognition (8 pts)
                </label>
            </div>
        </div>
    </div>
</div>

<?php elseif (strpos($criteria['criteria_name'], 'Eligibilit') !== false): ?>
<div class="mb-3">
    <label class="form-label fw-bold">Eligibility Type: <span class="text-danger">*</span></label>
    <div class="row">
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="eligibility_type" id="cs_sub_<?php echo $criteria['id']; ?>" value="cs_sub_professional" required>
                <label class="form-check-label" for="cs_sub_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-certificate me-2 text-primary"></i>CS Sub-Professional (3 pts)
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="eligibility_type" id="cs_prof_<?php echo $criteria['id']; ?>" value="cs_professional" required>
                <label class="form-check-label" for="cs_prof_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-award me-2 text-info"></i>CS Professional (4 pts)
                </label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check">
                <input type="radio" class="form-check-input" name="eligibility_type" id="prc_<?php echo $criteria['id']; ?>" value="prc" required>
                <label class="form-check-label" for="prc_<?php echo $criteria['id']; ?>">
                    <i class="fas fa-star me-2 text-success"></i>PRC (5 pts)
                </label>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- FOR PUBLICATIONS SECTION 3.1.2, UPDATE THE EXISTING CODE: -->
<?php elseif (strpos($criteria['criteria_name'], 'Journal') !== false || strpos($criteria['criteria_name'], 'Training Module') !== false || strpos($criteria['criteria_name'], 'Book') !== false): ?>
<!-- Publication Options -->
<div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label fw-bold">Number of Authors: <span class="text-danger">*</span></label>
        <input type="number" class="form-control" name="authors_count" id="authors_<?php echo $criteria['id']; ?>" 
               min="1" max="20" required placeholder="≥1 author">
        <div class="form-text">Minimum 1 author required</div>
    </div>
    <div class="col-md-6">
        <label class="form-label fw-bold">Circulation Level: <span class="text-danger">*</span></label>
        <!-- CHANGED FROM CHECKBOXES TO RADIO BUTTONS -->
        <div class="form-check">
            <input type="radio" class="form-check-input" name="circulation_level" id="local_circ_<?php echo $criteria['id']; ?>" value="local" required>
            <label class="form-check-label" for="local_circ_<?php echo $criteria['id']; ?>">
                <i class="fas fa-map-marker-alt me-2 text-primary"></i>Local Circulation
            </label>
        </div>
        <div class="form-check">
            <input type="radio" class="form-check-input" name="circulation_level" id="national_circ_<?php echo $criteria['id']; ?>" value="national" required>
            <label class="form-check-label" for="national_circ_<?php echo $criteria['id']; ?>">
                <i class="fas fa-flag me-2 text-info"></i>National w/ ISBN
            </label>
        </div>
        <div class="form-check">
            <input type="radio" class="form-check-input" name="circulation_level" id="international_circ_<?php echo $criteria['id']; ?>" value="international" required>
            <label class="form-check-label" for="international_circ_<?php echo $criteria['id']; ?>">
                <i class="fas fa-globe me-2 text-success"></i>International w/ Copyright
            </label>
        </div>
    </div>
</div>
<input type="hidden" name="publication_type" value="<?php 
    if (strpos($criteria['criteria_name'], 'Journal') !== false) echo 'journal';
    elseif (strpos($criteria['criteria_name'], 'Training Module') !== false) echo 'training_module';
    elseif (strpos($criteria['criteria_name'], 'Book') !== false) echo 'book';
    else echo 'publication';
?>">
        <?php endif; ?>
                                    
                                    <!-- File Upload Section -->
                                    <div class="mb-3">
                                        <label for="hierarchical_description_<?php echo $criteria['id']; ?>" class="form-label">Description (Optional)</label>
                                        <input type="text" class="form-control" id="hierarchical_description_<?php echo $criteria['id']; ?>" name="description" 
                                               placeholder="Brief description of this document">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="hierarchical_document_<?php echo $criteria['id']; ?>" class="form-label">Choose File *</label>
                                        <input type="file" class="form-control" id="hierarchical_document_<?php echo $criteria['id']; ?>" name="document" 
                                               accept=".pdf,.jpeg,.jpg,.png" required>
                                        <div class="form-text">
                                            Supported formats: PDF, JPG, PNG | Maximum size: 5MB
                                        </div>
                                    </div>
                                    
                                    <!-- Points Calculator
                                    <div class="points-calculator">
                                        <h6 class="mb-2"><i class="fas fa-calculator me-2"></i>Point Calculator</h6>
                                        <div id="points-display-<?php echo $criteria['id']; ?>">
                                            <p class="mb-0 text-muted">Select options above to see potential points</p>
                                        </div>
                                    </div> -->
                                    
                                
                                    <div class="mt-3">
    <button type="button" class="btn btn-success" onclick="submitHierarchicalUpload(this)">
        <i class="fas fa-upload me-2"></i>Upload with Specifications
    </button>
    <button type="button" class="btn btn-secondary ms-2" onclick="hideHierarchicalUpload(<?php echo $criteria['id']; ?>)">
        Cancel
    </button>
</div>
                                </form>
                            </div>

                            <!-- Show uploaded documents for this criteria -->
                            <?php if (isset($documents_by_criteria[$criteria['id']])): ?>
                            <div class="mt-3">
                                <strong class="text-success small">
                                    <i class="fas fa-check-circle me-1"></i>
                                    Uploaded Documents:
                                </strong>
                                <?php foreach ($documents_by_criteria[$criteria['id']] as $doc): ?>
                                <div class="document-item d-flex justify-content-between align-items-center">
                                    <div class="flex-grow-1">
                                        <i class="fas fa-file-pdf text-danger me-2"></i>
                                        <strong><?php echo htmlspecialchars($doc['original_filename']); ?></strong>
                                        <?php if ($doc['description']): ?>
                                        <span class="text-muted"> - <?php echo htmlspecialchars(explode("\n\nHierarchical Data:", $doc['description'])[0]); ?></span>
                                       <?php
// Try JSON column first; fallback sa lumang "Hierarchical Data:" sa description
$hier = null;
if (!empty($doc['hierarchical_data'])) {
    $hier = json_decode($doc['hierarchical_data'], true);
} else {
    if (strpos($doc['description'], 'Hierarchical Data:') !== false) {
        $hier_part = explode('Hierarchical Data:', $doc['description'])[1] ?? '';
        $hier = json_decode(trim($hier_part), true);
    }
}

if ($hier && is_array($hier)) {
    // Value label maps
    $valueLabel = [
        // shared
        'local'         => 'Local',
        'national'      => 'National',
        'international' => 'International',

        // roles / types
        'administrator' => 'Administrator',
        'supervisor'    => 'Training Supervisor',
        'trainer'       => 'Trainer/Lecturer',
        'sunday_school' => 'Sunday School Tutor',
        'daycare'       => 'Day Care Tutor',

        'cs_sub_professional' => 'CS Sub-Professional',
        'cs_professional'     => 'CS Professional',
        'prc'                 => 'PRC',

        'journal'          => 'Journal',
        'training_module'  => 'Training Module',
        'book'             => 'Book',
        'teaching_module'  => 'Teaching Module',
        'workbook'         => 'Workbook',
        'reading_kit'      => 'Reading Kit',
        'literacy_outreach'=> 'Early Literacy/Numeracy Outreach',
    ];

    // Field label map
    $fieldLabel = [
        'circulation_level'   => 'Circulation',
        'circulation_levels'  => 'Circulation',
        'acceptability_levels'=> 'Market',
        'service_levels'      => 'Service',
        'publication_type'    => 'Type',
        'patent_status'       => 'Patent',
        'invention_type'      => 'Invention',
        'education_level'     => 'Education',
        'years_experience'    => 'Years',
        'experience_role'     => 'Role',
        'coordination_level'  => 'Coordination',
        'participation_level' => 'Participation',
        'membership_level'    => 'Membership',
        'scholarship_level'   => 'Scholarship',
        'recognition_level'   => 'Recognition',
        'eligibility_type'    => 'Eligibility',
    ];

    // Special phrasing for literacy outreach (para lumabas kagaya ng gusto mo)
    $litPhrases = [
        'local'         => 'Local Community',
        'national'      => 'National Program',
        'international' => 'International Initiative',
    ];

    // Alamin kung literacy outreach
    $isLiteracy = isset($hier['publication_type']) && $hier['publication_type'] === 'literacy_outreach';

    echo '<br><small>';
    $chips = [];

    // 1) publication_type badge (kung meron)
    if (!empty($hier['publication_type'])) {
        $pt = $hier['publication_type'];
        $chips[] = "<span class='badge bg-secondary me-1'>".
                   $fieldLabel['publication_type'].": ".htmlspecialchars($valueLabel[$pt] ?? ucwords(str_replace('_',' ',$pt))).
                   "</span>";
    }

    // 2) single selection: circulation_level
    if (!empty($hier['circulation_level'])) {
        $val = $hier['circulation_level'];
        $text = $isLiteracy
            ? ($litPhrases[$val] ?? ucfirst($val))
            : (($valueLabel[$val] ?? ucfirst($val)).' Circulation');
        $chips[] = "<span class='badge bg-info text-dark me-1'>".
                   ($fieldLabel['circulation_level']).": ".htmlspecialchars($text)."</span>";
    }

    // 3) multi-selection: circulation_levels / acceptability_levels / service_levels
    foreach (['circulation_levels'=>'Circulation','acceptability_levels'=>'Market','service_levels'=>'Service'] as $k => $lbl) {
        if (!empty($hier[$k]) && is_array($hier[$k])) {
            $vals = array_map(function($v) use ($isLiteracy,$litPhrases,$valueLabel,$k){
                if ($k === 'circulation_levels' && $isLiteracy) {
                    return $litPhrases[$v] ?? ucfirst($v);
                }
                return $valueLabel[$v] ?? ucwords(str_replace('_',' ',$v));
            }, $hier[$k]);
            $chips[] = "<span class='badge bg-primary me-1'>".$lbl.": ".htmlspecialchars(implode(', ', $vals))."</span>";
        }
    }

    // 4) iba pang single fields
    foreach (['patent_status','invention_type','education_level','years_experience','experience_role','coordination_level','participation_level','membership_level','scholarship_level','recognition_level','eligibility_type'] as $k) {
        if (!empty($hier[$k])) {
            $val = is_string($hier[$k]) ? $hier[$k] : json_encode($hier[$k]);
            $pretty = $valueLabel[$val] ?? ucwords(str_replace('_',' ', (string)$val));
            $chips[] = "<span class='badge bg-light text-dark border me-1'>".$fieldLabel[$k].": ".htmlspecialchars($pretty)."</span>";
        }
    }

    echo implode(' ', $chips);
    echo '</small>';
}
?>

                                        <?php endif; ?>
                                        <div class="small text-muted">
                                            <?php echo date('M j, Y g:i A', strtotime($doc['upload_date'])); ?> | 
                                            <?php echo number_format($doc['file_size'] / 1024, 1); ?> KB
                                        </div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <button class="btn btn-outline-primary btn-sm" 
                                                onclick="viewDocument(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['original_filename']); ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($current_application['application_status'] === 'draft'): ?>
                                        <a href="?delete=<?php echo $doc['id']; ?>" 
                                           class="btn btn-outline-danger btn-sm"
                                           onclick="return confirm('Delete this document?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-3">
                <!-- Application Status -->
                <?php if ($current_application): ?>
                <div class="upload-card p-4 mb-4">
                    <h6 class="mb-3">Application Status</h6>
                    
                    <div class="d-flex align-items-center mb-3">
                        <i class="fas fa-graduation-cap text-primary me-2"></i>
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($current_application['program_name']); ?></div>
                            <small class="text-muted">
                                Created: <?php echo date('M j, Y', strtotime($current_application['created_at'])); ?>
                            </small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <span class="badge bg-<?php echo $current_application['application_status'] === 'submitted' ? 'success' : 'warning'; ?> w-100 py-2">
                            <?php echo ucfirst(str_replace('_', ' ', $current_application['application_status'])); ?>
                        </span>
                    </div>

                    <div class="mb-3">
                        <small class="text-muted">Documents Uploaded:</small>
                        <div class="fw-bold"><?php echo count($uploaded_documents); ?> files</div>
                    </div>

                    <div class="mb-3">
                        <small class="text-muted">Assessment Criteria:</small>
                        <div class="fw-bold"><?php echo count($assessment_criteria); ?> items</div>
                    </div>

                    <?php if ($current_application['application_status'] === 'draft' && count($uploaded_documents) > 0): ?>
                    <form method="POST" action="">
                        <button type="submit" name="submit_application" class="btn btn-success w-100"
                                onclick="return confirm('Submit your application? You won\'t be able to upload more documents after submission.')">
                            <i class="fas fa-paper-plane me-2"></i>Submit Application
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- Progress Overview -->
                <?php if ($current_application && !empty($assessment_criteria)): ?>
                <div class="upload-card p-4 mb-4">
                    <h6 class="mb-3">
                        <i class="fas fa-chart-pie me-2"></i>
                        Upload Progress
                    </h6>
                    
                    <?php
                    $sections = [];
                    $sections_with_docs = [];
                    foreach ($assessment_criteria as $criteria) {
                        $sections[$criteria['section_number']] = ($sections[$criteria['section_number']] ?? 0) + 1;
                        if (isset($documents_by_criteria[$criteria['id']])) {
                            $sections_with_docs[$criteria['section_number']] = true;
                        }
                    }
                    ?>
                    
                    <?php foreach ($sections as $section_num => $count): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small">Section <?php echo $section_num; ?></span>
                        <div>
                            <?php if (isset($sections_with_docs[$section_num])): ?>
                            <i class="fas fa-check-circle text-success"></i>
                            <?php else: ?>
                            <i class="fas fa-clock text-muted"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <hr class="my-3">
                    <div class="text-center">
                        <div class="h5 text-primary mb-0"><?php echo count($sections_with_docs); ?>/<?php echo count($sections); ?></div>
                        <small class="text-muted">Sections with documents</small>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Guidelines -->
                <div class="upload-card p-4">
                    <h6 class="mb-3">
                        <i class="fas fa-info-circle text-info me-2"></i>
                        Upload Guidelines
                    </h6>
                    
                    <div class="mb-3">
                        <h6 class="small mb-2">Upload Options:</h6>
                        <ul class="list-unstyled small">
                            <li><i class="fas fa-sitemap text-success me-1"></i> <strong>Detailed Upload:</strong> For inventions, publications, and extension services with specific levels</li>
                            <li><i class="fas fa-upload text-primary me-1"></i> <strong>Quick Upload:</strong> For general documents</li>
                        </ul>
                    </div>

                    <div class="mb-3">
                        <h6 class="small mb-2">File Requirements:</h6>
                        <ul class="list-unstyled small">
                            <li><i class="fas fa-check text-success me-1"></i> Format: PDF, JPG, PNG</li>
                            <li><i class="fas fa-check text-success me-1"></i> Max size: 5MB per file</li>
                            <li><i class="fas fa-check text-success me-1"></i> Clear and readable</li>
                            <li><i class="fas fa-check text-success me-1"></i> Original or certified copies</li>
                        </ul>
                    </div>

                    <div class="alert alert-info small mb-0">
                        <i class="fas fa-lightbulb me-1"></i>
                        <strong>Tip:</strong> Use the "Detailed Upload" option for more accurate point calculation and better evaluation results.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Upload Modal (existing functionality) -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-upload me-2"></i>
                        Quick Upload Document
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" id="upload_criteria_id" name="criteria_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Criteria:</label>
                            <div class="fw-bold text-primary" id="upload_criteria_name"></div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="upload_description" class="form-label">Description (Optional)</label>
                            <input type="text" class="form-control" id="upload_description" name="description" 
                                   placeholder="Brief description of this document">
                        </div>
                        
                        <div class="mb-3">
                            <label for="upload_document" class="form-label">Choose File *</label>
                            <input type="file" class="form-control" id="upload_document" name="document" 
                                   accept=".pdf,.jpeg,.jpg,.png" required>
                            <div class="form-text">
                                Supported formats: PDF, JPG, PNG | Maximum size: 5MB
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="upload_document" class="btn btn-success">
                            <i class="fas fa-upload me-2"></i>Upload Document
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Document Viewer Modal -->
    <div class="modal fade" id="documentViewerModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-alt me-2"></i>
                        <span id="docModalTitle">Document Viewer</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="docViewer" style="min-height:70vh;background:#f8f9fa;">
                        <div class="d-flex justify-content-center align-items-center" style="height:70vh;">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2"></i>Success!
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="fas fa-check-circle text-success mb-3" style="font-size: 4rem;"></i>
                    <h5 id="successModalMessage"><?php echo htmlspecialchars($success_message); ?></h5>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" data-bs-dismiss="modal">
                        <i class="fas fa-thumbs-up me-2"></i>OK
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Modal -->
    <div class="modal fade" id="errorModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Error
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <ul class="mb-0" id="errorModalList">
                        <?php if (!empty($errors)): ?>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

 <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
       let uploadModal;
    let viewerModal;
    let successModal;
    let errorModal;

    document.addEventListener('DOMContentLoaded', function() {
        uploadModal = new bootstrap.Modal(document.getElementById('uploadModal'));
        viewerModal = new bootstrap.Modal(document.getElementById('documentViewerModal'));
        successModal = new bootstrap.Modal(document.getElementById('successModal'));
        errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
        
        // ONLY show modal if from PHP success (keep this for non-AJAX uploads)
        <?php if ($success_message && !isset($_POST['ajax_upload'])): ?>
            successModal.show();
        <?php endif; ?>
        
        <?php if (!empty($errors) && !isset($_POST['ajax_upload'])): ?>
            errorModal.show();
        <?php endif; ?>
        
        setupPointCalculators();
    });

    // AJAX Upload Function
    function submitHierarchicalUpload(button) {
        const form = button.closest('form');
        const formData = new FormData(form);
        formData.append('upload_hierarchical_document', '1');
        formData.append('ajax_upload', '1');
        
        // Show loading on button
        const originalText = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Uploading...';
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(html => {
            // Parse response to check for success/error
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            // Check if upload was successful by looking for new document in response
            const hasError = html.includes('alert-danger') || html.includes('Failed to upload');
            
            if (hasError) {
                // Show error modal
                const errorMsg = doc.querySelector('.alert-danger');
                if (errorMsg) {
                    document.getElementById('errorModalList').innerHTML = errorMsg.querySelector('ul').innerHTML;
                }
                errorModal.show();
            } else {
                // Show success modal
                document.getElementById('successModalMessage').textContent = 'Document uploaded successfully with detailed specifications!';
                successModal.show();
                
                // Hide the upload form
                const criteriaId = form.querySelector('input[name="criteria_id"]').value;
                hideHierarchicalUpload(criteriaId);
                
                // Reload just the document list for this criteria (without full page reload)
                setTimeout(() => {
                    location.reload();
                }, 1500);
            }
            
            // Reset button
            button.disabled = false;
            button.innerHTML = originalText;
        })
        .catch(error => {
            console.error('Upload error:', error);
            alert('Upload failed. Please try again.');
            button.disabled = false;
            button.innerHTML = originalText;
        });
        
        return false;
    }

    function showUploadForm(criteriaId, criteriaName) {
        document.getElementById('upload_criteria_id').value = criteriaId;
        document.getElementById('upload_criteria_name').textContent = criteriaName;
        document.getElementById('upload_description').value = '';
        document.getElementById('upload_document').value = '';
        
        uploadModal.show();
    }

    function showHierarchicalUpload(criteriaId, criteriaName) {
        document.querySelectorAll('.hierarchical-upload-section').forEach(section => {
            section.classList.remove('show');
        });
        
        const section = document.getElementById('hierarchical-' + criteriaId);
        if (section) {
            section.classList.add('show');
        }
    }

    function hideHierarchicalUpload(criteriaId) {
        const section = document.getElementById('hierarchical-' + criteriaId);
        if (section) {
            section.classList.remove('show');
        }
    }

    function setupPointCalculators() {
        document.querySelectorAll('.hierarchical-upload-section').forEach(section => {
            const form = section.querySelector('form');
            if (form) {
                const inputs = form.querySelectorAll('input[type="radio"], input[type="checkbox"], input[type="number"]');
                inputs.forEach(input => {
                    input.addEventListener('change', function() {
                        updatePointCalculation(form);
                    });
                });
            }
        });
    }
        function updatePointCalculation(form) {
            const criteriaId = form.querySelector('input[name="criteria_id"]').value;
            const display = document.getElementById('points-display-' + criteriaId);
            
            if (!display) return;

            // Check what type of criteria this is and calculate accordingly
            const patentStatus = form.querySelector('input[name="patent_status"]:checked');
            const acceptabilityLevels = form.querySelectorAll('input[name="acceptability_levels[]"]:checked');
            const circulationLevels = form.querySelectorAll('input[name="circulation_levels[]"]:checked');
            const serviceLevels = form.querySelectorAll('input[name="service_levels[]"]:checked');

            let calculationHtml = '';
            let totalPoints = 0;

            if (patentStatus && acceptabilityLevels.length > 0) {
                // Invention/Innovation calculation
                const inventionType = form.querySelector('input[name="invention_type"]').value;
                const isInvention = inventionType === 'invention';
                
                const basePoints = patentStatus.value === 'patented' ? (isInvention ? 6 : 1) : (isInvention ? 5 : 2);
                totalPoints += basePoints;
                
                let marketPoints = 0;
                let marketList = [];
                
                acceptabilityLevels.forEach(checkbox => {
                    const level = checkbox.value;
                    if (level === 'local') {
                        const pts = isInvention ? 7 : 4;
                        marketPoints += pts;
                        marketList.push('Local (+' + pts + ')');
                    } else if (level === 'national') {
                        const pts = isInvention ? 8 : 5;
                        marketPoints += pts;
                        marketList.push('National (+' + pts + ')');
                    } else if (level === 'international') {
                        const pts = isInvention ? 9 : 6;
                        marketPoints += pts;
                        marketList.push('International (+' + pts + ')');
                    }
                });
                
                totalPoints += marketPoints;
                
                calculationHtml = `
                    <p class="mb-1"><strong>Patent:</strong> ${patentStatus.value === 'patented' ? 'Patented' : 'No Patent'} = ${basePoints} points</p>
                    <p class="mb-1"><strong>Markets:</strong> ${marketList.join(', ')} = ${marketPoints} points</p>
                `;
                
            } else if (circulationLevels.length > 0) {
                // Publication calculation
                const publicationType = form.querySelector('input[name="publication_type"]').value;
                const points = {
                    'journal': { local: 2, national: 3, international: 4 },
                    'training_module': { local: 3, national: 4, international: 5 },
                    'book': { local: 5, national: 6, international: 7 }
                };
                
                const currentPoints = points[publicationType] || points['journal'];
                let levelsList = [];
                
                circulationLevels.forEach(checkbox => {
                    const level = checkbox.value;
                    const pts = currentPoints[level];
                    totalPoints += pts;
                    levelsList.push(`${level.charAt(0).toUpperCase() + level.slice(1)} (+${pts})`);
                });
                
                calculationHtml = `
                    <p class="mb-1"><strong>Type:</strong> ${publicationType.replace('_', ' ')}</p>
                    <p class="mb-1"><strong>Levels:</strong> ${levelsList.join(', ')}</p>
                `;
                
            } else if (serviceLevels.length > 0) {
                // Extension service calculation
                const extensionType = form.querySelector('input[name="extension_type"]').value;
                
                const servicePoints = {
                    'consultancy': { local: 5, national: 10, international: 15 },
                    'lecturer': { local: 6, national: 8, international: 10 },
                    'community': { trainer: 3, official: 4, manager: 5 }
                };
                
                const currentPoints = servicePoints[extensionType];
                let levelsList = [];
                
                serviceLevels.forEach(checkbox => {
                    const level = checkbox.value;
                    const pts = currentPoints[level];
                    if (pts) {
                        totalPoints += pts;
                        levelsList.push(`${level.charAt(0).toUpperCase() + level.slice(1)} (+${pts})`);
                    }
                });
                
                calculationHtml = `
                    <p class="mb-1"><strong>Service:</strong> ${extensionType}</p>
                    <p class="mb-1"><strong>Levels:</strong> ${levelsList.join(', ')}</p>
                `;
            }

            if (calculationHtml) {
                display.innerHTML = `
                    ${calculationHtml}
                    <hr class="my-2">
                    <p class="mb-0 fw-bold text-success">Total Expected Points: ${totalPoints}</p>
                `;
            } else {
                display.innerHTML = '<p class="mb-0 text-muted">Select options above to see potential points</p>';
            }
        }

        function viewDocument(docId, filename) {
            document.getElementById('docModalTitle').textContent = filename;
            
            const viewer = document.getElementById('docViewer');
            viewer.innerHTML = `
                <div class="d-flex justify-content-center align-items-center" style="height:70vh;">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            viewerModal.show();
            
            // Load document after modal is shown
            setTimeout(() => {
                const url = `candidate_view_document.php?id=${encodeURIComponent(docId)}`;
                const ext = filename.split('.').pop().toLowerCase();
                
                if (ext === 'pdf') {
                    viewer.innerHTML = `<iframe src="${url}" style="width:100%;height:70vh;border:none;"></iframe>`;
                } else if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                    viewer.innerHTML = `
                        <div class="text-center p-3">
                            <img src="${url}" class="img-fluid" style="max-height:65vh;border-radius:8px;">
                        </div>
                    `;
                } else {
                    viewer.innerHTML = `
                        <div class="text-center p-5">
                            <i class="fas fa-file fa-4x text-muted mb-3"></i>
                            <h5>Preview not available</h5>
                            <p class="text-muted">This file type cannot be previewed.</p>
                            <a href="${url}&dl=1" class="btn btn-primary">
                                <i class="fas fa-download me-2"></i>Download to view
                            </a>
                        </div>
                    `;
                }
            }, 200);
        }

  
      // Auto-hide modals on successful upload
        <?php if ($success_message && strpos($success_message, 'uploaded') !== false): ?>
        setTimeout(() => {
            if (uploadModal && uploadModal._isShown) {
                uploadModal.hide();
            }
            // Hide any open hierarchical sections
            document.querySelectorAll('.hierarchical-upload-section').forEach(section => {
                section.classList.remove('show');
            });
        }, 1000);
        <?php endif; ?>

        // Enhanced form validation
     document.addEventListener('submit', function(e) {
  const form = e.target;

  if (form.querySelector('button[name="upload_hierarchical_document"]')) {
    let hasRequiredSelections = true;
    let errorMessage = '';

    // Invention/Innovation
    const hasPatentField = form.querySelector('input[name="patent_status"]');
    const patentStatus = form.querySelector('input[name="patent_status"]:checked');
    const acceptabilityLevels = form.querySelectorAll('input[name="acceptability_levels[]"]:checked');
    if (hasPatentField && !patentStatus) {
      hasRequiredSelections = false;
      errorMessage = 'Please select patent status.';
    } else if (form.querySelector('input[name="acceptability_levels[]"]') && acceptabilityLevels.length === 0) {
      hasRequiredSelections = false;
      errorMessage = 'Please select at least one market acceptability level.';
    }

    // Publications (RADIO)
    const hasCircRadio = form.querySelector('input[name="circulation_level"]');
    const circulationLevel = form.querySelector('input[name="circulation_level"]:checked');
    if (hasCircRadio && !circulationLevel) {
      hasRequiredSelections = false;
      errorMessage = 'Please select circulation level.';
    }

    // Extension services (multi)
    const serviceLevels = form.querySelectorAll('input[name="service_levels[]"]:checked');
    if (form.querySelector('input[name="service_levels[]"]') && serviceLevels.length === 0) {
      hasRequiredSelections = false;
      errorMessage = 'Please select at least one service level.';
    }

    // Section 1
    if (form.querySelector('input[name="education_level"]') && !form.querySelector('input[name="education_level"]:checked')) {
      hasRequiredSelections = false;
      errorMessage = 'Please select education level.';
    }

    // Section 2
    const yearsExp = form.querySelector('input[name="years_experience"]');
    if (yearsExp && (!yearsExp.value || Number(yearsExp.value) < 5)) {
      hasRequiredSelections = false;
      errorMessage = 'Please enter at least 5 years of experience.';
    }
    if (form.querySelector('input[name="experience_role"]') && !form.querySelector('input[name="experience_role"]:checked')) {
      hasRequiredSelections = false;
      errorMessage = 'Please select experience role.';
    }

    // Section 4
    if (form.querySelector('input[name="coordination_level"]') && !form.querySelector('input[name="coordination_level"]:checked')) {
      hasRequiredSelections = false;
      errorMessage = 'Please select coordination level.';
    }
    if (form.querySelector('input[name="participation_level"]') && !form.querySelector('input[name="participation_level"]:checked')) {
      hasRequiredSelections = false;
      errorMessage = 'Please select participation level.';
    }
    if (form.querySelector('input[name="membership_level"]') && !form.querySelector('input[name="membership_level"]:checked')) {
      hasRequiredSelections = false;
      errorMessage = 'Please select membership level.';
    }
    if (form.querySelector('input[name="scholarship_level"]') && !form.querySelector('input[name="scholarship_level"]:checked')) {
      hasRequiredSelections = false;
      errorMessage = 'Please select scholarship level.';
    }

    // Section 5
    if (form.querySelector('input[name="recognition_level"]') && !form.querySelector('input[name="recognition_level"]:checked')) {
      hasRequiredSelections = false;
      errorMessage = 'Please select recognition level.';
    }
    if (form.querySelector('input[name="eligibility_type"]') && !form.querySelector('input[name="eligibility_type"]:checked')) {
      hasRequiredSelections = false;
      errorMessage = 'Please select eligibility type.';
    }

    if (!hasRequiredSelections) {
      e.preventDefault();
      alert(errorMessage);
      return false;
    }
  }
});
        // Smooth scrolling for better UX
        function smoothScrollToElement(element) {
            element.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }

        // Auto-expand relevant sections based on URL hash
        if (window.location.hash) {
            const targetElement = document.querySelector(window.location.hash);
            if (targetElement) {
                setTimeout(() => {
                    smoothScrollToElement(targetElement);
                }, 500);
            }
        }
    </script>
    <script>
function updatePointCalculation(form) {
    const criteriaId = form.querySelector('input[name="criteria_id"]').value;
    const display = document.getElementById('points-display-' + criteriaId);
    
    if (!display) return;

    let calculationHtml = '';
    let totalPoints = 0;

    // Section 1 - Education
    const educationLevel = form.querySelector('input[name="education_level"]:checked');
    const scholarshipType = form.querySelector('input[name="scholarship_type"]:checked');
    
    if (educationLevel) {
        const eduPoints = {
            'high_school': 2,
            'vocational': 3,
            'technical': 4,
            'undergraduate': 5,
            'non_education': 6
        };
        totalPoints += eduPoints[educationLevel.value] || 0;
        calculationHtml += `<p class="mb-1"><strong>Education:</strong> ${educationLevel.value.replace('_', ' ')} = ${eduPoints[educationLevel.value]} points</p>`;
        
        if (scholarshipType && scholarshipType.value !== 'none') {
            const schPoints = scholarshipType.value === 'full' ? 2 : 1;
            totalPoints += schPoints;
            calculationHtml += `<p class="mb-1"><strong>Scholarship:</strong> ${scholarshipType.value} = +${schPoints} points</p>`;
        }
    }

    // Section 2 - Work Experience
    const yearsExp = form.querySelector('input[name="years_experience"]');
    const expRole = form.querySelector('input[name="experience_role"]:checked');
    
    if (yearsExp && expRole && yearsExp.value >= 5) {
        const rolePoints = {
            'administrator': 5,
            'supervisor': 3,
            'trainer': 2,
            'sunday_school': 1,
            'daycare': 1
        };
        totalPoints += rolePoints[expRole.value] || 0;
        calculationHtml += `<p class="mb-1"><strong>Experience:</strong> ${yearsExp.value} years as ${expRole.value.replace('_', ' ')} = ${rolePoints[expRole.value]} points</p>`;
    }

    // Section 3 - Publications (updated)
const authorsCount = form.querySelector('input[name="authors_count"]');
const circulationLevel = form.querySelector('input[name="circulation_level"]:checked');
if (form.querySelector('input[name="circulation_level"]') && !circulationLevel) {
  hasRequiredSelections = false;
  errorMessage = 'Please select circulation level.';
}

const publicationType = form.querySelector('input[name="publication_type"]');

if (circulationLevel && publicationType) {
    const pubPoints = {
        'journal': { local: 2, national: 3, international: 4 },
        'training_module': { local: 3, national: 4, international: 5 },
        'book': { local: 5, national: 6, international: 7 },
        'teaching_module': { local: 3, national: 4, international: 5 },    // NEW
        'workbook': { local: 2, national: 3, international: 4 },           // NEW
        'reading_kit': { local: 2, national: 3, international: 4 },        // NEW
        'literacy_outreach': { local: 4, national: 5, international: 6 }   // NEW
    };
    
    const currentPoints = pubPoints[publicationType.value] || pubPoints['journal'];
    const points = currentPoints[circulationLevel.value];
    totalPoints += points;
    
    calculationHtml += `<p class="mb-1"><strong>Publication:</strong> ${publicationType.value.replace('_', ' ')} - ${circulationLevel.value} = ${points} points</p>`;
    if (authorsCount && authorsCount.value) {
        calculationHtml += `<p class="mb-1"><strong>Authors:</strong> ${authorsCount.value} (for reference)</p>`;
    }
}

    // Section 4 - Professional Development
    const coordLevel = form.querySelector('input[name="coordination_level"]:checked');
    const partLevel = form.querySelector('input[name="participation_level"]:checked');
    const membLevel = form.querySelector('input[name="membership_level"]:checked');
    const scholLevel = form.querySelector('input[name="scholarship_level"]:checked');
    
    if (coordLevel) {
        const coordPoints = { local: 6, national: 8, international: 10 };
        totalPoints += coordPoints[coordLevel.value];
        calculationHtml += `<p class="mb-1"><strong>Coordination:</strong> ${coordLevel.value} level = ${coordPoints[coordLevel.value]} points</p>`;
    }
    
    if (partLevel) {
        const partPoints = { local: 3, national: 4, international: 5 };
        totalPoints += partPoints[partLevel.value];
        calculationHtml += `<p class="mb-1"><strong>Participation:</strong> ${partLevel.value} level = ${partPoints[partLevel.value]} points</p>`;
    }
    
    if (membLevel) {
        const membPoints = { local: 3, national: 4, international: 5 };
        totalPoints += membPoints[membLevel.value];
        calculationHtml += `<p class="mb-1"><strong>Membership:</strong> ${membLevel.value} level = ${membPoints[membLevel.value]} points</p>`;
    }
    
    if (scholLevel) {
        const scholPoints = parseFloat(scholLevel.value);
        totalPoints += scholPoints;
        calculationHtml += `<p class="mb-1"><strong>Scholarship:</strong> ${scholLevel.value} level = ${scholPoints} points</p>`;
    }

    // Section 5 - Recognition & Others
    const recogLevel = form.querySelector('input[name="recognition_level"]:checked');
    const eligType = form.querySelector('input[name="eligibility_type"]:checked');
    
    if (recogLevel) {
        const recogPoints = { local: 6, national: 8 };
        totalPoints += recogPoints[recogLevel.value];
        calculationHtml += `<p class="mb-1"><strong>Recognition:</strong> ${recogLevel.value} level = ${recogPoints[recogLevel.value]} points</p>`;
    }
    
    if (eligType) {
        const eligPoints = { cs_sub_professional: 3, cs_professional: 4, prc: 5 };
        totalPoints += eligPoints[eligType.value];
        calculationHtml += `<p class="mb-1"><strong>Eligibility:</strong> ${eligType.value.replace('_', ' ')} = ${eligPoints[eligType.value]} points</p>`;
    }

    // Existing sections (inventions, patents, etc.) - keep the existing code for these
    const patentStatus = form.querySelector('input[name="patent_status"]:checked');
    const acceptabilityLevels = form.querySelectorAll('input[name="acceptability_levels[]"]:checked');
    const serviceLevels = form.querySelectorAll('input[name="service_levels[]"]:checked');

    if (patentStatus && acceptabilityLevels.length > 0) {
        // Invention/Innovation calculation (existing code)
        const inventionType = form.querySelector('input[name="invention_type"]').value;
        const isInvention = inventionType === 'invention';
        
        const basePoints = patentStatus.value === 'patented' ? (isInvention ? 6 : 1) : (isInvention ? 5 : 2);
        totalPoints += basePoints;
        
        let marketPoints = 0;
        let marketList = [];
        
        acceptabilityLevels.forEach(checkbox => {
            const level = checkbox.value;
            if (level === 'local') {
                const pts = isInvention ? 7 : 4;
                marketPoints += pts;
                marketList.push('Local (+' + pts + ')');
            } else if (level === 'national') {
                const pts = isInvention ? 8 : 5;
                marketPoints += pts;
                marketList.push('National (+' + pts + ')');
            } else if (level === 'international') {
                const pts = isInvention ? 9 : 6;
                marketPoints += pts;
                marketList.push('International (+' + pts + ')');
            }
        });
        
        totalPoints += marketPoints;
        
        calculationHtml += `
            <p class="mb-1"><strong>Patent:</strong> ${patentStatus.value === 'patented' ? 'Patented' : 'No Patent'} = ${basePoints} points</p>
            <p class="mb-1"><strong>Markets:</strong> ${marketList.join(', ')} = ${marketPoints} points</p>
        `;
    } else if (serviceLevels.length > 0) {
        // Extension service calculation (existing code)
        const extensionType = form.querySelector('input[name="extension_type"]').value;
        
        const servicePoints = {
            'consultancy': { local: 5, national: 10, international: 15 },
            'lecturer': { local: 6, national: 8, international: 10 },
            'community': { trainer: 3, official: 4, manager: 5 }
        };
        
        const currentPoints = servicePoints[extensionType];
        let levelsList = [];
        
        serviceLevels.forEach(checkbox => {
            const level = checkbox.value;
            const pts = currentPoints[level];
            if (pts) {
                totalPoints += pts;
                levelsList.push(`${level.charAt(0).toUpperCase() + level.slice(1)} (+${pts})`);
            }
        });
        
        calculationHtml += `
            <p class="mb-1"><strong>Service:</strong> ${extensionType}</p>
            <p class="mb-1"><strong>Levels:</strong> ${levelsList.join(', ')}</p>
        `;
    }

    if (calculationHtml) {
        display.innerHTML = `
            ${calculationHtml}
            <hr class="my-2">
            <p class="mb-0 fw-bold text-success">Total Expected Points: ${totalPoints}</p>
        `;
    } else {
        display.innerHTML = '<p class="mb-0 text-muted">Select options above to see potential points</p>';
    }
}

// Enhanced form validation for new sections
document.addEventListener('submit', function(e) {
    const form = e.target;
    
    // Check if this is a hierarchical upload form
    if (form.querySelector('input[name="upload_hierarchical_document"]')) {
        let hasRequiredSelections = true;
        let errorMessage = '';
        
        // Section 1 validation
        const educationLevel = form.querySelector('input[name="education_level"]:checked');
        if (form.querySelector('input[name="education_level"]') && !educationLevel) {
            hasRequiredSelections = false;
            errorMessage = 'Please select education level.';
        }
        
        // Section 2 validation
        const yearsExp = form.querySelector('input[name="years_experience"]');
        const expRole = form.querySelector('input[name="experience_role"]:checked');
        if (yearsExp && (!yearsExp.value || yearsExp.value < 5)) {
            hasRequiredSelections = false;
            errorMessage = 'Please enter at least 5 years of experience.';
        } else if (form.querySelector('input[name="experience_role"]') && !expRole) {
            hasRequiredSelections = false;
            errorMessage = 'Please select experience role.';
        }
        
        // Section 3 validation (publications)
        const authorsCount = form.querySelector('input[name="authors_count"]');
      // publications
const circulationLevel = form.querySelector('input[name="circulation_level"]:checked');
if (form.querySelector('input[name="circulation_level"]') && !circulationLevel) {
  hasRequiredSelections = false;
  errorMessage = 'Please select circulation level.';
}
} else if (form.querySelector('input[name="circulation_level"]') && !circulationLevel) {
            hasRequiredSelections = false;
            errorMessage = 'Please select circulation level.';
        }
        
        // Section 4 validation
        if (form.querySelector('input[name="coordination_level"]') && !form.querySelector('input[name="coordination_level"]:checked')) {
            hasRequiredSelections = false;
            errorMessage = 'Please select coordination level.';
        }
        if (form.querySelector('input[name="participation_level"]') && !form.querySelector('input[name="participation_level"]:checked')) {
            hasRequiredSelections = false;
            errorMessage = 'Please select participation level.';
        }
        if (form.querySelector('input[name="membership_level"]') && !form.querySelector('input[name="membership_level"]:checked')) {
            hasRequiredSelections = false;
            errorMessage = 'Please select membership level.';
        }
        if (form.querySelector('input[name="scholarship_level"]') && !form.querySelector('input[name="scholarship_level"]:checked')) {
            hasRequiredSelections = false;
            errorMessage = 'Please select scholarship level.';
        }
        
        // Section 5 validation
        if (form.querySelector('input[name="recognition_level"]') && !form.querySelector('input[name="recognition_level"]:checked')) {
            hasRequiredSelections = false;
            errorMessage = 'Please select recognition level.';
        }
        if (form.querySelector('input[name="eligibility_type"]') && !form.querySelector('input[name="eligibility_type"]:checked')) {
            hasRequiredSelections = false;
            errorMessage = 'Please select eligibility type.';
        }
        
        // Existing validations for inventions, publications, etc.
        const patentStatus = form.querySelector('input[name="patent_status"]:checked');
        const acceptabilityLevels = form.querySelectorAll('input[name="acceptability_levels[]"]:checked');
        
        if (form.querySelector('input[name="patent_status"]') && !patentStatus) {
            hasRequiredSelections = false;
            errorMessage = 'Please select patent status.';
        } else if (form.querySelector('input[name="acceptability_levels[]"]') && acceptabilityLevels.length === 0) {
            hasRequiredSelections = false;
            errorMessage = 'Please select at least one market acceptability level.';
        }
        
        // Check for extension service requirements
        const serviceLevels = form.querySelectorAll('input[name="service_levels[]"]:checked');
        if (form.querySelector('input[name="service_levels[]"]') && serviceLevels.length === 0) {
            hasRequiredSelections = false;
            errorMessage = 'Please select at least one service level.';
        }
        
        if (!hasRequiredSelections) {
            e.preventDefault();
            alert(errorMessage);
            return false;
        }
    }
});
<script>
</body>
</html>