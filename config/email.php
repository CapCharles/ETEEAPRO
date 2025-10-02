<?php
/**
 * Email Configuration for ETEEAP System
 * Setup SMTP settings and email templates
 */

// Email Configuration - Updated with your working Gmail settings
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 465); // Using your working port
define('SMTP_USERNAME', 'cspbank911@gmail.com'); // Your working email
define('SMTP_PASSWORD', 'uzhtbqmdqigquyqq'); // Your working app password
define('SMTP_SECURE', 'ssl'); // Using your working encryption
define('SMTP_FROM_EMAIL', 'cspbank911@gmail.com'); // Using your working email
define('SMTP_FROM_NAME', 'ETEEAP System');

// Email Settings
define('EMAIL_ENABLED', true); // Set to false to disable emails during development
define('EMAIL_DEBUG', false); // Set to true for debugging

/**
 * Email Templates Configuration
 */
class EmailConfig {
    
    public static function getTemplate($type) {
        $templates = [
            'welcome' => [
                'subject' => 'Welcome to ETEEAP - Your Account is Ready!',
                'template' => 'welcome.php'
            ],
            'application_submitted' => [
                'subject' => 'Application Submitted Successfully - ETEEAP',
                'template' => 'application_submitted.php'
            ],
            'application_under_review' => [
                'subject' => 'Your Application is Under Review - ETEEAP',
                'template' => 'application_under_review.php'
            ],
            'assessment_complete' => [
                'subject' => 'Assessment Complete - Your ETEEAP Results',
                'template' => 'assessment_complete.php'
            ],
            'admin_new_application' => [
                'subject' => 'New Application Submitted - Action Required',
                'template' => 'admin_new_application.php'
            ],
            'password_reset' => [
                'subject' => 'Password Reset Request - ETEEAP',
                'template' => 'password_reset.php'
            ]
        ];
        
        return $templates[$type] ?? null;
    }
    
    /**
     * Get email footer content
     */
    public static function getFooter() {
        return "
        <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center; color: #666; font-size: 12px;'>
            <p>This is an automated message from the ETEEAP System.</p>
            <p>For support, contact us at <a href='mailto:support@eteeap.edu'>support@eteeap.edu</a></p>
            <p>&copy; " . date('Y') . " ETEEAP Assessment Platform. All rights reserved.</p>
        </div>";
    }
    
    /**
     * Get email header styles
     */
    public static function getHeaderStyles() {
        return "
        <style>
            .email-container { 
                max-width: 600px; 
                margin: 0 auto; 
                font-family: Arial, sans-serif; 
                line-height: 1.6; 
                color: #333; 
            }
            .email-header { 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                color: white; 
                padding: 30px; 
                text-align: center; 
                border-radius: 10px 10px 0 0; 
            }
            .email-body { 
                background: white; 
                padding: 30px; 
                border: 1px solid #ddd; 
            }
            .email-footer { 
                background: #f8f9fa; 
                padding: 20px; 
                text-align: center; 
                border-radius: 0 0 10px 10px; 
                border: 1px solid #ddd; 
                border-top: none; 
            }
            .btn { 
                display: inline-block; 
                padding: 12px 30px; 
                background: #667eea; 
                color: white; 
                text-decoration: none; 
                border-radius: 5px; 
                margin: 10px 0; 
            }
            .status-badge { 
                padding: 5px 15px; 
                border-radius: 20px; 
                font-weight: bold; 
                display: inline-block; 
            }
            .status-qualified { background: #d1e7dd; color: #0f5132; }
            .status-partially_qualified { background: #fff3cd; color: #664d03; }
            .status-not_qualified { background: #f8d7da; color: #721c24; }
            .status-under_review { background: #cff4fc; color: #055160; }
        </style>";
    }
}

/**
 * Admin Email Recipients
 */
define('ADMIN_EMAIL_RECIPIENTS', [
    'admin@eteeap.edu',
    'evaluator@eteeap.edu'
]);

// Email Queue Settings (for future implementation)
define('EMAIL_QUEUE_ENABLED', false);
define('EMAIL_BATCH_SIZE', 10);
?>