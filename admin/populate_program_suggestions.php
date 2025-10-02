<?php
// admin/populate_program_suggestions.php - One-time script to populate program suggestions
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/simple_document_processor.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

$processed = 0;
$success_count = 0;
$errors = [];

try {
    // Get all pending application forms that don't have program suggestions yet
    $stmt = $pdo->query("
        SELECT af.*, u.first_name, u.last_name 
        FROM application_forms af
        LEFT JOIN users u ON af.user_id = u.id
        WHERE af.status = 'pending_review' 
        AND (af.program_suggestion_id IS NULL OR af.extracted_program_confidence IS NULL)
        ORDER BY af.upload_date ASC
    ");
    
    $documents = $stmt->fetchAll();
    
    foreach ($documents as $doc) {
        $processed++;
        
        // Generate a suggested program based on filename and any available data
        $suggestion = generateProgramSuggestionFromFilename($doc['original_filename'], $doc['file_description']);
        
        if ($suggestion['program_id']) {
            // Update the document with suggestion
            $update_stmt = $pdo->prepare("
                UPDATE application_forms 
                SET program_suggestion_id = ?,
                    extracted_program = ?,
                    extracted_program_confidence = ?,
                    processed_date = NOW()
                WHERE id = ?
            ");
            
            $update_stmt->execute([
                $suggestion['program_id'],
                $suggestion['detected_text'],
                $suggestion['confidence'],
                $doc['id']
            ]);
            
            $success_count++;
        }
        
        // Small delay to prevent system overload
        if ($processed % 5 == 0) {
            usleep(100000); // 0.1 second delay every 5 documents
        }
    }
    
    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'successful' => $success_count,
        'message' => "Successfully processed {$processed} documents. {$success_count} got program suggestions."
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Generate program suggestion based on filename and description
 */
function generateProgramSuggestionFromFilename($filename, $description = '') {
    global $pdo;
    
    $text = strtolower($filename . ' ' . $description);
    $suggestions = [];
    
    // Define program patterns with keywords that might appear in filenames
    $patterns = [
        'BSIT' => [
            'keywords' => ['it', 'information technology', 'computer', 'programming', 'software', 'web', 'database', 'network', 'system', 'tech'],
            'confidence_base' => 75
        ],
        'BSCS' => [
            'keywords' => ['cs', 'computer science', 'algorithms', 'data structure', 'software engineering', 'programming'],
            'confidence_base' => 80
        ],
        'BSBA' => [
            'keywords' => ['business', 'management', 'admin', 'marketing', 'finance', 'entrepreneur', 'commerce'],
            'confidence_base' => 70
        ],
        'BSA' => [
            'keywords' => ['accounting', 'accountancy', 'cpa', 'audit', 'finance', 'bookkeeping'],
            'confidence_base' => 85
        ],
        'BSEE' => [
            'keywords' => ['electrical', 'electronics', 'power', 'circuit', 'engineering'],
            'confidence_base' => 80
        ],
        'BSME' => [
            'keywords' => ['mechanical', 'machine', 'manufacturing', 'engineering'],
            'confidence_base' => 80
        ],
        'BSCE' => [
            'keywords' => ['civil', 'construction', 'structural', 'engineering'],
            'confidence_base' => 80
        ],
        'BEED' => [
            'keywords' => ['elementary', 'education', 'teaching', 'teacher', 'pedagogy'],
            'confidence_base' => 75
        ],
        'BSN' => [
            'keywords' => ['nursing', 'nurse', 'medical', 'healthcare', 'patient care'],
            'confidence_base' => 85
        ],
        'BSTM' => [
            'keywords' => ['tourism', 'travel', 'hospitality', 'hotel', 'resort'],
            'confidence_base' => 80
        ]
    ];
    
    $best_match = null;
    $highest_score = 0;
    
    foreach ($patterns as $program_code => $pattern) {
        $score = 0;
        $matched_keywords = [];
        
        // Check for exact program code in filename
        if (stripos($text, strtolower($program_code)) !== false) {
            $score += 50;
            $matched_keywords[] = $program_code;
        }
        
        // Check for keywords
        foreach ($pattern['keywords'] as $keyword) {
            if (stripos($text, $keyword) !== false) {
                $score += 10;
                $matched_keywords[] = $keyword;
            }
        }
        
        if ($score > $highest_score) {
            $highest_score = $score;
            $best_match = [
                'program_code' => $program_code,
                'confidence' => min(95, $pattern['confidence_base'] + ($score - 10)),
                'keywords' => $matched_keywords
            ];
        }
    }
    
    // Get program ID from database
    $program_id = null;
    if ($best_match && $highest_score >= 10) {
        try {
            $stmt = $pdo->prepare("
                SELECT id FROM programs 
                WHERE program_code LIKE ? OR program_name LIKE ?
                AND status = 'active'
                ORDER BY program_code = ? DESC
                LIMIT 1
            ");
            $stmt->execute([
                '%' . $best_match['program_code'] . '%',
                '%' . $best_match['program_code'] . '%',
                $best_match['program_code']
            ]);
            $result = $stmt->fetch();
            if ($result) {
                $program_id = $result['id'];
            }
        } catch (PDOException $e) {
            // Ignore database errors
        }
    }
    
    return [
        'program_id' => $program_id,
        'confidence' => $best_match ? $best_match['confidence'] : 0,
        'detected_text' => $best_match ? implode(', ', $best_match['keywords']) : '',
        'program_code' => $best_match ? $best_match['program_code'] : null
    ];
}
?>