<?php
/**
 * admin/ajax/get_stats.php
 * Real-time dashboard statistics endpoint
 * Returns JSON data for dynamic dashboard updates
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
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Database connection
require_once __DIR__ . '/../../includes/db_connect.php';

// Set JSON header
header('Content-Type: application/json');

try {
    // Get current date info
    $current_month = date('Y-m');
    $today = date('Y-m-d');
    
    // Initialize response array
    $stats = [];
    
    // Total Users (active users only)
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='user'");
    $stats['total_users'] = (int)$result->fetch_assoc()['count'];
    
    // Total Expenses
    $result = $conn->query("SELECT COUNT(*) as count FROM expenses");
    $stats['total_expenses'] = (int)$result->fetch_assoc()['count'];
    
    // Total Categories
    $result = $conn->query("SELECT COUNT(*) as count FROM categories");
    $stats['total_categories'] = (int)$result->fetch_assoc()['count'];
    
    // Total Amount Spent (all time)
    $result = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses");
    $stats['total_spent'] = (float)$result->fetch_assoc()['total'];
    
    // Monthly Expenses Count
    $result = $conn->query("SELECT COUNT(*) as count FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = '$current_month'");
    $stats['month_expenses'] = (int)$result->fetch_assoc()['count'];
    
    // Monthly Spending Amount
    $result = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = '$current_month'");
    $stats['month_spent'] = (float)$result->fetch_assoc()['total'];
    
    // Today's Statistics
    $result = $conn->query("SELECT COUNT(*) as count FROM expenses WHERE DATE(expense_date) = '$today'");
    $stats['today_expenses'] = (int)$result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='user' AND DATE(created_at) = '$today'");
    $stats['new_users_today'] = (int)$result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE DATE(expense_date) = '$today'");
    $stats['today_spent'] = (float)$result->fetch_assoc()['total'];
    
    // Previous Month Comparison
    $prev_month = date('Y-m', strtotime('-1 month'));
    $result = $conn->query("SELECT COUNT(*) as count FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = '$prev_month'");
    $prev_month_expenses = (int)$result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = '$prev_month'");
    $prev_month_spent = (float)$result->fetch_assoc()['total'];
    
    // Calculate growth percentages
    $stats['expense_growth'] = $prev_month_expenses > 0 
        ? (($stats['month_expenses'] - $prev_month_expenses) / $prev_month_expenses) * 100 
        : 0;
        
    $stats['spending_growth'] = $prev_month_spent > 0 
        ? (($stats['month_spent'] - $prev_month_spent) / $prev_month_spent) * 100 
        : 0;
    
    // Average per user
    $stats['avg_per_user'] = $stats['total_users'] > 0 
        ? $stats['total_spent'] / $stats['total_users'] 
        : 0;
    
    // Linked Accounts Statistics
    try {
        $result = $conn->query("SELECT COUNT(*) as count FROM linked_accounts");
        $stats['total_linked_accounts'] = $result ? (int)$result->fetch_assoc()['count'] : 0;
        
        // Check if last_sync column exists
        $check_column = $conn->query("SHOW COLUMNS FROM linked_accounts LIKE 'last_sync'");
        if ($check_column && $check_column->num_rows > 0) {
            $result = $conn->query("SELECT COUNT(*) as count FROM linked_accounts WHERE DATE(last_sync) = '$today'");
            $stats['synced_today'] = $result ? (int)$result->fetch_assoc()['count'] : 0;
        } else {
            $stats['synced_today'] = 0;
        }
    } catch (Exception $e) {
        $stats['total_linked_accounts'] = 0;
        $stats['synced_today'] = 0;
    }
    
    // Recent Activity (last hour)
    $one_hour_ago = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $result = $conn->query("SELECT COUNT(*) as count FROM expenses WHERE created_at >= '$one_hour_ago'");
    $stats['recent_expenses'] = (int)$result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='user' AND created_at >= '$one_hour_ago'");
    $stats['recent_users'] = (int)$result->fetch_assoc()['count'];
    
    // Top Category Today
    $result = $conn->query("
        SELECT c.name, COUNT(e.id) as count, COALESCE(SUM(e.amount), 0) as total
        FROM categories c
        LEFT JOIN expenses e ON c.id = e.category_id AND DATE(e.expense_date) = '$today'
        GROUP BY c.id
        HAVING count > 0
        ORDER BY total DESC
        LIMIT 1
    ");
    
    if ($result && $result->num_rows > 0) {
        $top_cat = $result->fetch_assoc();
        $stats['top_category_today'] = [
            'name' => $top_cat['name'],
            'count' => (int)$top_cat['count'],
            'amount' => (float)$top_cat['total']
        ];
    } else {
        $stats['top_category_today'] = null;
    }
    
    // System Health Indicators
    $stats['system_health'] = [
        'database_connected' => true,
        'timestamp' => time(),
        'datetime' => date('Y-m-d H:i:s')
    ];
    
    // Success response
    echo json_encode([
        'success' => true,
        'data' => $stats,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch statistics',
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>