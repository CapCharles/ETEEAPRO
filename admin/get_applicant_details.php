<?php
// admin/get_applicant_details.php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'evaluator'])) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Unauthorized access</div>';
    exit();
}

$user_id = $_GET['user_id'] ?? null;

if (!$user_id) {
    echo '<div class="alert alert-danger">User ID is required</div>';
    exit();
}

try {
    // Get user information
    $stmt = $pdo->prepare("
        SELECT u.*, 
               COUNT(af.id) as total_forms,
               MIN(af.upload_date) as first_upload,
               MAX(af.upload_date) as last_upload
        FROM users u
        LEFT JOIN application_forms af ON u.id = af.user_id AND af.status = 'pending_review'
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo '<div class="alert alert-danger">Applicant not found</div>';
        exit();
    }
    
    // Get all application forms for this user
    $stmt = $pdo->prepare("
        SELECT af.*, p.program_name, p.program_code
        FROM application_forms af
        LEFT JOIN programs p ON af.program_suggestion_id = p.id
        WHERE af.user_id = ? AND af.status = 'pending_review'
        ORDER BY af.upload_date DESC
    ");
    $stmt->execute([$user_id]);
    $forms = $stmt->fetchAll();
    
    ?>
    <div class="row g-4">
        <!-- User Information -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="fas fa-user me-2"></i>
                        Personal Information
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="d-flex align-items-center mb-3">
                                <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center me-3" 
                                     style="width: 50px; height: 50px;">
                                    <i class="fas fa-user fa-lg text-white"></i>
                                </div>
                                <div>
                                    <h5 class="mb-1"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h5>
                                    <p class="text-muted mb-0">Applicant ID: <?php echo $user['id']; ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-sm-6">
                            <label class="form-label small text-muted">Email</label>
                            <div><?php echo htmlspecialchars($user['email']); ?></div>
                        </div>
                        
                        <div class="col-sm-6">
                            <label class="form-label small text-muted">Phone</label>
                            <div><?php echo htmlspecialchars($user['phone'] ?: 'Not provided'); ?></div>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label small text-muted">Address</label>
                            <div><?php echo htmlspecialchars($user['address'] ?: 'Not provided'); ?></div>
                        </div>
                        
                        <div class="col-sm-6">
                            <label class="form-label small text-muted">Registered</label>
                            <div><?php echo formatDate($user['created_at']); ?></div>
                        </div>
                        
                        <div class="col-sm-6">
                            <label class="form-label small text-muted">Status</label>
                            <div>
                                <span class="badge bg-warning">Pending Review</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Application Summary -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="fas fa-file-alt me-2"></i>
                        Application Summary
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="h4 text-primary mb-1"><?php echo $user['total_forms']; ?></div>
                                <div class="small text-muted">Forms Uploaded</div>
                            </div>
                        </div>
                        
                        <div class="col-6">
                            <div class="text-center p-3 bg-light rounded">
                                <div class="h4 text-warning mb-1">
                                    <?php 
                                    $days_waiting = $user['first_upload'] ? 
                                        ceil((time() - strtotime($user['first_upload'])) / (60 * 60 * 24)) : 0;
                                    echo $days_waiting;
                                    ?>
                                </div>
                                <div class="small text-muted">Days Waiting</div>
                            </div>
                        </div>
                        
                        <?php if ($user['first_upload']): ?>
                        <div class="col-sm-6">
                            <label class="form-label small text-muted">First Upload</label>
                            <div><?php echo formatDate($user['first_upload']); ?></div>
                        </div>
                        
                        <div class="col-sm-6">
                            <label class="form-label small text-muted">Last Upload</label>
                            <div><?php echo formatDate($user['last_upload']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-12">
                            <label class="form-label small text-muted">Urgency Level</label>
                            <div>
                                <?php if ($days_waiting > 7): ?>
                                    <span class="badge bg-danger">
                                        <i class="fas fa-exclamation-triangle me-1"></i>High - Urgent Review Needed
                                    </span>
                                <?php elseif ($days_waiting > 3): ?>
                                    <span class="badge bg-warning">
                                        <i class="fas fa-clock me-1"></i>Medium - Priority Review
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-check me-1"></i>Low - Normal Processing
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Application Forms Details -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="fas fa-folder-open me-2"></i>
                        Application Forms (<?php echo count($forms); ?>)
                    </h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($forms)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                        <p class="text-muted">No application forms found</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>Document</th>
                                    <th>Detected Program</th>
                                    <th>Confidence</th>
                                    <th>Upload Date</th>
                                    <th>Size</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($forms as $form): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-file-pdf fa-lg text-danger me-3"></i>
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($form['original_filename']); ?></div>
                                                <?php if ($form['file_description']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($form['file_description']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($form['program_name']): ?>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-magic text-success me-2"></i>
                                            <div>
                                                <div class="fw-semibold small"><?php echo htmlspecialchars($form['program_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($form['program_code']); ?></small>
                                            </div>
                                        </div>
                                        <?php elseif ($form['extracted_program']): ?>
                                        <div class="small text-muted">
                                            <i class="fas fa-search me-1"></i>
                                            "<?php echo htmlspecialchars(substr($form['extracted_program'], 0, 50)); ?>..."
                                        </div>
                                        <?php else: ?>
                                        <span class="text-muted">
                                            <i class="fas fa-question-circle me-1"></i>
                                            Not detected
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($form['extracted_program_confidence']): ?>
                                        <div class="d-flex align-items-center">
                                            <div class="progress me-2" style="width: 60px; height: 8px;">
                                                <div class="progress-bar bg-<?php echo $form['extracted_program_confidence'] >= 80 ? 'success' : ($form['extracted_program_confidence'] >= 60 ? 'warning' : 'danger'); ?>" 
                                                     style="width: <?php echo $form['extracted_program_confidence']; ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?php echo number_format($form['extracted_program_confidence'], 1); ?>%</small>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo formatDate($form['upload_date']); ?></div>
                                        <small class="text-muted"><?php echo timeAgo($form['upload_date']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo formatFileSize($form['file_size']); ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-outline-primary btn-sm" 
                                                    onclick="previewDocument('<?php echo $form['id']; ?>')" 
                                                    title="Preview Document">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" 
                                                    onclick="downloadDocument('<?php echo $form['id']; ?>')" 
                                                    title="Download Document">
                                                <i class="fas fa-download"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Program Recommendation Summary -->
    <?php 
    // Get the best program recommendation
    $best_recommendation = null;
    $highest_confidence = 0;
    foreach ($forms as $form) {
        if ($form['extracted_program_confidence'] && $form['extracted_program_confidence'] > $highest_confidence) {
            $highest_confidence = $form['extracted_program_confidence'];
            $best_recommendation = $form;
        }
    }
    ?>
    
    <?php if ($best_recommendation): ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-lightbulb me-2"></i>
                        AI Program Recommendation
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="text-success mb-2">
                                <i class="fas fa-magic me-2"></i>
                                <?php echo htmlspecialchars($best_recommendation['program_name']); ?>
                            </h5>
                            <p class="mb-2">
                                <strong>Program Code:</strong> <?php echo htmlspecialchars($best_recommendation['program_code']); ?>
                            </p>
                            <p class="mb-2">
                                <strong>Detected from:</strong> <?php echo htmlspecialchars($best_recommendation['original_filename']); ?>
                            </p>
                            <?php if ($best_recommendation['extracted_program']): ?>
                            <div class="alert alert-light mb-0">
                                <small class="text-muted">
                                    <strong>Extracted text:</strong><br>
                                    "<?php echo htmlspecialchars($best_recommendation['extracted_program']); ?>"
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 text-center">
                            <div class="display-6 text-success fw-bold">
                                <?php echo number_format($highest_confidence, 1); ?>%
                            </div>
                            <div class="text-muted">Confidence Level</div>
                            
                            <div class="mt-3">
                                <?php if ($highest_confidence >= 80): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-check-circle me-1"></i>High Confidence
                                </span>
                                <?php elseif ($highest_confidence >= 60): ?>
                                <span class="badge bg-warning">
                                    <i class="fas fa-exclamation-circle me-1"></i>Medium Confidence
                                </span>
                                <?php else: ?>
                                <span class="badge bg-danger">
                                    <i class="fas fa-question-circle me-1"></i>Low Confidence
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3 p-3 bg-light rounded">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Recommendation:</strong> 
                                <?php if ($highest_confidence >= 80): ?>
                                    <span class="text-success">Highly recommended for quick approval</span>
                                <?php elseif ($highest_confidence >= 60): ?>
                                    <span class="text-warning">Review recommended before approval</span>
                                <?php else: ?>
                                    <span class="text-danger">Manual review required</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($highest_confidence >= 60): ?>
                            <button type="button" class="btn btn-success btn-sm" 
                                    onclick="quickApproveFromDetails('<?php echo $user['id']; ?>', '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>', '<?php echo $best_recommendation['program_suggestion_id']; ?>', '<?php echo htmlspecialchars($best_recommendation['program_name']); ?>')">
                                <i class="fas fa-magic me-1"></i>Quick Approve
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        function previewDocument(formId) {
            // Open document preview in new window
            window.open(`preview_document.php?id=${formId}`, '_blank', 'width=800,height=600');
        }
        
        function downloadDocument(formId) {
            // Download document
            window.location.href = `download_document.php?id=${formId}`;
        }
        
        function quickApproveFromDetails(userId, userName, programId, programName) {
            // Close current modal and trigger quick approve modal from parent window
            if (window.parent && window.parent.showQuickApproveModal) {
                window.parent.bootstrap.Modal.getInstance(window.parent.document.getElementById('applicantDetailsModal')).hide();
                setTimeout(() => {
                    window.parent.showQuickApproveModal(userId, userName, programId, programName);
                }, 300);
            }
        }
    </script>
    
    <?php
    
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>