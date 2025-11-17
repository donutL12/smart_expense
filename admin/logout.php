<?php
// admin/logout.php - Admin Logout (ADMINS ONLY)
session_start();

require_once '../includes/db_connect.php';

// Log admin logout
if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_logged_in'])) {
    $admin_id = $_SESSION['admin_id'];
    
    try {
        $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action) VALUES (?, 'Admin logout')");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Admin logout logging failed: " . $e->getMessage());
    }
}

// Destroy ONLY admin session variables (not user)
unset($_SESSION['admin_logged_in']);
unset($_SESSION['admin_id']);
unset($_SESSION['admin_name']);
unset($_SESSION['admin_email']);
unset($_SESSION['admin_last_activity']);
unset($_SESSION['admin_created']);

// Note: We don't destroy the entire session in case user is also logged in
// Only clear session cookie if no other session data exists
if (empty($_SESSION)) {
    session_destroy();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
}

// Redirect to admin login
header("Location: login.php?logout=success");
exit();
?>