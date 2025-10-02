<?php
/**
 * ETEEAP System Helper Functions
 * Common functions used throughout the application
 */

// Prevent direct access
if (!defined('INCLUDED')) {
    define('INCLUDED', true);
}

/**
 * Security Functions
 */

/**
 * Sanitize input data
 * @param string $data Input data to sanitize
 * @return string Sanitized data
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Generate secure random token
 * @param int $length Token length
 * @return string Random token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Validate email address
 * @param string $email Email to validate
 * @return bool True if valid
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Check password strength
 * @param string $password Password to check
 * @return array Result with 'valid' and 'message'
 */
function checkPasswordStrength($password) {
    $result = ['valid' => true, 'message' => ''];
    
    if (strlen($password) < 6) {
        $result['valid'] = false;
        $result['message'] = 'Password must be at least 6 characters long';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $result['valid'] = false;
        $result['message'] = 'Password must contain both letters and numbers';
    }
    
    return $result;
}

/**
 * Authentication & Session Functions
 */

/**
 * Check if user is logged in
 * @return bool True if logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check if user has required role
 * @param array|string $allowedRoles Allowed user roles
 * @return bool True if user has required role
 */
function hasRole($allowedRoles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    if (is_string($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }
    
    return in_array($_SESSION['user_type'], $allowedRoles);
}

/**
 * Require authentication
 * @param array|string $allowedRoles Required roles (optional)
 * @param string $redirectUrl Redirect URL if not authenticated
 */
function requireAuth($allowedRoles = null, $redirectUrl = '/auth/login.php') {
    if (!isLoggedIn()) {
        header("Location: $redirectUrl");
        exit();
    }
    
    if ($allowedRoles && !hasRole($allowedRoles)) {
        header("Location: /auth/login.php?error=insufficient_permissions");
        exit();
    }
}

/**
 * Get current user information
 * @param PDO $pdo Database connection
 * @return array|null User information or null
 */
function getCurrentUser($pdo) {
    if (!isLoggedIn()) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * File Upload Functions
 */

/**
 * Validate uploaded file
 * @param array $file $_FILES array element
 * @param array $options Validation options
 * @return array Result with 'valid' and 'message'
 */
function validateFileUpload($file, $options = []) {
    $defaults = [
        'max_size' => 5242880, // 5MB
        'allowed_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'],
        'required' => true
    ];
    $options = array_merge($defaults, $options);
    
    $result = ['valid' => true, 'message' => ''];
    
    // Check if file was uploaded
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        if ($options['required']) {
            $result['valid'] = false;
            $result['message'] = 'Please select a file to upload';
        }
        return $result;
    }
    
    // Check file size
    if ($file['size'] > $options['max_size']) {
        $result['valid'] = false;
        $result['message'] = 'File size exceeds maximum allowed size (' . formatFileSize($options['max_size']) . ')';
        return $result;
    }
    
    // Check file type
    if (!in_array($file['type'], $options['allowed_types'])) {
        $result['valid'] = false;
        $result['message'] = 'Invalid file type. Allowed types: ' . implode(', ', $options['allowed_types']);
        return $result;
    }
    
    return $result;
}

/**
 * Generate secure filename
 * @param string $originalName Original filename
 * @param int $userId User ID
 * @param int $applicationId Application ID
 * @return string Secure filename
 */
function generateSecureFilename($originalName, $userId, $applicationId = null) {
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $timestamp = time();
    $random = uniqid();
    
    $filename = $userId . '_';
    if ($applicationId) {
        $filename .= $applicationId . '_';
    }
    $filename .= $timestamp . '_' . $random . '.' . $extension;
    
    return $filename;
}

/**
 * Format file size
 * @param int $bytes File size in bytes
 * @param int $precision Decimal precision
 * @return string Formatted file size
 */
