<?php
// admin/includes/auth_admin.php - Admin Authentication Check (ADMINS ONLY)
// Include this file at the top of every admin page (except login.php)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if admin is logged in with ADMIN-SPECIFIC session variable
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php?error=not_logged_in");
    exit();
}

// Verify admin still exists and has 'admin' role
require_once __DIR__ . '/../../includes/db_connect.php';

$admin_id = $_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? AND role = 'admin'");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    // Admin role revoked or user deleted
    unset($_SESSION['admin_logged_in']);
    unset($_SESSION['admin_id']);
    unset($_SESSION['admin_name']);
    unset($_SESSION['admin_email']);
    header("Location: login.php?error=account_deleted");
    exit();
}
$stmt->close();

// Optional: Check session timeout (2 hours)
if (isset($_SESSION['admin_last_activity'])) {
    $session_lifetime = 7200; // 2 hours in seconds
    
    if ((time() - $_SESSION['admin_last_activity']) > $session_lifetime) {
        // Session expired
        unset($_SESSION['admin_logged_in']);
        unset($_SESSION['admin_id']);
        unset($_SESSION['admin_name']);
        unset($_SESSION['admin_email']);
        unset($_SESSION['admin_last_activity']);
        unset($_SESSION['admin_created']);
        header("Location: login.php?error=session_expired");
        exit();
    }
}

// Update last activity timestamp
$_SESSION['admin_last_activity'] = time();

// Optional: Regenerate session ID periodically for security
if (!isset($_SESSION['admin_created'])) {
    $_SESSION['admin_created'] = time();
} else if ((time() - $_SESSION['admin_created']) > 1800) {
    // Regenerate session ID every 30 minutes
    session_regenerate_id(true);
    $_SESSION['admin_created'] = time();
}
?>