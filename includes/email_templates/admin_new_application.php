<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Application Submitted - ETEEAP Admin</title>
    <?php echo EmailConfig::getHeaderStyles(); ?>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header" style="background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);">
            <h1 style="margin: 0; font-size: 28px;">
                🚨 New Application Alert
            </h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9; font-size: 16px;">
                Action Required - ETEEAP Application Submitted
            </p>
        </div>
        
        <!-- Body -->
        <div class="email-body">
            <h2 style="color: #dc3545; margin-top: 0;">New Application Requiring Review</h2>
            
            <p>A new ETEEAP application has been submitted and is ready for evaluation. Please review the application details below and take appropriate action.</p>
            
            <!-- Application Details -->
            <div style="background: linear-gradient(135deg, #dc3545, #fd7e14); color: white; padding: 25px; border-radius: 15px; margin: 25px 0;">
                <h3 style="margin: 0 0 20px 0; text-align: center;">📋 Application Details</h3>
                <div style="background: rgba(255,255,255,0.2); padding: 20px; border-radius: 10px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <p style="margin: 5px 0;"><strong>Application ID:</strong></p>
                            <p style="margin: 0; font-size: 18px; font-weight: bold;">#<?php echo str_pad($application_id, 6, '0', STR_PAD_LEFT); ?></p>
                        </div>
                        <div>
                            <p style="margin: 5px 0;"><strong>Submitted:</strong></p>
                            <p style="margin: 0;"><?php echo $submission_date; ?></p>
                        </div>
                    </div>
                    <hr style="border: 1px solid rgba(255,255,255,0.3); margin: 15px 0;">
                    <p style="margin: 5px 0;"><strong>Program:</strong> <?php echo htmlspecialchars($program_name); ?></p>
                    <p style="margin: 5px 0;"><strong>Candidate:</strong> <?php echo htmlspecialchars($user_name); ?></p>
                    <p style="margin: 5px 0;"><strong>Email:</strong> <?php echo htmlspecialchars($candidate_email); ?></p>
                </div>
            </div>
            
            <!-- Priority Action -->
            <div style="background: #fff3cd; padding: 20px; border-radius: 10px; border-left: 4px solid #ffc107; margin: 25px 0;">
                <h4 style="margin-top: 0; color: #664d03;">⏰ Time-Sensitive Action Required</h4>
                <p style="margin: 0; color: #664d03;">
                    This application requires immediate attention. Please review and begin the evaluation process within 
                    <strong>3 business days</strong> to maintain our service level commitments.
                </p>
            </div>
            
            <!-- Quick Actions -->
            <h3>🚀 Quick Actions</h3>
            <div style="text-align: center; margin: 30px 0;">
                <a href="<?php echo $admin_url; ?>" class="btn" style="text-decoration: none; background: #dc3545; font-size: 16px; padding: 15px 30px; margin: 10px;">
                    🔍 Review Application Now
                </a>
                <a href="<?php echo str_replace('evaluate.php', 'dashboard.php', $admin_url); ?>" class="btn" style="text-decoration: none; background: #667eea; font-size: 16px; padding: 15px 30px; margin: 10px;">
                    📊 View Dashboard
                </a>
            </div>
            
            <!-- Evaluation Checklist -->
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 25px 0;">
                <h4 style="margin-top: 0; color: #333;">✅ Evaluation Checklist</h4>
                <ul style="margin: 0; list-style-type: none; padding: 0;">
                    <li style="margin: 10px 0; padding: 8px; background: white; border-radius: 5px; border-left: 3px solid #28a745;">
                        <strong>☐ Document Verification</strong><br>
                        <small style="color: #666;">Verify authenticity and completeness of submitted documents</small>
                    </li>
                    <li style="margin: 10px 0; padding: 8px; background: white; border-radius: 5px; border-left: 3px solid #ffc107;">
                        <strong>☐ Experience Assessment</strong><br>
                        <small style="color: #666;">Evaluate professional experience against program criteria</small>
                    </li>
                    <li style="margin: 10px 0; padding: 8px; background: white; border-radius: 5px; border-left: 3px solid #17a2b8;">
                        <strong>☐ Competency Mapping</strong><br>
                        <small style="color: #666;">Map candidate skills to academic learning outcomes</small>
                    </li>
                    <li style="margin: 10px 0; padding: 8px; background: white; border-radius: 5px; border-left: 3px solid #dc3545;">
                        <strong>☐ Final Scoring</strong><br>
                        <small style="color: #666;">Calculate weighted scores and provide recommendations</small>
                    </li>
                </ul>
            </div>
            
            <!-- Recent Activity Summary -->
            <div style="background: #e7f3ff; padding: 20px; border-radius: 10px; border-left: 4px solid #0066cc; margin: 25px 0;">
                <h4 style="margin-top: 0; color: #0066cc;">📈 Recent System Activity</h4>
                <p style="margin: 5px 0; color: #0066cc;">This application is part of today's submission queue.</p>
                <p style="margin: 0; color: #0066cc; font-size: 14px;">
                    <strong>Tip:</strong> Check the admin dashboard for a complete overview of all pending applications.
                </p>
            </div>
            
            <!-- Contact Information -->
            <div style="border-top: 2px solid #eee; padding-top: 20px; margin-top: 30px;">
                <h3>📞 Support & Escalation</h3>
                <p>If you need assistance with this evaluation or have technical issues:</p>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                    <p style="margin: 5px 0;"><strong>📧 Admin Support:</strong> <a href="mailto:admin@eteeap.edu">admin@eteeap.edu</a></p>
                    <p style="margin: 5px 0;"><strong>📞 Emergency Line:</strong> +63 (02) 8123-4567 (Ext. 101)</p>
                    <p style="margin: 5px 0;"><strong>💬 Internal Chat:</strong> #eteeap-admin (Slack)</p>
                    <p style="margin: 5px 0;"><strong>📋 Application ID:</strong> #<?php echo str_pad($application_id, 6, '0', STR_PAD_LEFT); ?></p>
                </div>
            </div>
            
            <!-- Reminder -->
            <div style="background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 20px; border-radius: 10px; margin: 25px 0; text-align: center;">
                <h4 style="margin: 0;">🎯 Quality Assurance Reminder</h4>
                <p style="margin: 10px 0 0 0; opacity: 0.95;">
                    Remember to maintain our high standards of evaluation. Each assessment impacts a candidate's academic future.
                </p>
            </div>
            
            <p style="text-align: center; color: #666; font-style: italic; margin-top: 30px;">
                "Excellence in evaluation, integrity in assessment."
            </p>
        </div>
        
        <!-- Footer -->
        <div class="email-footer">
            <p style="margin: 0; color: #666; font-size: 12px;">
                This alert was generated automatically when application #<?php echo str_pad($application_id, 6, '0', STR_PAD_LEFT); ?> was submitted on <?php echo $submission_date; ?>.
            </p>
            <?php echo EmailConfig::getFooter(); ?>
        </div>
    </div>
</body>
</html>