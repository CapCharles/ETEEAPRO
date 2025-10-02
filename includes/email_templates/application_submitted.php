<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Submitted - ETEEAP</title>
    <?php echo EmailConfig::getHeaderStyles(); ?>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header">
            <h1 style="margin: 0; font-size: 28px;">
                ✅ Application Submitted Successfully!
            </h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9; font-size: 16px;">
                Your ETEEAP application is now under review
            </p>
        </div>
        
        <!-- Body -->
        <div class="email-body">
            <h2 style="color: #28a745; margin-top: 0;">Great news, <?php echo htmlspecialchars($user_name); ?>! 🎉</h2>
            
            <p>Your ETEEAP application has been <strong>successfully submitted</strong> and is now in our evaluation queue. Our expert assessors will carefully review your credentials and experience.</p>
            
            <div style="background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 25px; border-radius: 15px; margin: 25px 0; text-align: center;">
                <h3 style="margin: 0 0 15px 0;">📋 Application Summary</h3>
                <div style="background: rgba(255,255,255,0.2); padding: 15px; border-radius: 10px;">
                    <p style="margin: 5px 0;"><strong>Application ID:</strong> #<?php echo str_pad($application_id, 6, '0', STR_PAD_LEFT); ?></p>
                    <p style="margin: 5px 0;"><strong>Program:</strong> <?php echo htmlspecialchars($program_name); ?></p>
                    <p style="margin: 5px 0;"><strong>Submitted:</strong> <?php echo $submission_date; ?></p>
                </div>
            </div>
            
            <h3>🔍 What Happens Next?</h3>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;">
                <div style="display: flex; align-items: center; margin: 15px 0;">
                    <div style="background: #667eea; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 15px; font-weight: bold;">1</div>
                    <div>
                        <strong>Document Verification</strong><br>
                        <small style="color: #666;">Our team will verify all submitted documents for authenticity</small>
                    </div>
                </div>
                
                <div style="display: flex; align-items: center; margin: 15px 0;">
                    <div style="background: #28a745; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 15px; font-weight: bold;">2</div>
                    <div>
                        <strong>Expert Assessment</strong><br>
                        <small style="color: #666;">Qualified evaluators will assess your experience against program criteria</small>
                    </div>
                </div>
                
                <div style="display: flex; align-items: center; margin: 15px 0;">
                    <div style="background: #ffc107; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 15px; font-weight: bold;">3</div>
                    <div>
                        <strong>Results & Recommendations</strong><br>
                        <small style="color: #666;">You'll receive detailed feedback and credit recommendations</small>
                    </div>
                </div>
            </div>
            
            <div style="background: #e7f3ff; padding: 20px; border-radius: 10px; border-left: 4px solid #0066cc; margin: 25px 0;">
                <h4 style="margin-top: 0; color: #0066cc;">📅 Timeline Expectations</h4>
                <ul style="margin: 0;">
                    <li><strong>Initial Review:</strong> 3-5 business days</li>
                    <li><strong>Document Verification:</strong> 5-7 business days</li>
                    <li><strong>Expert Assessment:</strong> 10-14 business days</li>
                    <li><strong>Final Results:</strong> 14-21 business days</li>
                </ul>
                <p style="margin: 15px 0 0 0; font-style: italic; color: #666;">
                    <strong>Note:</strong> Complex applications may require additional time for thorough evaluation.
                </p>
            </div>
            
            <h3>📊 Track Your Progress</h3>
            <p>You can monitor your application status anytime through your candidate dashboard:</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="<?php echo $assessment_url; ?>" class="btn" style="text-decoration: none; background: #667eea;">
                    👁️ View Application Status
                </a>
            </div>
            
            <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 25px 0;">
                <h4 style="margin-top: 0; color: #664d03;">⚠️ Important Reminders</h4>
                <ul style="margin: 0; color: #664d03;">
                    <li>Keep this email for your records</li>
                    <li>No additional documents can be submitted after this point</li>
                    <li>You'll receive email updates as your application progresses</li>
                    <li>Contact us immediately if you notice any errors in your submission</li>
                </ul>
            </div>
            
            <div style="border-top: 2px solid #eee; padding-top: 20px; margin-top: 30px;">
                <h3>❓ Questions or Concerns?</h3>
                <p>Our ETEEAP support team is ready to help:</p>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                    <p style="margin: 5px 0;"><strong>📧 Email:</strong> <a href="mailto:support@eteeap.edu">support@eteeap.edu</a></p>
                    <p style="margin: 5px 0;"><strong>📞 Phone:</strong> +63 (02) 8123-4567</p>
                    <p style="margin: 5px 0;"><strong>🕒 Hours:</strong> Monday - Friday, 8:00 AM - 5:00 PM</p>
                    <p style="margin: 5px 0;"><strong>📋 Reference ID:</strong> #<?php echo str_pad($application_id, 6, '0', STR_PAD_LEFT); ?></p>
                </div>
            </div>
            
            <p style="text-align: center; color: #666; font-style: italic; margin-top: 30px;">
                "Your professional journey is our priority. Thank you for choosing ETEEAP!"
            </p>
        </div>
        
        <!-- Footer -->
        <div class="email-footer">
            <?php echo EmailConfig::getFooter(); ?>
        </div>
    </div>
</body>
</html>