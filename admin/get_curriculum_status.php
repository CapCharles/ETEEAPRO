// Add this new file: admin/get_curriculum_status.php
<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'evaluator'])) {
    die(json_encode(['error' => 'Unauthorized']));
}

$application_id = $_POST['application_id'] ?? null;
$bridging_subjects = $_POST['bridging_subjects'] ?? [];

if (!$application_id) {
    die(json_encode(['error' => 'No application ID']));
}

// Get application program
$stmt = $pdo->prepare("SELECT program_code FROM programs p JOIN applications a ON p.id = a.program_id WHERE a.id = ?");
$stmt->execute([$application_id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
$programCode = $app['program_code'] ?? '';

// Get documents
$stmt = $pdo->prepare("SELECT * FROM documents WHERE application_id = ?");
$stmt->execute([$application_id]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get curriculum and passed subjects
$curriculumStatus = getPassedSubjects($documents, $programCode);
$curriculumSubjects = $curriculumStatus['curriculum'];
$passedSubjects = $curriculumStatus['passed'];

// Build HTML
$html = '';
foreach ($curriculumSubjects as $subject) {
    $isInBridging = in_array($subject['name'], $bridging_subjects);
    $isPassed = !$isInBridging;
    $evidence = isset($passedSubjects[$subject['name']]) ? $passedSubjects[$subject['name']] : 
               ($isPassed ? 'Fulfilled via prior learning/experience' : '—');
    
    $statusBadge = $isPassed ? 
        '<span class="badge bg-success">Passed</span>' : 
        '<span class="badge bg-warning text-dark">Required</span>';
    
    $html .= "<tr>
        <td>" . htmlspecialchars($subject['name']) . "</td>
        <td class='text-center'>{$statusBadge}</td>
        <td class='small text-muted'>" . htmlspecialchars($evidence) . "</td>
    </tr>";
}

echo json_encode(['html' => $html]);