<?php
session_start();

// top of evaluate.php
define('BASE_DIR', __DIR__); // folder ng evaluate.php

require_once BASE_DIR . '/../config/database.php';
require_once BASE_DIR . '/../config/constants.php';

// Kung hindi ka composer, at may folder ka talagang PHPMailer sa project:
require BASE_DIR . '/../PHPMailer/PHPMailer/src/Exception.php';
require BASE_DIR . '/../PHPMailer/PHPMailer/src/PHPMailer.php';
require BASE_DIR . '/../PHPMailer/PHPMailer/src/SMTP.php';

// Check authentication
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'evaluator'])) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
$application_id = isset($_GET['id']) ? $_GET['id'] : null;
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

$errors = [];
$success_message = '';
$current_application = null; // <-- add this line
// Email configuration
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;



function makeMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'cspbank911@gmail.com';
    $mail->Password = 'uzhtbqmdqigquyqq';
    $mail->SMTPSecure = 'ssl';
    $mail->Port = 465;
    $mail->setFrom('cspbank911@gmail.com', 'ETEEAP System');
    $mail->isHTML(true);
    return $mail;
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
function calculateBridgingUnits($finalScore) {
    if ($finalScore >= 95) return 3;
    if ($finalScore >= 91) return 6;
    if ($finalScore >= 85) return 9;
    if ($finalScore >= 80) return 12;
    if ($finalScore >= 75) return 15;
    if ($finalScore >= 70) return 18;
    if ($finalScore >= 65) return 21;
    if ($finalScore >= 60) return 24;
    return 0;
}



if ($_POST && isset($_POST['update_bridging'])) {
    $app_id = $_POST['application_id'];
    $bridging_subjects = $_POST['bridging_subjects'] ?? [];
    
    try {
        $pdo->beginTransaction();
        
        // Clear existing bridging requirements
        $stmt = $pdo->prepare("DELETE FROM bridging_requirements WHERE application_id = ?");
        $stmt->execute([$app_id]);
        
        // Insert new bridging requirements
        foreach ($bridging_subjects as $subject) {
            if (!empty($subject['name']) && !empty($subject['units'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO bridging_requirements (application_id, subject_name, subject_code, units, priority, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $app_id, 
                    $subject['name'], 
                    $subject['code'] ?? '', 
                    $subject['units'], 
                    $subject['priority'] ?? 2,
                    $user_id
                ]);
            }
        }
        
        $pdo->commit();
        $success_message = "Bridging requirements updated successfully!";
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $errors[] = "Failed to update bridging requirements: " . $e->getMessage();
    }
}

// Get existing bridging requirements
$bridging_requirements = [];
if (!empty($application_id)) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM bridging_requirements
            WHERE application_id = ?
            ORDER BY priority ASC, subject_name ASC
        ");
        $stmt->execute([$application_id]);
        $bridging_requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table might not exist – ensure it exists, then keep empty array
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS bridging_requirements (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    application_id INT NOT NULL,
                    subject_name VARCHAR(255) NOT NULL,
                    subject_code VARCHAR(50),
                    units INT NOT NULL,
                    priority INT DEFAULT 2,
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                )
            ");
        } catch (PDOException $ignore) {}
    }
}



