<?php
/**
 * PRESCRIPTIVE ENGINE - Simplified Version
 * File: includes/prescriptive_engine.php
 * 
 * Place this file in: includes/prescriptive_engine.php
 */

class PrescriptiveEngine {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // ================================================================
    // MAIN FUNCTION: Analyze Complete Application
    // Call this after document upload or evaluation
    // ================================================================
    
    public function analyzeApplication($applicationId, $candidateId) {
        try {
            // Step 1: Extract skills from documents
            $this->extractSkillsFromDocuments($candidateId, $applicationId);
            
            // Step 2: Analyze gaps
            $gaps = $this->analyzeSkillGaps($candidateId, $applicationId);
            
            // Step 3: Generate predictions
            $prediction = $this->predictScore($applicationId, $candidateId);
            
            // Step 4: Generate recommendations
            $recommendations = $this->generateRecommendations($candidateId, $applicationId, $gaps);
            
            return [
                'success' => true,
                'gaps' => $gaps,
                'prediction' => $prediction,
                'recommendations' => $recommendations
            ];
            
        } catch (Exception $e) {
            error_log("Prescriptive analysis error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ================================================================
    // 1. SKILL EXTRACTION (Keyword-Based)
    // ================================================================
    
    private function extractSkillsFromDocuments($candidateId, $applicationId) {
        // Get all document descriptions for this application
        $stmt = $this->pdo->prepare("
            SELECT description, original_filename 
            FROM documents 
            WHERE application_id = ?
        ");
        $stmt->execute([$applicationId]);
        $documents = $stmt->fetchAll();
        
        // Combine all text
        $allText = '';
        foreach ($documents as $doc) {
            $allText .= ' ' . $doc['description'] . ' ' . $doc['original_filename'];
        }
        
        // Extract skills using keyword matching
        $skills = $this->extractSkillsFromText($allText, $candidateId);
        
        // Save to database
        foreach ($skills as $skill) {
            $this->saveSkill($skill);
        }
        
        return $skills;
    }
    
    private function extractSkillsFromText($text, $candidateId) {
        $text = strtolower($text);
        $skills = [];
        
        // Define skill keywords (expand this list)
        $skillPatterns = [
            'PHP' => ['php', 'laravel', 'symfony', 'codeigniter'],
            'MySQL' => ['mysql', 'mariadb', 'sql', 'database'],
            'JavaScript' => ['javascript', 'js', 'jquery', 'react', 'vue', 'angular', 'node'],
            'HTML/CSS' => ['html', 'css', 'bootstrap', 'tailwind'],
            'AWS' => ['aws', 'amazon web services', 'cloud', 'ec2', 's3'],
            'Docker' => ['docker', 'container', 'kubernetes'],
            'Git' => ['git', 'github', 'gitlab', 'version control'],
            'API Development' => ['api', 'rest', 'restful', 'graphql'],
            'Web Development' => ['web development', 'web developer', 'frontend', 'backend'],
            'Database Design' => ['database design', 'schema', 'normalization'],
            'Security' => ['security', 'encryption', 'authentication', 'oauth']
        ];
        
        foreach ($skillPatterns as $skillName => $keywords) {
            $matches = 0;
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $matches++;
                }
            }
            
            if ($matches > 0) {
                // Estimate proficiency based on mentions
                $proficiency = min(40 + ($matches * 15), 90);
                
                $skills[] = [
                    'candidate_id' => $candidateId,
                    'skill_name' => $skillName,
                    'proficiency_level' => $proficiency,
                    'years_experience' => min($matches * 0.5, 5), // Rough estimate
                    'source' => 'auto_extracted'
                ];
            }
        }
        
        return $skills;
    }
    
    private function saveSkill($skill) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO candidate_skills 
                (candidate_id, skill_name, proficiency_level, years_experience, source)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                proficiency_level = GREATEST(proficiency_level, VALUES(proficiency_level))
            ");
            
            $stmt->execute([
                $skill['candidate_id'],
                $skill['skill_name'],
                $skill['proficiency_level'],
                $skill['years_experience'],
                $skill['source']
            ]);
        } catch (PDOException $e) {
            error_log("Save skill error: " . $e->getMessage());
        }
    }
    
    // ================================================================
    // 2. GAP ANALYSIS
    // ================================================================
    
