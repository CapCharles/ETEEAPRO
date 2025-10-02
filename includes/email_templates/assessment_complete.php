<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Complete - ETEEAP</title>
    <?php echo EmailConfig::getHeaderStyles(); ?>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header">
            <h1 style="margin: 0; font-size: 28px;">
                🎯 Assessment Complete!
            </h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9; font-size: 16px;">
                Your ETEEAP evaluation results are ready
            </p>
        </div>
        
        <!-- Body -->
        <div class="email-body">
            <h2 style="color: #667eea; margin-top: 0;">Dear <?php echo htmlspecialchars($user_name); ?>,</h2>
            
            <p>We're pleased to inform you that the assessment of your ETEEAP application has been <strong>completed</strong>. Our expert evaluators have thoroughly reviewed your credentials, experience, and documentation.</p>
            
            <!-- Results Summary -->
            <div style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 25px; border-radius: 15px; margin: 25px 0; text-align: center;">
                <h3 style="margin: 0 0 20px 0;">📊 Your Assessment Results</h3>
                <div style="background: rgba(255,255,255,0.2); padding: 20px; border-radius: 10px;">
                    <p style="margin: 5px 0; font-size: 14px;"><strong>Application ID:</strong> #<?php echo str_pad($application_id, 6, '0', STR_PAD_LEFT); ?></p>
                    <p style="margin: 5px 0; font-size: 14px;"><strong>Program:</strong> <?php echo htmlspecialchars($program_name); ?></p>
                    <p style="margin: 15px 0 5px 0; font-size: 14px;"><strong>Overall Score:</strong></p>
                    <div style="font-size: 36px; font-weight: bold; margin: 10px 0;">
                        <?php echo number_format($score, 1); ?>%
                    </div>
                    <div style="margin-top: 15px;">
                        <span class="status-badge <?php echo $status_class; ?>" style="font-size: 16px; padding: 8px 20px;">
                            <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <?php
            // Determine message based on status
            $statusMessages = [
                'qualified' => [
                    'icon' => '🎉',
                    'title' => 'Congratulations! You are Fully Qualified',
                    'message' => 'Your professional experience and credentials demonstrate mastery of the program competencies. You are eligible for full academic credit.',
                    'color' => '#28a745'
                ],
                'partially_qualified' => [
                    'icon' => '⭐',
                    'title' => 'Partially Qualified - Great Progress!',
                    'message' => 'Your experience shows significant competency in most areas. You may be eligible for partial academic credit with some additional requirements.',
                    'color' => '#ffc107'
                ],
                'not_qualified' => [
                    'icon' => '💪',
                    'title' => 'Additional Development Recommended',
                    'message' => 'While your experience is valuable, additional training or experience is recommended to meet the full program requirements.',
                    'color' => '#dc3545'
                ]
            ];
            
            $currentStatus = $statusMessages[$status] ?? $statusMessages['not_qualified'];
            ?>
            
            <div style="background: <?php echo $currentStatus['color']; ?>; color: white; padding: 20px; border-radius: 10px; margin: 25px 0;">
                <h3 style="margin: 0;"><?php echo $currentStatus['icon']; ?> <?php echo $currentStatus['title']; ?></h3>
                <p style="margin: 10px 0 0 0; opacity: 0.95;"><?php echo $currentStatus['message']; ?></p>
            </div>
            
            <?php if ($recommendation): ?>
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 25px 0; border-left: 4px solid #667eea;">
                <h4 style="margin-top: 0; color: #667eea;">📝 Evaluator's Recommendations</h4>
                <div style="background: white; padding: 15px; border-radius: 8px; border: 1px solid #e9ecef;">
                    <?php echo nl2br(htmlspecialchars($recommendation)); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($status === 'qualified'): ?>
            <div style="background: #d1e7dd; padding: 20px; border-radius: 10px; border-left: 4px solid #28a745; margin: 25px 0;">
                <h4 style="margin-top: 0; color: #0f5132;">🎓 Next Steps - Academic Credit</h4>
                <ul style="margin: 0; color: #0f5132;">
                    <li>Contact the admissions office to process your credit transfer</li>
                    <li>Request official transcripts reflecting your ETEEAP credits</li>
                    <li>Consult with academic advisors for degree completion planning</li>
                    <li>Consider enrollment in remaining required courses</li>
                </ul>
            </div>
            <?php elseif ($status === 'partially_qualified'): ?>
            <div style="background: #fff3cd; padding: 20px; border-radius: 10px; border-left: 4px solid #ffc107; margin: 25px 0;">
                <h4 style="margin-top: 0; color: #664d03;">⭐ Next Steps - Partial Credit</h4>
                <ul style="margin: 0; color: #664d03;">
                    <li>Review specific areas requiring additional development</li>
                    <li>Consider targeted training or certification programs</li>
                    <li>Gather additional documentation of relevant experience</li>
                    <li>Contact academic advisors for bridging course recommendations</li>
                </ul>
            </div>
            <?php else: ?>
            <div style="background: #f8d7da; padding: 20px; border-radius: 10px; border-left: 4px solid #dc3545; margin: 25px 0;">
                <h4 style="margin-top: 0; color: #721c24;">💪 Next Steps - Development Path</h4>
                <ul style="margin: 0; color: #721c24;">
                    <li>Review detailed feedback on areas for improvement</li>
                    <li>Pursue additional professional development opportunities</li>
                    <li>Gain more relevant work experience in target competency areas</li>
                    <li>Consider reapplying after meeting additional requirements</li>
                </ul>
            </div>
            <?php endif; ?>
            
            <h3>📊 View Your Detailed Report</h3>
            <p>Access your complete assessment report with detailed breakdowns, evidence evaluation, and personalized recommendations:</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="<?php echo $assessment_url; ?>" class="btn" style="text-decoration: none; background: #667eea; font-size: 16px; padding: 15px 30px;">
                    📋 View Complete Assessment Report
                </a>
            </div>
            
            <div style="background: #e7f3ff; padding: 20px; border-radius: 10px; border-left: 4px solid #0066cc; margin: 25px 0;">
                <h4 style="margin-top: 0; color: #0066cc;">📞 Schedule a Consultation</h4>
                <p style="margin: 0; color: #0066cc;">
                    Want to discuss your results in detail? Our academic advisors are available to help you understand your assessment and plan your next steps. Contact us to schedule a personalized consultation session.
                </p>
            </div>
            
            <div style="border-top: 2px solid #eee; padding-top: 20px; margin-top: 30px;">
                <h3>🤝 Thank You for Choosing ETEEAP</h3>
                <p>We appreciate the trust you've placed in our assessment process. Whether you're celebrating your qualification or planning your next steps, we're here to support your educational journey.</p>
                
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                    <h4 style="margin-top: 0;">Contact Information:</h4>
                    <p style="margin: 5px 0;"><strong>📧 Email:</strong> <a href="mailto:results@eteeap.edu">results@eteeap.edu</a></p>
                    <p style="margin: 5px 0;"><strong>📞 Phone:</strong> +63 (02) 8123-4567</p>
                    <p style="margin: 5px 0;"><strong>🕒 Hours:</strong> Monday - Friday, 8:00 AM - 5:00 PM</p>
                    <p style="margin: 5px 0;"><strong>📋 Reference ID:</strong> #<?php echo str_pad($application_id, 6, '0', STR_PAD_LEFT); ?></p>
                </div>
            </div>
            
            <p style="text-align: center; color: #666; font-style: italic; margin-top: 30px;">
                "Celebrating your achievements and supporting your continued growth."
            </p>
        </div>
        
        <!-- Footer -->
        <div class="email-footer">
            <p style="margin: 0; color: #666; font-size: 12px;">
                This assessment was completed on <?php echo $completion_date; ?> by certified ETEEAP evaluators.
            </p>
            <?php echo EmailConfig::getFooter(); ?>
        </div>
    </div>
</body>
</html>