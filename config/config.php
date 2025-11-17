<?php
/**
 * SpendLens AI - Configuration File
 * Complete setup for AI-powered expense tracking
 */

// Prevent direct access
if (!defined('SPENDLENS_APP')) {
    define('SPENDLENS_APP', true);
}

// ==========================================
// ENVIRONMENT SETTINGS
// ==========================================
define('ENVIRONMENT', 'development'); // 'development' or 'production'
define('DEBUG_MODE', ENVIRONMENT === 'development');

// Error reporting
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// ==========================================
// PATH CONFIGURATION
// ==========================================
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
define('LOGS_PATH', ROOT_PATH . '/logs');

// URL Configuration
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL', $protocol . '://' . $host . '/spendlens');

// ==========================================
// DATABASE CONFIGURATION
// ==========================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'spendlens_ai');
define('DB_USER', 'root');
define('DB_PASS', ''); // Your MySQL password
define('DB_CHARSET', 'utf8mb4');

// ==========================================
// SESSION CONFIGURATION
// ==========================================
define('SESSION_NAME', 'SPENDLENS_SESSION');
define('SESSION_LIFETIME', 7200); // 2 hours in seconds
define('SESSION_COOKIE_LIFETIME', 0); // Browser session
define('SESSION_REMEMBER_LIFETIME', 2592000); // 30 days

// ==========================================
// AI ENGINE CONFIGURATION
// ==========================================

// OpenAI API Configuration
define('OPENAI_API_KEY', 'sk-your-api-key-here'); // Get from https://platform.openai.com
define('OPENAI_MODEL', 'gpt-4-turbo-preview');
define('OPENAI_MAX_TOKENS', 100);
define('OPENAI_TEMPERATURE', 0.3);

// Local Python AI API (if using local model)
define('LOCAL_AI_ENABLED', true);
define('LOCAL_AI_URL', 'http://localhost:5000');

// AI Settings
define('AI_CONFIDENCE_THRESHOLD', 0.70); // Minimum confidence to auto-categorize
define('AI_FALLBACK_CATEGORY', 'Miscellaneous');
define('AI_TIMEOUT', 10); // API timeout in seconds

// ==========================================
// SECURITY SETTINGS
// ==========================================
define('ENCRYPTION_KEY', 'your-secret-encryption-key-change-this'); // Change in production!
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_HASH_ALGO', PASSWORD_BCRYPT);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// CSRF Protection
define('CSRF_TOKEN_LENGTH', 32);
define('CSRF_TOKEN_EXPIRE', 3600); // 1 hour

// ==========================================
// FILE UPLOAD SETTINGS
// ==========================================
define('UPLOAD_MAX_SIZE', 5242880); // 5MB in bytes
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('PROFILE_PICTURE_PATH', UPLOADS_PATH . '/profiles');

// ==========================================
// APPLICATION SETTINGS
// ==========================================
define('APP_NAME', 'SpendLens AI');
define('APP_VERSION', '1.0.0');
define('APP_DESCRIPTION', 'AI-Powered Expense Tracking System');

// Site Settings (for email functions)
define('SITE_NAME', 'SpendLens AI');
define('SITE_URL', BASE_URL);

// Default Settings
define('DEFAULT_CURRENCY', 'PHP');
define('DEFAULT_THEME', 'light');
define('DEFAULT_LANGUAGE', 'en');
define('DEFAULT_TIMEZONE', 'Asia/Manila');

// Pagination
define('ITEMS_PER_PAGE', 20);

// ==========================================
// EMAIL CONFIGURATION (Optional)
// ==========================================
define('SMTP_ENABLED', false);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');
define('SMTP_FROM_EMAIL', 'noreply@spendlens.com');
define('SMTP_FROM_NAME', 'SpendLens AI');

// ==========================================
// LOGGING CONFIGURATION
// ==========================================
define('LOG_ENABLED', true);
define('LOG_LEVEL', DEBUG_MODE ? 'DEBUG' : 'ERROR'); // DEBUG, INFO, WARNING, ERROR
define('LOG_FILE', LOGS_PATH . '/app_' . date('Y-m-d') . '.log');
define('LOG_MAX_SIZE', 10485760); // 10MB

// ==========================================
// CACHE CONFIGURATION
// ==========================================
define('CACHE_ENABLED', !DEBUG_MODE);
define('CACHE_LIFETIME', 3600); // 1 hour

// ==========================================
// FEATURE FLAGS
// ==========================================
define('FEATURE_AI_CATEGORIZATION', true);
define('FEATURE_AI_INSIGHTS', true);
define('FEATURE_AI_CHAT', false); // Future feature
define('FEATURE_BUDGET_ALERTS', true);
define('FEATURE_EXPORT_DATA', true);
define('FEATURE_MULTI_CURRENCY', false); // Future feature

// ==========================================
// CURRENCY SYMBOLS
// ==========================================
$GLOBALS['currency_symbols'] = [
    'PHP' => '₱',
    'USD' => '$',
    'EUR' => '€',
    'GBP' => '£',
    'JPY' => '¥'
];

// ==========================================
// HELPER FUNCTIONS (Basic only, detailed ones in functions.php)
// ==========================================

/**
 * Get currency symbol
 */
function get_currency_symbol($currency = null) {
    $currency = $currency ?? DEFAULT_CURRENCY;
    return $GLOBALS['currency_symbols'][$currency] ?? $currency;
}

/**
 * Generate secure random token
 */
if (!function_exists('generate_token')) {
    function generate_token($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
}

/**
 * Sanitize input
 */
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Log message to file
 */
function log_message($message, $level = 'INFO') {
    if (!LOG_ENABLED) return;
    
    if (!file_exists(LOGS_PATH)) {
        mkdir(LOGS_PATH, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    file_put_contents(LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Redirect helper
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Check if request is POST
 */
function is_post() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Check if request is AJAX
 */
function is_ajax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * JSON response helper
 */
function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get current timestamp for database
 */
function db_timestamp() {
    return date('Y-m-d H:i:s');
}

/**
 * Generate CSRF token
 */
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token']) || 
        !isset($_SESSION['csrf_token_time']) || 
        time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_EXPIRE) {
        
        $_SESSION['csrf_token'] = generate_token(CSRF_TOKEN_LENGTH);
        $_SESSION['csrf_token_time'] = time();
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }
    
    if (time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_EXPIRE) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

// ==========================================
// DATABASE CONNECTION
// ==========================================

// Create database connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        if (DEBUG_MODE) {
            die("Database Connection Failed: " . $conn->connect_error);
        } else {
            die("Database connection error. Please contact support.");
        }
    }
    
    // Set charset
    $conn->set_charset(DB_CHARSET);
    
} catch (Exception $e) {
    if (DEBUG_MODE) {
        die("Database Error: " . $e->getMessage());
    } else {
        die("Database error. Please contact support.");
    }
}

// ==========================================
// INITIALIZATION
// ==========================================

// Set timezone
date_default_timezone_set(DEFAULT_TIMEZONE);

// Create required directories
$required_dirs = [UPLOADS_PATH, LOGS_PATH, PROFILE_PICTURE_PATH];
foreach ($required_dirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

// Log application start (only in debug mode)
if (DEBUG_MODE) {
    log_message('Application initialized', 'DEBUG');
}