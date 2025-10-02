<?php
/**
 * ETEEAP Point Calculator
 * Calculates points based on hierarchical data from detailed uploads
 */

function calculatePoints($hierarchical_data, $criteria_info) {
    if (empty($hierarchical_data)) {
        return 0;
    }
    
    $points = 0;
    $data = is_string($hierarchical_data) ? json_decode($hierarchical_data, true) : $hierarchical_data;
    
    if (!$data) {
        return 0;
    }
    
    // Section 1 - Education
    if (isset($data['education_level'])) {
        $edu_points = [
            'high_school' => 2,
            'vocational' => 3,
            'technical' => 4,
            'undergraduate' => 5,
            'non_education' => 6
        ];
        $points += $edu_points[$data['education_level']] ?? 0;
        
        // Scholarship bonus
        if (isset($data['scholarship_type']) && $data['scholarship_type'] !== 'none') {
            $points += ($data['scholarship_type'] === 'full') ? 2 : 1;
        }
    }
    
    // Section 2 - Work Experience
    if (isset($data['years_experience']) && isset($data['experience_role'])) {
        $role_points = [
            'administrator' => 5,
            'supervisor' => 3,
            'trainer' => 2,
            'sunday_school' => 1,
            'daycare' => 1
        ];
        
        $years = intval($data['years_experience']);
        if ($years >= 5) {
            $points += $role_points[$data['experience_role']] ?? 0;
            // Additional points for extra years (optional)
            $extra_years = max(0, $years - 5);
            $points += min($extra_years * 0.5, 5); // Cap at 5 extra points
        }
    }
    
    // Section 3 - Inventions/Innovations
    if (isset($data['patent_status']) && isset($data['acceptability_levels'])) {
        $is_invention = (isset($data['invention_type']) && $data['invention_type'] === 'invention');
        
        // Base patent points
        if ($data['patent_status'] === 'patented') {
            $points += $is_invention ? 6 : 1;
        } else {
            $points += $is_invention ? 5 : 2;
        }
        
        // Market acceptability points
        $acceptability_levels = is_array($data['acceptability_levels']) ? $data['acceptability_levels'] : [];
        foreach ($acceptability_levels as $level) {
            if ($level === 'local') {
                $points += $is_invention ? 7 : 4;
            } elseif ($level === 'national') {
                $points += $is_invention ? 8 : 5;
            } elseif ($level === 'international') {
                $points += $is_invention ? 9 : 6;
            }
        }
    }
    
    // Section 3 - Publications
    if (isset($data['circulation_level']) && isset($data['publication_type'])) {
        $pub_points = [
            'journal' => ['local' => 2, 'national' => 3, 'international' => 4],
            'training_module' => ['local' => 3, 'national' => 4, 'international' => 5],
            'book' => ['local' => 5, 'national' => 6, 'international' => 7],
            'teaching_module' => ['local' => 3, 'national' => 4, 'international' => 5],
            'workbook' => ['local' => 2, 'national' => 3, 'international' => 4],
            'reading_kit' => ['local' => 2, 'national' => 3, 'international' => 4],
            'literacy_outreach' => ['local' => 4, 'national' => 5, 'international' => 6]
        ];
        
        $pub_type = $data['publication_type'];
        $circulation = $data['circulation_level'];
        
        if (isset($pub_points[$pub_type][$circulation])) {
            $points += $pub_points[$pub_type][$circulation];
        }
    }
    
    // Section 3 - Extension Services
    if (isset($data['service_levels']) && isset($data['extension_type'])) {
        $service_points = [
            'consultancy' => ['local' => 5, 'national' => 10, 'international' => 15],
            'lecturer' => ['local' => 6, 'national' => 8, 'international' => 10],
            'community' => ['trainer' => 3, 'official' => 4, 'manager' => 5]
        ];
        
        $ext_type = $data['extension_type'];
        $levels = is_array($data['service_levels']) ? $data['service_levels'] : [];
        
        if (isset($service_points[$ext_type])) {
            foreach ($levels as $level) {
                if (isset($service_points[$ext_type][$level])) {
                    $points += $service_points[$ext_type][$level];
                }
            }
        }
    }
    
    // Section 4 - Professional Development
    if (isset($data['coordination_level'])) {
        $coord_points = ['local' => 6, 'national' => 8, 'international' => 10];
        $points += $coord_points[$data['coordination_level']] ?? 0;
    }
    
    if (isset($data['participation_level'])) {
        $part_points = ['local' => 3, 'national' => 4, 'international' => 5];
        $points += $part_points[$data['participation_level']] ?? 0;
    }
    
    if (isset($data['membership_level'])) {
        $memb_points = ['local' => 3, 'national' => 4, 'international' => 5];
        $points += $memb_points[$data['membership_level']] ?? 0;
    }
    
    if (isset($data['scholarship_level'])) {
        $points += floatval($data['scholarship_level']);
    }
    
    // Section 5 - Recognition & Others
    if (isset($data['recognition_level'])) {
        $recog_points = ['local' => 6, 'national' => 8];
        $points += $recog_points[$data['recognition_level']] ?? 0;
    }
    
    if (isset($data['eligibility_type'])) {
        $elig_points = ['cs_sub_professional' => 3, 'cs_professional' => 4, 'prc' => 5];
        $points += $elig_points[$data['eligibility_type']] ?? 0;
    }
    
    return round($points, 2);
}

function generatePointSummary($hierarchical_data) {
    if (empty($hierarchical_data)) {
        return '';
    }
    
    $data = is_string($hierarchical_data) ? json_decode($hierarchical_data, true) : $hierarchical_data;
    if (!$data) {
        return '';
    }
    
    $summary = [];
    
    // Add readable descriptions of selections
    if (isset($data['education_level'])) {
        $summary[] = "Education: " . ucfirst(str_replace('_', ' ', $data['education_level']));
        if (isset($data['scholarship_type']) && $data['scholarship_type'] !== 'none') {
            $summary[] = "Scholarship: " . ucfirst($data['scholarship_type']);
        }
    }
    
    if (isset($data['years_experience'])) {
        $summary[] = "Experience: {$data['years_experience']} years as " . str_replace('_', ' ', $data['experience_role']);
    }
    
    if (isset($data['patent_status'])) {
        $summary[] = "Patent: " . ucfirst(str_replace('_', ' ', $data['patent_status']));
        if (isset($data['acceptability_levels'])) {
            $levels = is_array($data['acceptability_levels']) ? $data['acceptability_levels'] : [];
            $summary[] = "Markets: " . implode(', ', array_map('ucfirst', $levels));
        }
    }
    
    if (isset($data['circulation_level'])) {
        $summary[] = "Publication: " . ucfirst($data['circulation_level']) . " " . str_replace('_', ' ', $data['publication_type']);
    }
    
    if (isset($data['service_levels'])) {
        $levels = is_array($data['service_levels']) ? $data['service_levels'] : [];
        $summary[] = "Service: " . implode(', ', array_map('ucfirst', $levels)) . " " . $data['extension_type'];
    }
    
    return implode(' | ', $summary);
}

function getMaxPointsForCriteria($criteria_id, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT max_score FROM assessment_criteria WHERE id = ?");
        $stmt->execute([$criteria_id]);
        $result = $stmt->fetch();
        return $result ? floatval($result['max_score']) : 0;
    } catch (Exception $e) {
        return 0;
    }
}
?>