function getPassedSubjects($documents, $programCode) {
    global $pdo, $current_application;
    
    $curriculum = [];
    
    // Fetch curriculum from database instead of hardcoded arrays
    try {
        $stmt = $pdo->prepare("
            SELECT subject_name as name, subject_code
            FROM subjects 
            WHERE program_id = ? AND status = 'active'
            ORDER BY year_level, semester, subject_name
        ");
        $stmt->execute([$current_application['program_id']]);
        $dbSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build curriculum with keywords for matching
        foreach ($dbSubjects as $subject) {
            $keywords = [];
            
            // Generate keywords from subject name and code
            $nameParts = explode(' ', strtolower($subject['name']));
            $keywords = array_merge($keywords, $nameParts);
            
            if (!empty($subject['subject_code'])) {
                $keywords[] = strtolower($subject['subject_code']);
            }
            
            // Remove common words
            $keywords = array_filter($keywords, function($word) {
                return !in_array($word, ['and', 'of', 'the', 'with', 'for', 'to']);
            });
            
            $curriculum[] = [
                'name' => $subject['name'],
                'keywords' => array_unique($keywords)
            ];
        }
        
    } catch (PDOException $e) {
        error_log("Error fetching curriculum: " . $e->getMessage());
        // Fallback to empty curriculum
        $curriculum = [];
    }
    
    $passed = [];
    foreach ($curriculum as $subject) {
        foreach ($documents as $doc) {
            $filename = strtolower($doc['original_filename']);
            $desc = strtolower($doc['description'] ?? '');
            
            foreach ($subject['keywords'] as $keyword) {
                if (strpos($filename, $keyword) !== false || strpos($desc, $keyword) !== false) {
                    $evidence = [];
                    if (strpos($filename, 'transcript') !== false || strpos($filename, 'tor') !== false) {
                        $evidence[] = 'TOR';
                    }
                    if (strpos($filename, 'certificate') !== false || strpos($filename, 'cert') !== false) {
                        $evidence[] = 'Certificate';
                    }
                    if (strpos($filename, 'diploma') !== false) {
                        $evidence[] = 'Diploma';
                    }
                    if (!$evidence) $evidence[] = pathinfo($doc['original_filename'], PATHINFO_EXTENSION);
                    
                    $passed[$subject['name']] = implode(', ', $evidence);
                    break 2;
                }
            }
        }
    }
    
    return ['curriculum' => $curriculum, 'passed' => $passed];
}



// Add this function near the top of your evaluate.php file, after the predefined_subjects array
function getFilteredSubjects($programCode, $predefined_subjects) {
    // Since we're already filtering by program_id in the database query,
    // just return all subjects
    return $predefined_subjects;
}

function generateEnhancedRecommendation($score, $programCode, $status, $criteriaMissing = [], $passedSubjects = [], $curriculumSubjects = [], $program_id = null) {
    $recommendations = [];
    $bridgingUnits = calculateBridgingUnits($score);
    $subjectPlan = getSubjectRecommendations($programCode, $bridgingUnits, $program_id);
    
    // Get bridging subject names
    $bridgingSubjectNames = array_column($subjectPlan['subjects'], 'name');
    
    // Header with assessment outcome
    $recommendations[] = "**ETEEAP ASSESSMENT RESULTS**";
    $recommendations[] = "Program: {$programCode}";
    $recommendations[] = "Final Assessment Score: {$score}%";
    $recommendations[] = "---";
    
    switch($status) {
        case 'qualified':
            $recommendations[] = "**ASSESSMENT OUTCOME: QUALIFIED FOR ETEEAP CREDIT**";
            $recommendations[] = "";
            $recommendations[] = "Congratulations! Your professional experience and competencies demonstrate substantial equivalency to formal academic study. Based on our comprehensive evaluation, you have successfully qualified for the Expanded Tertiary Education Equivalency and Accreditation Program (ETEEAP).";
            
            // === CREDITED SUBJECTS (Passed/Recognized) ===
            if (!empty($passedSubjects) && !empty($curriculumSubjects)) {
                $recommendations[] = "";
                $recommendations[] = "**═══════════════════════════════════════════**";
                $recommendations[] = "**CREDITED SUBJECTS - PRIOR LEARNING RECOGNITION**";
                $recommendations[] = "**═══════════════════════════════════════════**";
                $recommendations[] = "";
                $recommendations[] = "The following subjects have been CREDITED based on your demonstrated competencies and uploaded evidence:";
                $recommendations[] = "";
                
                $creditedCount = 0;
                foreach ($curriculumSubjects as $subject) {
                    // Only show subjects NOT in bridging requirements (i.e., passed/credited)
                    if (!in_array($subject['name'], $bridgingSubjectNames)) {
                        $creditedCount++;
                        $evidenceNote = isset($passedSubjects[$subject['name']]) 
                            ? $passedSubjects[$subject['name']] 
                            : 'Credit via ETEEAP assessment';
                        $recommendations[] = "✓ {$subject['name']}";
                        $recommendations[] = "   Evidence: {$evidenceNote}";
                        $recommendations[] = "";
                    }
                }
                
                $recommendations[] = "**Summary:** {$creditedCount} subjects credited through prior learning assessment";
            }
            
            // === REQUIRED BRIDGING SUBJECTS ===
            if ($bridgingUnits > 0) {
                $recommendations[] = "";
                $recommendations[] = "**═══════════════════════════════════════════**";
                $recommendations[] = "**REQUIRED BRIDGING COURSES**";
                $recommendations[] = "**═══════════════════════════════════════════**";
                $recommendations[] = "";
                $recommendations[] = "To complete your degree, you must fulfill {$bridgingUnits} units of bridging courses:";
                $recommendations[] = "";
                
                foreach ($subjectPlan['subjects'] as $index => $subject) {
                    $priorityLabel = $subject['priority'] === 1 ? '[REQUIRED - HIGH PRIORITY]' : '[REQUIRED - STANDARD]';
                    $recommendations[] = ($index + 1) . ". {$subject['name']} ({$subject['code']})";
                    $recommendations[] = "   Units: {$subject['units']} | Priority: {$priorityLabel}";
                    $recommendations[] = "";
                }
                
                $recommendations[] = "**Total Bridging Units Required:** {$bridgingUnits} units";
                
                if ($subjectPlan['remaining_units'] > 0) {
                    $recommendations[] = "";
                    $recommendations[] = "Note: Additional {$subjectPlan['remaining_units']} units of elective courses may be determined during enrollment counseling.";
                }
                
                $recommendations[] = "";
                $recommendations[] = "**PROGRAM COMPLETION TIMELINE**";
                $recommendations[] = "• Credited Subjects: " . (count($curriculumSubjects) - count($bridgingSubjectNames)) . " subjects"; 
                $recommendations[] = "• Bridging Requirements: " . count($subjectPlan['subjects']) . " subjects ({$bridgingUnits} units)";
                $recommendations[] = "• Estimated Completion: 1-2 semesters (depending on subject availability)";
            } else {
                $recommendations[] = "";
                $recommendations[] = "**OUTSTANDING ACHIEVEMENT**";
                $recommendations[] = "Your exceptional assessment score qualifies you for maximum credit recognition with minimal additional coursework requirements.";
            }
            
            $recommendations[] = "";
            $recommendations[] = "**NEXT STEPS**";
            $recommendations[] = "1. Our Admissions Office will contact you within 3-5 business days";
            $recommendations[] = "2. Schedule an academic counseling session to finalize your study plan";
            $recommendations[] = "3. Complete enrollment requirements for bridging courses";
            $recommendations[] = "4. Begin your accelerated path to degree completion";
            break;
            
        case 'partially_qualified':
            $recommendations[] = "**ASSESSMENT OUTCOME: PARTIAL QUALIFICATION**";
            $recommendations[] = "";
            $recommendations[] = "Your assessment score of {$score}% demonstrates significant competencies in several areas. You have achieved partial qualification status under the ETEEAP program.";
            
            // === CREDITED SUBJECTS (Passed) ===
            if (!empty($passedSubjects) && !empty($curriculumSubjects)) {
                $recommendations[] = "";
                $recommendations[] = "**═══════════════════════════════════════════**";
                $recommendations[] = "**CREDITED SUBJECTS - RECOGNIZED COMPETENCIES**";
                $recommendations[] = "**═══════════════════════════════════════════**";
                $recommendations[] = "";
                
                $creditedCount = 0;
                foreach ($curriculumSubjects as $subject) {
                    if (!in_array($subject['name'], $bridgingSubjectNames)) {
                        $creditedCount++;
                        $evidenceNote = isset($passedSubjects[$subject['name']]) 
                            ? $passedSubjects[$subject['name']] 
                            : 'Credit via ETEEAP assessment';
                        $recommendations[] = "✓ {$subject['name']} — {$evidenceNote}";
                    }
                }
                
                $recommendations[] = "";
                $recommendations[] = "**Credited:** {$creditedCount} subjects recognized";
            }
            
            // === REQUIRED BRIDGING SUBJECTS ===
            if ($bridgingUnits > 0) {
                $recommendations[] = "";
                $recommendations[] = "**═══════════════════════════════════════════**";
                $recommendations[] = "**REQUIRED BRIDGING PROGRAM ({$bridgingUnits} units)**";
                $recommendations[] = "**═══════════════════════════════════════════**";
                $recommendations[] = "";
                $recommendations[] = "To achieve full qualification, you must complete:";
                $recommendations[] = "";
                
                foreach ($subjectPlan['subjects'] as $index => $subject) {
                    $priorityLabel = $subject['priority'] === 1 ? '[PRIORITY]' : '[STANDARD]';
                    $recommendations[] = ($index + 1) . ". {$subject['name']} ({$subject['code']}) — {$subject['units']} units {$priorityLabel}";
                }
                
                if ($subjectPlan['remaining_units'] > 0) {
                    $recommendations[] = "";
                    $recommendations[] = "Plus {$subjectPlan['remaining_units']} units of remedial coursework to be determined.";
                }
                
                $recommendations[] = "";
                $recommendations[] = "**PATHWAY TO COMPLETION**";
                $recommendations[] = "• Complete all required bridging courses";
                $recommendations[] = "• Estimated timeline: 2-3 semesters";
                $recommendations[] = "• Upon successful completion → Full degree qualification";
            }
            
            if (!empty($criteriaMissing)) {
                $recommendations[] = "";
                $recommendations[] = "**AREAS FOR DEVELOPMENT**";
                $recommendations[] = "Focus on strengthening competencies in:";
                foreach (array_slice($criteriaMissing, 0, 3) as $criteria) {
                    $recommendations[] = "• {$criteria['name']}";
                }
            }
            
            $recommendations[] = "";
            $recommendations[] = "**NEXT STEPS**";
            $recommendations[] = "1. Review your personalized bridging program requirements";
            $recommendations[] = "2. Schedule academic advising to create your study plan";
            $recommendations[] = "3. Enroll in bridging courses for the upcoming semester";
            break;
            
        case 'not_qualified':
            $recommendations[] = "**ASSESSMENT OUTCOME: FURTHER PREPARATION RECOMMENDED**";
            $recommendations[] = "";
            $recommendations[] = "Based on your assessment score of {$score}%, we recommend additional preparation before pursuing ETEEAP credit recognition.";
            
            // Show any recognized competencies
            if (!empty($passedSubjects)) {
                $recommendations[] = "";
                $recommendations[] = "**RECOGNIZED STRENGTHS**";
                $passedCount = count($passedSubjects);
                if ($passedCount > 0) {
                    $recommendations[] = "You demonstrated competencies in {$passedCount} area(s):";
                    $recommendations[] = "";
                    foreach (array_slice($passedSubjects, 0, 5, true) as $subject => $evidence) {
                        $recommendations[] = "• {$subject} (Evidence: {$evidence})";
                    }
                }
            }
            
            $recommendations[] = "";
            $recommendations[] = "**RECOMMENDED PATHWAYS**";
            $recommendations[] = "";
            $recommendations[] = "**Option 1: Regular Degree Program** (Recommended)";
            $recommendations[] = "• Complete the full academic curriculum";
            $recommendations[] = "• Build comprehensive foundational knowledge";
            $recommendations[] = "";
            $recommendations[] = "**Option 2: Professional Development Track**";
            $recommendations[] = "• Targeted skill-building program";
            $recommendations[] = "• Industry certifications";
            $recommendations[] = "• Re-apply for ETEEAP after 1-2 years";
            
            if (!empty($criteriaMissing)) {
                $recommendations[] = "";
                $recommendations[] = "**PRIORITY DEVELOPMENT AREAS**";
                foreach (array_slice($criteriaMissing, 0, 5) as $criteria) {
                    $recommendations[] = "• {$criteria['name']}";
                }
            }
            
            $recommendations[] = "";
            $recommendations[] = "**SUPPORT AVAILABLE**";
            $recommendations[] = "Schedule a consultation with our academic advisors to create a personalized development plan.";
            break;
    }
    

    
    if (!empty($curriculumSubjects)) {
        $totalSubjects = count($curriculumSubjects);
        $creditedSubjects = $totalSubjects - count($bridgingSubjectNames);
        $requiredSubjects = count($bridgingSubjectNames);
        
        $recommendations[] = "• Total Curriculum Subjects: {$totalSubjects}";
        $recommendations[] = "• Credited (Passed): {$creditedSubjects} subjects";
        $recommendations[] = "• Required (Bridging): {$requiredSubjects} subjects ({$bridgingUnits} units)";
        $recommendations[] = "• Completion Rate: " . round(($creditedSubjects / $totalSubjects) * 100, 1) . "%";
    }
    
    $recommendations[] = "";
    $recommendations[] = "For questions or appointments:";
  
    
    return implode("\n", $recommendations);
}

function getSubjectRecommendations($programCode, $requiredUnits, $program_id) {
    global $pdo;
    
    if (empty($program_id)) {
        return ['subjects' => [], 'total_units' => 0, 'remaining_units' => $requiredUnits];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT subject_name as name, subject_code as code, units
            FROM subjects 
            WHERE program_id = ? AND status = 'active'
            ORDER BY year_level DESC, semester DESC, subject_name
        ");
        $stmt->execute([$program_id]);
        $availableSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $selectedSubjects = [];
        $totalUnits = 0;
        
        foreach ($availableSubjects as $subject) {
            $units = (int)$subject['units'];
            if ($totalUnits + $units <= $requiredUnits) {
                $selectedSubjects[] = [
                    'name' => $subject['name'],
                    'code' => $subject['code'],
                    'units' => $units,
                    'priority' => 1
                ];
                $totalUnits += $units;
            }
            if ($totalUnits >= $requiredUnits) break;
        }
        
        $remaining = $requiredUnits - $totalUnits;
        if ($remaining > 0 && !empty($selectedSubjects)) {
            $selectedSubjects[count($selectedSubjects) - 1]['units'] += $remaining;
            $totalUnits = $requiredUnits;
        }
        
        return [
            'subjects' => $selectedSubjects,
            'total_units' => $totalUnits,
            'remaining_units' => max(0, $requiredUnits - $totalUnits)
        ];
        
    } catch (PDOException $e) {
        error_log("Error in getSubjectRecommendations: " . $e->getMessage());
        return ['subjects' => [], 'total_units' => 0, 'remaining_units' => $requiredUnits];
    }
}

function parse_hier($doc) {
    // 1) JSON column
    if (!empty($doc['hierarchical_data'])) {
        $h = json_decode($doc['hierarchical_data'], true);
        if (is_array($h)) return $h;
    }
    // 2) Fallback: hinango mula sa description
    if (!empty($doc['description']) && strpos($doc['description'], 'Hierarchical Data:') !== false) {
        $parts = explode('Hierarchical Data:', $doc['description']);
        $json  = trim($parts[1] ?? '');
        $h = json_decode($json, true);
        if (is_array($h)) return $h;
    }
    return [];
}

function render_hier_badges(array $hier) {
    if (!$hier) return '';

    // Value → friendly label
    $valueLabel = [
        // common levels
        'local'=>'Local','national'=>'National','international'=>'International',
        // edu (Sec 1)
        'high_school'=>'High School','vocational'=>'Vocational','technical'=>'Technical',
        'undergraduate'=>'Undergraduate','non_education'=>'Non-Education',
        'full'=>'Full','partial'=>'Partial','none'=>'None',
        // roles / exp (Sec 2)
        'administrator'=>'Administrator','supervisor'=>'Training Supervisor','trainer'=>'Trainer/Lecturer',
        'sunday_school'=>'Sunday School Tutor','daycare'=>'Day Care Tutor',
        // eligibility (Sec 5)
        'cs_sub_professional'=>'CS Sub-Professional','cs_professional'=>'CS Professional','prc'=>'PRC',
        // pubs/types (Sec 3)
        'journal'=>'Journal','training_module'=>'Training Module','book'=>'Book',
        'teaching_module'=>'Teaching Module','workbook'=>'Workbook','reading_kit'=>'Reading Kit',
        'literacy_outreach'=>'Early Literacy/Numeracy Outreach',
        // inventions
        'invention'=>'Invention','innovation'=>'Innovation','patented'=>'Patented','no_patent'=>'No Patent',
    ];
    // Field → label
    $fieldLabel = [
        'publication_type'=>'Type','patent_status'=>'Patent','invention_type'=>'Invention',
        'circulation_level'=>'Circulation','circulation_levels'=>'Circulation',
        'acceptability_levels'=>'Market','service_levels'=>'Service',
        'education_level'=>'Education','scholarship_type'=>'Scholarship',
        'years_experience'=>'Years','experience_role'=>'Role',
        'coordination_level'=>'Coordination','participation_level'=>'Participation',
        'membership_level'=>'Membership','scholarship_level'=>'Scholarship',
        'recognition_level'=>'Recognition','eligibility_type'=>'Eligibility',
    ];
    // special phrasing para sa literacy outreach
    $litPhrases = ['local'=>'Local Community','national'=>'National Program','international'=>'International Initiative'];
    $isLiteracy = (isset($hier['publication_type']) && $hier['publication_type']==='literacy_outreach');

    $chips = [];

    // “type-ish” fields muna
    foreach (['publication_type','invention_type','patent_status'] as $k) {
        if (!empty($hier[$k])) {
            $v = $hier[$k];
            $pretty = $valueLabel[$v] ?? ucwords(str_replace('_',' ',$v));
            $chips[] = "<span class='badge bg-secondary me-1'>{$fieldLabel[$k]}: ".htmlspecialchars($pretty)."</span>";
        }
    }

    // single-selects
    foreach (['circulation_level','education_level','experience_role','coordination_level','participation_level','membership_level','scholarship_level','recognition_level','eligibility_type','scholarship_type'] as $k) {
        if (!empty($hier[$k])) {
            $v = $hier[$k];
            $pretty = $valueLabel[$v] ?? ucwords(str_replace('_',' ',$v));
            if ($k==='circulation_level') {
                $pretty = $isLiteracy ? ($litPhrases[$v] ?? ucfirst($v)) : ($pretty.' Circulation');
            }
            $chips[] = "<span class='badge bg-info text-dark me-1'>{$fieldLabel[$k]}: ".htmlspecialchars($pretty)."</span>";
        }
    }

    // multi-select arrays
    foreach (['circulation_levels'=>'Circulation','acceptability_levels'=>'Market','service_levels'=>'Service'] as $k=>$lbl) {
        if (!empty($hier[$k]) && is_array($hier[$k])) {
            $vals = array_map(function($v) use($isLiteracy,$litPhrases,$valueLabel,$k){
                if ($k==='circulation_levels' && $isLiteracy) return $litPhrases[$v] ?? ucfirst($v);
                return $valueLabel[$v] ?? ucwords(str_replace('_',' ',$v));
            }, $hier[$k]);
            $chips[] = "<span class='badge bg-primary me-1'>".$lbl.": ".htmlspecialchars(implode(', ',$vals))."</span>";
        }
    }

    // numbers
    if (!empty($hier['years_experience']) && is_numeric($hier['years_experience'])) {
        $chips[] = "<span class='badge bg-light text-dark border me-1'>Years: ".intval($hier['years_experience'])."</span>";
    }

    return '<div class="mt-1 small">'.implode(' ',$chips).'</div>';
}



if (!function_exists('doc_matches_criteria')) {
    function doc_matches_criteria(array $doc, array $criteria): bool {
        // 1) direct link
        if ((int)$doc['criteria_id'] === (int)$criteria['id']) return true;

        // 2) via hierarchical_data badges
        $h     = parse_hier($doc);
        $cname = strtolower($criteria['criteria_name'] ?? '');
        $ctype = strtolower($criteria['criteria_type'] ?? '');

        // publications / modules / books
        if (strpos($cname,'journal') !== false) {
            return ($h['publication_type'] ?? '') === 'journal';
        }
        if (strpos($cname,'training module') !== false || strpos($cname,'training modules') !== false) {
            return in_array($h['publication_type'] ?? '', ['training_module','teaching_module'], true);
        }
        if (strpos($cname,'book') !== false || strpos($cname,'workbook') !== false || strpos($cname,'lab manual') !== false) {
            return in_array($h['publication_type'] ?? '', ['book','workbook'], true);
        }

        // resource speaker / lecturer
        if (strpos($cname,'resource speaker') !== false || strpos($cname,'lecturer') !== false || strpos($cname,'speaker') !== false) {
            return !empty($h['service_levels']) || !empty($h['service_level']);
        }

        // program coordination / participation
        if (strpos($cname,'program coordination') !== false) {
            return !empty($h['coordination_level']);
        }
        if (strpos($cname,'participation') !== false) {
            return !empty($h['participation_level']);
        }

        // scholarships/grants
        if (strpos($cname,'scholarship') !== false || strpos($cname,'grant') !== false) {
            return !empty($h['scholarship_level']) || !empty($h['scholarship_type']);
        }

        // 3) fallback sa dating filename heuristic
        if (empty($doc['criteria_id']) && stripos($doc['original_filename'] ?? '', $ctype) !== false) {
            return true;
        }
        return false;
    }
}

// === Auto-suggest scoring helpers ==========================================

// Gumamit ng existing parse_hier($doc)
function score_one_doc(array $criteria, array $hier): array {
    $max     = (float)$criteria['max_score'];
    $name    = $criteria['criteria_name'] ?? '';
    $section = (int)($criteria['section_number'] ?? 0);

    $score = 0.0;
    $why   = [];

    // --- Sec 1: Education ---------------------------------------------------
    if ($section === 1) {
        $eduPts = [
            'high_school'=>2,'vocational'=>3,'technical'=>4,'undergraduate'=>5,'non_education'=>6
        ];
        if (!empty($hier['education_level']) && isset($eduPts[$hier['education_level']])) {
            $add = $eduPts[$hier['education_level']];
            $score += $add;  $why[] = "Education: {$hier['education_level']} (+{$add})";
        }
        if (!empty($hier['scholarship_type']) && $hier['scholarship_type'] !== 'none') {
            $bonus = $hier['scholarship_type']==='full' ? 2 : 1;
            $score += $bonus; $why[] = "Scholarship: {$hier['scholarship_type']} (+{$bonus})";
        }
    }

    // --- Sec 2: Work Experience (simple baseline) ---------------------------
    if ($section === 2) {
        $rolePts = [
            'administrator'=>5,'supervisor'=>3,'trainer'=>2,'sunday_school'=>1,'daycare'=>1
        ];
        $yrs  = (int)($hier['years_experience'] ?? 0);
        $role = $hier['experience_role'] ?? null;
        if ($yrs >= 5 && $role && isset($rolePts[$role])) {
            $add = $rolePts[$role];
            $score += $add; $why[] = "Experience: {$yrs} yrs, role {$role} (+{$add})";
        }
    }

    // --- Sec 3: Publications / Inventions ----------------------------------
    // Invention / Innovation
    if (stripos($name,'Invention')!==false || stripos($name,'Innovation')!==false) {
        $isInvention = (isset($hier['invention_type']) && $hier['invention_type']==='invention')
                       || stripos($name,'Invention')!==false;
        if (!empty($hier['patent_status'])) {
            $base = ($hier['patent_status']==='patented') ? ($isInvention?6:1) : ($isInvention?5:2);
            $score += $base; $why[] = "Patent: {$hier['patent_status']} (+{$base})";
        }
        // acceptability_levels: local/national/international (array)
        $sum = 0;
        foreach ((array)($hier['acceptability_levels'] ?? []) as $lvl) {
            $sum += $isInvention ? (['local'=>7,'national'=>8,'international'=>9][$lvl] ?? 0)
                                 : (['local'=>4,'national'=>5,'international'=>6][$lvl] ?? 0);
        }
        if ($sum>0) { $score += $sum; $why[] = "Market: ".implode(', ', (array)$hier['acceptability_levels'])." (+{$sum})"; }
    }

    // Publications / Modules / Books / Literacy outreach
    if (stripos($name,'Journal')!==false || stripos($name,'Training Module')!==false ||
        stripos($name,'Book')!==false   || stripos($name,'Teaching Modules')!==false ||
        stripos($name,'Workbooks')!==false || stripos($name,'Reading Kits')!==false ||
        stripos($name,'Early Literacy')!==false) {

        $ptype = $hier['publication_type'] ?? 'journal';
        $tbl = [
            'journal'          => ['local'=>2,'national'=>3,'international'=>4],
            'training_module'  => ['local'=>3,'national'=>4,'international'=>5],
            'book'             => ['local'=>5,'national'=>6,'international'=>7],
            'teaching_module'  => ['local'=>3,'national'=>4,'international'=>5],
            'workbook'         => ['local'=>2,'national'=>3,'international'=>4],
            'reading_kit'      => ['local'=>2,'national'=>3,'international'=>4],
            'literacy_outreach'=> ['local'=>4,'national'=>5,'international'=>6],
        ];
        $lvl = $hier['circulation_level'] ?? null;
        $levelsArr = $hier['circulation_levels'] ?? [];

        if ($lvl && isset($tbl[$ptype][$lvl])) {
            $add = $tbl[$ptype][$lvl];
            $score += $add; $why[] = ucfirst(str_replace('_',' ',$ptype)).": {$lvl} (+{$add})";
        } elseif (is_array($levelsArr) && $levelsArr) {
            $sum=0; $hit=[];
            foreach ($levelsArr as $lv) { if (isset($tbl[$ptype][$lv])) { $sum+=$tbl[$ptype][$lv]; $hit[]=$lv; } }
            if ($sum>0) { $score += $sum; $why[] = ucfirst(str_replace('_',' ',$ptype)).": ".implode(', ',$hit)." (+{$sum})"; }
        }
    }

    // --- Sec 4: Professional Development (quick map) ------------------------
    if ($section === 4) {
        if (!empty($hier['coordination_level'])) {
            $add = ['local'=>6,'national'=>8,'international'=>10][$hier['coordination_level']] ?? 0;
            $score += $add; $why[] = "Coordination: {$hier['coordination_level']} (+{$add})";
        }
        if (!empty($hier['participation_level'])) {
            $add = ['local'=>3,'national'=>4,'international'=>5][$hier['participation_level']] ?? 0;
            $score += $add; $why[] = "Participation: {$hier['participation_level']} (+{$add})";
        }
        if (!empty($hier['membership_level'])) {
            $add = ['local'=>3,'national'=>4,'international'=>5][$hier['membership_level']] ?? 0;
            $score += $add; $why[] = "Membership: {$hier['membership_level']} (+{$add})";
        }
        if (!empty($hier['scholarship_level'])) {
        $lvl = $hier['scholarship_level'];
        if (is_numeric($lvl)) {
            $add = (float)$lvl;  // e.g., "5" -> 5 pts
        } else {
            $map = ['local'=>3,'national'=>4,'international'=>5];
            $add = $map[strtolower($lvl)] ?? 0;
        }
        if ($add > 0) { $score += $add; $why[] = "Scholarship level: {$lvl} (+{$add})"; }
    }
    // optional bonus for competitive/non-competitive type
    if (!empty($hier['scholarship_type'])) {
        $bonusMap = ['full'=>1.0, 'partial'=>0.5];
        $stype = strtolower($hier['scholarship_type']);
        if (isset($bonusMap[$stype])) {
            $score += $bonusMap[$stype];
            $why[] = "Scholarship type: {$stype} (+{$bonusMap[$stype]})";
        }
    }
    }

    if (stripos($name, 'program coordination') !== false && !empty($hier['coordination_level'])) {
    $lvl = strtolower($hier['coordination_level']);
    // scale to max_score: local 60%, national 80%, international 100%
    $scale = ['local'=>0.60, 'national'=>0.80, 'international'=>1.00];
    if (isset($scale[$lvl])) {
        $add = round($max * $scale[$lvl], 2);
        $score += $add;
        $why[] = "Coordination: {$lvl} (+{$add})";
    }
}

// Resource Speaker / Trainer (Local/National/International)
if ((stripos($name, 'resource speaker') !== false || stripos($name, 'trainer') !== false)
    && (!empty($hier['service_levels']) || !empty($hier['service_level']))) {

    $levels = [];
    if (!empty($hier['service_levels']) && is_array($hier['service_levels'])) {
        $levels = $hier['service_levels'];
    } elseif (!empty($hier['service_level'])) {
        $levels = [$hier['service_level']];
    }
    // pick the highest level present
    $rank = ['local'=>1, 'national'=>2, 'international'=>3];
    $best = 'local';
    foreach ($levels as $lv) {
        $lv = strtolower($lv);
        if (isset($rank[$lv]) && $rank[$lv] > $rank[$best]) $best = $lv;
    }
    $scale = ['local'=>0.60, 'national'=>0.80, 'international'=>1.00];
    $add   = round($max * ($scale[$best] ?? 0), 2);
    if ($add > 0) {
        $score += $add;
        $why[] = "Service: {$best} (+{$add})";
    }
}


    $lvls = [];
    if (!empty($hier['service_levels']) && is_array($hier['service_levels'])) $lvls = $hier['service_levels'];
    if (!empty($hier['service_level'])) $lvls[] = $hier['service_level'];

    if ($lvls) {
        // Default map for community/lecturer/speaker
        $mapDefault  = ['local'=>3,'national'=>4,'international'=>5];
        // Heavier map for consultancy (mas mataas ang max sa criteria na ito)
        $mapConsult  = ['local'=>5,'national'=>10,'international'=>15];

        $use = (stripos($name,'consultancy') !== false) ? $mapConsult : $mapDefault;

        // piliin ang pinakamataas na level na present
        $got = array_intersect_key($use, array_flip($lvls));
        $add = $got ? max($got) : 0;

        if ($add > 0) {
            $score += $add;
            $why[] = "Service scope: ".implode(', ', $lvls)." (+{$add})";
        }
    }


    // --- Sec 5: Recognition / Eligibility -----------------------------------
    if ($section === 5) {
        if (!empty($hier['recognition_level'])) {
            $add = ['local'=>6,'national'=>8][$hier['recognition_level']] ?? 0;
            $score += $add; $why[] = "Recognition: {$hier['recognition_level']} (+{$add})";
        }
        if (!empty($hier['eligibility_type'])) {
            $add = ['cs_sub_professional'=>3,'cs_professional'=>4,'prc'=>5][$hier['eligibility_type']] ?? 0;
            $score += $add; $why[] = "Eligibility: {$hier['eligibility_type']} (+{$add})";
        }
    }

    // Cap sa max ng criteria
    $final = round(min($score, $max), 2);
    return [$final, $why ? implode('; ', $why) : 'No detailed selections found'];
}

/**
 * Piliin ang BEST document para iwas double-counting.
 * (Pwede mo itong palitan sa pagsum ng lahat: palitan mo lang ang logic dito.)
 */
function suggest_score_from_docs(array $criteria, array $criteriaDocs): array {
    $best = 0.0; $bestWhy = 'No documents';
    foreach ($criteriaDocs as $doc) {
        $hier = parse_hier($doc);
        if (!$hier) continue;
        [$s, $w] = score_one_doc($criteria, $hier);
        if ($s > $best) { $best = $s; $bestWhy = $w; }
    }
    return [$best, $bestWhy];
}


if ($_POST && isset($_POST['submit_evaluation'])) {
    $app_id = $_POST['application_id'];
    $submitted = $_POST['evaluations'] ?? [];
    $additional_comments = trim($_POST['recommendation']);
    $manual_override = $_POST['final_status'] ?? 'auto';

    // Huwag i-block kapag empty ang $submitted — pwede kasing lahat ay 0.
    // if (empty($submitted)) { ... }  // alisin ito kung meron ka

    try {
        $pdo->beginTransaction();

        // 1) Kunin muna ang program_id ng application
        $stmt = $pdo->prepare("SELECT program_id FROM applications WHERE id = ?");
        $stmt->execute([$app_id]);
        $appRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$appRow) {
            throw new Exception("Application not found.");
        }
        $program_id = (int)$appRow['program_id'];

        // 2) Kunin lahat ng ACTIVE criteria ng program (ito ang magiging denominator mo)
        $stmt = $pdo->prepare("
            SELECT id, criteria_name, max_score, weight
            FROM assessment_criteria
            WHERE program_id = ? AND status = 'active'
            ORDER BY section_number, criteria_type, criteria_name
        ");
        $stmt->execute([$program_id]);
        $allCriteria = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Safety: kapag wala talagang criteria, iwasan division by zero
        if (!$allCriteria) {
            throw new Exception("No active assessment criteria found for this program.");
        }

        // 3) Kunin lahat ng documents ng application (gagamitin sa doc-check)
        $stmt = $pdo->prepare("
            SELECT id, criteria_id, original_filename
            FROM documents
            WHERE application_id = ?
        ");
        $stmt->execute([$app_id]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- START: TRUE weighted average across ALL criteria ---
        $total_score = 0;
        $total_weight = 0;
        $passing_threshold = 60;
        $criteriaMissing = [];

        foreach ($allCriteria as $c) {
            $criteria_id   = (int)$c['id'];
            $max_score     = (float)$c['max_score'];
            $weight        = (float)$c['weight'];
            $criteria_name = $c['criteria_name'];

            // values mula sa form; default sa 0/blank kung walang na-post
            $input    = $submitted[$criteria_id] ?? ['score' => 0, 'comments' => ''];
            $score    = (float)($input['score'] ?? 0);
            $comments = trim($input['comments'] ?? '');

            // check kung may doc para sa criteria na ito
           // Instead of the simple filter:
$criteriaDocs = array_filter($documents, function($doc) use ($criteria_id) {
    return (int)$doc['criteria_id'] === $criteria_id
        || (empty($doc['criteria_id']) && stripos($doc['original_filename'], 'criteria_' . $criteria_id) !== false);
});

// Use the same matcher as the UI:
$criteriaDocs = array_values(array_filter($documents, function($doc) use ($c) {
    return doc_matches_criteria($doc, $c);
}));
$hasCriteriaDocs = count($criteriaDocs) > 0;


            // kung walang doc, force 0 at lagyan ng note
            if (!$hasCriteriaDocs) {
                $score    = 0;
                $comments = "No supporting documents uploaded for this criteria. " . $comments;
            }

            // clamp sa [0, max]
            if ($score < 0) $score = 0;
            if ($score > $max_score) $score = $max_score;

            // ALWAYS include weight sa denominator, kahit 0 ang score
            $percentage     = ($max_score > 0) ? ($score / $max_score) * 100 : 0;
            $weighted_score = $percentage * $weight;

            $total_score  += $weighted_score;
            $total_weight += $weight;

            if ($score == 0) {
                $criteriaMissing[] = ['name' => $criteria_name];
            }

            // save/update evaluation (kasama ang zeros)
            $stmtSave = $pdo->prepare("
                INSERT INTO evaluations (application_id, criteria_id, evaluator_id, score, max_score, comments, evaluation_date)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    score = VALUES(score),
                    comments = VALUES(comments),
                    evaluation_date = VALUES(evaluation_date)
            ");
            $stmtSave->execute([$app_id, $criteria_id, $user_id, $score, $max_score, $comments]);
        }

        $final_score = $total_weight > 0 ? round($total_score / $total_weight, 2) : 0;
        // --- END: TRUE weighted average ---

        // Status
        if ($manual_override === 'auto') {
            if ($final_score >= $passing_threshold) {
                $final_status = 'qualified';
            } elseif ($final_score >= ($passing_threshold * 0.8)) {
                $final_status = 'partially_qualified';
            } else {
                $final_status = 'not_qualified';
            }
        } else {
            $final_status = $manual_override;
        }

        // Program code para sa rekomendasyon
        $stmt = $pdo->prepare("SELECT program_code FROM programs p JOIN applications a ON p.id = a.program_id WHERE a.id = ?");
        $stmt->execute([$app_id]);
        $programInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        $programCode = $programInfo['program_code'] ?? '';

// Get curriculum and passed subjects
$curriculumStatus = getPassedSubjects($documents, $programCode);
$curriculumSubjects = $curriculumStatus['curriculum'];
$passedSubjects = $curriculumStatus['passed'];

       $auto_recommendation = generateEnhancedRecommendation(
    $final_score, 
    $programCode, 
    $final_status, 
    $criteriaMissing,
    $passedSubjects,        // Add this
    $curriculumSubjects,     // Add this.
     $program_id 
);
        $full_recommendation = !empty($additional_comments)
            ? $auto_recommendation . "\n\n=== Additional Evaluator Comments ===\n" . $additional_comments
            : $auto_recommendation;



        // Update application
        $stmt = $pdo->prepare("
            UPDATE applications 
               SET application_status = ?, total_score = ?, recommendation = ?, 
                   evaluator_id = ?, evaluation_date = NOW()
             WHERE id = ?
        ");
        $stmt->execute([$final_status, $final_score, $full_recommendation, $user_id, $app_id]);
        
 if ($final_score >= 60) {
            $requiredUnits = calculateBridgingUnits($final_score);
            
            // Get program_id
            $stmt = $pdo->prepare("SELECT program_id FROM applications WHERE id = ?");
            $stmt->execute([$app_id]);
            $programRow = $stmt->fetch();
            $program_id = $programRow['program_id'];
            
            // Clear existing bridging requirements
            $stmt = $pdo->prepare("DELETE FROM bridging_requirements WHERE application_id = ?");
            $stmt->execute([$app_id]);
            
            // Get recommended subjects from database
            $stmt = $pdo->prepare("
                SELECT subject_name, subject_code, units
                FROM subjects 
                WHERE program_id = ? AND status = 'active'
                ORDER BY year_level DESC, semester DESC, subject_name
            ");
            $stmt->execute([$program_id]);
            $availableSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Select subjects to fill required units
            $totalUnits = 0;
            $selectedSubjects = [];
            
            foreach ($availableSubjects as $subject) {
                $units = (int)$subject['units'];
                if ($totalUnits + $units <= $requiredUnits) {
                    $selectedSubjects[] = [
                        'name' => $subject['subject_name'],
                        'code' => $subject['subject_code'],
                        'units' => $units,
                        'priority' => 1
                    ];
                    $totalUnits += $units;
                }
                if ($totalUnits >= $requiredUnits) break;
            }
            
            // Handle remaining units if needed
            $remaining = $requiredUnits - $totalUnits;
            if ($remaining > 0 && !empty($selectedSubjects)) {
                // Add remaining units to last subject
                $selectedSubjects[count($selectedSubjects) - 1]['units'] += $remaining;
            }
            
            // Insert bridging requirements into database
            foreach ($selectedSubjects as $subject) {
                $stmt = $pdo->prepare("
                    INSERT INTO bridging_requirements (application_id, subject_name, subject_code, units, priority, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $app_id,
                    $subject['name'],
                    $subject['code'],
                    $subject['units'],
                    $subject['priority'],
                    $user_id
                ]);
            }
        }

        $pdo->commit();

        $bridgingUnits = calculateBridgingUnits($final_score);
        $success_message = "Evaluation completed! Final Score: {$final_score}% | Status: " . ucfirst($final_status);
        if ($final_score >= $passing_threshold && $bridgingUnits > 0) {
            $success_message .= " | Bridging Units Required: {$bridgingUnits}";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = "Failed to save evaluation: " . $e->getMessage();
    }
}






// Get application data with enhanced document checking
$current_application = null;
$assessment_criteria = [];
$existing_evaluations = [];
$documents = [];
$hasDocs = false;
$docCounts = [];
$predefined_subjects = []; // Initialize empty

if ($application_id) {
    try {
        // 1. GET APPLICATION DETAILS FIRST
        $stmt = $pdo->prepare("
            SELECT a.*, p.program_name, p.program_code,
                CONCAT(u.first_name, ' ', u.last_name) as candidate_name,
                u.email as candidate_email, u.phone, u.address
            FROM applications a 
            LEFT JOIN programs p ON a.program_id = p.id 
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.id = ?
        ");
        $stmt->execute([$application_id]);
        $current_application = $stmt->fetch();
        
        if ($current_application) {
            // 2. NOW GET SUBJECTS FOR THIS PROGRAM (from database)
            $stmt = $pdo->prepare("
                SELECT 
                    subject_code as code, 
                    subject_name as name, 
                    units,
                    year_level,
                    semester
                FROM subjects 
                WHERE program_id = ? AND status = 'active'
                ORDER BY year_level DESC, semester DESC, subject_name
            ");
            $stmt->execute([$current_application['program_id']]);
            $predefined_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 3. GET ASSESSMENT CRITERIA
            $stmt = $pdo->prepare("
                SELECT * FROM assessment_criteria 
                WHERE program_id = ? AND status = 'active'
                ORDER BY section_number, criteria_type, criteria_name
            ");
            $stmt->execute([$current_application['program_id']]);
            $assessment_criteria = $stmt->fetchAll();
            
            // Get existing evaluations
            $stmt = $pdo->prepare("
                SELECT * FROM evaluations 
                WHERE application_id = ?
            ");
            $stmt->execute([$application_id]);
            $existing_evals = $stmt->fetchAll();
            foreach ($existing_evals as $eval) {
                $existing_evaluations[$eval['criteria_id']] = $eval;
            }
            
            // Enhanced document fetching with counts
            $stmt = $pdo->prepare("
                SELECT *, 
                       CASE 
                           WHEN LOWER(mime_type) LIKE '%pdf%' THEN 'pdf'
                           WHEN LOWER(mime_type) LIKE '%image%' THEN 'image'
                           WHEN LOWER(mime_type) LIKE '%word%' OR LOWER(mime_type) LIKE '%doc%' THEN 'document'
                           ELSE 'other'
                       END as file_category
                FROM documents 
                WHERE application_id = ? 
                ORDER BY document_type, upload_date DESC
            ");
            $stmt->execute([$application_id]);
            $documents = $stmt->fetchAll();
            
            // Set document flags and counts
            $hasDocs = count($documents) > 0;
            $docCounts = [
                'total' => count($documents),
                'by_type' => [],
                'by_category' => ['pdf' => 0, 'image' => 0, 'document' => 0, 'other' => 0]
            ];
            
            foreach ($documents as $doc) {
                $type = $doc['document_type'];
                $category = $doc['file_category'];
                
                $docCounts['by_type'][$type] = ($docCounts['by_type'][$type] ?? 0) + 1;
                $docCounts['by_category'][$category]++;
            }
        }
    } catch (PDOException $e) {
        $current_application = null;
    }
}

// Get all applications for listing
$applications = [];
$where_clause = "WHERE 1=1";
$params = [];

if ($filter_status) {
    $where_clause .= " AND a.application_status = ?";
    $params[] = $filter_status;
}

try {
    $stmt = $pdo->prepare("
        SELECT a.*, p.program_name, p.program_code,
            CONCAT(u.first_name, ' ', u.last_name) as candidate_name,
            u.email as candidate_email
        FROM applications a 
        LEFT JOIN programs p ON a.program_id = p.id 
        LEFT JOIN users u ON a.user_id = u.id
        $where_clause
        ORDER BY 
            CASE a.application_status
                WHEN 'submitted' THEN 1
                WHEN 'under_review' THEN 2
                ELSE 3
            END,
            a.submission_date DESC,
            a.created_at DESC
    ");
    $stmt->execute($params);
    $applications = $stmt->fetchAll();
} catch (PDOException $e) {
    $applications = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Evaluation System - ETEEAP</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            margin: 0; 
            padding-top: 0 !important;
        }
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
        .evaluation-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: none;
        }
        .criteria-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid #667eea;
        }
        .score-input {
            max-width: 80px;
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
        .bridging-preview { 
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border-radius: 10px;
            padding: 15px;
            margin-top: 10px;
            border-left: 4px solid #2196f3;
            margin-bottom: 15px;
        }
        .subject-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px dotted #ccc;
        }
        .priority-badge {
            background: #ff9800;
            color: white;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 10px;
            margin-left: 5px;
        }
        .table-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .score-calculator-pass { background-color: #d1e7dd !important; }
        .score-calculator-partial { background-color: #fff3cd !important; }
        .score-calculator-fail { background-color: #f8d7da !important; }
        .progress-bar-pass { background-color: #198754 !important; }
        .progress-bar-partial { background-color: #ffc107 !important; }
        .progress-bar-fail { background-color: #dc3545 !important; }
        
        /* Document viewer styles */
        .document-viewer {
            background: white;
            border-radius: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: none;
        }
        .doc-thumbnail {
            width: 60px;
            height: 60px;
            background: #f8f9fa;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #dee2e6;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .doc-thumbnail:hover {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .doc-thumbnail.pdf { background: #ff5722; color: white; }
        .doc-thumbnail.image { background: #4caf50; color: white; }
        .doc-thumbnail.document { background: #2196f3; color: white; }
        .doc-thumbnail.other { background: #9e9e9e; color: white; }
        .doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }
        .doc-item {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .doc-item:hover {
            border-color: #667eea;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .no-docs-alert {
            background: linear-gradient(135deg, #ffeaa7 0%, #fab1a0 100%);
            border: none;
            color: #d63031;
            border-radius: 10px;
        }
        .doc-count-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 5px 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge { border-radius: 999px; }
.bridging-management {
    background: linear-gradient(135deg, #e8f5e8 0%, #f0f8f0 100%);
    border-radius: 15px;
    padding: 20px;
    border-left: 4px solid #28a745;
}

.bridging-subject-item {
    background: white;
    transition: all 0.3s ease;
}

.bridging-subject-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.custom-subject-input {
    transition: all 0.3s ease;
}

#totalUnitsDisplay {
    font-size: 1rem;
    padding: 8px 12px;
}

.subject-selector option[data-category]::before {
    content: attr(data-category) ': ';
    font-weight: bold;
    color: #666;
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
    </a>

    <a class="nav-link" href="evaluate.php">
        <i class="fas fa-clipboard-check me-2"></i>
        Evaluate Applications
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
                            <span class="small"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
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
                    <?php if (!$current_application): ?>
                    <!-- Application Listing -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 class="mb-1">Enhanced Evaluation System</h2>
                            <p class="text-muted mb-0">Smart evaluation with automatic bridging unit calculation</p>
                        </div>
                        
                        <!-- Status Filter -->
                        <div class="dropdown">
                            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-filter me-1"></i>
                                Filter: <?php echo $filter_status ? ucfirst(str_replace('_', ' ', $filter_status)) : 'All'; ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="evaluate.php">All Applications</a></li>
                                <li><a class="dropdown-item" href="evaluate.php?status=submitted">Submitted</a></li>
                                <li><a class="dropdown-item" href="evaluate.php?status=under_review">Under Review</a></li>
                                <li><a class="dropdown-item" href="evaluate.php?status=qualified">Qualified</a></li>
                                <li><a class="dropdown-item" href="evaluate.php?status=partially_qualified">Partially Qualified</a></li>
                                <li><a class="dropdown-item" href="evaluate.php?status=not_qualified">Not Qualified</a></li>
                            </ul>
                        </div>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
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

                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Candidate</th>
                                        <th>Program</th>
                                        <th>Status</th>
                                        <th>Score</th>
                                        <th>Bridging Units</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($applications)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted">
                                            <i class="fas fa-inbox fa-3x mb-3"></i><br>
                                            No applications found
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($applications as $app): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($app['candidate_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($app['candidate_email']); ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($app['program_code']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($app['program_name']); ?></small>
                                        </td>
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
                                            <?php if ($app['total_score'] >= 60): ?>
                                                <span class="badge bg-info"><?php echo calculateBridgingUnits($app['total_score']); ?> units</span>
                                            <?php elseif ($app['total_score'] > 0 && $app['total_score'] < 60): ?>
                                                <span class="badge bg-warning">Regular Program</span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="evaluate.php?id=<?php echo $app['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-calculator me-1"></i>Evaluate
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php else: ?>
                    <!-- Individual Application Evaluation -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 class="mb-1">Smart Evaluation System</h2>
                            <p class="text-muted mb-0">Advanced assessment with bridging unit calculator</p>
                        </div>
                        <a href="evaluate.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back to List
                        </a>
                    </div>

                    <!-- Document Status Alert -->
                    <?php if (!$hasDocs): ?>
                    <div class="alert no-docs-alert mb-4">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                            <div>
                                <h5 class="mb-1">No Documents Uploaded</h5>
                                <p class="mb-0">This application has no supporting documents. All evaluation scores will default to 0 until documents are provided.</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Candidate Information -->
                    <div class="evaluation-card mb-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem; border-radius: 15px;">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h3 class="mb-2"><?php echo htmlspecialchars($current_application['candidate_name']); ?></h3>
                                <p class="mb-1">
                                    <i class="fas fa-envelope me-2"></i>
                                    <?php echo htmlspecialchars($current_application['candidate_email']); ?>
                                </p>
                                <p class="mb-3">
                                    <i class="fas fa-graduation-cap me-2"></i>
                                    <?php echo htmlspecialchars($current_application['program_name']); ?>
                                    <span class="badge bg-light text-dark ms-2"><?php echo htmlspecialchars($current_application['program_code']); ?></span>
                                </p>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <div class="mb-2">
                                    <span class="status-badge status-<?php echo $current_application['application_status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $current_application['application_status'])); ?>
                                    </span>
                                </div>
                                <div class="mb-2">
                                    <span class="doc-count-badge">
                                        <i class="fas fa-file-alt me-1"></i>
                                        <?php echo $docCounts['total']; ?> Documents
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <!-- Documents (Enhanced) -->
                        <div class="col-lg-4">
                            <div class="evaluation-card p-4">
                                <h5 class="mb-3">
                                    <i class="fas fa-folder-open me-2"></i>
                                    Documents (<?php echo $docCounts['total']; ?>)
                                </h5>
                                
                                <?php if (!$hasDocs): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-folder-open fa-3x mb-3" style="opacity: 0.3;"></i>
                                    <p class="mb-0">No documents uploaded</p>
                                    <small>Evaluation scores will default to 0</small>
                                </div>
                                <?php else: ?>
                                <!-- Document type summary -->
                                <div class="mb-3">
                                    <div class="row g-2">
                                        <?php foreach ($docCounts['by_category'] as $category => $count): ?>
                                        <?php if ($count > 0): ?>
                                        <div class="col-6">
                                            <div class="small text-center">
                                                <div class="doc-thumbnail <?php echo $category; ?> mx-auto mb-1" style="width: 40px; height: 40px;">
                                                    <i class="fas fa-<?php echo $category === 'pdf' ? 'file-pdf' : ($category === 'image' ? 'image' : ($category === 'document' ? 'file-word' : 'file')); ?>"></i>
                                                </div>
                                                <div><?php echo ucfirst($category); ?>: <?php echo $count; ?></div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <!-- Document list with preview -->
                                <div class="doc-list" style="max-height: 400px; overflow-y: auto;">
                                    <?php foreach ($documents as $doc): ?>
                                    <div class="doc-item mb-2" data-bs-toggle="modal" data-bs-target="#docModal" 
                                         data-doc-id="<?php echo $doc['id']; ?>"
                                         data-doc-filename="<?php echo htmlspecialchars($doc['original_filename']); ?>"
                                         data-doc-path="<?php echo htmlspecialchars($doc['file_path']); ?>"
                                         data-doc-type="<?php echo $doc['file_category']; ?>"
                                         data-doc-size="<?php echo $doc['file_size']; ?>">
                                        <div class="d-flex align-items-center">
                                            <div class="doc-thumbnail <?php echo $doc['file_category']; ?> me-3">
                                                <i class="fas fa-<?php echo $doc['file_category'] === 'pdf' ? 'file-pdf' : ($doc['file_category'] === 'image' ? 'image' : ($doc['file_category'] === 'document' ? 'file-word' : 'file')); ?>"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1 small"><?php echo htmlspecialchars($doc['original_filename']); ?></h6>
                                                <div class="d-flex justify-content-between">
                                                    <span class="badge bg-primary small">
                                                        <?php echo ucfirst(str_replace('_', ' ', $doc['document_type'])); ?>
                                                    </span>
                                                    <small class="text-muted">
                                                        <?php echo number_format($doc['file_size'] / 1024, 1); ?> KB
                                                    </small>
                                                </div>
                                                <?php
    $hier = parse_hier($doc);
    echo render_hier_badges($hier);
?>

                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Enhanced Evaluation Form -->
                        <div class="col-lg-8">
                            <form method="POST" action="">
                                <input type="hidden" name="application_id" value="<?php echo $current_application['id']; ?>">
                                
                                <div class="evaluation-card p-4">
                                    <div class="d-flex justify-content-between align-items-center mb-4">
                                        <h5 class="mb-0">
                                            <i class="fas fa-brain me-2"></i>
                                            Smart Assessment
                                        </h5>
                                        <div class="text-end">
                                            <div class="fw-bold text-success">Qualified: 60%+</div>
                                            <!-- <div class="small text-warning">Partial: 48%+</div> -->
                                        </div>
                                    </div>

                                    <!-- Live Score Calculator -->
                                    <div class="alert alert-info mb-4" id="liveScoreCalculator" style="display: none;">
                                        <div class="row align-items-center">
                                            <div class="col-md-8">
                                                <strong>Live Score: <span id="currentLiveScore">0</span>%</strong>
                                                <div class="progress mt-2" style="height: 8px;">
                                                    <div class="progress-bar" id="liveScoreProgress" role="progressbar"></div>
                                                </div>
                                            </div>
                                            <div class="col-md-4 text-end">
                                                <span id="liveScoreStatus" class="badge bg-secondary">Enter scores</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Bridging Units Preview -->
                                    <div class="bridging-preview" id="bridgingPreview" style="display: none;">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <strong><i class="fas fa-graduation-cap me-2"></i>Bridging Requirements</strong>
                                            <span class="badge bg-primary" id="requiredUnits">0 units</span>
                                        </div>
                                        <div id="subjectList" class="small"></div>
                                    </div>

                                    <?php foreach ($assessment_criteria as $criteria): ?>
                                    <?php 
                                    // Get documents related to this criteria
                                    
               $criteriaDocs = array_values(array_filter($documents, fn($doc) => doc_matches_criteria($doc, $criteria)));
$hasCriteriaDocs = !empty($criteriaDocs);

                                    // Auto-suggest score mula sa Detailed Upload
$suggestedScore = 0; 
$suggestedWhy   = 'No detailed selections';
if ($hasCriteriaDocs) {
    list($suggestedScore, $suggestedWhy) = suggest_score_from_docs($criteria, $criteriaDocs);
}

                                    ?>
                                    
                                    <div class="criteria-card">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <h6 class="mb-0"><?php echo htmlspecialchars($criteria['criteria_name']); ?></h6>
                                                    <div class="d-flex gap-2">
                                                        <span class="badge bg-secondary">
                                                            <?php echo ucfirst(str_replace('_', ' ', $criteria['criteria_type'])); ?>
                                                        </span>
                                                        <span class="badge <?php echo $hasCriteriaDocs ? 'bg-success' : 'bg-warning'; ?>">
                                                            Max: <?php echo $criteria['max_score']; ?> pts
                                                        </span>
                                                        <span class="badge bg-info">
                                                            Weight: <?php echo $criteria['weight']; ?>x
                                                        </span>
                                                    </div>
                                                </div>
                                                
                                                <p class="text-muted small mb-3"><?php echo htmlspecialchars($criteria['description']); ?></p>
                                                
                                           <?php if ($hasCriteriaDocs): ?>
<div class="mb-3 p-3 rounded" style="background: #e8f5e8; border-left: 4px solid #28a745;">
    <h6 class="mb-3 text-success">
        <i class="fas fa-folder-open me-2"></i>Uploaded Documents
    </h6>
    <?php foreach ($criteriaDocs as $doc): ?>
    <div class="d-flex align-items-center mb-2 p-2 bg-white rounded border">
        <div class="doc-thumbnail <?php echo $doc['file_category']; ?> me-3" style="width: 40px; height: 40px; min-width: 40px;">
            <i class="fas fa-<?php echo $doc['file_category'] === 'pdf' ? 'file-pdf' : ($doc['file_category'] === 'image' ? 'image' : 'file'); ?>"></i>
        </div>
        <div class="flex-grow-1">
            <div class="fw-semibold small"><?php echo htmlspecialchars($doc['original_filename']); ?></div>
            <div class="text-muted small">
                <?php echo date('M j, Y \a\t g:i A', strtotime($doc['upload_date'])); ?> | 
                <?php echo number_format($doc['file_size'] / 1024, 1); ?> KB
            </div>
            <?php
                $hier = parse_hier($doc);
                echo render_hier_badges($hier);
            ?>
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm" 
                data-bs-toggle="modal" data-bs-target="#docModal"
                data-doc-id="<?php echo $doc['id']; ?>"
                data-doc-filename="<?php echo htmlspecialchars($doc['original_filename']); ?>"
                data-doc-path="<?php echo htmlspecialchars($doc['file_path']); ?>"
                data-doc-type="<?php echo $doc['file_category']; ?>"
                data-doc-size="<?php echo $doc['file_size']; ?>">
            <i class="fas fa-eye"></i>
        </button>
    </div>
    <?php endforeach; ?>
</div>


                                                <?php else: ?>
<div class="mb-3 p-3 rounded" style="background: #fff3cd; border-left: 4px solid #ffc107;">
    <div class="d-flex align-items-center">
        <i class="fas fa-exclamation-triangle text-warning me-2"></i>
        <span class="text-warning"><strong>No supporting documents uploaded for this criteria.</strong></span>
    </div>
    <small class="text-muted">Score will automatically be set to 0</small>
</div>
<?php endif; ?>
                                                
                                                <textarea class="form-control" 
                                                        name="evaluations[<?php echo $criteria['id']; ?>][comments]" 
                                                        rows="2" 
                                                        placeholder="Evidence and justification..."><?php echo isset($existing_evaluations[$criteria['id']]) ? htmlspecialchars($existing_evaluations[$criteria['id']]['comments']) : ''; ?></textarea>
                                            </div>
                                            <div class="col-md-4 text-end">
                                                <div class="sticky-top" style="top: 20px;">
                                                    <div class="input-group mb-2">
                                                        <input type="number" 
                                                            class="form-control score-input text-center smart-score-input" 
                                                            name="evaluations[<?php echo $criteria['id']; ?>][score]" 
                                                            data-max="<?php echo $criteria['max_score']; ?>"
                                                            data-weight="<?php echo $criteria['weight']; ?>"
                                                            data-criteria-id="<?php echo $criteria['id']; ?>"
                                                            data-has-docs="<?php echo $hasCriteriaDocs ? 'true' : 'false'; ?>"
                                                            min="0" 
                                                            max="<?php echo $criteria['max_score']; ?>" 
                                                            step="0.1"
                                                           value="<?php
  if (isset($existing_evaluations[$criteria['id']])) {
      echo $existing_evaluations[$criteria['id']]['score'];   // keep saved value
  } else {
      echo $hasCriteriaDocs ? $suggestedScore : '0';          // prefill from auto-suggest
  }
?>"

                                                            <?php echo !$hasCriteriaDocs ? 'style="background-color: #fff3cd;" readonly' : ''; ?>
                                                            required>
                                                        <span class="input-group-text">/ <?php echo $criteria['max_score']; ?></span>
                                                    </div>
                                                    
                                                    <div class="text-center mb-3">
                                                        <div class="fw-bold <?php echo $hasCriteriaDocs ? 'text-primary' : 'text-warning'; ?>" 
                                                             id="percentage-<?php echo $criteria['id']; ?>">0%</div>
                                                        <small class="text-muted">Current Score</small>
                                                    </div>
                                                    <?php if ($hasCriteriaDocs): ?>
  <div class="small text-muted mb-3">
    <i class="fas fa-magic me-1 text-success"></i>
    Auto-suggested: <strong><?php echo $suggestedScore; ?></strong>
    <div class="text-muted"><?php echo htmlspecialchars($suggestedWhy); ?></div>
    <button type="button" class="btn btn-link p-0 small"
      onclick="var inp=this.closest('.criteria-card').querySelector('input.smart-score-input'); inp.value='<?php echo $suggestedScore; ?>'; calculateSmartScore();">
      Use this score
    </button>
  </div>
<?php endif; ?>

                                                    
                                                    <?php if ($hasCriteriaDocs): ?>
                                                    <div class="alert alert-success p-2 small text-center">
                                                        <i class="fas fa-thumbs-up"></i><br>
                                                        Ready for scoring
                                                    </div>
                                                    <?php else: ?>
                                                    <div class="alert alert-warning p-2 small text-center">
                                                        <i class="fas fa-lock"></i><br>
                                                        Auto-scored as 0<br>
                                                        <em>No documents</em>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                
                                <!-- Smart Final Assessment -->
<div class="evaluation-card p-4">
    <h5 class="mb-3">
        <i class="fas fa-robot me-2"></i>
        Smart Recommendations & Auto-Bridging Requirements
    </h5>

    <!-- Status + Required -->
    <div class="alert alert-light border mb-3" id="smartRecommendation" style="display:none;">
        <div class="row">
            <div class="col-md-6"><strong>Status:</strong><div id="recommendedStatus" class="mt-1"></div></div>
            <div class="col-md-6"><strong>Required:</strong><div id="recommendedBridging" class="mt-1"></div></div>
        </div>
    </div>

<div class="evaluation-card p-4">
    <h5 class="mb-3">
        <i class="fas fa-robot me-2"></i>
        Smart Recommendations & Curriculum Status
    </h5>

    <?php 
    $curriculumStatus = getPassedSubjects($documents, $current_application['program_code']);
    $curriculumSubjects = $curriculumStatus['curriculum'];
    $passedSubjects = $curriculumStatus['passed'];
    ?>


<div class="mb-4 p-3 rounded border" style="background: linear-gradient(135deg, #e8f5e9, #f1f8e9);">
    <h6 class="mb-3">
        <i class="fas fa-list-check me-2 text-success"></i>
        Curriculum Requirements for <?php echo htmlspecialchars($current_application['program_code']); ?>
    </h6>
    
    <table class="table table-sm table-bordered mb-0">
        <thead class="table-light">
            <tr>
                <th style="width: 60%;">Subject</th>
                <th style="width: 20%;" class="text-center">Status</th>
                <th style="width: 20%;">Evidence</th>
            </tr>
        </thead>
        <tbody id="curriculumTableBody">
            <?php foreach ($curriculumSubjects as $subject): ?>
            <?php
            $requiredSubjects = array_column($bridging_requirements, 'subject_name');
            $isRequired = in_array($subject['name'], $requiredSubjects);
            
            if (isset($passedSubjects[$subject['name']])) {
                $evidence = $passedSubjects[$subject['name']];
            } elseif (!$isRequired) {
                $evidence = 'Credit via ETEEAP assessment';
            } else {
                $evidence = 'To be completed';
            }
            ?>
            <tr data-subject="<?php echo htmlspecialchars($subject['name']); ?>">
                <td><?php echo htmlspecialchars($subject['name']); ?></td>
                <td class="text-center status-cell">
                    <?php if ($isRequired): ?>
                        <span class="badge bg-warning text-dark">Required</span>
                    <?php else: ?>
                        <span class="badge bg-success">Passed</span>
                    <?php endif; ?>
                </td>
                <td class="small text-muted evidence-cell">
                    <?php echo htmlspecialchars($evidence); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="mt-2 small text-muted">
        <i class="fas fa-info-circle me-1"></i>
        Subjects marked "Required" must be completed as bridging courses. "Passed" subjects are credited through prior learning assessment.
    </div>
</div>

    <!-- Status + Required -->
    <div class="alert alert-light border mb-3" id="smartRecommendation" style="display:none;">
        <div class="row">
            <div class="col-md-6"><strong>Status:</strong><div id="recommendedStatus" class="mt-1"></div></div>
            <div class="col-md-6"><strong>Required:</strong><div id="recommendedBridging" class="mt-1"></div></div>
        </div>
    </div>

    <!-- Bridging Requirements (Only shows subjects NOT passed) -->
    <div class="mb-3 p-3 rounded border" id="bridgingSummary" style="background:linear-gradient(135deg,#eef7ff,#f6fff0)">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">
                <i class="fas fa-graduation-cap me-2 text-primary"></i>
                Bridging Requirements
                <span class="badge bg-success ms-2">
                    <i class="fas fa-magic me-1"></i>Auto-Generated
                </span>
            </h6>
            <div class="d-flex gap-2">
                <span class="badge bg-primary">
                    Required: <span id="summaryRequiredUnits"><?php echo $reqUnits ?: '—'; ?></span>
                </span>
                <span class="badge bg-info">
                    Current: <span id="summaryCurrentUnits">
                        <?php echo !empty($bridging_requirements) ? array_sum(array_column($bridging_requirements,'units')).' units' : '0 units'; ?>
                    </span>
                </span>
                <button type="button" class="btn btn-sm btn-outline-primary" id="toggleEditBridging">
                    <i class="fas fa-pen-to-square me-1"></i>Customize
                </button>
            </div>
        </div>

        <div id="summaryList">
            <?php if (!empty($bridging_requirements)): ?>
                <?php foreach ($bridging_requirements as $req): ?>
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                        <div>
                            <strong><?php echo htmlspecialchars($req['subject_name']); ?></strong>
                            <?php if (!empty($req['subject_code'])): ?>
                                <span class="text-muted"> (<?php echo htmlspecialchars($req['subject_code']); ?>)</span>
                            <?php endif; ?>
                            <?php if (!empty($req['priority'])): ?>
                                <span class="priority-badge ms-1"><?php echo (int)$req['priority']==1?'HIGH':((int)$req['priority']==2?'MEDIUM':'LOW'); ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-light text-dark"><?php echo (int)$req['units']; ?> units</span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-muted small">
                    <i class="fas fa-info-circle me-1"></i>
                    Bridging subjects will auto-load when evaluation reaches 60% or higher.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- EDIT MODE (hidden by default, no Load Smart button) -->
    <div class="bridging-management mb-4" id="bridgingManagement" style="display:none;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">
                <i class="fas fa-graduation-cap me-2 text-primary"></i>
                Customize Bridging Requirements
                <small class="text-muted ms-2">(Auto-loaded based on score)</small>
            </h6>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshSmart">
                    <i class="fas fa-refresh me-1"></i>Refresh Suggestions
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addBridgingSubject()">
                    <i class="fas fa-plus me-1"></i>Add Subject
                </button>
            </div>
        </div>

        <div class="alert alert-info small mb-3">
            <i class="fas fa-lightbulb me-1"></i>
            <strong>Auto-Loading:</strong> Bridging requirements are automatically generated when the evaluation score reaches 60% or higher. 
            You can customize them below if needed.
        </div>

        <form method="POST" action="" id="bridgingForm">
            <input type="hidden" name="application_id" value="<?php echo $current_application['id']; ?>">

            <div id="bridgingSubjectsList" class="mb-3">
                <!-- Existing bridging requirements will be loaded here -->
              <?php if (!empty($bridging_requirements)): ?>
    <?php foreach ($bridging_requirements as $index => $req): ?>
        <div class="bridging-subject-item mb-2 p-3 border rounded">
            <div class="row align-items-center">
                <div class="col-md-5">
                    <label class="form-label small">Subject Name</label>
                    <select class="form-select form-select-sm subject-selector" 
                            name="bridging_subjects[<?php echo $index; ?>][name]" 
                            onchange="updateSubjectDetails(this, <?php echo $index; ?>)" required>
                        <option value="">Select Subject...</option>
                        <?php foreach ($predefined_subjects as $subject): ?>
                        <option value="<?php echo htmlspecialchars($subject['name']); ?>" 
                                data-code="<?php echo htmlspecialchars($subject['code']); ?>"
                                data-units="<?php echo (int)$subject['units']; ?>"
                                <?php echo $req['subject_name'] === $subject['name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($subject['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Code</label>
                    <input type="text" class="form-control form-control-sm" 
                           name="bridging_subjects[<?php echo $index; ?>][code]" 
                           value="<?php echo htmlspecialchars($req['subject_code']); ?>" readonly>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Units</label>
                    <input type="number" class="form-control form-control-sm units-input" 
                           name="bridging_subjects[<?php echo $index; ?>][units]" 
                           value="<?php echo (int)$req['units']; ?>" readonly required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Priority</label>
                    <select class="form-select form-select-sm" 
                            name="bridging_subjects[<?php echo $index; ?>][priority]">
                        <option value="1" <?php echo (int)$req['priority'] === 1 ? 'selected' : ''; ?>>High</option>
                        <option value="2" <?php echo (int)$req['priority'] === 2 ? 'selected' : ''; ?>>Medium</option>
                        <option value="3" <?php echo (int)$req['priority'] === 3 ? 'selected' : ''; ?>>Low</option>
                    </select>
                </div>
                <div class="col-md-1 text-end">
                    <button type="button" class="btn btn-sm btn-outline-danger" 
                            onclick="removeBridgingSubject(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary" id="totalUnitsDisplay">
                        Total: <span id="totalUnitsCount"><?php echo array_sum(array_column($bridging_requirements, 'units')); ?></span> units
                    </span>
                    <span class="badge bg-success">
                        Required: <span id="editRequiredUnits"><?php echo $reqUnits ?: 0; ?></span> units
                    </span>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleCancelEdit">Close</button>
                    <button type="submit" name="update_bridging" class="btn btn-sm btn-success">
                        <i class="fas fa-save me-1"></i>Save Requirements
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label">Additional Comments (Optional)</label>
            <textarea class="form-control" name="recommendation" rows="3" 
                      placeholder="Add comments beyond auto-generated recommendations..."></textarea>
        </div>
        <div class="col-md-4">
            <label class="form-label">Status Override</label>
            <select class="form-select" name="final_status">
                <option value="auto">Use Smart Recommendation</option>
                <option value="qualified">Override: Qualified</option>
                <option value="partially_qualified">Override: Partially Qualified</option>
                <option value="not_qualified">Override: Not Qualified</option>
            </select>
        </div>
    </div>
    
    <div class="mt-4">
        <button type="submit" name="submit_evaluation" class="btn btn-success btn-lg">
            <i class="fas fa-brain me-2"></i>
            Submit Smart Evaluation
        </button>
        <a href="evaluate.php" class="btn btn-outline-secondary btn-lg ms-2">Cancel</a>
    </div>

</div>

                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Document Preview Modal -->
    <div class="modal fade" id="docModal" tabindex="-1" aria-labelledby="docModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="docModalLabel">
                        <i class="fas fa-file-alt me-2"></i>
                        <span id="modalDocName">Document Preview</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="docModalBody" style="min-height: 400px;">
                    <div class="d-flex justify-content-center align-items-center" style="height: 400px;">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="me-auto">
                        <span id="modalDocInfo" class="text-muted small"></span>
                    </div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a id="modalDownloadBtn" href="#" class="btn btn-primary" target="_blank">
                        <i class="fas fa-download me-1"></i>Download
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Enhanced JavaScript for smart evaluation system with document handling
        
        
        const programCode = '<?php echo $current_application['program_code'] ?? ''; ?>';
const programId = <?php echo $current_application['program_id'] ?? 'null'; ?>;
const hasDocuments = <?php echo $hasDocs ? 'true' : 'false'; ?>;
 
   // Replace the hardcoded subjectData with PHP-generated data
const subjectData = <?php echo json_encode($predefined_subjects); ?>;

// Simple: Just pick subjects from database until we reach required units
function getSubjectRecommendations(programCode, requiredUnits) {
    if (!subjectData || subjectData.length === 0) {
        return { subjects: [], totalUnits: 0, remaining: requiredUnits };
    }
    
    let selectedSubjects = [];
    let totalUnits = 0;
    
    // Add subjects until we're close
    for (let subject of subjectData) {
        const units = parseInt(subject.units) || 3;
        
        if (totalUnits + units <= requiredUnits) {
            selectedSubjects.push({
                name: subject.name,
                code: subject.code,
                units: units,
                priority: 1
            });
            totalUnits += units;
        }
        
        if (totalUnits === requiredUnits) break;
    }
    
    // If there's a small gap remaining, adjust the last subject or add a flexible one
    const remaining = requiredUnits - totalUnits;
    if (remaining > 0 && remaining <= 3 && selectedSubjects.length > 0) {
        // Add the remaining units to the last subject
        selectedSubjects[selectedSubjects.length - 1].units += remaining;
        totalUnits = requiredUnits;
    } else if (remaining > 0) {
        // Add more subjects to fill the gap
        let index = 0;
        while (totalUnits < requiredUnits && index < subjectData.length) {
            const subject = subjectData[index];
            const isDuplicate = selectedSubjects.some(s => s.name === subject.name);
            
            if (!isDuplicate) {
                const unitsNeeded = requiredUnits - totalUnits;
                const subjectUnits = Math.min(parseInt(subject.units) || 3, unitsNeeded);
                
                selectedSubjects.push({
                    name: subject.name,
                    code: subject.code,
                    units: subjectUnits,
                    priority: 2
                });
                totalUnits += subjectUnits;
            }
            index++;
        }
    }
    
    return { 
        subjects: selectedSubjects, 
        totalUnits: totalUnits,
        remaining: 0 // Should always be 0 now
    };
}
        
  function calculateBridgingUnits(score) {
    if (score >= 95) return 3;
    if (score >= 91) return 6;
    if (score >= 85) return 9;
    if (score >= 80) return 12;
    if (score >= 75) return 15;
    if (score >= 70) return 18;
    if (score >= 65) return 21;
    if (score >= 60) return 24;
    return 0;
}



     function calculateSmartScore() {
    const scoreInputs = document.querySelectorAll('.smart-score-input');
    let totalWeightedScore = 0;
    let totalWeight = 0;
    let hasScores = false;
    
    scoreInputs.forEach(input => {
        const score = parseFloat(input.value) || 0;
        const maxScore = parseFloat(input.getAttribute('data-max'));
        const weight = parseFloat(input.getAttribute('data-weight'));
        const criteriaId = input.getAttribute('data-criteria-id');
        const hasDocs = input.getAttribute('data-has-docs') === 'true';

        // lock to 0 if no docs
        const finalScore = hasDocs ? score : 0;

        // clamp
        const bounded = Math.max(0, Math.min(finalScore, maxScore));
        const percentage = maxScore > 0 ? (bounded / maxScore) * 100 : 0;

        // ALWAYS accumulate denominator
        totalWeightedScore += (percentage * weight);
        totalWeight += weight;

        // UI per-criteria %
        const pctEl = document.getElementById(`percentage-${criteriaId}`);
        if (pctEl) {
            pctEl.textContent = Math.round(percentage) + '%';
            pctEl.className = 'fw-bold ' + (hasDocs ? 'text-primary' : 'text-warning');
        }

        // keep a flag to show widgets
        hasScores = hasScores || bounded > 0;
    });

    const finalScore = totalWeight > 0 ? Math.round(totalWeightedScore / totalWeight) : 0;
    
    if (hasScores) {
        updateScoreDisplay(finalScore);
        updateBridgingPreview(finalScore);
        updateSmartRecommendation(finalScore);
        autoLoadBridgingRequirements(finalScore);
        updateCurriculumStatus(); // ← Add this call
    } else {
        hideScoreDisplays();
        updateCurriculumStatus(); // ← Add this call too
    }
}
// New function to automatically load bridging requirements
function autoLoadBridgingRequirements(score) {
    const container = document.getElementById('bridgingSubjectsList');
    const required = score >= 60 ? calculateBridgingUnits(score) : 0;
    
    // Only auto-load if:
    // 1. Score is 60% or higher
    // 2. No existing bridging subjects are already loaded
    // 3. Container exists
      if (required > 0 && container && container.children.length === 0) {
         const rec = getSubjectRecommendations(programCode || '', required);
        
        // Clear counter and load suggestions
        subjectCounter = 0;
        
        rec.subjects.forEach(s => {
            addBridgingSubject(); // creates a new row
            
            // fill the new row with recommended data
            const row = container.lastElementChild;
            const selectElement = row.querySelector('select.subject-selector');
            
            // Try to find matching predefined subject
            const matchingOption = Array.from(selectElement.options).find(option => 
                option.value === s.name || option.textContent.trim() === s.name
            );
            
            if (matchingOption) {
                selectElement.value = matchingOption.value;
                // Trigger the change event to populate other fields
                updateSubjectDetails(selectElement, subjectCounter - 1);
            } else {
                // Use custom option if no exact match
                selectElement.value = 'custom';
                updateSubjectDetails(selectElement, subjectCounter - 1);
                row.querySelector('input[name*="[custom_name]"]').value = s.name;
            }
            
            // Set the other fields
            row.querySelector('input[name*="[code]"]').value = s.code || '';
            row.querySelector('input[name*="[units]"]').value = s.units || 3;
            row.querySelector('select[name*="[priority]"]').value = s.priority || 2;
        });
        
        updateTotalUnits();
        
        // Show a subtle notification that bridging requirements were auto-loaded
        showToast(`Auto-loaded ${rec.subjects.length} bridging subjects based on ${score}% score`, 'success');
    }
}

        function updateScoreDisplay(score) {
            const calculator = document.getElementById('liveScoreCalculator');
            const currentScore = document.getElementById('currentLiveScore');
            const progressBar = document.getElementById('liveScoreProgress');
            const statusSpan = document.getElementById('liveScoreStatus');
            
            calculator.style.display = 'block';
            currentScore.textContent = score;
            progressBar.style.width = Math.min(score, 100) + '%';
            
            let status, statusClass, progressClass, calculatorClass;
            if (score >= 60) {
                status = 'QUALIFIED';
                statusClass = 'bg-success';
                progressClass = 'progress-bar-pass';
                calculatorClass = 'score-calculator-pass';
            } else if (score >= 48) {
                status = 'PARTIAL';
                statusClass = 'bg-warning';
                progressClass = 'progress-bar-partial';
                calculatorClass = 'score-calculator-partial';
            } else {
                status = 'NOT QUALIFIED';
                statusClass = 'bg-danger';
                progressClass = 'progress-bar-fail';
                calculatorClass = 'score-calculator-fail';
            }
            
            statusSpan.textContent = status;
            statusSpan.className = `badge ${statusClass}`;
            progressBar.className = `progress-bar ${progressClass}`;
            calculator.className = `alert alert-info mb-4 ${calculatorClass}`;
        }

function updateBridgingPreview(score) {
    const bridgingPreview = document.getElementById('bridgingPreview');
    const requiredUnitsSpan = document.getElementById('requiredUnits');
    const subjectList = document.getElementById('subjectList');
    const bridgingManagement = document.getElementById('bridgingManagement');

    if (!bridgingPreview || !requiredUnitsSpan || !subjectList) return;

    if (score >= 60) {
        const requiredUnits = calculateBridgingUnits(score);
        const recommendations = getSubjectRecommendations(programCode || '', requiredUnits);

        // Show preview + management
        bridgingPreview.style.display = 'block';
        if (bridgingManagement) bridgingManagement.style.display = 'block';
        requiredUnitsSpan.textContent = `${requiredUnits} units`;

        // Auto-populate edit form kung empty pa
        autoPopulateBridgingForm(recommendations, requiredUnits);

        // Build preview list - REMOVE THE PLACEHOLDER TEXT
        let subjectsHtml = '';
        recommendations.subjects.forEach(subject => {
            const priorityBadge = subject.priority === 1 ? '<span class="priority-badge">HIGH</span>' : '';
            subjectsHtml += `
                <div class="subject-item">
                    <span>${subject.name} (${subject.code})${priorityBadge}</span>
                    <span class="badge bg-light text-dark">${subject.units} units</span>
                </div>
            `;
        });

        // REMOVED: The "Additional X units to be determined" section
        // If you need ALL required units filled, handle it in autoPopulateBridgingForm instead

        subjectList.innerHTML = subjectsHtml;
        syncRequiredUnits(requiredUnits);

    } else if (score >= 48) {
        bridgingPreview.style.display = 'block';
        if (bridgingManagement) bridgingManagement.style.display = 'none';
        requiredUnitsSpan.textContent = 'Regular Program';
        subjectList.innerHTML = `<div class="text-center text-muted">
            <i class="fas fa-info-circle me-2"></i>Student must enroll in regular degree program
        </div>`;
        syncRequiredUnits(0);
    } else {
        bridgingPreview.style.display = 'none';
        if (bridgingManagement) bridgingManagement.style.display = 'none';
        syncRequiredUnits(0);
    }
}



function getFilteredBridgingSubjects(allSubjects, passedSubjects) {
    return allSubjects.filter(subject => !passedSubjects.hasOwnProperty(subject.name));
}

// Modify the autoPopulateBridgingForm function to use filtered subjects
function autoPopulateBridgingForm(recommendations, requiredUnits) {
    const container = document.getElementById('bridgingSubjectsList');
    if (!container) return;

    // Get passed subjects from PHP
    const passedSubjects = <?php echo json_encode($passedSubjects); ?>;
    
    // Filter out already-passed subjects
    const filteredSubjects = recommendations.subjects.filter(s => !passedSubjects.hasOwnProperty(s.name));
    

    const existingRows = container.querySelectorAll('.bridging-subject-item');
    if (existingRows.length > 0) {
        updateTotalUnits();
        syncCurrentUnits();
        return;
    }

    // Reset
    container.innerHTML = '';
    subjectCounter = 0;

    // Load recommended subjects
    let loadedUnits = 0;
    recommendations.subjects.forEach(subject => {
        addBridgingSubject();
        const row = container.lastElementChild;

        const selectElement = row.querySelector('select.subject-selector');
        const matchingOption = Array.from(selectElement.options).find(
            option => option.value === subject.name || option.textContent.trim() === subject.name
        );
        if (matchingOption) {
            selectElement.value = matchingOption.value;
            updateSubjectDetails(selectElement, subjectCounter - 1);
        } else {
            selectElement.value = 'custom';
            updateSubjectDetails(selectElement, subjectCounter - 1);
            row.querySelector('input[name*="[custom_name]"]').value = subject.name;
        }

        row.querySelector('input[name*="[code]"]').value = subject.code || '';
        row.querySelector('input[name*="[units]"]').value = subject.units || 3;
        row.querySelector('select[name*="[priority]"]').value = subject.priority || 2;

        loadedUnits += parseInt(subject.units || 0, 10);
    });

    // If there's still a gap, add actual subjects to fill it (not placeholders)
    const remaining = Math.max(0, parseInt(requiredUnits, 10) - loadedUnits);
    if (remaining > 0) {
        // Get additional subjects to fill the gap
        const PC = (programCode || '').toUpperCase();
        let additionalSubjects = [];
        
        if (PC.includes('BSED')) {
            additionalSubjects = [
                {name: 'Teaching Strategies 3', code: 'TS3', units: 3, priority: 2},
                {name: 'Introduction to Educational Research', code: 'IER', units: 3, priority: 2},
                {name: 'Professional Ethics', code: 'PE', units: 3, priority: 2}
            ];
        } else if (PC.includes('BEED')) {
            additionalSubjects = [
                {name: 'Teaching Strategies 2', code: 'TS2', units: 3, priority: 2},
                {name: 'Introduction to Educational Research (BEEd)', code: 'IER-BEED', units: 3, priority: 2},
                {name: 'Professional Ethics (BEEd)', code: 'PE-BEED', units: 3, priority: 2}
            ];
        } else {
            additionalSubjects = [
                {name: 'Professional Development Course 2', code: 'PDC2', units: 3, priority: 2},
                {name: 'Capstone Project', code: 'CAP', units: 3, priority: 2}
            ];
        }

        let remainingToFill = remaining;
        for (let subject of additionalSubjects) {
            if (remainingToFill <= 0) break;
            
            addBridgingSubject();
            const row = container.lastElementChild;
            const selectElement = row.querySelector('select.subject-selector');

            const match = Array.from(selectElement.options).find(opt =>
                opt.value === subject.name || opt.textContent.trim() === subject.name
            );
            if (match) {
                selectElement.value = match.value;
                updateSubjectDetails(selectElement, subjectCounter - 1);
            } else {
                selectElement.value = 'custom';
                updateSubjectDetails(selectElement, subjectCounter - 1);
                row.querySelector('input[name*="[custom_name]"]').value = subject.name;
            }

            const unitsToAdd = Math.min(subject.units, remainingToFill);
            row.querySelector('input[name*="[code]"]').value = subject.code || '';
            row.querySelector('input[name*="[units]"]').value = unitsToAdd;
            row.querySelector('select[name*="[priority]"]').value = subject.priority || 2;
            
            remainingToFill -= unitsToAdd;
        }
    }

    updateTotalUnits();
    syncCurrentUnits();

    if (remaining > 0) {
        showToast(`Loaded bridging requirements totaling ${requiredUnits} units`, 'success');
    }
}

// helper to add a custom “Bridging Elective” row with N units
function addFillerRow(container, units) {
    if (!units || units <= 0) return;
    addBridgingSubject();
    const row = container.lastElementChild;

    const selectElement = row.querySelector('select.subject-selector');
    selectElement.value = 'custom';
    updateSubjectDetails(selectElement, subjectCounter - 1);

    // Label/code for fillers
    row.querySelector('input[name*="[custom_name]"]').value =
        `Bridging Elective (${units} ${units === 1 ? 'unit' : 'units'})`;
    row.querySelector('input[name*="[code]"]').value  = 'ELEC';
    row.querySelector('input[name*="[units]"]').value = units;
    row.querySelector('select[name*="[priority]"]').value = 2; // Medium
}

        function updateSmartRecommendation(score) {
            const smartRecommendation = document.getElementById('smartRecommendation');
            const recommendedStatus = document.getElementById('recommendedStatus');
            const recommendedBridging = document.getElementById('recommendedBridging');
            
            smartRecommendation.style.display = 'block';
            
            let statusHtml, bridgingHtml, alertClass;
            
            if (score >= 60) {
                const requiredUnits = calculateBridgingUnits(score);
                statusHtml = '<span class="badge bg-success">QUALIFIED</span>';
                bridgingHtml = `<span class="badge bg-info">${requiredUnits} bridging units</span>`;
                alertClass = 'alert-success';
                
                if (score >= 95) {
                    statusHtml += '<div class="small text-success mt-1"><i class="fas fa-star"></i> Exceptional Performance</div>';
                } else if (score >= 85) {
                    statusHtml += '<div class="small text-success mt-1"><i class="fas fa-thumbs-up"></i> Strong Performance</div>';
                }
            } else if (score >= 48) {
                statusHtml = '<span class="badge bg-warning">PARTIALLY QUALIFIED</span>';
                bridgingHtml = '<span class="badge bg-secondary">Regular Program Required</span>';
                alertClass = 'alert-warning';
                statusHtml += '<div class="small text-warning mt-1"><i class="fas fa-exclamation-triangle"></i> Consider regular degree program</div>';
            } else {
                statusHtml = '<span class="badge bg-danger">NOT QUALIFIED</span>';
                bridgingHtml = '<span class="badge bg-secondary">N/A</span>';
                alertClass = 'alert-danger';
                statusHtml += '<div class="small text-danger mt-1"><i class="fas fa-times-circle"></i> Does not meet minimum requirements</div>';
            }
            
            recommendedStatus.innerHTML = statusHtml;
            recommendedBridging.innerHTML = bridgingHtml;
            smartRecommendation.className = `alert ${alertClass} border mb-3`;
        }

        function hideScoreDisplays() {
            document.getElementById('liveScoreCalculator').style.display = 'none';
            document.getElementById('bridgingPreview').style.display = 'none';
            document.getElementById('smartRecommendation').style.display = 'none';
        }

        function validateScoreInput(input) {
            const score = parseFloat(input.value);
            const maxScore = parseFloat(input.getAttribute('data-max'));
            const hasDocs = input.getAttribute('data-has-docs') === 'true';
            
            // Force score to 0 if no documents
            if (!hasDocs && score > 0) {
                input.value = '0';
                showToast('Score cannot be greater than 0 - no documents uploaded for this criteria', 'warning');
                return;
            }
            
            if (score > maxScore) {
                input.value = maxScore;
                showToast(`Maximum score for this criteria is ${maxScore}`, 'warning');
            } else if (score < 0) {
                input.value = 0;
                showToast('Score cannot be negative', 'warning');
            }
            
            // Add visual feedback
            if (score > 0 && hasDocs) {
                input.classList.add('is-valid');
                input.classList.remove('is-invalid');
            } else {
                input.classList.remove('is-valid', 'is-invalid');
            }
        }

        // Document Modal Handler
        function initializeDocumentModal() {
            const docModal = document.getElementById('docModal');
            if (!docModal) return;

            docModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const docId = button.getAttribute('data-doc-id');
                const docFilename = button.getAttribute('data-doc-filename');
                const docPath = button.getAttribute('data-doc-path');
                const docType = button.getAttribute('data-doc-type');
                const docSize = button.getAttribute('data-doc-size');

                // Update modal title and info
                document.getElementById('modalDocName').textContent = docFilename;
                document.getElementById('modalDocInfo').innerHTML = `
                    <i class="fas fa-info-circle me-1"></i>
                    Type: ${docType.toUpperCase()} | Size: ${(docSize / 1024).toFixed(1)} KB
                `;
                
                // Set download link
                document.getElementById('modalDownloadBtn').href = docPath;

                // Load document preview
                loadDocumentPreview(docPath, docType, docFilename);
            });
        }

        function loadDocumentPreview(docPath, docType, filename) {
            const modalBody = document.getElementById('docModalBody');
            
            // Show loading
            modalBody.innerHTML = `
                <div class="d-flex justify-content-center align-items-center" style="height: 400px;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;

            if (docType === 'image') {
                modalBody.innerHTML = `
                    <div class="text-center">
                        <img src="${docPath}" class="img-fluid" style="max-height: 70vh;" 
                             alt="${filename}" onload="this.style.opacity=1" style="opacity:0;transition:opacity 0.3s">
                    </div>
                `;
            } else if (docType === 'pdf') {
                modalBody.innerHTML = `
                    <div class="text-center">
                        <iframe src="${docPath}" width="100%" height="600px" style="border: none; border-radius: 8px;">
                            <p>Your browser does not support PDFs. 
                               <a href="${docPath}" target="_blank">Download the PDF</a> instead.</p>
                        </iframe>
                    </div>
                `;
            } else {
                modalBody.innerHTML = `
                    <div class="text-center py-5">
                        <i class="fas fa-file fa-4x text-muted mb-3"></i>
                        <h5>Preview not available</h5>
                        <p class="text-muted">This file type cannot be previewed in the browser.</p>
                        <a href="${docPath}" class="btn btn-primary" target="_blank">
                            <i class="fas fa-download me-1"></i>Download to view
                        </a>
                    </div>
                `;
            }
        }



let subjectCounter = <?php echo count($bridging_requirements); ?>;

// Add this near the top of your JavaScript section
const filteredSubjects = <?php echo json_encode(array_values(getFilteredSubjects($current_application['program_code'] ?? '', $predefined_subjects))); ?>;

// Update addBridgingSubject function
function addBridgingSubject() {
    const container = document.getElementById('bridgingSubjectsList');
    const newItem = document.createElement('div');
    newItem.className = 'bridging-subject-item mb-2 p-3 border rounded';
    
    // Build simple dropdown from database
    let subjectOptions = '<option value="">Select Subject...</option>';
    subjectData.forEach(subject => {
        subjectOptions += `<option value="${subject.name}" 
            data-code="${subject.code}"
            data-units="${subject.units}">
            ${subject.name}
        </option>`;
    });
    
    newItem.innerHTML = `
        <div class="row align-items-center">
            <div class="col-md-5">
                <label class="form-label small">Subject Name</label>
                <select class="form-select form-select-sm subject-selector" 
                        name="bridging_subjects[${subjectCounter}][name]" 
                        onchange="updateSubjectDetails(this, ${subjectCounter})" required>
                    ${subjectOptions}
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Code</label>
                <input type="text" class="form-control form-control-sm" 
                       name="bridging_subjects[${subjectCounter}][code]" 
                       placeholder="Auto" readonly>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Units</label>
                <input type="number" class="form-control form-control-sm units-input" 
                       name="bridging_subjects[${subjectCounter}][units]" 
                       min="1" max="12" value="3" readonly required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Priority</label>
                <select class="form-select form-select-sm" 
                        name="bridging_subjects[${subjectCounter}][priority]">
                    <option value="1" selected>High</option>
                    <option value="2">Medium</option>
                    <option value="3">Low</option>
                </select>
            </div>
            <div class="col-md-1 text-end">
                <button type="button" class="btn btn-sm btn-outline-danger" 
                        onclick="removeBridgingSubject(this)" 
                        title="Remove">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `;
    
    container.appendChild(newItem);
    subjectCounter++;
    updateTotalUnits();
    updateCurriculumStatus();
}

function removeBridgingSubject(button) {
    if (!confirm('Remove this subject from bridging requirements?')) return;
    button.closest('.bridging-subject-item').remove();
    updateTotalUnits();
    updateCurriculumStatus(); // Add this line
}

function isDuplicateSubject(subjectName, excludeIndex = -1) {
    const container = document.getElementById('bridgingSubjectsList');
    const rows = container.querySelectorAll('.bridging-subject-item');
    
    for (let i = 0; i < rows.length; i++) {
        if (i === excludeIndex) continue;
        
        const selectElement = rows[i].querySelector('select.subject-selector');
        if (selectElement && selectElement.value.toLowerCase() === subjectName.toLowerCase()) {
            return true;
        }
    }
    return false;
}

// Update the updateSubjectDetails function to check for duplicates
function autoPopulateBridgingForm(recommendations, requiredUnits) {
    const container = document.getElementById('bridgingSubjectsList');
    if (!container) return;
    
    const existingRows = container.querySelectorAll('.bridging-subject-item');
    if (existingRows.length > 0) {
        updateTotalUnits();
        syncCurrentUnits();
        return; // Don't overwrite existing
    }
    
    container.innerHTML = '';
    subjectCounter = 0;
    
    recommendations.subjects.forEach(subject => {
        addBridgingSubject();
        const row = container.lastElementChild;
        const selectElement = row.querySelector('select.subject-selector');
        
        // Set the value
        selectElement.value = subject.name;
        updateSubjectDetails(selectElement, subjectCounter - 1);
        
        // Set priority
        const prioritySelect = row.querySelector('select[name*="[priority]"]');
        if (prioritySelect) {
            prioritySelect.value = subject.priority || 1;
        }
    });
    
    updateTotalUnits();
    syncCurrentUnits();
}


function updateTotalUnits() {
    const unitsInputs = document.querySelectorAll('.units-input, input[name*="[units]"]');
    let total = 0;
    
    unitsInputs.forEach(input => {
        const units = parseInt(input.value) || 0;
        total += units;
    });
    
    document.getElementById('totalUnitsCount').textContent = total;
    syncCurrentUnits(); // **Add this line**
     updateCurriculumStatus();
}


// --- EDIT TOGGLE between summary and form ---
const toggleBtn = document.getElementById('toggleEditBridging');
const cancelEditBtn = document.getElementById('toggleCancelEdit');
const summaryBox = document.getElementById('bridgingSummary');
const editBox = document.getElementById('bridgingManagement');

function openEdit() {
  editBox.style.display = 'block';
  // kung walang rows sa edit form pero may smart required units, pwede nang i-load suggestion
  const hasRows = editBox.querySelectorAll('.bridging-subject-item').length > 0;
  if (!hasRows) loadSmartSuggestion();
  updateTotalUnits();
}
function closeEdit() { editBox.style.display = 'none'; }

if (toggleBtn) toggleBtn.addEventListener('click', openEdit);
if (cancelEditBtn) cancelEditBtn.addEventListener('click', closeEdit);

// --- SHOW REQUIRED in both summary and edit when score changes ---
function syncRequiredUnits(required) {
    const s1 = document.getElementById('summaryRequiredUnits');
    const s2 = document.getElementById('editRequiredUnits');
    const text = required ? (required + ' units') : '—';
    if (s1) s1.textContent = text;
    if (s2) s2.textContent = required || 0;
}

function syncCurrentUnits() {
    const unitsInputs = document.querySelectorAll('.units-input, input[name*="[units]"]');
    let total = 0;
    unitsInputs.forEach(input => { total += parseInt(input.value, 10) || 0; });

    // Update badges
    const required = parseInt((document.getElementById('editRequiredUnits')?.textContent || '0'), 10);
    const currentSlot = document.getElementById('summaryCurrentUnits');
    if (currentSlot) {
        const matched = required > 0 && total === required ? ' (auto-matched)' : '';
        currentSlot.textContent = `${total} units${matched}`;
    }

    const totalUnitsCount = document.getElementById('totalUnitsCount');
    if (totalUnitsCount) totalUnitsCount.textContent = total;
}

// hook into your existing calculator
const _origUpdateBridgingPreview = updateBridgingPreview;
updateBridgingPreview = function(score){
  _origUpdateBridgingPreview(score);
  const req = score >= 60 ? calculateBridgingUnits(score) : 0;
  syncRequiredUnits(req);
};

// update Current units whenever list changes
const _origUpdateTotalUnits = updateTotalUnits;
updateTotalUnits = function(){
  _origUpdateTotalUnits();
  syncCurrentUnits();
};

// --- Confirm delete row ---
function removeBridgingSubject(button){
  if (!confirm('Remove this subject from bridging requirements?')) return;
  button.closest('.bridging-subject-item').remove();
  updateTotalUnits();
     updateCurriculumStatus(); // Add this line
}
// keep name - this overrides your earlier definition

// --- Load Smart Suggestion button ---
document.getElementById('btnLoadSmart')?.addEventListener('click', loadSmartSuggestion);

function loadSmartSuggestion() {
    const scoreText = document.getElementById('currentLiveScore')?.textContent;
    let score = parseFloat(scoreText || '0');
    if (isNaN(score)) score = 0;

    const required = score >= 60 ? calculateBridgingUnits(score) : 0;
    if (required === 0) { 
        showToast('Smart suggestion needs 60%+ score.', 'warning'); 
        return; 
    }

    // Clear existing rows first
    const container = document.getElementById('bridgingSubjectsList');
    container.innerHTML = '';
    subjectCounter = 0;

    const rec = getSubjectRecommendations(programCode || '', required);
    
    rec.subjects.forEach(s => {
        addBridgingSubject();
        const row = container.lastElementChild;
        
        const selectElement = row.querySelector('select.subject-selector');
        const matchingOption = Array.from(selectElement.options).find(option => 
            option.value === s.name || option.textContent.trim() === s.name
        );
        
        if (matchingOption) {
            selectElement.value = matchingOption.value;
            updateSubjectDetails(selectElement, subjectCounter - 1);
        } else {
            selectElement.value = 'custom';
            updateSubjectDetails(selectElement, subjectCounter - 1);
            row.querySelector('input[name*="[custom_name]"]').value = s.name;
        }
        
        row.querySelector('input[name*="[code]"]').value = s.code || '';
        row.querySelector('input[name*="[units]"]').value = s.units || 3;
        row.querySelector('select[name*="[priority]"]').value = s.priority || 2;
    });

    updateTotalUnits();
    showToast('Bridging requirements refreshed manually.', 'success');
}

document.getElementById('btnRefreshSmart')?.addEventListener('click', function() {
    if (confirm('This will replace all current bridging subjects with fresh suggestions. Continue?')) {
        loadSmartSuggestion(); // Use the existing function but now as a manual refresh
    }
});

// Update the subjectCounter initialization for existing subjects
document.addEventListener('DOMContentLoaded', function() {
    // Set the counter based on existing subjects in the form
    const existingSubjects = document.querySelectorAll('.bridging-subject-item');
    subjectCounter = existingSubjects.length;
    
    
    // Rest of your existing initialization code...
});
// Update the existing updateBridgingPreview function to show the management section
// function updateBridgingPreview(score) {
//     const bridgingManagement = document.getElementById('bridgingManagement');
    
//     if (score >= 60) {
//         bridgingManagement.style.display = 'block';
//         updateTotalUnits(); // Update the total units display
//     } else {
//         bridgingManagement.style.display = 'none';
//     }
// }

function updateSubjectDetails(selectElement, index) {
    const selectedOption = selectElement.selectedOptions[0];
    const row = selectElement.closest('.row');
    const codeInput = row.querySelector('input[name*="[code]"]');
    const unitsInput = row.querySelector('input[name*="[units]"]');
    
    if (selectElement.value && selectedOption) {
        // Check for duplicates
        if (isDuplicateSubject(selectElement.value, index)) {
            showToast('This subject has already been added', 'warning');
            selectElement.value = '';
            codeInput.value = '';
            unitsInput.value = 3;
            return;
        }
        
        // Auto-fill from database
        codeInput.value = selectedOption.dataset.code || '';
        unitsInput.value = selectedOption.dataset.units || 3;
    } else {
        codeInput.value = '';
        unitsInput.value = 3;
    }
    
    updateTotalUnits();
    updateCurriculumStatus();
}

function updateCurriculumStatus() {
    // Get current score
    const scoreText = document.getElementById('currentLiveScore')?.textContent;
    let score = parseFloat(scoreText || '0');
    
    // Get all current bridging subjects
    const bridgingSubjects = [];
    document.querySelectorAll('.bridging-subject-item').forEach(row => {
        const selectElement = row.querySelector('select.subject-selector');
        const customInput = row.querySelector('input[name*="[custom_name]"]');
        
        let subjectName = '';
        if (selectElement.value === 'custom') {
            subjectName = customInput.value.trim();
        } else {
            subjectName = selectElement.value;
        }
        
        if (subjectName) {
            bridgingSubjects.push(subjectName);
        }
    });
    
    // Update each row in curriculum table
    document.querySelectorAll('#curriculumTableBody tr').forEach(row => {
        const subjectName = row.getAttribute('data-subject');
        const statusCell = row.querySelector('.status-cell');
        const evidenceCell = row.querySelector('.evidence-cell');
        
        if (score < 60) {
            // Below 60% = ALL subjects required (regular program)
            statusCell.innerHTML = '<span class="badge bg-danger">Required</span>';
            evidenceCell.textContent = 'Must enroll in regular program';
        } else if (bridgingSubjects.includes(subjectName)) {
            // Score ≥ 60% AND in bridging list = Required
            statusCell.innerHTML = '<span class="badge bg-warning text-dark">Required</span>';
            evidenceCell.textContent = 'To be completed';
        } else {
            // Score ≥ 60% AND NOT in bridging list = Passed
            statusCell.innerHTML = '<span class="badge bg-success">Passed</span>';
            if (evidenceCell.textContent === 'To be completed' || evidenceCell.textContent === 'Must enroll in regular program' || evidenceCell.textContent === '') {
                evidenceCell.textContent = 'Credit via ETEEAP assessment';
            }
        }
    });
}
document.addEventListener('DOMContentLoaded', function() {
    // ... existing initialization code ...
    
    // Initialize document modal
    initializeDocumentModal();
    
    // Initialize document-aware scoring
    initializeDocumentAwareScoring();
     updateCurriculumStatus();
    
    // Add event listeners to score inputs
    document.querySelectorAll('.smart-score-input').forEach(input => {
        input.addEventListener('input', function() {
            validateScoreInput(this);
            calculateSmartScore(); // This will now auto-load bridging requirements
        });
        
        input.addEventListener('blur', function() {
            validateScoreInput(this);
        });
        
        // Prevent manual input on locked fields
        input.addEventListener('keydown', function(e) {
            const hasDocs = this.getAttribute('data-has-docs') === 'true';
            if (!hasDocs && !['Tab', 'Shift', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) {
                e.preventDefault();
                showToast('Cannot modify score - no documents uploaded for this criteria', 'warning');
            }
        });
    });
    
    // Initialize score calculation and auto-load bridging if scores exist
    calculateSmartScore();
    
    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            document.querySelector('button[name="submit_evaluation"]')?.click();
        }
    });
    
    console.log('Enhanced ETEEAP Evaluation System with Auto-Loading Bridging Requirements initialized!');
});
        // Enhanced Document-aware scoring initialization
        function initializeDocumentAwareScoring() {
            // Initialize all score inputs based on document availability
            document.querySelectorAll('.smart-score-input').forEach(input => {
                const hasDocs = input.getAttribute('data-has-docs') === 'true';
                
                if (!hasDocs) {
                    // Lock score at 0 and style accordingly
                    input.value = '0';
                    input.readOnly = true;
                    input.style.backgroundColor = '#fff3cd';
                    input.style.cursor = 'not-allowed';
                    input.title = 'Score locked at 0 - no documents uploaded for this criteria';
                } else {
                    // Enable normal scoring
                    input.readOnly = false;
                    input.style.backgroundColor = '';
                    input.style.cursor = '';
                    input.title = 'Enter score based on document evidence';
                }
            });
        }

        function showToast(message, type = 'info') {
            // Create toast notification
            const toastContainer = document.getElementById('toast-container') || createToastContainer();
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-bg-${type} border-0`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-info-circle me-2"></i>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();
            
            toast.addEventListener('hidden.bs.toast', () => {
                toast.remove();
            });
        }

        function createToastContainer() {
            const container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1055';
            document.body.appendChild(container);
            return container;
        }

        // Initialize the enhanced evaluation system
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize document modal
            initializeDocumentModal();
            
            // Initialize document-aware scoring
            initializeDocumentAwareScoring();
            
            // Add event listeners to score inputs
            document.querySelectorAll('.smart-score-input').forEach(input => {
                input.addEventListener('input', function() {
                    validateScoreInput(this);
                    calculateSmartScore();
                });
                
                input.addEventListener('blur', function() {
                    validateScoreInput(this);
                });
                
                // Prevent manual input on locked fields
                input.addEventListener('keydown', function(e) {
                    const hasDocs = this.getAttribute('data-has-docs') === 'true';
                    if (!hasDocs && !['Tab', 'Shift', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) {
                        e.preventDefault();
                        showToast('Cannot modify score - no documents uploaded for this criteria', 'warning');
                    }
                });
            });
            
            // Initialize score calculation if there are existing scores
            calculateSmartScore();
            
            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    document.querySelector('button[name="submit_evaluation"]')?.click();
                }
            });
            
            console.log('Enhanced ETEEAP Evaluation System with Per-Criteria Document Management initialized!');
        });
    </script>
</body>
</html>