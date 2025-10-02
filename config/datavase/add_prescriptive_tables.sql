-- ================================================================
-- STEP 1: ADD PRESCRIPTIVE ANALYTICS TABLES
-- Add these to your existing database (eteeap_db)
-- Run this file once: mysql -u root eteeap_db < add_prescriptive_tables.sql
-- ================================================================

-- Check if tables exist to prevent errors
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';

-- ================================================================
-- 1. CANDIDATE SKILLS TABLE
-- Stores extracted or manually entered candidate skills
-- ================================================================
CREATE TABLE IF NOT EXISTS candidate_skills (
    id INT PRIMARY KEY AUTO_INCREMENT,
    candidate_id INT NOT NULL,
    skill_name VARCHAR(100) NOT NULL,
    proficiency_level INT DEFAULT 50 COMMENT '0-100 scale',
    years_experience DECIMAL(3,1) DEFAULT 0,
    source VARCHAR(50) COMMENT 'document name or manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (candidate_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_candidate (candidate_id),
    INDEX idx_skill (skill_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- 2. PROGRAM SKILL REQUIREMENTS TABLE
-- Defines what skills each program requires
-- ================================================================
CREATE TABLE IF NOT EXISTS program_skill_requirements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    program_id INT NOT NULL,
    skill_name VARCHAR(100) NOT NULL,
    required_level INT DEFAULT 70 COMMENT 'Minimum level 0-100',
    weight DECIMAL(3,2) DEFAULT 1.00 COMMENT 'Importance multiplier',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    UNIQUE KEY unique_program_skill (program_id, skill_name),
    INDEX idx_program (program_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- 3. SKILL GAPS TABLE
-- Identifies what skills candidate is missing
-- ================================================================
CREATE TABLE IF NOT EXISTS skill_gaps (
    id INT PRIMARY KEY AUTO_INCREMENT,
    candidate_id INT NOT NULL,
    application_id INT NOT NULL,
    skill_name VARCHAR(100) NOT NULL,
    required_level INT NOT NULL,
    current_level INT NOT NULL,
    gap_points INT AS (required_level - current_level) STORED,
    priority ENUM('critical', 'high', 'medium', 'low') DEFAULT 'medium',
    impact_score DECIMAL(5,2) DEFAULT 0 COMMENT 'Impact on total score',
    status ENUM('identified', 'in_progress', 'resolved') DEFAULT 'identified',
    identified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (candidate_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    INDEX idx_candidate_app (candidate_id, application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- 4. PRESCRIPTIVE RECOMMENDATIONS TABLE
-- Stores what candidate should do to improve
-- ================================================================
CREATE TABLE IF NOT EXISTS prescriptive_recommendations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    candidate_id INT NOT NULL,
    application_id INT NOT NULL,
    skill_gap_id INT NULL,
    recommendation_type ENUM('course', 'certification', 'project', 'training') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    resource_url VARCHAR(500),
    estimated_impact DECIMAL(5,2) DEFAULT 0 COMMENT 'Points improvement',
    estimated_duration_days INT DEFAULT 0,
    estimated_cost DECIMAL(10,2) DEFAULT 0,
    priority_rank INT DEFAULT 999,
    status ENUM('pending', 'viewed', 'accepted', 'completed', 'skipped') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (candidate_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_gap_id) REFERENCES skill_gaps(id) ON DELETE SET NULL,
    INDEX idx_candidate_app (candidate_id, application_id),
    INDEX idx_priority (priority_rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- 5. PREDICTIVE SCORES TABLE
-- Stores prediction results
-- ================================================================
CREATE TABLE IF NOT EXISTS predictive_scores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    application_id INT NOT NULL,
    candidate_id INT NOT NULL,
    current_score DECIMAL(5,2) DEFAULT 0,
    predicted_score DECIMAL(5,2) DEFAULT 0,
    qualification_probability DECIMAL(3,2) DEFAULT 0 COMMENT '0.00 to 1.00',
    confidence_level DECIMAL(3,2) DEFAULT 0,
    risk_level ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    factors_analyzed JSON,
    prediction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    FOREIGN KEY (candidate_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_application (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- SEED DATA: Add sample skill requirements for existing programs
-- Adjust program_id based on your actual program IDs
-- ================================================================

-- Get the first program ID (usually BSIT)
SET @program_id = (SELECT id FROM programs LIMIT 1);

-- Insert skill requirements for BSIT (or your first program)
INSERT IGNORE INTO program_skill_requirements (program_id, skill_name, required_level, weight) VALUES
-- Core skills
(@program_id, 'PHP', 75, 1.5),
(@program_id, 'MySQL', 70, 1.3),
(@program_id, 'JavaScript', 70, 1.2),
(@program_id, 'HTML/CSS', 65, 1.0),
(@program_id, 'Web Development', 75, 1.4),

-- Important skills
(@program_id, 'Git', 60, 1.1),
(@program_id, 'API Development', 65, 1.1),
(@program_id, 'Database Design', 70, 1.2),

-- High-value skills
(@program_id, 'AWS', 70, 1.5),
(@program_id, 'Docker', 60, 1.2),
(@program_id, 'Security', 60, 1.0);

-- ================================================================
-- HELPER VIEW: Easy access to gap analysis
-- ================================================================
CREATE OR REPLACE VIEW v_skill_gap_summary AS
SELECT 
    sg.candidate_id,
    sg.application_id,
    CONCAT(u.first_name, ' ', u.last_name) as candidate_name,
    p.program_name,
    sg.skill_name,
    sg.current_level,
    sg.required_level,
    sg.gap_points,
    sg.priority,
    sg.impact_score,
    sg.status
FROM skill_gaps sg
JOIN users u ON sg.candidate_id = u.id
JOIN applications a ON sg.application_id = a.id
JOIN programs p ON a.program_id = p.id
ORDER BY sg.priority DESC, sg.gap_points DESC;

-- ================================================================
-- VERIFICATION: Show what was created
-- ================================================================
SELECT 
    'Tables Created Successfully!' as Status,
    (SELECT COUNT(*) FROM information_schema.tables 
     WHERE table_schema = DATABASE() 
     AND table_name IN ('candidate_skills', 'program_skill_requirements', 
                        'skill_gaps', 'prescriptive_recommendations', 
                        'predictive_scores')) as NewTables,
    (SELECT COUNT(*) FROM program_skill_requirements) as SkillRequirements;

SET SQL_MODE=@OLD_SQL_MODE;