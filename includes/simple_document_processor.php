<?php
// includes/simple_document_processor.php

/**
 * Simple Document Processor for ETEEAP Program Detection
 * Extracts program information from uploaded application forms
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Program keywords and patterns for detection
 */
$PROGRAM_PATTERNS = [
    // Information Technology programs
    'BSIT' => [
        'keywords' => ['information technology', 'computer science', 'programming', 'software', 'IT', 'BSIT', 'BS IT', 'computer programming', 'web development', 'database', 'networking', 'system administration', 'tech', 'technology'],
        'program_codes' => ['BSIT', 'BS-IT', 'BS IT'],
        'confidence_multiplier' => 1.0
    ],
    'BSCS' => [
        'keywords' => ['computer science', 'CS', 'BSCS', 'BS CS', 'algorithms', 'data structures', 'artificial intelligence', 'machine learning', 'software engineering', 'computer'],
        'program_codes' => ['BSCS', 'BS-CS', 'BS CS'],
        'confidence_multiplier' => 1.0
    ],
    
    // Business Administration programs
    'BSBA' => [
        'keywords' => ['business administration', 'business management', 'BA', 'BSBA', 'BS BA', 'management', 'marketing', 'finance', 'accounting', 'entrepreneurship', 'human resources', 'business', 'admin', 'commerce'],
        'program_codes' => ['BSBA', 'BS-BA', 'BS BA', 'BBA'],
        'confidence_multiplier' => 1.0
    ],
    'BSA' => [
        'keywords' => ['accountancy', 'accounting', 'BSA', 'CPA', 'auditing', 'financial accounting', 'cost accounting', 'bookkeeping', 'audit'],
        'program_codes' => ['BSA', 'BS-A'],
        'confidence_multiplier' => 1.0
    ],
    
    // Engineering programs
    'BSEE' => [
        'keywords' => ['electrical engineering', 'electronics', 'BSEE', 'BS EE', 'electrical', 'power systems', 'telecommunications', 'circuit', 'electronic'],
        'program_codes' => ['BSEE', 'BS-EE', 'BS EE'],
        'confidence_multiplier' => 1.0
    ],
    'BSME' => [
        'keywords' => ['mechanical engineering', 'BSME', 'BS ME', 'mechanical', 'thermodynamics', 'machine design', 'manufacturing', 'machine'],
        'program_codes' => ['BSME', 'BS-ME', 'BS ME'],
        'confidence_multiplier' => 1.0
    ],
    'BSCE' => [
        'keywords' => ['civil engineering', 'BSCE', 'BS CE', 'civil', 'construction', 'structural engineering', 'surveying', 'structural'],
        'program_codes' => ['BSCE', 'BS-CE', 'BS CE'],
        'confidence_multiplier' => 1.0
    ],
    
    // Education programs
    'BEED' => [
        'keywords' => ['elementary education', 'BEED', 'teaching', 'education', 'pedagogy', 'teacher', 'elementary'],
        'program_codes' => ['BEED', 'BS-ED'],
        'confidence_multiplier' => 1.0
    ],
    'BSED' => [
        'keywords' => ['secondary education', 'BSED', 'BS ED', 'teaching', 'education major', 'secondary', 'teacher'],
        'program_codes' => ['BSED', 'BS-ED', 'BS ED'],
        'confidence_multiplier' => 1.0
    ],
    
    // Nursing and Health Sciences
    'BSN' => [
        'keywords' => ['nursing', 'BSN', 'registered nurse', 'RN', 'healthcare', 'medical', 'nurse', 'patient care', 'health'],
        'program_codes' => ['BSN'],
        'confidence_multiplier' => 1.0
    ],
    
    // Tourism and Hospitality
    'BSTM' => [
        'keywords' => ['tourism management', 'BSTM', 'BS TM', 'hospitality', 'hotel management', 'tourism', 'travel', 'hotel', 'resort'],
        'program_codes' => ['BSTM', 'BS-TM', 'BS TM'],
        'confidence_multiplier' => 1.0
    ],
    
    // Agriculture
    'BSA-AG' => [
        'keywords' => ['agriculture', 'agribusiness', 'farming', 'agricultural engineering', 'crop science', 'agricultural', 'agri'],
        'program_codes' => ['BSA', 'BS-AG'],
        'confidence_multiplier' => 1.0
    ],
    
    // Psychology
    'BSPSYCH' => [
        'keywords' => ['psychology', 'psych', 'psychological', 'counseling', 'behavioral', 'mental health'],
        'program_codes' => ['BSPSYCH', 'BS-PSYCH', 'AB PSYCH'],
        'confidence_multiplier' => 1.0
    ],
    
    // Criminal Justice
    'BSCRIM' => [
        'keywords' => ['criminology', 'criminal justice', 'law enforcement', 'police', 'security', 'forensic'],
        'program_codes' => ['BSCRIM', 'BS-CRIM'],
        'confidence_multiplier' => 1.0
    ]
];

