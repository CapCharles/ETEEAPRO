<?php
// Base URL ng project (may trailing slash)
define('BASE_URL', 'http://localhost/ETEEAPRO/');

// Absolute disk path (Windows)
define('BASE_PATH', 'C:/xampp/htdocs/ETEEAPRO/');

// Upload path (naka-base sa BASE_PATH)
define('UPLOAD_PATH', BASE_PATH . 'uploads/documents/');

// Max upload size (5MB)
define('MAX_FILE_SIZE', 5242880); 

// Helpers
function url($path = '') { 
    return BASE_URL . ltrim($path, '/'); 
}

function pathf($path = '') { 
    return BASE_PATH . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/')); 
}
?>