function formatFileSize($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Date & Time Functions
 */

/**
 * Format date for display
 * @param string $date Date string
 * @param string $format Display format
 * @return string Formatted date
 */
function formatDate($date, $format = 'M j, Y') {
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return 'Not set';
    }
    return date($format, strtotime($date));
}

/**
 * Format datetime for display
 * @param string $datetime Datetime string
 * @param string $format Display format
 * @return string Formatted datetime
 */
function formatDateTime($datetime, $format = 'M j, Y g:i A') {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return 'Not set';
    }
    return date($format, strtotime($datetime));
}

/**
 * Get time ago string
 * @param string $datetime Datetime string
 * @return string Time ago string
 */
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'Just now';
    if ($time < 3600) return floor($time / 60) . ' minutes ago';
    if ($time < 86400) return floor($time / 3600) . ' hours ago';
    if ($time < 2592000) return floor($time / 86400) . ' days ago';
    if ($time < 31536000) return floor($time / 2592000) . ' months ago';
    
    return floor($time / 31536000) . ' years ago';
}

/**
 * Application Status Functions
 */

/**
 * Get status badge class
 * @param string $status Application status
 * @return string CSS class for status badge
 */
function getStatusBadgeClass($status) {
    $classes = [
        'draft' => 'bg-secondary',
        'submitted' => 'bg-info',
        'under_review' => 'bg-warning',
        'qualified' => 'bg-success',
        'partially_qualified' => 'bg-warning',
        'not_qualified' => 'bg-danger'
    ];
    
    return $classes[$status] ?? 'bg-secondary';
}

/**
 * Get status display name
 * @param string $status Application status
 * @return string Display name
 */
function getStatusDisplayName($status) {
    $names = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'under_review' => 'Under Review',
        'qualified' => 'Qualified',
        'partially_qualified' => 'Partially Qualified',
        'not_qualified' => 'Not Qualified'
    ];
    
    return $names[$status] ?? ucfirst($status);
}

/**
 * Get document type display name
 * @param string $type Document type
 * @return string Display name
 */