/**
 * Extract text from PDF file (simplified version)
 * In production, you would use a proper PDF parser like pdf2text or similar
 */
function extractTextFromPDF($filePath) {
    // This is a simplified implementation
    // In a real application, you would use:
    // - pdf2text command line tool
    // - TCPDF parser
    // - PDFParser library
    // - Or similar PDF text extraction library
    
    $text = '';
    
    // Try to read PDF as text (this won't work for most PDFs)
    if (function_exists('shell_exec')) {
        // Attempt to use pdftotext if available
        $command = "pdftotext '{$filePath}' -";
        $output = @shell_exec($command);
        if ($output !== null) {
            $text = $output;
        }
    }
    
    // If PDF extraction failed, return filename as fallback
    if (empty(trim($text))) {
        $filename = basename($filePath);
        $text = $filename;
    }
    
    return $text;
}

/**
 * Extract text from image file using OCR (simplified version)
 */
function extractTextFromImage($filePath) {
    // This is a placeholder for OCR functionality
    // In production, you would use:
    // - Tesseract OCR
    // - Google Vision API
    // - AWS Textract
    // - Or similar OCR service
    
    $filename = basename($filePath);
    return $filename; // Return filename as fallback
}

/**
 * Extract text content from file based on type
 */
function extractTextFromFile($filePath, $mimeType) {
    $text = '';
    
    switch ($mimeType) {
        case 'application/pdf':
            $text = extractTextFromPDF($filePath);
            break;
            
        case 'image/jpeg':
        case 'image/jpg':
        case 'image/png':
            $text = extractTextFromImage($filePath);
            break;
            
        default:
            // For unknown types, use filename
            $text = basename($filePath);
            break;
    }
    
    return $text;
}

/**
 * Detect program from extracted text
 */
