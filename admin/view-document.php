<?php
// admin/view-document.php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../includes/functions.php';

// --- Auth guard ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Access denied');
}
$user_id   = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? 'candidate';

// --- Inputs ---
$file_id       = isset($_GET['id'])   ? trim($_GET['id'])   : null;   // usually numeric
$file_path_in  = isset($_GET['path']) ? trim($_GET['path']) : null;   // optional fallback
$mode          = $_GET['mode'] ?? 'view';

// --- Helpers ---
define('PROJECT_ROOT', realpath(dirname(__DIR__)));                          // .../ETEEAPRO
define('UPLOADS_ROOT', PROJECT_ROOT . DIRECTORY_SEPARATOR . 'uploads');      // .../ETEEAPRO/uploads

function is_absolute_path($p) {
    // Windows "C:\..." or UNC "\\server\..." or POSIX "/..."
    return (bool)preg_match('#^([A-Za-z]:[\\/]|[\\/]{2}|/)#', $p);
}

function safe_join($base, $rel) {
    $rel  = str_replace('\\', '/', $rel);
    $rel  = ltrim($rel, '/');
    $full = realpath($base . DIRECTORY_SEPARATOR . $rel);
    if ($full === false) return false;
    $baseReal = realpath($base);
    if (strpos($full, $baseReal) !== 0) return false; // prevent path traversal
    return $full;
}

function normalize_to_absolute_path($p) {
    if (!$p) return false;

    // 1) absolute path na?
    if (is_absolute_path($p) && is_file($p)) return $p;

    // 2) kung nagsisimula sa "uploads/", i-join sa PROJECT_ROOT
    $pNorm = str_replace('\\', '/', $p);
    if (stripos($pNorm, 'uploads/') === 0) {
        $full = safe_join(PROJECT_ROOT, $pNorm);
        if ($full && is_file($full)) return $full;
    }

    // 3) treat as relative sa UPLOADS_ROOT
    $full = safe_join(UPLOADS_ROOT, $pNorm);
    if ($full && is_file($full)) return $full;

    // 4) last resort: realpath kung sakaling relative sa kasalukuyang dir
    $full = realpath($p);
    if ($full && is_file($full)) return $full;

    return false;
}

function stream_inline_or_download($fullpath, $downloadName = null, $forceDownload = false) {
    if (!is_file($fullpath) || !is_readable($fullpath)) {
        http_response_code(404);
        echo "File not found";
        exit;
    }
    $ext  = strtolower(pathinfo($fullpath, PATHINFO_EXTENSION));
    $mime = 'application/octet-stream';
    if ($ext === 'pdf') $mime = 'application/pdf';
    if (in_array($ext, ['jpg','jpeg'])) $mime = 'image/jpeg';
    if ($ext === 'png') $mime = 'image/png';
    if ($ext === 'gif') $mime = 'image/gif';

    header('Content-Type: ' . $mime);
    $disp = $forceDownload ? 'attachment' : 'inline';
    $name = $downloadName ?: basename($fullpath);
    header('Content-Disposition: ' . $disp . '; filename="' . $name . '"');
    header('Content-Length: ' . filesize($fullpath));
    readfile($fullpath);
    exit;
}