    private function analyzeSkillGaps($candidateId, $applicationId) {
        // Get program requirements
        $stmt = $this->pdo->prepare("
            SELECT psr.skill_name, psr.required_level, psr.weight
            FROM program_skill_requirements psr
            JOIN applications a ON a.program_id = psr.program_id
            WHERE a.id = ?
        ");
        $stmt->execute([$applicationId]);
        $requirements = $stmt->fetchAll();
        
        // Get candidate skills
        $stmt = $this->pdo->prepare("
            SELECT skill_name, MAX(proficiency_level) as level
            FROM candidate_skills
            WHERE candidate_id = ?
            GROUP BY skill_name
        ");
        $stmt->execute([$candidateId]);
        $candidateSkills = [];
        while ($row = $stmt->fetch()) {
            $candidateSkills[$row['skill_name']] = $row['level'];
        }
        
        // Calculate gaps
        $gaps = [];
        foreach ($requirements as $req) {
            $currentLevel = $candidateSkills[$req['skill_name']] ?? 0;
            $gap = $req['required_level'] - $currentLevel;
            
            if ($gap > 0) {
                $priority = $this->determinePriority($gap);
                $impact = round($gap * $req['weight'] * 0.1, 2);
                
                $gapData = [
                    'candidate_id' => $candidateId,
                    'application_id' => $applicationId,
                    'skill_name' => $req['skill_name'],
                    'required_level' => $req['required_level'],
                    'current_level' => $currentLevel,
                    'priority' => $priority,
                    'impact_score' => $impact
                ];
                
                $this->saveGap($gapData);
                $gaps[] = $gapData;
            }
        }
        
        return $gaps;
    }
    
    private function determinePriority($gap) {
        if ($gap >= 30) return 'critical';
        if ($gap >= 20) return 'high';
        if ($gap >= 10) return 'medium';
        return 'low';
    }
    
    private function saveGap($gap) {
        try {
            // Clear old gaps first
            $stmt = $this->pdo->prepare("
                DELETE FROM skill_gaps 
                WHERE candidate_id = ? AND application_id = ? AND skill_name = ?
            ");
            $stmt->execute([
                $gap['candidate_id'],
                $gap['application_id'],
                $gap['skill_name']
            ]);
            
            // Insert new gap
            $stmt = $this->pdo->prepare("
                INSERT INTO skill_gaps 
                (candidate_id, application_id, skill_name, required_level, 
                 current_level, priority, impact_score, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'identified')
            ");
            
            $stmt->execute([
                $gap['candidate_id'],
                $gap['application_id'],
                $gap['skill_name'],
                $gap['required_level'],
                $gap['current_level'],
                $gap['priority'],
                $gap['impact_score']
            ]);
        } catch (PDOException $e) {
            error_log("Save gap error: " . $e->getMessage());
        }
    }
    
    // ================================================================
    // 3. PREDICTIVE SCORING
    // ================================================================
    
    private function predictScore($applicationId, $candidateId) {
        // Get current score
        $stmt = $this->pdo->prepare("SELECT total_score FROM applications WHERE id = ?");
        $stmt->execute([$applicationId]);
        $currentScore = $stmt->fetchColumn() ?: 0;
        
        // Get total gap impact
        $stmt = $this->pdo->prepare("
            SELECT SUM(impact_score) as total_impact
            FROM skill_gaps
            WHERE application_id = ? AND status = 'identified'
        ");
        $stmt->execute([$applicationId]);
        $gapImpact = $stmt->fetchColumn() ?: 0;
        
        // Get number of strong skills
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as strong_count
            FROM candidate_skills
            WHERE candidate_id = ? AND proficiency_level >= 75
        ");
        $stmt->execute([$candidateId]);
        $strongSkills = $stmt->fetchColumn() ?: 0;
        
        // Calculate prediction
        if ($currentScore > 0) {
            $predictedScore = $currentScore + ($gapImpact * 0.7);
        } else {
            $predictedScore = 45 + ($strongSkills * 4) - ($gapImpact * 0.3);
        }
        
        $predictedScore = max(0, min(100, $predictedScore));
        
        // Calculate probability (0-1)
        $threshold = 75;
        if ($predictedScore >= $threshold) {
            $probability = 0.85;
        } elseif ($predictedScore >= $threshold - 5) {
            $probability = 0.70;
        } elseif ($predictedScore >= $threshold - 10) {
            $probability = 0.50;
        } else {
            $probability = 0.30;
        }
        
        // Determine risk
        $riskLevel = 'low';
        if ($predictedScore < $threshold - 10) $riskLevel = 'high';
        elseif ($predictedScore < $threshold - 5) $riskLevel = 'medium';
        
        $prediction = [
            'application_id' => $applicationId,
            'candidate_id' => $candidateId,
            'current_score' => $currentScore,
            'predicted_score' => round($predictedScore, 2),
            'qualification_probability' => round($probability, 2),
            'confidence_level' => 0.75,
            'risk_level' => $riskLevel
        ];
        
        $this->savePrediction($prediction);
        
        return $prediction;
    }
    
    private function savePrediction($prediction) {
        try {
            // Clear old prediction
            $stmt = $this->pdo->prepare("
                DELETE FROM predictive_scores WHERE application_id = ?
            ");
            $stmt->execute([$prediction['application_id']]);
            
            // Insert new
            $stmt = $this->pdo->prepare("
                INSERT INTO predictive_scores 
                (application_id, candidate_id, current_score, predicted_score,
                 qualification_probability, confidence_level, risk_level, factors_analyzed)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $factors = json_encode([
                'method' => 'rule_based',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $stmt->execute([
                $prediction['application_id'],
                $prediction['candidate_id'],
                $prediction['current_score'],
                $prediction['predicted_score'],
                $prediction['qualification_probability'],
                $prediction['confidence_level'],
                $prediction['risk_level'],
                $factors
            ]);
        } catch (PDOException $e) {
            error_log("Save prediction error: " . $e->getMessage());
        }
    }
    
    // ================================================================
    // 4. RECOMMENDATION GENERATION (PRESCRIPTIVE)
    // ================================================================
    
    private function generateRecommendations($candidateId, $applicationId, $gaps) {
        $recommendations = [];
        $priorityRank = 1;
        
        // Recommendation database
        $recDatabase = [
            'PHP' => [
                'type' => 'course',
                'title' => 'Complete PHP Advanced Course',
                'description' => 'Master PHP programming including OOP, MVC, and frameworks',
                'url' => 'https://www.udemy.com/course/php-for-complete-beginners',
                'duration' => 14,
                'cost' => 0,
                'impact_per_point' => 0.3
            ],
            'MySQL' => [
                'type' => 'course',
                'title' => 'MySQL Database Mastery',
                'description' => 'Learn database design, optimization, and advanced queries',
                'url' => 'https://www.udemy.com/course/the-ultimate-mysql-bootcamp',
                'duration' => 10,
                'cost' => 0,
                'impact_per_point' => 0.28
            ],
            'AWS' => [
                'type' => 'certification',
                'title' => 'Get AWS Cloud Practitioner Certification',
                'description' => 'Industry-recognized cloud computing certification',
                'url' => 'https://aws.amazon.com/certification/certified-cloud-practitioner',
                'duration' => 14,
                'cost' => 100,
                'impact_per_point' => 0.4
            ],
            'JavaScript' => [
                'type' => 'course',
                'title' => 'Modern JavaScript Essential Training',
                'description' => 'Learn ES6+, async programming, and modern frameworks',
                'url' => 'https://www.freecodecamp.org/learn/javascript-algorithms-and-data-structures',
                'duration' => 12,
                'cost' => 0,
                'impact_per_point' => 0.3
            ],
            'Docker' => [
                'type' => 'project',
                'title' => 'Build Dockerized Application',
                'description' => 'Create and containerize a web application using Docker',
                'url' => 'https://docs.docker.com/get-started',
                'duration' => 7,
                'cost' => 0,
                'impact_per_point' => 0.35
            ],
            'Git' => [
                'type' => 'training',
                'title' => 'Git Version Control Fundamentals',
                'description' => 'Master Git workflows, branching, and collaboration',
                'url' => 'https://www.atlassian.com/git/tutorials',
                'duration' => 3,
                'cost' => 0,
                'impact_per_point' => 0.25
            ]
        ];
        
        // Generate recommendation for each gap
        foreach ($gaps as $gap) {
            $skillName = $gap['skill_name'];
            
            if (isset($recDatabase[$skillName])) {
                $rec = $recDatabase[$skillName];
                $estimatedImpact = round($gap['gap_points'] * $rec['impact_per_point'], 2);
                
                $recommendation = [
                    'candidate_id' => $candidateId,
                    'application_id' => $applicationId,
                    'skill_gap_id' => null, // Will be set when saving
                    'recommendation_type' => $rec['type'],
                    'title' => $rec['title'],
                    'description' => $rec['description'],
                    'resource_url' => $rec['url'],
                    'estimated_impact' => $estimatedImpact,
                    'estimated_duration_days' => $rec['duration'],
                    'estimated_cost' => $rec['cost'],
                    'priority_rank' => $priorityRank++
                ];
                
                $this->saveRecommendation($recommendation);
                $recommendations[] = $recommendation;
            }
        }
        
        return $recommendations;
    }
    
    private function saveRecommendation($rec) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO prescriptive_recommendations 
                (candidate_id, application_id, skill_gap_id, recommendation_type,
                 title, description, resource_url, estimated_impact,
                 estimated_duration_days, estimated_cost, priority_rank, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            
            $stmt->execute([
                $rec['candidate_id'],
                $rec['application_id'],
                $rec['skill_gap_id'],
                $rec['recommendation_type'],
                $rec['title'],
                $rec['description'],
                $rec['resource_url'],
                $rec['estimated_impact'],
                $rec['estimated_duration_days'],
                $rec['estimated_cost'],
                $rec['priority_rank']
            ]);
        } catch (PDOException $e) {
            error_log("Save recommendation error: " . $e->getMessage());
        }
    }
    
    // ================================================================
    // HELPER FUNCTIONS
    // ================================================================
    
    public function getPrediction($applicationId) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM predictive_scores WHERE application_id = ? ORDER BY prediction_date DESC LIMIT 1
        ");
        $stmt->execute([$applicationId]);
        return $stmt->fetch();
    }
    
    public function getRecommendations($applicationId) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM prescriptive_recommendations 
            WHERE application_id = ? 
            ORDER BY priority_rank ASC
        ");
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }
    
    public function getSkillGaps($applicationId) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM skill_gaps 
            WHERE application_id = ? 
            ORDER BY priority DESC, gap_points DESC
        ");
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }
}

// End of prescriptive_engine.php
?>