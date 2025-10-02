<?php
/**
 * Intelligent Recommendations System for ETEEAP
 * AI-Enhanced evaluation with automatic recommendations generation
 */

/**
 * Process evaluation with intelligent recommendations
 * @param PDO $pdo Database connection
 * @param int $applicationId Application ID
 * @param array $evaluations Array of evaluation scores
 * @param int $evaluatorId Evaluator user ID
 * @return array Analysis result with recommendations
 */
function processEvaluationWithRecommendations($pdo, $applicationId, $evaluations, $evaluatorId) {
    try {
        $pdo->beginTransaction();
        
        // Get application and program details
        $stmt = $pdo->prepare("
            SELECT a.*, p.program_name, p.program_code, p.id as program_id,
                   u.first_name, u.last_name, u.email
            FROM applications a
            JOIN programs p ON a.program_id = p.id
            JOIN users u ON a.user_id = u.id
            WHERE a.id = ?
        ");
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch();
        
        if (!$application) {
            throw new Exception("Application not found");
        }
        
        // Calculate scores and analyze performance
        $analysisResult = calculateIntelligentScores($pdo, $applicationId, $evaluations, $application['program_id']);
        
        // Save individual evaluations
        foreach ($evaluations as $criteriaId => $evaluation) {
            $score = floatval($evaluation['score']);
            $comments = trim($evaluation['comments']);
            
            // Get criteria info
            $stmt = $pdo->prepare("SELECT * FROM assessment_criteria WHERE id = ?");
            $stmt->execute([$criteriaId]);
            $criteria = $stmt->fetch();
            
            if ($criteria) {
                // Insert or update evaluation
                $stmt = $pdo->prepare("
                    INSERT INTO evaluations (application_id, criteria_id, evaluator_id, score, max_score, comments, evaluation_date)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                    score = VALUES(score), comments = VALUES(comments), evaluation_date = VALUES(evaluation_date)
                ");
                $stmt->execute([$applicationId, $criteriaId, $evaluatorId, $score, $criteria['max_score'], $comments]);
            }
        }
        
        // Update application with final results
        $stmt = $pdo->prepare("
            UPDATE applications 
            SET application_status = ?, total_score = ?, recommendation = ?, 
                evaluator_id = ?, evaluation_date = NOW(),
                system_generated_recommendation = TRUE,
                recommendation_confidence = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $analysisResult['final_status'], 
            $analysisResult['overall_score'], 
            $analysisResult['summary'],
            $evaluatorId,
            $analysisResult['confidence'],
            $applicationId
        ]);
        
        // Save section scores
        saveSectionScores($pdo, $applicationId, $analysisResult['section_scores']);
        
        // Generate and save intelligent recommendations
        generateIntelligentRecommendations($pdo, $applicationId, $analysisResult);
        
        // Log the AI evaluation activity
        $stmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, table_name, record_id, new_values)
            VALUES (?, 'ai_evaluation_completed', 'applications', ?, ?)
        ");
        $stmt->execute([
            $evaluatorId, 
            $applicationId, 
            json_encode([
                'overall_score' => $analysisResult['overall_score'],
                'final_status' => $analysisResult['final_status'],
                'recommendations_count' => count($analysisResult['recommendations'])
            ])
        ]);
        
        $pdo->commit();
        return $analysisResult;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Calculate intelligent scores with section analysis
 */
function calculateIntelligentScores($pdo, $applicationId, $evaluations, $programId) {
    // Get all criteria for the program
    $stmt = $pdo->prepare("
        SELECT * FROM assessment_criteria 
        WHERE program_id = ? AND status = 'active' 
        ORDER BY section_number, subsection
    ");
    $stmt->execute([$programId]);
    $allCriteria = $stmt->fetchAll();
    
    // Group criteria by sections
    $sections = [];
    $totalWeightedScore = 0;
    $totalWeight = 0;
    
    foreach ($allCriteria as $criteria) {
        $sectionKey = $criteria['section_number'];
        $criteriaId = $criteria['id'];
        
        if (!isset($sections[$sectionKey])) {
            $sections[$sectionKey] = [
                'section_name' => "Section {$criteria['section_number']}: " . ucfirst($criteria['criteria_type']),
                'section_number' => $criteria['section_number'],
                'criteria' => [],
                'total_score' => 0,
                'max_possible' => 0,
                'weight_sum' => 0,
                'passing_threshold' => 60 // Default 60% to pass each section
            ];
        }
        
        // Add criteria to section
        $sections[$sectionKey]['criteria'][] = $criteria;
        $sections[$sectionKey]['max_possible'] += $criteria['max_score'];
        $sections[$sectionKey]['weight_sum'] += $criteria['weight'];
        
        // Calculate score if evaluation exists
        if (isset($evaluations[$criteriaId])) {
            $score = floatval($evaluations[$criteriaId]['score']);
            $maxScore = $criteria['max_score'];
            $weight = $criteria['weight'];
            
            // Add to section total
            $sections[$sectionKey]['total_score'] += $score;
            
            // Calculate weighted contribution to overall score
            $percentage = ($score / $maxScore) * 100;
            $weightedScore = $percentage * $weight;
            $totalWeightedScore += $weightedScore;
            $totalWeight += $weight;
        }
    }
    
    // Calculate section percentages and pass/fail
    foreach ($sections as $key => &$section) {
        $section['percentage'] = $section['max_possible'] > 0 ? 
            round(($section['total_score'] / $section['max_possible']) * 100, 1) : 0;
        $section['passed'] = $section['percentage'] >= $section['passing_threshold'];
    }
    
    // Calculate overall score
    $overallScore = $totalWeight > 0 ? round($totalWeightedScore / $totalWeight, 1) : 0;
    
    // Determine final status using AI logic
    $finalStatus = determineIntelligentStatus($overallScore, $sections);
    
    // Generate recommendations based on performance
    $recommendations = analyzePerformanceAndGenerateRecommendations($sections, $overallScore);
    
    // Generate summary
    $summary = generateIntelligentSummary($overallScore, $finalStatus, $sections, $recommendations);
    
    return [
        'overall_score' => $overallScore,
        'final_status' => $finalStatus,
        'section_scores' => $sections,
        'recommendations' => $recommendations,
        'summary' => $summary,
        'confidence' => calculateConfidenceScore($sections, $overallScore)
    ];
}

/**
 * Determine status using intelligent logic
 */
function determineIntelligentStatus($overallScore, $sections) {
    // Count failed sections
    $failedSections = 0;
    $totalSections = count($sections);
    
    foreach ($sections as $section) {
        if (!$section['passed']) {
            $failedSections++;
        }
    }
    
    // AI decision logic
    if ($overallScore >= 75 && $failedSections == 0) {
        return 'qualified';
    } elseif ($overallScore >= 60 && $failedSections <= 1) {
        return 'partially_qualified';
    } elseif ($overallScore >= 50 && $failedSections <= 2) {
        return 'under_review'; // Needs human review
    } else {
        return 'not_qualified';
    }
}

/**
 * Analyze performance and generate specific recommendations
 */
function analyzePerformanceAndGenerateRecommendations($sections, $overallScore) {
    $recommendations = [
        'immediate_actions' => [],
        'educational_requirements' => [],
        'experience_requirements' => [],
        'documentation_improvements' => []
    ];
    
    foreach ($sections as $section) {
        $sectionName = $section['section_name'];
        $percentage = $section['percentage'];
        
        if (!$section['passed']) {
            // Critical failures - immediate actions needed
            if ($percentage < 40) {
                $recommendations['immediate_actions'][] = [
                    'action' => "Critical Deficiency in {$sectionName}",
                    'details' => "Score of {$percentage}% is significantly below requirements. Immediate action required.",
                    'priority' => 'high',
                    'timeline' => 'Immediate',
                    'suggested_steps' => [
                        'Obtain additional training or certification',
                        'Gain relevant work experience',
                        'Submit additional supporting documentation'
                    ]
                ];
            } else {
                // Moderate failures - targeted improvements
                $recommendations['educational_requirements'][] = [
                    'requirement' => "Improve {$sectionName} Performance",
                    'description' => "Current score of {$percentage}% needs improvement to meet 60% threshold.",
                    'timeline' => '3-6 months',
                    'priority' => 'medium'
                ];
            }
        } elseif ($percentage < 70) {
            // Barely passing - recommend strengthening
            $recommendations['documentation_improvements'][] = [
                'requirement' => "Strengthen {$sectionName} Documentation",
                'description' => "While passing at {$percentage}%, additional documentation could improve standing.",
                'timeline' => '1-3 months',
                'priority' => 'low'
            ];
        }
    }
    
    // Overall score-based recommendations
    if ($overallScore < 60) {
        $recommendations['immediate_actions'][] = [
            'action' => 'Comprehensive Skills Assessment',
            'details' => 'Overall score of ' . $overallScore . '% indicates need for systematic improvement across multiple areas.',
            'priority' => 'high',
            'timeline' => 'Immediate'
        ];
    }
    
    return $recommendations;
}

/**
 * Generate intelligent summary
 */
function generateIntelligentSummary($overallScore, $finalStatus, $sections, $recommendations) {
    $passedSections = array_filter($sections, function($s) { return $s['passed']; });
    $failedSections = array_filter($sections, function($s) { return !$s['passed']; });
    
    $summary = "INTELLIGENT ASSESSMENT SUMMARY\n\n";
    $summary .= "Overall Performance: {$overallScore}% - " . strtoupper(str_replace('_', ' ', $finalStatus)) . "\n\n";
    
    $summary .= "Section Analysis:\n";
    foreach ($sections as $section) {
        $status = $section['passed'] ? 'PASSED' : 'FAILED';
        $summary .= "• {$section['section_name']}: {$section['percentage']}% - {$status}\n";
    }
    
    $summary .= "\nKey Findings:\n";
    if (count($passedSections) > 0) {
        $summary .= "• Strengths identified in " . count($passedSections) . " section(s)\n";
    }
    if (count($failedSections) > 0) {
        $summary .= "• Improvement needed in " . count($failedSections) . " section(s)\n";
    }
    
    $summary .= "\nRecommendations Generated:\n";
    $summary .= "• High Priority Actions: " . count($recommendations['immediate_actions']) . "\n";
    $summary .= "• Educational Requirements: " . count($recommendations['educational_requirements']) . "\n";
    $summary .= "• Experience Requirements: " . count($recommendations['experience_requirements']) . "\n";
    
    return $summary;
}

/**
 * Calculate confidence score for the evaluation
 */
function calculateConfidenceScore($sections, $overallScore) {
    $baseConfidence = 0.8; // 80% base confidence
    
    // Adjust based on score clarity
    if ($overallScore > 80 || $overallScore < 40) {
        $baseConfidence += 0.1; // More confident on clear cases
    }
    
    // Adjust based on section consistency
    $sectionScores = array_column($sections, 'percentage');
    $standardDeviation = calculateStandardDeviation($sectionScores);
    
    if ($standardDeviation < 15) {
        $baseConfidence += 0.05; // More confident if scores are consistent
    }
    
    return min(0.95, $baseConfidence); // Cap at 95%
}

/**
 * Helper function to calculate standard deviation
 */
function calculateStandardDeviation($array) {
    $count = count($array);
    if ($count == 0) return 0;
    
    $mean = array_sum($array) / $count;
    $variance = array_sum(array_map(function($x) use ($mean) {
        return pow($x - $mean, 2);
    }, $array)) / $count;
    
    return sqrt($variance);
}

/**
 * Save section scores to database
 */
function saveSectionScores($pdo, $applicationId, $sections) {
    // Clear existing section scores
    $stmt = $pdo->prepare("DELETE FROM evaluation_sections WHERE application_id = ?");
    $stmt->execute([$applicationId]);
    
    // Insert new section scores
    foreach ($sections as $section) {
        $stmt = $pdo->prepare("
            INSERT INTO evaluation_sections 
            (application_id, section_number, section_name, total_score, max_possible, percentage, passed, evaluation_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $applicationId,
            $section['section_number'],
            $section['section_name'],
            $section['total_score'],
            $section['max_possible'],
            $section['percentage'],
            $section['passed'] ? 1 : 0
        ]);
    }
}

/**
 * Generate and save intelligent recommendations
 */
function generateIntelligentRecommendations($pdo, $applicationId, $analysisResult) {
    // Clear existing recommendations
    $stmt = $pdo->prepare("DELETE FROM evaluation_recommendations WHERE application_id = ?");
    $stmt->execute([$applicationId]);
    
    $recommendations = $analysisResult['recommendations'];
    
    // Save immediate actions (high priority)
    foreach ($recommendations['immediate_actions'] as $action) {
        $stmt = $pdo->prepare("
            INSERT INTO evaluation_recommendations 
            (application_id, category, requirement, details, priority, timeline, suggested_actions, generated_date)
            VALUES (?, 'immediate_action', ?, ?, 'high', ?, ?, NOW())
        ");
        $stmt->execute([
            $applicationId,
            $action['action'],
            $action['details'],
            $action['timeline'],
            json_encode($action['suggested_steps'] ?? [])
        ]);
    }
    
    // Save educational requirements (medium priority)
    foreach ($recommendations['educational_requirements'] as $req) {
        $stmt = $pdo->prepare("
            INSERT INTO evaluation_recommendations 
            (application_id, category, requirement, details, priority, timeline, generated_date)
            VALUES (?, 'educational', ?, ?, 'medium', ?, NOW())
        ");
        $stmt->execute([
            $applicationId,
            $req['requirement'],
            $req['description'],
            $req['timeline']
        ]);
    }
    
    // Save experience requirements (medium priority)
    foreach ($recommendations['experience_requirements'] as $req) {
        $stmt = $pdo->prepare("
            INSERT INTO evaluation_recommendations 
            (application_id, category, requirement, details, priority, timeline, generated_date)
            VALUES (?, 'experience', ?, ?, 'medium', ?, NOW())
        ");
        $stmt->execute([
            $applicationId,
            $req['requirement'],
            $req['description'],
            $req['timeline']
        ]);
    }
    
    // Save documentation improvements (low priority)
    foreach ($recommendations['documentation_improvements'] as $req) {
        $stmt = $pdo->prepare("
            INSERT INTO evaluation_recommendations 
            (application_id, category, requirement, details, priority, timeline, generated_date)
            VALUES (?, 'documentation', ?, ?, 'low', ?, NOW())
        ");
        $stmt->execute([
            $applicationId,
            $req['requirement'],
            $req['description'],
            $req['timeline']
        ]);
    }
}

/**
 * Create necessary database tables for the intelligent system
 */
function createIntelligentEvaluationTables($pdo) {
    // Table for section scores
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS evaluation_sections (
            id INT PRIMARY KEY AUTO_INCREMENT,
            application_id INT NOT NULL,
            section_number INT NOT NULL,
            section_name VARCHAR(255) NOT NULL,
            total_score DECIMAL(5,2) DEFAULT 0,
            max_possible DECIMAL(5,2) DEFAULT 0,
            percentage DECIMAL(5,2) DEFAULT 0,
            passed BOOLEAN DEFAULT FALSE,
            evaluation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
            INDEX idx_application_section (application_id, section_number)
        )
    ");
    
    // Table for intelligent recommendations
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS evaluation_recommendations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            application_id INT NOT NULL,
            category ENUM('immediate_action', 'educational', 'experience', 'documentation') NOT NULL,
            requirement VARCHAR(500) NOT NULL,
            details TEXT,
            priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
            timeline VARCHAR(100),
            suggested_actions JSON,
            status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
            generated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
            INDEX idx_application_priority (application_id, priority)
        )
    ");
    
    // Add new columns to applications table for AI features
    try {
        $pdo->exec("ALTER TABLE applications ADD COLUMN system_generated_recommendation BOOLEAN DEFAULT FALSE");
        $pdo->exec("ALTER TABLE applications ADD COLUMN recommendation_confidence DECIMAL(3,2) DEFAULT 0.00");
    } catch (PDOException $e) {
        // Columns might already exist
    }
    
    // Add section tracking to assessment criteria
    try {
        $pdo->exec("ALTER TABLE assessment_criteria ADD COLUMN section_number INT DEFAULT 1");
        $pdo->exec("ALTER TABLE assessment_criteria ADD COLUMN subsection INT DEFAULT 1");
    } catch (PDOException $e) {
        // Columns might already exist
    }
}

?>