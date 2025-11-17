<?php
/**
 * admin/ajax/get_chart_data.php
 * Dynamic chart data endpoint for admin dashboard
 * Supports multiple chart types and time ranges
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

// Get request parameters
$chart_type = $_GET['type'] ?? 'spending_trend';
$period = $_GET['period'] ?? '12'; // months
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

try {
    $response = ['success' => true, 'data' => []];
    
    switch ($chart_type) {
        case 'spending_trend':
            // Spending trend over time (monthly)
            $months = (int)$period;
            $query = "
                SELECT 
                    DATE_FORMAT(expense_date, '%Y-%m') as month,
                    COUNT(*) as expense_count,
                    COALESCE(SUM(amount), 0) as total_amount
                FROM expenses
                WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL $months MONTH)
                GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
                ORDER BY month ASC
            ";
            
            $result = $conn->query($query);
            $labels = [];
            $amounts = [];
            $counts = [];
            
            while ($row = $result->fetch_assoc()) {
                $labels[] = date('M Y', strtotime($row['month'] . '-01'));
                $amounts[] = (float)$row['total_amount'];
                $counts[] = (int)$row['expense_count'];
            }
            
            $response['data'] = [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Amount Spent (₱)',
                        'data' => $amounts,
                        'type' => 'amount'
                    ],
                    [
                        'label' => 'Expense Count',
                        'data' => $counts,
                        'type' => 'count'
                    ]
                ]
            ];
            break;
            
        case 'category_distribution':
            // Category spending distribution
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 8;
            $time_filter = '';
            
            if (isset($_GET['time_range'])) {
                switch ($_GET['time_range']) {
                    case 'month':
                        $time_filter = "AND DATE_FORMAT(e.expense_date, '%Y-%m') = '" . date('Y-m') . "'";
                        break;
                    case 'year':
                        $time_filter = "AND YEAR(e.expense_date) = YEAR(CURDATE())";
                        break;
                    case 'quarter':
                        $time_filter = "AND QUARTER(e.expense_date) = QUARTER(CURDATE()) AND YEAR(e.expense_date) = YEAR(CURDATE())";
                        break;
                }
            }
            
            $query = "
                SELECT 
                    c.name,
                    c.id,
                    COUNT(e.id) as expense_count,
                    COALESCE(SUM(e.amount), 0) as total_amount
                FROM categories c
                LEFT JOIN expenses e ON c.id = e.category_id $time_filter
                GROUP BY c.id
                HAVING total_amount > 0
                ORDER BY total_amount DESC
                LIMIT $limit
            ";
            
            $result = $conn->query($query);
            $labels = [];
            $amounts = [];
            $counts = [];
            
            while ($row = $result->fetch_assoc()) {
                $labels[] = $row['name'];
                $amounts[] = (float)$row['total_amount'];
                $counts[] = (int)$row['expense_count'];
            }
            
            $response['data'] = [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Amount Spent',
                        'data' => $amounts
                    ]
                ],
                'expense_counts' => $counts
            ];
            break;
            
        case 'user_growth':
            // User registration growth
            $months = (int)$period;
            $query = "
                SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as user_count
                FROM users
                WHERE role='user' AND created_at >= DATE_SUB(CURDATE(), INTERVAL $months MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month ASC
            ";
            
            $result = $conn->query($query);
            $labels = [];
            $counts = [];
            $cumulative = 0;
            
            // Get starting count
            $start_query = "SELECT COUNT(*) as count FROM users WHERE role='user' AND created_at < DATE_SUB(CURDATE(), INTERVAL $months MONTH)";
            $start_result = $conn->query($start_query);
            $cumulative = (int)$start_result->fetch_assoc()['count'];
            
            while ($row = $result->fetch_assoc()) {
                $labels[] = date('M Y', strtotime($row['month'] . '-01'));
                $cumulative += (int)$row['user_count'];
                $counts[] = $cumulative;
            }
            
            $response['data'] = [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Total Users',
                        'data' => $counts
                    ]
                ]
            ];
            break;
            
        case 'daily_activity':
            // Daily expense activity for last 30 days
            $query = "
                SELECT 
                    DATE(expense_date) as date,
                    COUNT(*) as expense_count,
                    COALESCE(SUM(amount), 0) as total_amount
                FROM expenses
                WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(expense_date)
                ORDER BY date ASC
            ";
            
            $result = $conn->query($query);
            $labels = [];
            $amounts = [];
            $counts = [];
            
            while ($row = $result->fetch_assoc()) {
                $labels[] = date('M d', strtotime($row['date']));
                $amounts[] = (float)$row['total_amount'];
                $counts[] = (int)$row['expense_count'];
            }
            
            $response['data'] = [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Daily Spending',
                        'data' => $amounts
                    ],
                    [
                        'label' => 'Daily Expenses',
                        'data' => $counts
                    ]
                ]
            ];
            break;
            
        case 'top_spenders':
            // Top spending users
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $time_range = $_GET['time_range'] ?? 'month';
            
            $time_filter = match($time_range) {
                'week' => "AND e.expense_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
                'month' => "AND DATE_FORMAT(e.expense_date, '%Y-%m') = '" . date('Y-m') . "'",
                'year' => "AND YEAR(e.expense_date) = YEAR(CURDATE())",
                default => ""
            };
            
            $query = "
                SELECT 
                    u.id,
                    u.name,
                    u.email,
                    COUNT(e.id) as expense_count,
                    COALESCE(SUM(e.amount), 0) as total_spent
                FROM users u
                LEFT JOIN expenses e ON u.id = e.user_id $time_filter
                WHERE u.role = 'user'
                GROUP BY u.id
                HAVING total_spent > 0
                ORDER BY total_spent DESC
                LIMIT $limit
            ";
            
            $result = $conn->query($query);
            $data = [];
            
            while ($row = $result->fetch_assoc()) {
                $data[] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'expense_count' => (int)$row['expense_count'],
                    'total_spent' => (float)$row['total_spent']
                ];
            }
            
            $response['data'] = $data;
            break;
            
        case 'hourly_activity':
            // Expense activity by hour of day
            $query = "
                SELECT 
                    HOUR(created_at) as hour,
                    COUNT(*) as expense_count,
                    COALESCE(SUM(amount), 0) as total_amount
                FROM expenses
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY HOUR(created_at)
                ORDER BY hour ASC
            ";
            
            $result = $conn->query($query);
            $hours = array_fill(0, 24, 0);
            $amounts = array_fill(0, 24, 0);
            
            while ($row = $result->fetch_assoc()) {
                $hour = (int)$row['hour'];
                $hours[$hour] = (int)$row['expense_count'];
                $amounts[$hour] = (float)$row['total_amount'];
            }
            
            $labels = array_map(function($h) { return sprintf('%02d:00', $h); }, range(0, 23));
            
            $response['data'] = [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Expense Count',
                        'data' => array_values($hours)
                    ],
                    [
                        'label' => 'Amount',
                        'data' => array_values($amounts)
                    ]
                ]
            ];
            break;
            
        default:
            throw new Exception('Invalid chart type');
    }
    
    $response['timestamp'] = time();
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch chart data',
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>