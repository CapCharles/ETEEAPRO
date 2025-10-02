<?php
// includes/focused_program_extractor.php

/**
 * Focused Program Extractor
 * Specifically looks for degree program choices in application forms
 */

class FocusedProgramExtractor {
    private $pdo;
    private $programs = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadPrograms();
    }
    
    private function loadPrograms() {
        try {
            $stmt = $this->pdo->query("SELECT * FROM programs WHERE status = 'active' ORDER BY program_name");
            $this->programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Failed to load programs: " . $e->getMessage());
        }
    }
    
    /**
     * Extract program choices from application form text
     */
    public function extractProgramChoice($text) {
        $text = $this->cleanText($text);
        
        // Define patterns specifically for application forms
        $patterns = [
            // First choice patterns
            '/(?:first\s+choice|1st\s+choice|choice\s+1)[\s\:]*([^\n\r]+)/i',
            '/(?:preferred\s+program|desired\s+program|program\s+preference)[\s\:]*([^\n\r]+)/i',
            
            // Second choice patterns  
            '/(?:second\s+choice|2nd\s+choice|choice\s+2|alternative\s+program)[\s\:]*([^\n\r]+)/i',
            
            // General degree program patterns
            '/(?:degree\s+program|program|course\s+applied\s+for)[\s\:]*([^\n\r]+)/i',
            '/(?:field\s+being\s+applied\s+for|applying\s+for)[\s\:]*([^\n\r]+)/i',
            '/(?:intended\s+degree|program\s+of\s+study)[\s\:]*([^\n\r]+)/i',
        ];
        
        $extracted_choices = [];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $match) {
                    $cleaned_match = trim($match);
                    if (!empty($cleaned_match) && strlen($cleaned_match) > 3) {
                        // Determine if this is first choice, second choice, etc.
                        $choice_type = $this->determineChoiceType($pattern, $match, $text);
                        $extracted_choices[] = [
                            'raw_text' => $cleaned_match,
                            'choice_type' => $choice_type,
                            'matched_program' => $this->matchToExistingProgram($cleaned_match)
                        ];
                    }
                }
            }
        }
        
        // Remove duplicates and sort by priority
        $extracted_choices = $this->prioritizeChoices($extracted_choices);
        
        return [
            'choices' => $extracted_choices,
            'primary_choice' => !empty($extracted_choices) ? $extracted_choices[0] : null,
            'confidence' => $this->calculateOverallConfidence($extracted_choices)
        ];
    }
    
    /**
     * Determine what type of choice this is (first, second, etc.)
     */
    private function determineChoiceType($pattern, $match, $fullText) {
        $pattern_lower = strtolower($pattern);
        
        if (strpos($pattern_lower, 'first') !== false || strpos($pattern_lower, '1st') !== false) {
            return 'first_choice';
        } elseif (strpos($pattern_lower, 'second') !== false || strpos($pattern_lower, '2nd') !== false) {
            return 'second_choice';
        } elseif (strpos($pattern_lower, 'preferred') !== false || strpos($pattern_lower, 'desired') !== false) {
            return 'preferred';
        } else {
            return 'general';
        }
    }
    
    /**
     * Match extracted text to existing programs in database
     */
    private function matchToExistingProgram($extractedText) {
        $extractedText = strtolower($extractedText);
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($this->programs as $program) {
            $score = $this->calculateMatchScore($extractedText, $program);
            
            if ($score > $bestScore && $score > 0.5) { // Only high confidence matches
                $bestScore = $score;
                $bestMatch = [
                    'program_id' => $program['id'],
                    'program_name' => $program['program_name'],
                    'program_code' => $program['program_code'],
                    'match_score' => $score
                ];
            }
        }
        
        return $bestMatch;
    }
    
    /**
     * Calculate how well extracted text matches a program
     */
    private function calculateMatchScore($extractedText, $program) {
        $programName = strtolower($program['program_name']);
        $programCode = strtolower($program['program_code']);
        $score = 0;
        
        // Exact program code match (highest priority)
        if (strpos($extractedText, $programCode) !== false) {
            $score = 0.95;
        }
        // Exact program name match
        elseif (strpos($extractedText, $programName) !== false) {
            $score = 0.90;
        }
        // Partial name match
        else {
            $programWords = explode(' ', $programName);
            $extractedWords = explode(' ', $extractedText);
            $matchingWords = array_intersect($programWords, $extractedWords);
            
            if (!empty($matchingWords)) {
                $score = (count($matchingWords) / count($programWords)) * 0.8;
                
                // Bonus for important keywords
                $importantKeywords = ['information', 'technology', 'business', 'administration', 'engineering', 'education'];
                foreach ($importantKeywords as $keyword) {
                    if (in_array($keyword, $matchingWords)) {
                        $score += 0.1;
                    }
                }
            }
        }
        
        return min(1.0, $score);
    }
    
    /**
     * Prioritize choices (first choice gets priority, then by confidence)
     */
    private function prioritizeChoices($choices) {
        usort($choices, function($a, $b) {
            // Priority order: first_choice > preferred > second_choice > general
            $priority_order = [
                'first_choice' => 4,
                'preferred' => 3,
                'second_choice' => 2,
                'general' => 1
            ];
            
            $a_priority = $priority_order[$a['choice_type']] ?? 0;
            $b_priority = $priority_order[$b['choice_type']] ?? 0;
            
            if ($a_priority === $b_priority) {
                // Same priority, sort by match score
                $a_score = $a['matched_program']['match_score'] ?? 0;
                $b_score = $b['matched_program']['match_score'] ?? 0;
                return $b_score <=> $a_score;
            }
            
            return $b_priority <=> $a_priority;
        });
        
        // Remove duplicates (same program)
        $unique_choices = [];
        $seen_programs = [];
        
        foreach ($choices as $choice) {
            $program_id = $choice['matched_program']['program_id'] ?? null;
            if ($program_id && !in_array($program_id, $seen_programs)) {
                $unique_choices[] = $choice;
                $seen_programs[] = $program_id;
            } elseif (!$program_id) {
                $unique_choices[] = $choice; // Keep unmatched choices for manual review
            }
        }
        
        return $unique_choices;
    }
    
    /**
     * Calculate overall confidence based on all extracted choices
     */
    private function calculateOverallConfidence($choices) {
        if (empty($choices)) {
            return 0;
        }
        
        $total_confidence = 0;
        $count = 0;
        
        foreach ($choices as $choice) {
            if (isset($choice['matched_program']['match_score'])) {
                $total_confidence += $choice['matched_program']['match_score'];
                $count++;
            }
        }
        
        return $count > 0 ? round($total_confidence / $count, 2) : 0;
    }
    
    /**
     * Clean and normalize text for processing
     */
    private function cleanText($text) {
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        // Remove common OCR artifacts
        $text = str_replace(['_', '|', '~', '*'], ' ', $text);
        
        // Normalize some common abbreviations
        $text = str_replace(['B.S.', 'BS', 'B.A.', 'BA'], ['Bachelor of Science', 'Bachelor of Science', 'Bachelor of Arts', 'Bachelor of Arts'], $text);
        
        return $text;
    }
    
    /**
     * Process application form document
     */
    public function processApplicationForm($applicationFormId) {
        try {
            // Get document info
            $stmt = $this->pdo->prepare("SELECT * FROM application_forms WHERE id = ?");
            $stmt->execute([$applicationFormId]);
            $document = $stmt->fetch();
            
            if (!$document || !file_exists($document['file_path'])) {
                return false;
            }
            
            // Extract text from document
            $text = $this->extractTextFromFile($document['file_path']);
            
            if (empty($text)) {
                return false;
            }
            
            // Extract program choices
            $extraction_result = $this->extractProgramChoice($text);
            
            if ($extraction_result['primary_choice'] && $extraction_result['primary_choice']['matched_program']) {
                // Update database with results
                $primary_choice = $extraction_result['primary_choice'];
                
                $stmt = $this->pdo->prepare("
                    UPDATE application_forms 
                    SET extracted_program = ?, 
                        extracted_program_confidence = ?, 
                        program_suggestion_id = ?
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $primary_choice['raw_text'] . ' (' . $primary_choice['choice_type'] . ')',
                    $extraction_result['confidence'],
                    $primary_choice['matched_program']['program_id'],
                    $applicationFormId
                ]);
                
                return $extraction_result;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Error processing application form: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Simple text extraction from files
     */
    private function extractTextFromFile($filePath) {
        if (!file_exists($filePath)) {
            return '';
        }
        
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        switch ($extension) {
            case 'txt':
                return file_get_contents($filePath);
                
            case 'pdf':
                // Try to extract text using pdftotext if available
                if (function_exists('shell_exec')) {
                    $output = shell_exec("pdftotext '$filePath' -");
                    if (!empty($output)) {
                        return $output;
                    }
                }
                
                // Fallback: simple PDF text extraction
                $content = file_get_contents($filePath);
                if (preg_match_all('/\((.*?)\)/', $content, $matches)) {
                    return implode(' ', $matches[1]);
                }
                return '';
                
            default:
                return '';
        }
    }
    
    /**
     * Get extraction summary for admin review
     */
    public function getExtractionSummary($userId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    af.id,
                    af.original_filename,
                    af.extracted_program,
                    af.extracted_program_confidence,
                    p.program_name,
                    p.program_code
                FROM application_forms af
                LEFT JOIN programs p ON af.program_suggestion_id = p.id
                WHERE af.user_id = ? AND af.status = 'pending_review'
                ORDER BY af.extracted_program_confidence DESC
            ");
            
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>