function getDocumentTypeDisplayName($type) {
    $names = [
        'diploma' => 'Diploma/Certificate',
        'transcript' => 'Transcript of Records',
        'certificate' => 'Professional Certificate',
        'employment_record' => 'Employment Records',
        'portfolio' => 'Portfolio',
        'other' => 'Other Document'
    ];
    
    return $names[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

/**
 * Database Helper Functions
 */

/**
 * Get application statistics
 * @param PDO $pdo Database connection
 * @param int $userId User ID (optional, for user-specific stats)
 * @return array Statistics array
 */
function getApplicationStats($pdo, $userId = null) {
    try {
        $whereClause = $userId ? "WHERE user_id = ?" : "";
        $params = $userId ? [$userId] : [];
        
        // Total applications
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM applications $whereClause");
        $stmt->execute($params);
        $total = $stmt->fetch()['total'];
        
        // Status counts
        $stmt = $pdo->prepare("
            SELECT application_status, COUNT(*) as count 
            FROM applications $whereClause
            GROUP BY application_status
        ");
        $stmt->execute($params);
        $statusCounts = [];
        while ($row = $stmt->fetch()) {
            $statusCounts[$row['application_status']] = $row['count'];
        }
        
        return [
            'total' => $total,
            'draft' => $statusCounts['draft'] ?? 0,
            'submitted' => $statusCounts['submitted'] ?? 0,
            'under_review' => $statusCounts['under_review'] ?? 0,
            'qualified' => $statusCounts['qualified'] ?? 0,
            'partially_qualified' => $statusCounts['partially_qualified'] ?? 0,
            'not_qualified' => $statusCounts['not_qualified'] ?? 0,
            'pending' => ($statusCounts['submitted'] ?? 0) + ($statusCounts['under_review'] ?? 0),
            'completed' => ($statusCounts['qualified'] ?? 0) + ($statusCounts['partially_qualified'] ?? 0) + ($statusCounts['not_qualified'] ?? 0)
        ];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get user's active application
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return array|null Active application or null
 */
function getUserActiveApplication($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, p.program_name, p.program_code
            FROM applications a
            LEFT JOIN programs p ON a.program_id = p.id
            WHERE a.user_id = ? AND a.application_status IN ('draft', 'submitted', 'under_review')
            ORDER BY a.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Scoring & Evaluation Functions
 */

/**
 * Calculate weighted score
 * @param array $evaluations Array of evaluations with scores and weights
 * @return float Calculated weighted score
 */
function calculateWeightedScore($evaluations) {
    $totalWeightedScore = 0;
    $totalWeight = 0;
    
    foreach ($evaluations as $evaluation) {
        if (isset($evaluation['score'], $evaluation['max_score'], $evaluation['weight'])) {
            $percentage = ($evaluation['score'] / $evaluation['max_score']) * 100;
            $weightedScore = $percentage * $evaluation['weight'];
            $totalWeightedScore += $weightedScore;
            $totalWeight += $evaluation['weight'];
        }
    }
    
    return $totalWeight > 0 ? round($totalWeightedScore / $totalWeight, 2) : 0;
}

/**
 * Get score grade
 * @param float $score Score percentage
 * @return array Grade info with class and text
 */
function getScoreGrade($score) {
    if ($score >= 90) {
        return ['class' => 'success', 'text' => 'Excellent', 'icon' => 'fas fa-star'];
    } elseif ($score >= 75) {
        return ['class' => 'primary', 'text' => 'Good', 'icon' => 'fas fa-thumbs-up'];
    } elseif ($score >= 60) {
        return ['class' => 'warning', 'text' => 'Satisfactory', 'icon' => 'fas fa-check'];
    } elseif ($score >= 40) {
        return ['class' => 'danger', 'text' => 'Needs Improvement', 'icon' => 'fas fa-exclamation'];
    } else {
        return ['class' => 'dark', 'text' => 'Insufficient', 'icon' => 'fas fa-times'];
    }
}

/**
 * Logging Functions
 */

/**
 * Log system activity
 * @param PDO $pdo Database connection
 * @param string $action Action performed
 * @param int $userId User ID (optional)
 * @param string $tableName Table affected (optional)
 * @param int $recordId Record ID (optional)
 * @param array $oldValues Old values (optional)
 * @param array $newValues New values (optional)
 */
function logActivity($pdo, $action, $userId = null, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $action,
            $tableName,
            $recordId,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (PDOException $e) {
        // Log error but don't break functionality
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

/**
 * Utility Functions
 */

/**
 * Redirect with message
 * @param string $url Redirect URL
 * @param string $message Flash message
 * @param string $type Message type (success, error, warning, info)
 */
function redirectWithMessage($url, $message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: $url");
    exit();
}

/**
 * Get and clear flash message
 * @return array|null Flash message array or null
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = [
            'message' => $_SESSION['flash_message'],
            'type' => $_SESSION['flash_type'] ?? 'info'
        ];
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return $message;
    }
    return null;
}

/**
 * Generate breadcrumb navigation
 * @param array $breadcrumbs Breadcrumb array
 * @return string HTML breadcrumb
 */
function generateBreadcrumb($breadcrumbs) {
    if (empty($breadcrumbs)) {
        return '';
    }
    
    $html = '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
    
    foreach ($breadcrumbs as $key => $breadcrumb) {
        $isLast = ($key === array_key_last($breadcrumbs));
        
        if ($isLast) {
            $html .= '<li class="breadcrumb-item active" aria-current="page">' . htmlspecialchars($breadcrumb['title']) . '</li>';
        } else {
            $html .= '<li class="breadcrumb-item"><a href="' . htmlspecialchars($breadcrumb['url']) . '">' . htmlspecialchars($breadcrumb['title']) . '</a></li>';
        }
    }
    
    $html .= '</ol></nav>';
    return $html;
}

/**
 * Format number with appropriate suffix
 * @param int $number Number to format
 * @return string Formatted number
 */
function formatNumberShort($number) {
    if ($number >= 1000000) {
        return round($number / 1000000, 1) . 'M';
    } elseif ($number >= 1000) {
        return round($number / 1000, 1) . 'K';
    }
    return number_format($number);
}

/**
 * Generate pagination HTML
 * @param int $currentPage Current page number
 * @param int $totalPages Total number of pages
 * @param string $baseUrl Base URL for pagination links
 * @param array $params Additional URL parameters
 * @return string Pagination HTML
 */
function generatePagination($currentPage, $totalPages, $baseUrl, $params = []) {
    if ($totalPages <= 1) {
        return '';
    }
    
    $html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';
    
    // Previous button
    if ($currentPage > 1) {
        $prevParams = array_merge($params, ['page' => $currentPage - 1]);
        $prevUrl = $baseUrl . '?' . http_build_query($prevParams);
        $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($prevUrl) . '">Previous</a></li>';
    }
    
    // Page numbers
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    if ($start > 1) {
        $firstParams = array_merge($params, ['page' => 1]);
        $firstUrl = $baseUrl . '?' . http_build_query($firstParams);
        $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($firstUrl) . '">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $currentPage) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $pageParams = array_merge($params, ['page' => $i]);
            $pageUrl = $baseUrl . '?' . http_build_query($pageParams);
            $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($pageUrl) . '">' . $i . '</a></li>';
        }
    }
    
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $lastParams = array_merge($params, ['page' => $totalPages]);
        $lastUrl = $baseUrl . '?' . http_build_query($lastParams);
        $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($lastUrl) . '">' . $totalPages . '</a></li>';
    }
    
    // Next button
    if ($currentPage < $totalPages) {
        $nextParams = array_merge($params, ['page' => $currentPage + 1]);
        $nextUrl = $baseUrl . '?' . http_build_query($nextParams);
        $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($nextUrl) . '">Next</a></li>';
    }
    
    $html .= '</ul></nav>';
    return $html;
}

/**
 * Email Functions (for future use)
 */

/**
 * Send notification email (placeholder for future implementation)
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $message Email message
 * @param array $options Additional options
 * @return bool Success status
 */
function sendNotificationEmail($to, $subject, $message, $options = []) {
    // Placeholder for email functionality
    // You can implement PHPMailer or similar here
    return true;
}

/**
 * Configuration Functions
 */

/**
 * Get system configuration value
 * @param PDO $pdo Database connection
 * @param string $key Configuration key
 * @param mixed $default Default value
 * @return mixed Configuration value
 */
function getConfig($pdo, $key, $default = null) {
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['config_value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

/**
 * Set system configuration value
 * @param PDO $pdo Database connection
 * @param string $key Configuration key
 * @param mixed $value Configuration value
 * @return bool Success status
 */
function setConfig($pdo, $key, $value) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_config (config_key, config_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
        ");
        return $stmt->execute([$key, $value]);
    } catch (PDOException $e) {
        return false;
    }
}


function recomputeApplicationRowStatus(PDO $pdo, int $userId): void {
    $q = $pdo->prepare("
        SELECT 
            COUNT(*) total,
            SUM(status='needs_revision') needs_revision
        FROM application_forms
        WHERE user_id = ?
    ");
    $q->execute([$userId]);
    $s = $q->fetch(PDO::FETCH_ASSOC);

    // Default: under_review; kung may kahit 1 needs_revision => needs_revision
    $rowStatus = ((int)$s['needs_revision'] > 0) ? 'needs_revision' : 'under_review';

    $u = $pdo->prepare("UPDATE users SET application_form_status=? WHERE id=?");
    $u->execute([$rowStatus, $userId]);
}
// End of functions.php


?>