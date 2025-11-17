<?php
/**
 * admin/ajax/sync_status.php
 * Bank account synchronization status monitoring
 * Returns real-time sync status, progress, and error information
 */

// Prevent direct access
if (!defined('AJAX_REQUEST')) {
    define('AJAX_REQUEST', true);
}

// Start session and authenticate admin
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Database connection
require_once __DIR__ . '/../../includes/db_connect.php';

// Set JSON header
header('Content-Type: application/json');

// Get request parameters
$action = $_GET['action'] ?? 'get_status';
$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : null;
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

try {
    // Check if linked_accounts table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'linked_accounts'");
    if ($table_check->num_rows === 0) {
        throw new Exception('Linked accounts feature is not yet configured');
    }
    
    $response = ['success' => true, 'timestamp' => time()];
    
    switch ($action) {
        case 'get_status':
            // Get overall sync status
            $status_query = "
                SELECT 
                    COUNT(*) as total_accounts,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_accounts,
                    SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_accounts,
                    SUM(CASE WHEN status = 'syncing' THEN 1 ELSE 0 END) as syncing_accounts,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_accounts
                FROM linked_accounts
            ";
            
            $result = $conn->query($status_query);
            $status = $result->fetch_assoc();
            
            // Get sync activity today
            $today_query = "
                SELECT COUNT(*) as synced_today
                FROM linked_accounts
                WHERE DATE(last_sync) = CURDATE()
            ";
            
            $today_result = $conn->query($today_query);
            $status['synced_today'] = (int)$today_result->fetch_assoc()['synced_today'];
            
            // Get last sync time
            $last_sync_query = "SELECT MAX(last_sync) as last_sync FROM linked_accounts WHERE last_sync IS NOT NULL";
            $last_sync_result = $conn->query($last_sync_query);
            $last_sync = $last_sync_result->fetch_assoc()['last_sync'];
            
            $status['last_sync'] = $last_sync ? date('Y-m-d H:i:s', strtotime($last_sync)) : null;
            $status['last_sync_human'] = $last_sync ? timeAgo($last_sync) : 'Never';
            
            $response['data'] = [
                'total_accounts' => (int)$status['total_accounts'],
                'active_accounts' => (int)$status['active_accounts'],
                'error_accounts' => (int)$status['error_accounts'],
                'syncing_accounts' => (int)$status['syncing_accounts'],
                'pending_accounts' => (int)$status['pending_accounts'],
                'synced_today' => (int)$status['synced_today'],
                'last_sync' => $status['last_sync'],
                'last_sync_human' => $status['last_sync_human']
            ];
            break;
            
        case 'get_accounts':
            // Get detailed account information
            $query = "
                SELECT 
                    la.id,
                    la.user_id,
                    la.account_type,
                    la.account_name,
                    la.account_number,
                    la.bank_name,
                    la.status,
                    la.last_sync,
                    la.sync_frequency,
                    la.last_error,
                    la.created_at,
                    u.name as user_name,
                    u.email as user_email
                FROM linked_accounts la
                LEFT JOIN users u ON la.user_id = u.id
            ";
            
            // Add filters
            $conditions = [];
            $params = [];
            $types = "";
            
            if ($user_id !== null) {
                $conditions[] = "la.user_id = ?";
                $params[] = $user_id;
                $types .= "i";
            }
            
            if (isset($_GET['status'])) {
                $conditions[] = "la.status = ?";
                $params[] = $_GET['status'];
                $types .= "s";
            }
            
            if (!empty($conditions)) {
                $query .= " WHERE " . implode(" AND ", $conditions);
            }
            
            $query .= " ORDER BY la.last_sync DESC";
            
            if (!empty($params)) {
                $stmt = $conn->prepare($query);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = $conn->query($query);
            }
            
            $accounts = [];
            while ($row = $result->fetch_assoc()) {
                $accounts[] = [
                    'id' => (int)$row['id'],
                    'user_id' => (int)$row['user_id'],
                    'user_name' => $row['user_name'],
                    'user_email' => $row['user_email'],
                    'account_type' => $row['account_type'],
                    'account_name' => $row['account_name'],
                    'account_number' => maskAccountNumber($row['account_number']),
                    'bank_name' => $row['bank_name'],
                    'status' => $row['status'],
                    'last_sync' => $row['last_sync'],
                    'last_sync_human' => $row['last_sync'] ? timeAgo($row['last_sync']) : 'Never',
                    'sync_frequency' => $row['sync_frequency'],
                    'last_error' => $row['last_error'],
                    'created_at' => $row['created_at'],
                    'needs_sync' => needsSync($row['last_sync'], $row['sync_frequency'])
                ];
            }
            
            if (isset($stmt)) {
                $stmt->close();
            }
            
            $response['data'] = $accounts;
            $response['count'] = count($accounts);
            break;
            
        case 'get_sync_history':
            // Get sync history/logs
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            
            $query = "
                SELECT 
                    sl.id,
                    sl.account_id,
                    sl.sync_date,
                    sl.status,
                    sl.records_synced,
                    sl.error_message,
                    sl.duration_seconds,
                    la.account_name,
                    la.bank_name,
                    u.name as user_name
                FROM sync_logs sl
                LEFT JOIN linked_accounts la ON sl.account_id = la.id
                LEFT JOIN users u ON la.user_id = u.id
            ";
            
            if ($account_id !== null) {
                $query .= " WHERE sl.account_id = ?";
            }
            
            $query .= " ORDER BY sl.sync_date DESC LIMIT ?";
            
            $stmt = $conn->prepare($query);
            if ($account_id !== null) {
                $stmt->bind_param("ii", $account_id, $limit);
            } else {
                $stmt->bind_param("i", $limit);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $history = [];
            while ($row = $result->fetch_assoc()) {
                $history[] = [
                    'id' => (int)$row['id'],
                    'account_id' => (int)$row['account_id'],
                    'account_name' => $row['account_name'],
                    'bank_name' => $row['bank_name'],
                    'user_name' => $row['user_name'],
                    'sync_date' => $row['sync_date'],
                    'sync_date_human' => timeAgo($row['sync_date']),
                    'status' => $row['status'],
                    'records_synced' => (int)$row['records_synced'],
                    'error_message' => $row['error_message'],
                    'duration_seconds' => (float)$row['duration_seconds']
                ];
            }
            
            $stmt->close();
            
            $response['data'] = $history;
            $response['count'] = count($history);
            break;
            
        case 'get_errors':
            // Get accounts with sync errors
            $query = "
                SELECT 
                    la.id,
                    la.account_name,
                    la.bank_name,
                    la.status,
                    la.last_error,
                    la.last_sync,
                    u.name as user_name,
                    u.email as user_email
                FROM linked_accounts la
                LEFT JOIN users u ON la.user_id = u.id
                WHERE la.status = 'error' OR la.last_error IS NOT NULL
                ORDER BY la.last_sync DESC
            ";
            
            $result = $conn->query($query);
            
            $errors = [];
            while ($row = $result->fetch_assoc()) {
                $errors[] = [
                    'id' => (int)$row['id'],
                    'account_name' => $row['account_name'],
                    'bank_name' => $row['bank_name'],
                    'user_name' => $row['user_name'],
                    'user_email' => $row['user_email'],
                    'status' => $row['status'],
                    'last_error' => $row['last_error'],
                    'last_sync' => $row['last_sync'],
                    'last_sync_human' => $row['last_sync'] ? timeAgo($row['last_sync']) : 'Never'
                ];
            }
            
            $response['data'] = $errors;
            $response['count'] = count($errors);
            break;
            
        case 'get_statistics':
            // Get comprehensive sync statistics
            $stats = [];
            
            // Total syncs today
            $today_query = "SELECT COUNT(*) as count FROM sync_logs WHERE DATE(sync_date) = CURDATE()";
            $result = $conn->query($today_query);
            $stats['syncs_today'] = (int)$result->fetch_assoc()['count'];
            
            // Successful syncs today
            $success_query = "SELECT COUNT(*) as count FROM sync_logs WHERE DATE(sync_date) = CURDATE() AND status = 'success'";
            $result = $conn->query($success_query);
            $stats['successful_syncs_today'] = (int)$result->fetch_assoc()['count'];
            
            // Failed syncs today
            $failed_query = "SELECT COUNT(*) as count FROM sync_logs WHERE DATE(sync_date) = CURDATE() AND status = 'error'";
            $result = $conn->query($failed_query);
            $stats['failed_syncs_today'] = (int)$result->fetch_assoc()['count'];
            
            // Average sync duration
            $duration_query = "SELECT AVG(duration_seconds) as avg_duration FROM sync_logs WHERE sync_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            $result = $conn->query($duration_query);
            $stats['avg_sync_duration'] = round((float)$result->fetch_assoc()['avg_duration'], 2);
            
            // Total records synced today
            $records_query = "SELECT SUM(records_synced) as total FROM sync_logs WHERE DATE(sync_date) = CURDATE()";
            $result = $conn->query($records_query);
            $stats['records_synced_today'] = (int)$result->fetch_assoc()['total'];
            
            // Accounts needing sync
            $needs_sync_query = "
                SELECT COUNT(*) as count 
                FROM linked_accounts 
                WHERE status = 'active' 
                AND (
                    last_sync IS NULL 
                    OR last_sync < DATE_SUB(NOW(), INTERVAL sync_frequency HOUR)
                )
            ";
            $result = $conn->query($needs_sync_query);
            $stats['accounts_needing_sync'] = (int)$result->fetch_assoc()['count'];
            
            $response['data'] = $stats;
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();

/**
 * Helper Functions
 */

function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } elseif ($diff < 2592000) {
        return floor($diff / 86400) . ' days ago';
    } elseif ($diff < 31536000) {
        return floor($diff / 2592000) . ' months ago';
    } else {
        return floor($diff / 31536000) . ' years ago';
    }
}

function maskAccountNumber($account_number) {
    if (empty($account_number)) {
        return 'N/A';
    }
    $length = strlen($account_number);
    if ($length <= 4) {
        return $account_number;
    }
    return str_repeat('*', $length - 4) . substr($account_number, -4);
}

function needsSync($last_sync, $sync_frequency) {
    if (empty($last_sync)) {
        return true;
    }
    
    $frequency_hours = (int)$sync_frequency ?: 24; // Default to 24 hours
    $last_sync_timestamp = strtotime($last_sync);
    $next_sync_timestamp = $last_sync_timestamp + ($frequency_hours * 3600);
    
    return time() >= $next_sync_timestamp;
}
?>