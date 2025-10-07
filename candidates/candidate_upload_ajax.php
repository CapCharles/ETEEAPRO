<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Check if user is logged in and is a candidate
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'candidate') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$user_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

// Get current application
$current_application = null;
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
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit();
}

if (!$current_application) {
    echo json_encode(['success' => false, 'message' => 'No active application found']);
    exit();
}

if ($current_application['application_status'] !== 'draft') {
    echo json_encode(['success' => false, 'message' => 'Application already submitted']);
    exit();
}

// Handle hierarchical file upload
if (isset($_POST['upload_hierarchical_document'])) {
    $criteria_id = $_POST['criteria_id'] ?? null;
    $document_type = $_POST['document_type'] ?? 'portfolio';
    $description = trim($_POST['description'] ?? '');
    $application_id = $current_application['id'];

    // Collect hierarchical data
    $hierarchical_data = [];

    // Section 1 - Education
    if (isset($_POST['education_level'])) $hierarchical_data['education_level'] = $_POST['education_level'];
    if (isset($_POST['scholarship_type'])) $hierarchical_data['scholarship_type'] = $_POST['scholarship_type'];

    // Section 2 - Work Experience
    if (isset($_POST['years_experience'])) $hierarchical_data['years_experience'] = $_POST['years_experience'];
    if (isset($_POST['experience_role'])) $hierarchical_data['experience_role'] = $_POST['experience_role'];

    // Section 3 - Publications
    if (isset($_POST['authors_count'])) $hierarchical_data['authors_count'] = $_POST['authors_count'];
    if (isset($_POST['circulation_level'])) $hierarchical_data['circulation_level'] = $_POST['circulation_level'];
    if (isset($_POST['publication_type'])) $hierarchical_data['publication_type'] = $_POST['publication_type'];

    // Inventions/Innovations
    if (isset($_POST['patent_status'])) $hierarchical_data['patent_status'] = $_POST['patent_status'];
    if (!empty($_POST['acceptability_levels'])) $hierarchical_data['acceptability_levels'] = (array)$_POST['acceptability_levels'];
    if (isset($_POST['invention_type'])) $hierarchical_data['invention_type'] = $_POST['invention_type'];

    // Extension/Outreach
    if (!empty($_POST['service_levels'])) $hierarchical_data['service_levels'] = (array)$_POST['service_levels'];
    if (isset($_POST['extension_type'])) $hierarchical_data['extension_type'] = $_POST['extension_type'];

    // Section 4 - Professional Development
    if (isset($_POST['coordination_level'])) $hierarchical_data['coordination_level'] = $_POST['coordination_level'];
    if (isset($_POST['participation_level'])) $hierarchical_data['participation_level'] = $_POST['participation_level'];
    if (isset($_POST['membership_level'])) $hierarchical_data['membership_level'] = $_POST['membership_level'];
    if (isset($_POST['scholarship_level'])) $hierarchical_data['scholarship_level'] = $_POST['scholarship_level'];

    // Section 5 - Recognition & Others
    if (isset($_POST['recognition_level'])) $hierarchical_data['recognition_level'] = $_POST['recognition_level'];
    if (isset($_POST['eligibility_type'])) $hierarchical_data['eligibility_type'] = $_POST['eligibility_type'];

    // Handle circulation_levels array fallback
    if (empty($hierarchical_data['circulation_level']) && !empty($hierarchical_data['circulation_levels']) && is_array($hierarchical_data['circulation_levels'])) {
        $rank = ['local'=>1,'national'=>2,'international'=>3];
        usort($hierarchical_data['circulation_levels'], function($a,$b) use ($rank){
            return ($rank[$b] ?? 0) <=> ($rank[$a] ?? 0);
        });
        $hierarchical_data['circulation_level'] = $hierarchical_data['circulation_levels'][0];
    }

    // Type casting
    if (isset($hierarchical_data['years_experience'])) $hierarchical_data['years_experience'] = (int)$hierarchical_data['years_experience'];
    if (isset($hierarchical_data['authors_count'])) $hierarchical_data['authors_count'] = (int)$hierarchical_data['authors_count'];
    if (isset($hierarchical_data['scholarship_level'])) $hierarchical_data['scholarship_level'] = (float)$hierarchical_data['scholarship_level'];

    // Validation
    if (empty($criteria_id)) {
        echo json_encode(['success' => false, 'message' => 'Invalid criteria selection']);
        exit();
    }

    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Please select a file to upload']);
        exit();
    }

    $file = $_FILES['document'];
    $file_size = $file['size'];
    $file_type = $file['type'];
    $original_name = $file['name'];

    if ($file_size > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File size must be less than 5MB']);
        exit();
    }

    $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    if (!in_array($file_type, $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Only PDF, JPG, and PNG files are allowed']);
        exit();
    }

    // Upload file
    try {
        $upload_dir = '../uploads/documents/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

        $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $stored_filename = $user_id . '_' . $application_id . '_' . uniqid() . '.' . $file_extension;
        $file_path = $upload_dir . $stored_filename;

        if (move_uploaded_file($file['tmp_name'], $file_path)) {
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

            echo json_encode([
                'success' => true, 
                'message' => 'Document uploaded successfully with detailed specifications!',
                'filename' => $original_name,
                'criteria_id' => $criteria_id
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload file. Please try again.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error occurred while saving document.']);
    }
}
?>