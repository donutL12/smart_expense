<?php
// includes/auth_user.php - User Authentication Check (USERS ONLY)
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in with USER-SPECIFIC session variable
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    // Store the current page to redirect back after login
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    
    // Redirect to login page
    header("Location: index.php?error=not_logged_in");
    exit();
}

// Optional: Verify user still exists and has 'user' role
require_once __DIR__ . '/db_connect.php';

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? AND role = 'user'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    // User deleted or role changed to admin
    session_unset();
    session_destroy();
    header("Location: index.php?error=account_deleted");
    exit();
}
$stmt->close();

// Optional: Check session timeout (2 hours)
if (isset($_SESSION['last_activity'])) {
    $session_lifetime = 7200; // 2 hours in seconds
    
    if ((time() - $_SESSION['last_activity']) > $session_lifetime) {
        // Session expired
        session_unset();
        session_destroy();
        header("Location: index.php?error=session_expired");
        exit();
    }
}

// Update last activity timestamp
$_SESSION['last_activity'] = time();

// Optional: Regenerate session ID periodically for security
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} else if ((time() - $_SESSION['created']) > 1800) {
    // Regenerate session ID every 30 minutes
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}
?>