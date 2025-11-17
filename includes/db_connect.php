<?php
// includes/db_connect.php - Database Connection

require_once __DIR__ . '/language.php';

// Prevent direct access
if (!defined('SPENDLENS_APP')) {
    define('SPENDLENS_APP', true);
}

require_once __DIR__ . '/../config/config.php';

// Database connection is already created in config.php as $conn
// Just verify it exists
if (!isset($conn)) {
    die("Database connection not established");
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to execute prepared statements
function execute_query($query, $params = [], $types = '') {
    global $conn;
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return false;
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $result = $stmt->execute();
    
    if (strpos($query, 'SELECT') === 0) {
        return $stmt->get_result();
    }
    
    return $result;
}
?>