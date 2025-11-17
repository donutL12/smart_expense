<?php
// logout.php - User Logout (USERS ONLY)
session_start();

// Log the logout action (optional)
if (isset($_SESSION['user_id']) && isset($_SESSION['user_logged_in'])) {
    require_once 'includes/db_connect.php';
    
    $user_id = $_SESSION['user_id'];
    $action = "User logged out";
    
    // Verify user exists before logging
    $check_user = "SELECT id FROM users WHERE id = ?";
    $stmt = $conn->prepare($check_user);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // User exists, safe to log
        try {
            $log_query = "INSERT INTO system_logs (user_id, action) VALUES (?, ?)";
            $stmt = $conn->prepare($log_query);
            $stmt->bind_param("is", $user_id, $action);
            $stmt->execute();
        } catch (Exception $e) {
            // Silently fail if logging doesn't work
            error_log("Logout logging failed: " . $e->getMessage());
        }
    }
}

// Destroy ONLY user session variables (not admin)
unset($_SESSION['user_logged_in']);
unset($_SESSION['user_id']);
unset($_SESSION['user_name']);
unset($_SESSION['user_email']);
unset($_SESSION['last_activity']);
unset($_SESSION['created']);
unset($_SESSION['redirect_url']);

// Note: We don't destroy the entire session in case admin is also logged in
// Only clear session cookie if no other session data exists
if (empty($_SESSION)) {
    session_destroy();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
}

// Redirect to login page
header("Location: index.php?logout=success");
exit();
?>