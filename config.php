<?php
/**
 * Database Configuration File
 */

// Error handling - disable display_errors in production
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Changed to 0 for production security
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'job');
define('DB_USER', 'root');
define('DB_PASS', '');

// Security constants
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// Secure session configuration
// APP_SESSION_NAME lets applicant-facing pages run under a separate session
// cookie (see applicant_config.php) so staff and applicant logins don't collide.
if (session_status() === PHP_SESSION_NONE) {
    if (defined('APP_SESSION_NAME')) {
        session_name(APP_SESSION_NAME);
    }
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.sid_length', 48);
    ini_set('session.cookie_lifetime', 0);
    session_start();
}

// Set HTTP security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// Create database connection
function getDBConnection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($conn->connect_error) {
            error_log("Database connection failed: " . $conn->connect_error);
            die("Database connection failed. Please try again later.");
        }

        $conn->set_charset("utf8mb4");
        return $conn;
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        die("Database error occurred. Please contact administrator.");
    }
}

// CSRF Protection Functions
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Resolves a job posting's open/closed state from its manual override and open/close dates
function computeEffectiveStatus($statusOverride, $openDate, $closeDate) {
    if ($statusOverride === 'force_open') {
        return 'open';
    }
    if ($statusOverride === 'force_closed') {
        return 'closed';
    }
    $today = date('Y-m-d');
    if ($openDate && $today < $openDate) {
        return 'closed';
    }
    if ($closeDate && $today > $closeDate) {
        return 'closed';
    }
    return 'open';
}

// Permission check - admins implicitly have every permission, users need an explicit grant
function hasPermission($user_id, $role, $permission_slug) {
    if ($role === 'admin') {
        return true;
    }

    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT 1 FROM user_permissions up
                                 JOIN permissions p ON p.id = up.permission_id
                                 WHERE up.user_id = ? AND p.slug = ?");
        $stmt->bind_param("is", $user_id, $permission_slug);
        $stmt->execute();
        $result = $stmt->get_result();
        $granted = $result->num_rows > 0;
        $stmt->close();
        $conn->close();
        return $granted;
    } catch (Exception $e) {
        error_log("Permission check error: " . $e->getMessage());
        return false;
    }
}

// Session timeout check
function checkSessionTimeout() {
    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT)) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['LAST_ACTIVITY'] = time();
    return true;
}

// Input validation functions
function validateUsername($username) {
    $username = trim($username);
    if (strlen($username) < 3 || strlen($username) > 50) {
        return false;
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return false;
    }
    return $username;
}

function validatePassword($password) {
    if (strlen($password) < 6 || strlen($password) > 255) {
        return false;
    }
    return $password;
}

// Rate limiting functions
function checkLoginAttempts($username) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45) NOT NULL,
        INDEX idx_username (username),
        INDEX idx_attempt_time (attempt_time)
    )");
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts
                           WHERE username = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $lockout = LOGIN_LOCKOUT_TIME;
    $stmt->bind_param("si", $username, $lockout);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $row['attempts'] >= MAX_LOGIN_ATTEMPTS;
}

function recordLoginAttempt($username) {
    $conn = getDBConnection();
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)");
    $stmt->bind_param("ss", $username, $ip);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

function clearLoginAttempts($username) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// Activity Logging Functions
function logActivity($user_id, $action, $description = '', $options = []) {
    try {
        $conn = getDBConnection();

        $application_id = isset($options['application_id']) ? intval($options['application_id']) : null;
        $module = isset($options['module']) ? $options['module'] : null;
        $old_values = isset($options['old_values']) ? json_encode($options['old_values']) : null;
        $new_values = isset($options['new_values']) ? json_encode($options['new_values']) : null;
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null;

        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, application_id, action, module, description, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisssssss",
            $user_id, $application_id, $action, $module, $description,
            $old_values, $new_values, $ip, $user_agent
        );
        $stmt->execute();
        $stmt->close();
        $conn->close();

        return true;
    } catch (Exception $e) {
        error_log("Activity logging error: " . $e->getMessage());
        return false;
    }
}

function getActivityLogs($user_id = null, $role = 'staff', $limit = null) {
    try {
        $conn = getDBConnection();

        // Admin sees all logs, users see only their own
        if ($role === 'admin' && $user_id === null) {
            $sql = "SELECT l.*, u.full_name AS user_name FROM activity_logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC";
            if ($limit) {
                $sql .= " LIMIT ?";
            }
            $stmt = $conn->prepare($sql);
            if ($limit) {
                $stmt->bind_param("i", $limit);
            }
        } else {
            $sql = "SELECT l.*, u.full_name AS user_name FROM activity_logs l LEFT JOIN users u ON l.user_id = u.id WHERE l.user_id = ? ORDER BY l.created_at DESC";
            if ($limit) {
                $sql .= " LIMIT ?";
            }
            $stmt = $conn->prepare($sql);
            if ($limit) {
                $stmt->bind_param("ii", $user_id, $limit);
            } else {
                $stmt->bind_param("i", $user_id);
            }
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }

        $stmt->close();
        $conn->close();

        return $logs;
    } catch (Exception $e) {
        error_log("Get activity logs error: " . $e->getMessage());
        return [];
    }
}

// System Settings Functions
function getSetting($key, $default = null) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $value = $row['setting_value'];
            $stmt->close();
            $conn->close();
            return $value;
        }

        $stmt->close();
        $conn->close();
        return $default;
    } catch (Exception $e) {
        error_log("Get setting error: " . $e->getMessage());
        return $default;
    }
}

function setSetting($key, $value) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("sss", $key, $value, $value);
        $result = $stmt->execute();
        $stmt->close();
        $conn->close();
        return $result;
    } catch (Exception $e) {
        error_log("Set setting error: " . $e->getMessage());
        return false;
    }
}

