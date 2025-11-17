<?php
/**
 * Change Language Handler
 * Handles language switching for the entire application
 * MySQLi Version - FIXED
 */

session_start();
define('SPENDLENS_APP', true);
require_once 'includes/db_connect.php';

// Get the requested language
$lang = isset($_GET['lang']) ? $_GET['lang'] : (isset($_POST['lang']) ? $_POST['lang'] : null);

// Get the redirect URL (where to go after changing language)
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : (isset($_POST['redirect']) ? $_POST['redirect'] : $_SERVER['HTTP_REFERER'] ?? 'dashboard.php');

// Validate language
$supported_languages = ['en', 'tl', 'es', 'zh', 'ja'];

if ($lang && in_array($lang, $supported_languages)) {
    // Set session language
    $_SESSION['language'] = $lang;
    
    // If user is logged in, update their language preference in database
    if (isset($_SESSION['user_id']) && isset($conn)) {
        try {
            $user_id = $_SESSION['user_id'];
            $stmt = $conn->prepare("UPDATE users SET language_preference = ? WHERE id = ?");
            
            if ($stmt) {
                $stmt->bind_param("si", $lang, $user_id);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            // Silent fail - language is still set in session
            error_log("Failed to update user language preference: " . $e->getMessage());
        }
    }
    
    // Set cookie for non-logged-in users (expires in 1 year)
    setcookie('language', $lang, time() + (365 * 24 * 60 * 60), '/');
    
    // Clear translation cache for this user
    if (function_exists('clear_user_translation_cache')) {
        clear_user_translation_cache();
    }
    
    // Add success message
    $_SESSION['success_message'] = "Language changed successfully";
}

// Redirect back to the previous page or dashboard
header("Location: " . $redirect);
exit();
?>