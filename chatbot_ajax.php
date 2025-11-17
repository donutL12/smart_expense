<?php
/**
 * Chatbot AJAX Handler (Separate File)
 * File: chatbot_ajax.php
 * This handles all chatbot requests separately
 */

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'Please log in to use the chatbot.'
    ]);
    exit;
}

// Check if it's a valid request
if (!isset($_POST['chatbot_message'])) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid request'
    ]);
    exit;
}

$message = trim($_POST['chatbot_message']);

if (empty($message) || strlen($message) > 500) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Please enter a valid message (max 500 characters)'
    ]);
    exit;
}

try {
    // Include database connection
    require_once 'includes/db_connect.php';
    
    // Load chatbot engine
    require_once 'includes/chatbot_engine.php';
    
    $chatbot = new FinsightChatbot($conn, $_SESSION['user_id']);
    $response = $chatbot->getResponse($message);
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Sorry, there was an error processing your request.',
        'debug' => $e->getMessage()
    ]);
    exit;
}