// --- Load document record ---
// Strategy: kung may id => subukan muna sa `application_forms`, kapag wala, sa `documents`.
// Kung walang id pero may path => admins/evaluators only.
$document      = null;
$document_from = null;
if ($file_id) {
    try {
        // Preferably numeric id, pero allow string as long as parameterized.
        // 1) Try application_forms
        $stmt = $pdo->prepare("
            SELECT af.*, u.first_name, u.last_name 
            FROM application_forms af
            LEFT JOIN users u ON af.user_id = u.id
            WHERE af.id = ?
        ");
        $stmt->execute([$file_id]);
        $document = $stmt->fetch();
        if ($document) $document_from = 'application_form';

        // 2) If not found, try documents
        if (!$document) {
            $stmt = $pdo->prepare("
                SELECT d.*, u.first_name, u.last_name, a.user_id AS owner_user_id
                FROM documents d
                LEFT JOIN applications a ON d.application_id = a.id
                LEFT JOIN users u ON a.user_id = u.id
                WHERE d.id = ?
            ");
            $stmt->execute([$file_id]);
            $document = $stmt->fetch();
            if ($document) $document_from = 'document';
        }

        if (!$document) {
            http_response_code(404);
            die('Document not found');
        }

        // Permissions
        if ($user_type === 'candidate') {
            if ($document_from === 'application_form' && (int)$document['user_id'] !== (int)$user_id) {
                http_response_code(403); die('Access denied');
            }
            if ($document_from === 'document') {
                $ownerId = (int)($document['owner_user_id'] ?? 0);
                if ($ownerId !== (int)$user_id) {
                    http_response_code(403); die('Access denied');
                }
            }
        }

        $file_path = $document['file_path'] ?? null;
    } catch (PDOException $e) {
        http_response_code(500);
        die('Database error');
    }
} else {
    // No ID, path only => restrict to admin/evaluator
    if (!in_array($user_type, ['admin','evaluator'])) {
        http_response_code(403); die('Access denied');
    }
    $file_path = $file_path_in;
    $document_from = 'path';
}

// --- Resolve absolute path ---
$absolute_path = normalize_to_absolute_path($file_path);
if ($absolute_path === false) {
    http_response_code(404);
    die('File not found');
}

// --- File meta & security ---
$file_info      = pathinfo($absolute_path);
$file_extension = strtolower($file_info['extension'] ?? '');
$allowed_view   = ['pdf','jpg','jpeg','png','gif'];
$allowed_download_only = ['doc','docx','xls','xlsx','ppt','pptx'];

if (in_array($file_extension, $allowed_view)) {
    // ok, proceed with viewer (PDF/images)
} elseif (in_array($file_extension, $allowed_download_only)) {
    // diretso download, no preview
    stream_inline_or_download($absolute_path, $file_name, true);
} else {
    http_response_code(403);
    die('File type not allowed');
}

$file_name = $document['original_filename'] ?? $file_info['basename'];
$file_size = filesize($absolute_path);
$mime_type = function_exists('mime_content_type') ? mime_content_type($absolute_path) : 'application/octet-stream';

// --- Streaming modes (server-side early exit) ---
if ($mode === 'download') {
    stream_inline_or_download($absolute_path, $file_name, true);
}
if ($mode === 'raw') {
    stream_inline_or_download($absolute_path, $file_name, false);
}

// --- Default: render viewer ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Document Viewer - <?php echo htmlspecialchars($file_name); ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
    body { margin:0; background:#f8f9fa; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
    .viewer-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color:#fff; padding:1rem; position:sticky; top:0; z-index:10; box-shadow:0 2px 6px rgba(0,0,0,.1);
    }
    .viewer-content { height: calc(100vh - 80px); background:#fff; overflow:auto; }
    .document-frame { width:100%; height:100%; border:0; }
    .document-image { max-width:100%; display:block; margin:24px auto; border-radius:8px; box-shadow:0 4px 8px rgba(0,0,0,.08); }
    .document-info { background:#e9ecef; padding:.75rem 1rem; border-bottom:1px solid #dee2e6; }
    .loading { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); }
    .zoom { position: fixed; right: 16px; bottom: 16px; background: rgba(0,0,0,.8); border-radius:8px; padding:8px; display:none; }
    .zoom button { color:#fff; background:transparent; border:1px solid rgba(255,255,255,.3); padding:6px 10px; border-radius:4px; margin:0 2px; }
</style>
</head>
<body>
    <div class="viewer-header">
        <div class="d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <i class="fas fa-file-alt fa-lg me-3"></i>
                <div>
                    <h5 class="mb-0"><?php echo htmlspecialchars($file_name); ?></h5>
                    <small class="opacity-75">
                        <?php echo htmlspecialchars(formatFileSize($file_size)); ?> • <?php echo strtoupper($file_extension); ?>
                        <?php if (!empty($document['first_name']) || !empty($document['last_name'])): ?>
                            • Uploaded by <?php echo htmlspecialchars(($document['first_name'] ?? '').' '.($document['last_name'] ?? '')); ?>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
            <div class="d-flex align-items-center">
                <a class="btn btn-outline-light me-2" href="?<?php echo http_build_query(array_merge($_GET, ['mode'=>'download'])); ?>">
                    <i class="fas fa-download me-1"></i>Download
                </a>
                <button class="btn btn-outline-light me-2" onclick="printDocument()">
                    <i class="fas fa-print me-1"></i>Print
                </button>
                <button class="btn btn-outline-light" onclick="window.close()">
                    <i class="fas fa-times me-1"></i>Close
                </button>
            </div>
        </div>
    </div>

    <?php if (!empty($document_from) && $document_from !== 'path'): ?>
    <div class="document-info">
        <small class="text-muted">
            <strong>Source:</strong> <?php echo $document_from === 'application_form' ? 'Application Form' : 'Document'; ?>
            <?php if (!empty($document['upload_date'])): ?>
                • <strong>Uploaded:</strong> <?php echo htmlspecialchars(formatDateTime($document['upload_date'])); ?>
            <?php endif; ?>
            <?php if (!empty($document['file_description'])): ?>
                • <strong>Description:</strong> <?php echo htmlspecialchars($document['file_description']); ?>
            <?php endif; ?>
        </small>
    </div>
    <?php endif; ?>

    <div class="viewer-content" id="container">
        <div class="loading" id="loading">
            <div class="text-center">
                <div class="spinner-border text-primary"></div>
                <div class="text-muted mt-2">Loading document...</div>
            </div>
        </div>
    </div>

    <div class="zoom" id="zoom">
        <button onclick="zoomOut()" title="Zoom Out"><i class="fas fa-search-minus"></i></button>
        <button onclick="resetZoom()" title="Reset"><i class="fas fa-search"></i></button>
        <button onclick="zoomIn()" title="Zoom In"><i class="fas fa-search-plus"></i></button>
    </div>

<script>
    const ext = "<?php echo $file_extension; ?>";
    const rawUrl = "?<?php echo http_build_query(array_merge($_GET, ['mode'=>'raw'])); ?>";
    let zoom = 1;

    function hideLoading(){ document.getElementById('loading').style.display='none'; }
    function showZoom(){ if (['jpg','jpeg','png','gif'].includes(ext)) document.getElementById('zoom').style.display='block'; }
    function applyZoom(){
        const img = document.getElementById('docimg');
        if (img) img.style.transform = 'scale(' + zoom + ')';
    }
    function zoomIn(){ zoom = Math.min(zoom * 1.2, 3); applyZoom(); }
    function zoomOut(){ zoom = Math.max(zoom / 1.2, 0.5); applyZoom(); }
    function resetZoom(){ zoom = 1; applyZoom(); }

    function loadDoc(){
        const c = document.getElementById('container');
        if (ext === 'pdf') {
            c.innerHTML = '<iframe class="document-frame" src="'+rawUrl+'" title="PDF"></iframe>';
            const iframe = c.querySelector('iframe');
            iframe.onload = hideLoading;
            iframe.onerror = function(){
                c.innerHTML = errorHtml('PDF loading failed');
                hideLoading();
            }
        } else if (['jpg','jpeg','png','gif'].includes(ext)) {
            const img = new Image();
            img.id = 'docimg';
            img.className = 'document-image';
            img.onload = function(){
                c.innerHTML = '';
                c.appendChild(img);
                hideLoading();
                showZoom();
                applyZoom();
            };
            img.onerror = function(){
                c.innerHTML = errorHtml('Image loading failed');
                hideLoading();
            };
            img.src = rawUrl;
        } else {
            c.innerHTML = errorHtml('Unsupported file type');
            hideLoading();
        }
    }

    function errorHtml(msg){
        const dl = "?<?php echo http_build_query(array_merge($_GET, ['mode'=>'download'])); ?>";
        return `
            <div class="text-center p-5">
                <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                <h4 class="mb-2">Unable to Display Document</h4>
                <p class="text-muted">${msg}</p>
                <a class="btn btn-primary me-2" href="${rawUrl}" target="_blank"><i class="fas fa-external-link-alt me-2"></i>Open Raw</a>
                <a class="btn btn-outline-primary" href="${dl}"><i class="fas fa-download me-2"></i>Download</a>
            </div>
        `;
    }

    function printDocument(){
        if (ext === 'pdf') {
            const f = document.querySelector('.document-frame');
            if (f && f.contentWindow) f.contentWindow.print(); else window.print();
        } else {
            window.print();
        }
    }

    document.addEventListener('DOMContentLoaded', loadDoc);
    document.addEventListener('keydown', e=>{
        if ((e.ctrlKey||e.metaKey) && (e.key==='+'||e.key==='=')) { e.preventDefault(); zoomIn(); }
        if ((e.ctrlKey||e.metaKey) && e.key==='-') { e.preventDefault(); zoomOut(); }
        if ((e.ctrlKey||e.metaKey) && e.key==='0') { e.preventDefault(); resetZoom(); }
        if ((e.key==='Escape')) window.close();
    });
</script>
</body>
</html>
