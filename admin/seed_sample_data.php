<?php
// admin/seed_sample_data.php - Add sample program suggestions for immediate testing
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

try {
    // Get programs from database first
    $stmt = $pdo->query("SELECT id, program_code, program_name FROM programs WHERE status = 'active' ORDER BY program_code");
    $programs = $stmt->fetchAll();
    
    if (empty($programs)) {
        echo json_encode([
            'success' => false,
            'error' => 'No active programs found. Please add programs first.'
        ]);
        exit();
    }
    
    // Create a mapping of program codes to IDs
    $program_map = [];
    foreach ($programs as $program) {
        $program_map[$program['program_code']] = $program['id'];
    }
    
    // Get application forms that don't have program suggestions
    $stmt = $pdo->query("
        SELECT af.id, af.original_filename, af.user_id
        FROM application_forms af
        WHERE af.status = 'pending_review' 
        AND af.program_suggestion_id IS NULL
        ORDER BY af.upload_date DESC
        LIMIT 20
    ");
    
    $forms = $stmt->fetchAll();
    $updated = 0;
    
    // Sample program suggestions based on common filename patterns
    $filename_patterns = [
        // IT/CS patterns
        'it' => ['BSIT', 75],
        'computer' => ['BSIT', 70], 
        'programming' => ['BSIT', 80],
        'software' => ['BSIT', 75],
        'web' => ['BSIT', 70],
        'database' => ['BSIT', 75],
        'network' => ['BSIT', 70],
        'tech' => ['BSIT', 65],
        'cs' => ['BSCS', 85],
        'science' => ['BSCS', 60],
        
        // Business patterns
        'business' => ['BSBA', 75],
        'management' => ['BSBA', 70],
        'admin' => ['BSBA', 65],
        'marketing' => ['BSBA', 70],
        'finance' => ['BSBA', 70],
        'accounting' => ['BSA', 85],
        'cpa' => ['BSA', 90],
        'audit' => ['BSA', 80],
        
        // Engineering patterns
        'electrical' => ['BSEE', 80],
        'electronics' => ['BSEE', 75],
        'mechanical' => ['BSME', 80],
        'civil' => ['BSCE', 80],
        'construction' => ['BSCE', 70],
        'engineering' => ['BSEE', 60], // Default to EE
        
        // Education patterns
        'education' => ['BEED', 70],
        'teaching' => ['BEED', 75],
        'teacher' => ['BEED', 80],
        'elementary' => ['BEED', 85],
        'secondary' => ['BSED', 85],
        
        // Healthcare patterns
        'nursing' => ['BSN', 90],
        'nurse' => ['BSN', 85],
        'medical' => ['BSN', 70],
        'healthcare' => ['BSN', 70],
        
        // Tourism patterns
        'tourism' => ['BSTM', 85],
        'hospitality' => ['BSTM', 80],
        'hotel' => ['BSTM', 75],
        'travel' => ['BSTM', 70]
    ];
    
    foreach ($forms as $form) {
        $filename = strtolower($form['original_filename']);
        $best_match = null;
        $best_confidence = 0;
        
        // Check filename against patterns
        foreach ($filename_patterns as $pattern => $suggestion) {
            if (stripos($filename, $pattern) !== false) {
                $program_code = $suggestion[0];
                $confidence = $suggestion[1];
                
                if ($confidence > $best_confidence && isset($program_map[$program_code])) {
                    $best_match = [
                        'program_id' => $program_map[$program_code],
                        'program_code' => $program_code,
                        'confidence' => $confidence,
                        'pattern' => $pattern
                    ];
                    $best_confidence = $confidence;
                }
            }
        }
        
        // If no specific pattern matched, give a random suggestion for demo purposes
        if (!$best_match && !empty($programs)) {
            $random_program = $programs[array_rand($programs)];
            $best_match = [
                'program_id' => $random_program['id'],
                'program_code' => $random_program['program_code'],
                'confidence' => rand(45, 65), // Lower confidence for random suggestions
                'pattern' => 'general'
            ];
        }
        
        if ($best_match) {
            // Update the application form with suggestion
            $stmt = $pdo->prepare("
                UPDATE application_forms 
                SET program_suggestion_id = ?,
                    extracted_program = ?,
                    extracted_program_confidence = ?,
                    processed_date = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $best_match['program_id'],
                "Detected: " . $best_match['pattern'] . " (" . $best_match['program_code'] . ")",
                $best_match['confidence'],
                $form['id']
            ]);
            
            $updated++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'processed' => count($forms),
        'updated' => $updated,
        'message' => "Successfully added program suggestions to {$updated} out of " . count($forms) . " application forms."
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>