// Profile Image Upload Functions
function uploadProfileImage($file, $user_id) {
    // Create uploads directory if it doesn't exist
    $upload_dir = __DIR__ . '/uploads/profiles/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Validate file
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 2 * 1024 * 1024; // 2MB

    // Check file size
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File size must be less than 2MB'];
    }

    // Check file type by MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP allowed'];
    }

    // Check extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'Invalid file extension'];
    }

    // Generate unique filename
    $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => 'uploads/profiles/' . $filename];
    }

    return ['success' => false, 'message' => 'Failed to upload file'];
}

function deleteProfileImage($filepath) {
    if ($filepath && file_exists(__DIR__ . '/' . $filepath)) {
        return unlink(__DIR__ . '/' . $filepath);
    }
    return false;
}

// Logo Upload Function
function uploadLogoImage($file) {
    $upload_dir = __DIR__ . '/uploads/logos/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 2 * 1024 * 1024; // 2MB

    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File size must be less than 2MB'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP allowed'];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'Invalid file extension'];
    }

    $filename = 'login_logo_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => 'uploads/logos/' . $filename];
    }

    return ['success' => false, 'message' => 'Failed to upload file'];
}

// Document Upload Functions
function uploadDocument($file, $application_id, $user_id) {
    $upload_dir = __DIR__ . '/uploads/documents/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $allowed_types = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp'
    ];
    $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 15 * 1024 * 1024; // 15MB

    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File size must be less than 15MB'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG, GIF, WEBP'];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'Invalid file extension'];
    }

    $filename = 'doc_' . $application_id . '_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $filepath = $upload_dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return [
            'success' => true,
            'filename' => 'uploads/documents/' . $filename,
            'original_name' => $file['name'],
            'mime_type' => $mime_type,
            'file_size_kb' => intval(ceil($file['size'] / 1024))
        ];
    }

    return ['success' => false, 'message' => 'Failed to upload file'];
}

function deleteDocumentFile($filepath) {
    if ($filepath && file_exists(__DIR__ . '/' . $filepath)) {
        return unlink(__DIR__ . '/' . $filepath);
    }
    return false;
}

function getProfileImage($user_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT avatar_url FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $profile_image = $row['avatar_url'];
            $stmt->close();
            $conn->close();
            return $profile_image;
        }

        $stmt->close();
        $conn->close();
        return null;
    } catch (Exception $e) {
        error_log("Get profile image error: " . $e->getMessage());
        return null;
    }
}

// User Theme Preferences Functions
function getUserTheme($user_id) {
    $defaults = [
        'theme_primary' => '#e8262c',
        'theme_secondary' => '#023f57',
        'theme_accent' => '#023f57',
        'theme_mode' => 'light'
    ];

    try {
        $conn = getDBConnection();

        // First check if theme columns exist
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_primary'");
        if (!$check || $check->num_rows == 0) {
            $conn->close();
            return $defaults;
        }

        $stmt = $conn->prepare("SELECT theme_primary, theme_secondary, theme_accent, theme_mode FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            $conn->close();
            return [
                'theme_primary' => $row['theme_primary'] ?? $defaults['theme_primary'],
                'theme_secondary' => $row['theme_secondary'] ?? $defaults['theme_secondary'],
                'theme_accent' => $row['theme_accent'] ?? $defaults['theme_accent'],
                'theme_mode' => $row['theme_mode'] ?? $defaults['theme_mode']
            ];
        }

        $stmt->close();
        $conn->close();
        return $defaults;
    } catch (Exception $e) {
        error_log("Get user theme error: " . $e->getMessage());
        return $defaults;
    }
}

function setUserTheme($user_id, $theme_primary, $theme_secondary, $theme_accent, $theme_mode) {
    try {
        $conn = getDBConnection();

        // Check if theme columns exist
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_primary'");
        if (!$check || $check->num_rows == 0) {
            $conn->close();
            return false; // Columns don't exist, need to run setup.php first
        }

        $stmt = $conn->prepare("UPDATE users SET theme_primary = ?, theme_secondary = ?, theme_accent = ?, theme_mode = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $theme_primary, $theme_secondary, $theme_accent, $theme_mode, $user_id);
        $result = $stmt->execute();
        $stmt->close();
        $conn->close();
        return $result;
    } catch (Exception $e) {
        error_log("Set user theme error: " . $e->getMessage());
        return false;
    }
}

// Brand accent color: a single admin-set value (not a per-user preference) used for
// --navy-accent everywhere, so an org-wide brand color can't be diluted by personal themes.
function getBrandAccentColor() {
    $accent = getSetting('brand_accent_color', '#023f57');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $accent)) {
        $accent = '#023f57';
    }
    return $accent;
}

// For pages without a logged-in user context (login, careers, apply) that still want the brand accent
function generateBrandAccentCSS() {
    return "<style id='brand-accent-css'>:root { --navy-accent: " . getBrandAccentColor() . "; }</style>\n";
}

// Generate CSS variables for user theme
function generateUserThemeCSS($user_id) {
    $theme = getUserTheme($user_id);

    // Calculate hover color (slightly lighter/darker)
    $primary = $theme['theme_primary'];

    $css = "<style id='user-theme-css'>\n";
    $css .= ":root {\n";
    $css .= "    --navy-primary: {$theme['theme_primary']};\n";
    $css .= "    --navy-light: {$theme['theme_secondary']};\n";
    $css .= "    --navy-accent: " . getBrandAccentColor() . ";\n";
    $css .= "    --navy-dark: {$theme['theme_primary']};\n";
    $css .= "    --navy-hover: {$theme['theme_secondary']};\n";
    $css .= "}\n";
    $css .= "</style>\n";

    return $css;
}
?>
