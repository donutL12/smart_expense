<?php
/**
 * API Authentication Endpoint
 * Handles user authentication and token management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/db_connect.php';

function sendResponse($success, $message, $data = null, $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('c')
    ]);
    exit();
}

function generateApiToken() {
    return bin2hex(random_bytes(32));
}

function createApiToken($conn, $user_id, $expires_hours = 24) {
    $token = generateApiToken();
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_hours} hours"));
    
    $stmt = $conn->prepare(
        "INSERT INTO api_tokens (user_id, token, expires_at, status, created_at) 
         VALUES (?, ?, ?, 'active', NOW())"
    );
    $stmt->bind_param("iss", $user_id, $token, $expires_at);
    
    if ($stmt->execute()) {
        return [
            'token' => $token,
            'expires_at' => $expires_at,
            'token_id' => $conn->insert_id
        ];
    }
    
    return false;
}

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'login':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendResponse(false, 'Method not allowed', null, 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
            $password = $input['password'] ?? '';
            
            if (empty($email) || empty($password)) {
                sendResponse(false, 'Email and password required', null, 400);
            }
            
            // Verify user credentials
            $stmt = $conn->prepare(
                "SELECT id, name, email, password, status FROM users WHERE email = ?"
            );
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            
            if (!$user) {
                sendResponse(false, 'Invalid credentials', null, 401);
            }
            
            if ($user['status'] !== 'active') {
                sendResponse(false, 'Account is inactive', null, 403);
            }
            
            if (!password_verify($password, $user['password'])) {
                sendResponse(false, 'Invalid credentials', null, 401);
            }
            
            // Generate API token
            $token_data = createApiToken($conn, $user['id'], 24);
            
            if (!$token_data) {
                sendResponse(false, 'Failed to generate token', null, 500);
            }
            
            // Update last login
            $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update_stmt->bind_param("i", $user['id']);
            $update_stmt->execute();
            
            sendResponse(true, 'Login successful', [
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ],
                'token' => $token_data['token'],
                'expires_at' => $token_data['expires_at']
            ]);
            break;
            
        case 'register':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendResponse(false, 'Method not allowed', null, 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $name = filter_var($input['name'] ?? '', FILTER_SANITIZE_STRING);
            $email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
            $password = $input['password'] ?? '';
            $monthly_budget = floatval($input['monthly_budget'] ?? 0);
            
            if (empty($name) || empty($email) || empty($password)) {
                sendResponse(false, 'Name, email and password required', null, 400);
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendResponse(false, 'Invalid email format', null, 400);
            }
            
            if (strlen($password) < 6) {
                sendResponse(false, 'Password must be at least 6 characters', null, 400);
            }
            
            // Check if email exists
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                sendResponse(false, 'Email already registered', null, 409);
            }
            
            // Create user
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "INSERT INTO users (name, email, password, monthly_budget, status, created_at) 
                 VALUES (?, ?, ?, ?, 'active', NOW())"
            );
            $stmt->bind_param("sssd", $name, $email, $hashed_password, $monthly_budget);
            
            if (!$stmt->execute()) {
                sendResponse(false, 'Registration failed', null, 500);
            }
            
            $user_id = $conn->insert_id;
            
            // Generate API token
            $token_data = createApiToken($conn, $user_id, 24);
            
            sendResponse(true, 'Registration successful', [
                'user' => [
                    'id' => $user_id,
                    'name' => $name,
                    'email' => $email
                ],
                'token' => $token_data['token'],
                'expires_at' => $token_data['expires_at']
            ], 201);
            break;
            
        case 'refresh':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendResponse(false, 'Method not allowed', null, 405);
            }
            
            $headers = getallheaders();
            $token = null;
            
            if (isset($headers['Authorization'])) {
                if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
                    $token = $matches[1];
                }
            }
            
            if (!$token) {
                sendResponse(false, 'Token required', null, 401);
            }
            
            // Verify current token
            $stmt = $conn->prepare(
                "SELECT user_id FROM api_tokens WHERE token = ? AND status = 'active'"
            );
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            if (!$result) {
                sendResponse(false, 'Invalid token', null, 401);
            }
            
            // Invalidate old token
            $invalidate_stmt = $conn->prepare(
                "UPDATE api_tokens SET status = 'expired' WHERE token = ?"
            );
            $invalidate_stmt->bind_param("s", $token);
            $invalidate_stmt->execute();
            
            // Generate new token
            $token_data = createApiToken($conn, $result['user_id'], 24);
            
            sendResponse(true, 'Token refreshed', [
                'token' => $token_data['token'],
                'expires_at' => $token_data['expires_at']
            ]);
            break;
            
        case 'logout':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendResponse(false, 'Method not allowed', null, 405);
            }
            
            $headers = getallheaders();
            $token = null;
            
            if (isset($headers['Authorization'])) {
                if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
                    $token = $matches[1];
                }
            }
            
            if (!$token) {
                sendResponse(false, 'Token required', null, 401);
            }
            
            // Invalidate token
            $stmt = $conn->prepare(
                "UPDATE api_tokens SET status = 'revoked' WHERE token = ?"
            );
            $stmt->bind_param("s", $token);
            $stmt->execute();
            
            sendResponse(true, 'Logout successful');
            break;
            
        default:
            sendResponse(false, 'Invalid action', [
                'available_actions' => ['login', 'register', 'refresh', 'logout']
            ], 400);
    }
    
} catch (Exception $e) {
    error_log("API Auth Error: " . $e->getMessage());
    sendResponse(false, 'An error occurred', null, 500);
}