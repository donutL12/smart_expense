<?php
/**
 * admin/ajax/user_actions.php
 * Quick user management actions (block/unblock, delete, reset password, etc.)
 * Handles AJAX requests for user-related operations
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

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Get action and user_id
$action = $_POST['action'] ?? '';
$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

// Validate input
if (empty($action) || $user_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit();
}

try {
    // Start transaction for data consistency
    $conn->begin_transaction();
    
    // Verify user exists and is not an admin
    $check_stmt = $conn->prepare("SELECT id, name, email, role, status FROM users WHERE id = ?");
    $check_stmt->bind_param("i", $user_id);
    $check_stmt->execute();
    $user_result = $check_stmt->get_result();
    
    if ($user_result->num_rows === 0) {
        throw new Exception('User not found');
    }
    
    $user = $user_result->fetch_assoc();
    $check_stmt->close();
    
    // Prevent actions on admin accounts
    if ($user['role'] === 'admin') {
        throw new Exception('Cannot perform actions on admin accounts');
    }
    
    $response = ['success' => true];
    
    switch ($action) {
        case 'block':
            // Block user account
            $stmt = $conn->prepare("UPDATE users SET status = 'blocked' WHERE id = ? AND role != 'admin'");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $response['message'] = 'User blocked successfully';
                $response['new_status'] = 'blocked';
                
                // Log admin action
                logAdminAction($conn, $_SESSION['admin_id'], 'block_user', "Blocked user: {$user['name']} (ID: {$user_id})");
            } else {
                throw new Exception('Failed to block user');
            }
            $stmt->close();
            break;
            
        case 'unblock':
            // Unblock user account
            $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role != 'admin'");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $response['message'] = 'User unblocked successfully';
                $response['new_status'] = 'active';
                
                // Log admin action
                logAdminAction($conn, $_SESSION['admin_id'], 'unblock_user', "Unblocked user: {$user['name']} (ID: {$user_id})");
            } else {
                throw new Exception('Failed to unblock user');
            }
            $stmt->close();
            break;
            
        case 'delete':
            // Delete user and all associated data
            // Delete expenses first (foreign key constraint)
            $stmt = $conn->prepare("DELETE FROM expenses WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $deleted_expenses = $stmt->affected_rows;
            $stmt->close();
            
            // Delete linked accounts
            try {
                $stmt = $conn->prepare("DELETE FROM linked_accounts WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            } catch (Exception $e) {
                // Table might not exist
            }
            
            // Delete notifications
            try {
                $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
            } catch (Exception $e) {
                // Table might not exist
            }
            
            // Finally delete user
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $response['message'] = "User deleted successfully (including {$deleted_expenses} expenses)";
                
                // Log admin action
                logAdminAction($conn, $_SESSION['admin_id'], 'delete_user', "Deleted user: {$user['name']} (ID: {$user_id}) and {$deleted_expenses} expenses");
            } else {
                throw new Exception('Failed to delete user');
            }
            $stmt->close();
            break;
            
        case 'reset_password':
            // Generate temporary password
            $temp_password = bin2hex(random_bytes(8)); // 16 character random password
            $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("UPDATE users SET password = ?, password_reset_required = 1 WHERE id = ? AND role != 'admin'");
            $stmt->bind_param("si", $hashed_password, $user_id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $response['message'] = 'Password reset successfully';
                $response['temp_password'] = $temp_password;
                $response['note'] = 'User will be required to change password on next login';
                
                // Log admin action
                logAdminAction($conn, $_SESSION['admin_id'], 'reset_password', "Reset password for user: {$user['name']} (ID: {$user_id})");
                
                // TODO: Send email notification to user
            } else {
                throw new Exception('Failed to reset password');
            }
            $stmt->close();
            break;
            
        case 'get_stats':
            // Get user statistics
            $stats = [];
            
            // Total expenses
            $stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM expenses WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $expense_data = $result->fetch_assoc();
            $stats['total_expenses'] = (int)$expense_data['count'];
            $stats['total_spent'] = (float)$expense_data['total'];
            $stmt->close();
            
            // Current month expenses
            $current_month = date('Y-m');
            $stmt = $conn->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM expenses WHERE user_id = ? AND DATE_FORMAT(expense_date, '%Y-%m') = ?");
            $stmt->bind_param("is", $user_id, $current_month);
            $stmt->execute();
            $result = $stmt->get_result();
            $month_data = $result->fetch_assoc();
            $stats['month_expenses'] = (int)$month_data['count'];
            $stats['month_spent'] = (float)$month_data['total'];
            $stmt->close();
            
            // Linked accounts
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM linked_accounts WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $stats['linked_accounts'] = (int)$result->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {
                $stats['linked_accounts'] = 0;
            }
            
            // Last activity
            $stmt = $conn->prepare("SELECT MAX(created_at) as last_activity FROM expenses WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $last_activity = $result->fetch_assoc()['last_activity'];
            $stats['last_activity'] = $last_activity ? date('M d, Y h:i A', strtotime($last_activity)) : 'Never';
            $stmt->close();
            
            $response['stats'] = $stats;
            $response['user'] = [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'status' => $user['status']
            ];
            break;
            
        case 'send_notification':
            // Send custom notification to user
            $message = $_POST['message'] ?? '';
            $title = $_POST['title'] ?? 'Admin Notification';
            
            if (empty($message)) {
                throw new Exception('Notification message is required');
            }
            
            try {
                $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'admin', NOW())");
                $stmt->bind_param("iss", $user_id, $title, $message);
                $stmt->execute();
                $stmt->close();
                
                $response['message'] = 'Notification sent successfully';
                
                // Log admin action
                logAdminAction($conn, $_SESSION['admin_id'], 'send_notification', "Sent notification to user: {$user['name']} (ID: {$user_id})");
            } catch (Exception $e) {
                throw new Exception('Failed to send notification. Notifications table may not exist.');
            }
            break;
            
        case 'export_data':
            // Export user data (expenses only, returns file path)
            $stmt = $conn->prepare("SELECT * FROM expenses WHERE user_id = ? ORDER BY expense_date DESC");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $expenses = [];
            while ($row = $result->fetch_assoc()) {
                $expenses[] = $row;
            }
            $stmt->close();
            
            // Generate filename
            $filename = "user_{$user_id}_expenses_" . date('Y-m-d_His') . ".json";
            $filepath = __DIR__ . "/../../uploads/exports/" . $filename;
            
            // Save to file
            if (!is_dir(__DIR__ . "/../../uploads/exports/")) {
                mkdir(__DIR__ . "/../../uploads/exports/", 0755, true);
            }
            
            file_put_contents($filepath, json_encode($expenses, JSON_PRETTY_PRINT));
            
            $response['message'] = 'User data exported successfully';
            $response['file'] = $filename;
            $response['count'] = count($expenses);
            
            // Log admin action
            logAdminAction($conn, $_SESSION['admin_id'], 'export_user_data', "Exported data for user: {$user['name']} (ID: {$user_id})");
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode($response);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();

/**
 * Log admin actions for audit trail
 */
function logAdminAction($conn, $admin_id, $action, $description) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("issss", $admin_id, $action, $description, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Silently fail if logging table doesn't exist
        // In production, you might want to log this to a file instead
    }
}
?>