function detectProgramFromText($text) {
    global $PROGRAM_PATTERNS, $pdo;
    
    $text = strtolower($text);
    $bestMatch = null;
    $highestScore = 0;
    $matchedText = '';
    
    foreach ($PROGRAM_PATTERNS as $programKey => $pattern) {
        $score = 0;
        $matches = [];
        
        // Check for exact program code matches (highest priority)
        foreach ($pattern['program_codes'] as $code) {
            if (stripos($text, strtolower($code)) !== false) {
                $score += 50 * $pattern['confidence_multiplier'];
                $matches[] = $code;
            }
        }
        
        // Check for keyword matches
        foreach ($pattern['keywords'] as $keyword) {
            $keyword = strtolower($keyword);
            $count = substr_count($text, $keyword);
            if ($count > 0) {
                $score += ($count * 10) * $pattern['confidence_multiplier'];
                if ($count > 0) {
                    $matches[] = $keyword;
                }
            }
        }
        
        // Bonus for multiple keyword matches
        if (count($matches) > 1) {
            $score += count($matches) * 5;
        }
        
        if ($score > $highestScore) {
            $highestScore = $score;
            $bestMatch = $programKey;
            $matchedText = implode(', ', array_unique($matches));
        }
    }
    
    // Calculate confidence percentage
    $confidence = min(100, ($highestScore / 100) * 100);
    
    // Get program ID from database
    $programId = null;
    if ($bestMatch && $confidence > 20) { // Only suggest if confidence > 20%
        try {
            $stmt = $pdo->prepare("
                SELECT id FROM programs 
                WHERE program_code LIKE ? OR program_name LIKE ? 
                ORDER BY status = 'active' DESC 
                LIMIT 1
            ");
            $stmt->execute(["%{$bestMatch}%", "%{$bestMatch}%"]);
            $result = $stmt->fetch();
            if ($result) {
                $programId = $result['id'];
            }
        } catch (PDOException $e) {
            // Ignore database errors in detection
        }
    }
    
    return [
        'detected_program' => $bestMatch,
        'confidence' => $confidence,
        'matched_text' => $matchedText,
        'program_id' => $programId,
        'full_text_sample' => substr($text, 0, 200) // Store sample for debugging
    ];
}

/**
 * Process a single document for program detection
 */
function processDocument($documentId) {
    global $pdo;
    
    try {
        // Get document information
        $stmt = $pdo->prepare("
            SELECT af.*, u.first_name, u.last_name 
            FROM application_forms af
            LEFT JOIN users u ON af.user_id = u.id
            WHERE af.id = ? AND af.status = 'pending_review'
        ");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch();
        
        if (!$document) {
            return false;
        }
        
        // Check if file exists
        if (!file_exists($document['file_path'])) {
            return false;
        }
        
        // Extract text from document
        $extractedText = extractTextFromFile($document['file_path'], $document['mime_type']);
        
        // Detect program from text
        $detection = detectProgramFromText($extractedText);
        
        // Update database with detection results
        $stmt = $pdo->prepare("
            UPDATE application_forms 
            SET extracted_text = ?,
                extracted_program = ?,
                extracted_program_confidence = ?,
                program_suggestion_id = ?,
                processed_date = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $detection['full_text_sample'],
            $detection['matched_text'],
            $detection['confidence'],
            $detection['program_id'],
            $documentId
        ]);
        
        // Log the processing
        error_log("ETEEAP: Processed document {$documentId} for user {$document['user_id']} - Detected: {$detection['detected_program']} ({$detection['confidence']}% confidence)");
        
        return true;
        
    } catch (Exception $e) {
        error_log("ETEEAP: Error processing document {$documentId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Process pending documents in batches
 */
function processPendingDocuments($batchSize = 10) {
    global $pdo;
    
    try {
        // Get unprocessed documents
        $stmt = $pdo->prepare("
            SELECT id FROM application_forms 
            WHERE status = 'pending_review' 
            AND (processed_date IS NULL OR extracted_program_confidence IS NULL)
            ORDER BY upload_date ASC 
            LIMIT ?
        ");
        $stmt->execute([$batchSize]);
        $documents = $stmt->fetchAll();
        
        $processed = 0;
        $successful = 0;
        
        foreach ($documents as $doc) {
            $processed++;
            if (processDocument($doc['id'])) {
                $successful++;
            }
            
            // Small delay to prevent system overload
            usleep(100000); // 0.1 second
        }
        
        return [
            'processed' => $processed,
            'successful' => $successful,
            'remaining' => max(0, count($documents) - $processed)
        ];
        
    } catch (PDOException $e) {
        error_log("ETEEAP: Error in batch processing: " . $e->getMessage());
        return false;
    }
}

/**
 * Get program detection statistics
 */
function getProgramDetectionStats() {
    global $pdo;
    
    try {
        $stats = [];
        
        // Total documents processed
        $stmt = $pdo->query("
            SELECT COUNT(*) as total 
            FROM application_forms 
            WHERE processed_date IS NOT NULL
        ");
        $stats['total_processed'] = $stmt->fetch()['total'];
        
        // Documents with program suggestions
        $stmt = $pdo->query("
            SELECT COUNT(*) as total 
            FROM application_forms 
            WHERE program_suggestion_id IS NOT NULL
        ");
        $stats['with_suggestions'] = $stmt->fetch()['total'];
        
        // Average confidence
        $stmt = $pdo->query("
            SELECT AVG(extracted_program_confidence) as avg_confidence 
            FROM application_forms 
            WHERE extracted_program_confidence IS NOT NULL
        ");
        $avgConf = $stmt->fetch()['avg_confidence'];
        $stats['average_confidence'] = $avgConf ? round($avgConf, 1) : 0;
        
        // High confidence suggestions (>= 80%)
        $stmt = $pdo->query("
            SELECT COUNT(*) as total 
            FROM application_forms 
            WHERE extracted_program_confidence >= 80
        ");
        $stats['high_confidence'] = $stmt->fetch()['total'];
        
        // Pending processing
        $stmt = $pdo->query("
            SELECT COUNT(*) as total 
            FROM application_forms 
            WHERE status = 'pending_review' AND processed_date IS NULL
        ");
        $stats['pending_processing'] = $stmt->fetch()['total'];
        
        return $stats;
        
    } catch (PDOException $e) {
        return [
            'total_processed' => 0,
            'with_suggestions' => 0,
            'average_confidence' => 0,
            'high_confidence' => 0,
            'pending_processing' => 0
        ];
    }
}

/**
 * Manual program suggestion override
 */
function setProgramSuggestion($documentId, $programId, $confidence = 100, $note = 'Manual override') {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE application_forms 
            SET program_suggestion_id = ?,
                extracted_program_confidence = ?,
                extracted_program = ?,
                processed_date = NOW()
            WHERE id = ?
        ");
        
        return $stmt->execute([$programId, $confidence, $note, $documentId]);
        
    } catch (PDOException $e) {
        return false;
    }
}

// Auto-process documents when this file is included
if (php_sapi_name() !== 'cli') {
    // Only auto-process in web context, not CLI
    register_shutdown_function(function() {
        // Process a few documents on each page load (background processing)
        processPendingDocuments(2);
    });
}
?>