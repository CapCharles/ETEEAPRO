<?php
// admin/preview_document.php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'evaluator'])) {
    http_response_code(403);
    die('Unauthorized');
}

$form_id = $_GET['id'] ?? null;

if (!$form_id) {
    die('Form ID is required');
}

try {
    // Get document information
    $stmt = $pdo->prepare("
        SELECT af.*, u.first_name, u.last_name, u.email
        FROM application_forms af
        LEFT JOIN users u ON af.user_id = u.id
        WHERE af.id = ?
    ");
    $stmt->execute([$form_id]);
    $document = $stmt->fetch();
    
    if (!$document) {
        die('Document not found');
    }
    
    // Check if file exists
    if (!file_exists($document['file_path'])) {
        die('File not found on server');
    }
    
    $filename = $document['original_filename'];
    $mime_type = $document['mime_type'];
    $file_path = $document['file_path'];
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Preview: <?php echo htmlspecialchars($filename); ?></title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            body { background-color: #f8f9fa; }
            .preview-container { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .document-info { background: #f8f9fa; border-bottom: 1px solid #dee2e6; }
            #documentViewer { width: 100%; height: 600px; border: 1px solid #dee2e6; }
        </style>
    </head>
    <body>
        <div class="container-fluid py-3">
            <div class="preview-container">
                <!-- Document Header -->
                <div class="document-info p-3">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="mb-1">
                                <i class="fas fa-file-pdf me-2"></i>
                                <?php echo htmlspecialchars($filename); ?>
                            </h5>
                            <div class="text-muted small">
                                <span class="me-3">
                                    <i class="fas fa-user me-1"></i>
                                    <?php echo htmlspecialchars($document['first_name'] . ' ' . $document['last_name']); ?>
                                </span>
                                <span class="me-3">
                                    <i class="fas fa-envelope me-1"></i>
                                    <?php echo htmlspecialchars($document['email']); ?>
                                </span>
                                <span class="me-3">
                                    <i class="fas fa-calendar me-1"></i>
                                    <?php echo formatDate($document['upload_date']); ?>
                                </span>
                                <span>
                                    <i class="fas fa-hdd me-1"></i>
                                    <?php echo formatFileSize($document['file_size']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <a href="download_document.php?id=<?php echo $form_id; ?>" 
                               class="btn btn-primary btn-sm me-2">
                                <i class="fas fa-download me-1"></i>Download
                            </a>
                            <button onclick="window.close()" class="btn btn-secondary btn-sm">
                                <i class="fas fa-times me-1"></i>Close
                            </button>
                        </div>
                    </div>
                    
                    <?php if ($document['file_description']): ?>
                    <div class="mt-2">
                        <small class="text-muted">
                            <strong>Description:</strong> <?php echo htmlspecialchars($document['file_description']); ?>
                        </small>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Program Detection Info -->
                    <?php if ($document['extracted_program'] || $document['program_suggestion_id']): ?>
                    <div class="mt-3 p-2 bg-info bg-opacity-10 rounded">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <small class="text-info">
                                    <i class="fas fa-magic me-1"></i>
                                    <strong>AI Program Detection:</strong>
                                    <?php if ($document['extracted_program']): ?>
                                        "<?php echo htmlspecialchars($document['extracted_program']); ?>"
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="col-md-4 text-end">
                                <?php if ($document['extracted_program_confidence']): ?>
                                <span class="badge bg-<?php echo $document['extracted_program_confidence'] >= 80 ? 'success' : ($document['extracted_program_confidence'] >= 60 ? 'warning' : 'secondary'); ?>">
                                    <?php echo number_format($document['extracted_program_confidence'], 1); ?>% confidence
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Document Viewer -->
                <div class="p-3">
                    <?php if ($mime_type === 'application/pdf'): ?>
                        <embed id="documentViewer" 
                               src="view_document.php?id=<?php echo $form_id; ?>" 
                               type="application/pdf">
                        <div class="text-center mt-3">
                            <p class="text-muted">
                                If the PDF doesn't load above, 
                                <a href="view_document.php?id=<?php echo $form_id; ?>" target="_blank">click here to view it</a>
                                or <a href="download_document.php?id=<?php echo $form_id; ?>">download it</a>.
                            </p>
                        </div>
                    <?php elseif (strpos($mime_type, 'image/') === 0): ?>
                        <div class="text-center">
                            <img src="view_document.php?id=<?php echo $form_id; ?>" 
                                 class="img-fluid" 
                                 style="max-height: 600px; border: 1px solid #dee2e6;"
                                 alt="<?php echo htmlspecialchars($filename); ?>">
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-file fa-4x text-muted mb-3"></i>
                            <h5>Preview not available</h5>
                            <p class="text-muted mb-3">
                                This file type (<?php echo htmlspecialchars($mime_type); ?>) cannot be previewed in the browser.
                            </p>
                            <a href="download_document.php?id=<?php echo $form_id; ?>" 
                               class="btn btn-primary">
                                <i class="fas fa-download me-2"></i>Download File
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <script>
            // Handle PDF loading errors
            document.getElementById('documentViewer')?.addEventListener('error', function() {
                this.style.display = 'none';
                const errorDiv = document.createElement('div');
                errorDiv.className = 'text-center py-5';
                errorDiv.innerHTML = `
                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                    <h5>Unable to load PDF preview</h5>
                    <p class="text-muted">The document cannot be displayed in your browser.</p>
                    <a href="download_document.php?id=<?php echo $form_id; ?>" class="btn btn-primary">
                        <i class="fas fa-download me-2"></i>Download Document
                    </a>
                `;
                this.parentNode.appendChild(errorDiv);
            });
        </script>
    </body>
    </html>
    
    <?php
    
} catch (PDOException $e) {
    die('Database error: ' . htmlspecialchars($e->getMessage()));
}
?>