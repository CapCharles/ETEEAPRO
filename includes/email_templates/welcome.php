<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to ETEEAP</title>
    <?php echo EmailConfig::getHeaderStyles(); ?>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header">
            <h1 style="margin: 0; font-size: 28px;">
                🎓 Welcome to ETEEAP!
            </h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9; font-size: 16px;">
                Your journey to academic recognition starts here
            </p>
        </div>
        
        <!-- Body -->
        <div class="email-body">
            <h2 style="color: #667eea; margin-top: 0;">Hello <?php echo htmlspecialchars($user_name); ?>! 👋</h2>
            
            <p>Welcome to the <strong>ETEEAP (Expanded Tertiary Education Equivalency and Accreditation Program)</strong> platform! We're excited to help you transform your professional experience into academic credit.</p>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;">
                <h3 style="margin-top: 0; color: #333;">Your Account Details:</h3>
                <ul style="list-style: none; padding: 0;">
                    <li style="margin: 8px 0;"><strong>📧 Email:</strong> <?php echo htmlspecialchars($user_email); ?></li>
                    <li style="margin: 8px 0;"><strong>👤 Account Type:</strong> <?php echo ucfirst($user_type); ?></li>
                    <li style="margin: 8px 0;"><strong>📅 Registration Date:</strong> <?php echo date('F j, Y'); ?></li>
                </ul>
            </div>
            
            <h3>🚀 What's Next?</h3>
            
            <?php if ($user_type === 'candidate'): ?>
            <div style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 20px; border-radius: 10px; margin: 20px 0;">
                <h4 style="margin-top: 0;">For Candidates - Get Started:</h4>
                <ol style="margin: 0; padding-left: 20px;">
                    <li style="margin: 10px 0;">Complete your profile information</li>
                    <li style="margin: 10px 0;">Choose your desired program</li>
                    <li style="margin: 10px 0;">Upload your credentials and documents</li>
                    <li style="margin: 10px 0;">Submit your application for evaluation</li>
                </ol>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="<?php echo $dashboard_url; ?>" class="btn" style="background: #28a745; text-decoration: none;">
                    📝 Start Your Application
                </a>
            </div>
            
            <div style="background: #e7f3ff; padding: 15px; border-radius: 8px; border-left: 4px solid #0066cc;">
                <h4 style="margin-top: 0; color: #0066cc;">💡 Pro Tips for Success:</h4>
                <ul style="margin: 0;">
                    <li>Gather all relevant certificates and work documents</li>
                    <li>Provide detailed descriptions of your work experience</li>
                    <li>Include professional training certificates</li>
                    <li>Prepare a comprehensive portfolio of your achievements</li>
                </ul>
            </div>
            
            <?php else: ?>
            <div style="background: linear-gradient(135deg, #dc3545, #fd7e14); color: white; padding: 20px; border-radius: 10px; margin: 20px 0;">
                <h4 style="margin-top: 0;">For Administrators/Evaluators:</h4>
                <ol style="margin: 0; padding-left: 20px;">
                    <li style="margin: 10px 0;">Access the admin dashboard</li>
                    <li style="margin: 10px 0;">Review pending applications</li>
                    <li style="margin: 10px 0;">Conduct thorough evaluations</li>
                    <li style="margin: 10px 0;">Generate assessment reports</li>
                </ol>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="<?php echo $dashboard_url; ?>" class="btn" style="background: #dc3545; text-decoration: none;">
                    🔧 Access Admin Dashboard
                </a>
            </div>
            <?php endif; ?>
            
            <div style="border-top: 2px solid #eee; padding-top: 20px; margin-top: 30px;">
                <h3>📞 Need Help?</h3>
                <p>Our support team is here to assist you every step of the way:</p>
                <ul style="list-style: none; padding: 0;">
                    <li style="margin: 8px 0;">📧 <strong>Email:</strong> <a href="mailto:support@eteeap.edu">support@eteeap.edu</a></li>
                    <li style="margin: 8px 0;">📱 <strong>Phone:</strong> +63 (02) 8123-4567</li>
                    <li style="margin: 8px 0;">🕒 <strong>Support Hours:</strong> Monday - Friday, 8:00 AM - 5:00 PM</li>
                </ul>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="<?php echo $login_url; ?>" class="btn" style="text-decoration: none;">
                    🔐 Login to Your Account
                </a>
            </div>
            
            <p style="text-align: center; color: #666; font-style: italic;">
                "Transforming experience into expertise, one credential at a time."
            </p>
        </div>
        
        <!-- Footer -->
        <div class="email-footer">
            <?php echo EmailConfig::getFooter(); ?>
        </div>
    </div>
</body>
</html>