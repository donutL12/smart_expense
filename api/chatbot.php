<?php
/**
 * Chatbot API Endpoint (MySQLi Version)
 * File: api/chatbot.php
 */

require_once '../includes/db_connect.php';
require_once '../includes/chatbot_engine.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$message = isset($input['message']) ? trim($input['message']) : '';

if (empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Message is required']);
    exit;
}

// Validate message length
if (strlen($message) > 500) {
    echo json_encode(['success' => false, 'message' => 'Message too long (max 500 characters)']);
    exit;
}

try {
    $chatbot = new FinsightChatbot($conn, $_SESSION['user_id']);
    $response = $chatbot->getResponse($message);
    echo json_encode($response);
} catch (Exception $e) {
    error_log("Chatbot error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}