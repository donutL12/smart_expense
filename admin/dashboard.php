<?php
require_once 'includes/auth_admin.php';
require_once '../includes/db_connect.php';

// Get statistics
$total_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='user'")->fetch_assoc()['count'];
$total_expenses = $conn->query("SELECT COUNT(*) as count FROM expenses")->fetch_assoc()['count'];
$total_categories = $conn->query("SELECT COUNT(*) as count FROM categories")->fetch_assoc()['count'];
$total_spent = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses")->fetch_assoc()['total'];

// Get current month statistics
$current_month = date('Y-m');
$month_expenses = $conn->query("SELECT COUNT(*) as count FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = '$current_month'")->fetch_assoc()['count'];
$month_spent = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = '$current_month'")->fetch_assoc()['total'];

// Get previous month for comparison
$prev_month = date('Y-m', strtotime('-1 month'));
$prev_month_expenses = $conn->query("SELECT COUNT(*) as count FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = '$prev_month'")->fetch_assoc()['count'];
$prev_month_spent = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = '$prev_month'")->fetch_assoc()['total'];

// Calculate growth percentages
$expense_growth = $prev_month_expenses > 0 ? (($month_expenses - $prev_month_expenses) / $prev_month_expenses) * 100 : 0;
$spending_growth = $prev_month_spent > 0 ? (($month_spent - $prev_month_spent) / $prev_month_spent) * 100 : 0;

// Get data for last 12 months chart
$monthly_data = $conn->query("
    SELECT 
        DATE_FORMAT(expense_date, '%Y-%m') as month,
        COUNT(*) as expense_count,
        COALESCE(SUM(amount), 0) as total_amount
    FROM expenses
    WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
    ORDER BY month ASC
");

$months = [];
$expense_counts = [];
$amounts = [];
while ($row = $monthly_data->fetch_assoc()) {
    $months[] = date('M Y', strtotime($row['month'] . '-01'));
    $expense_counts[] = (int)$row['expense_count'];
    $amounts[] = (float)$row['total_amount'];
}

// Category distribution for pie chart
$category_chart_data = $conn->query("
    SELECT c.name, COALESCE(SUM(e.amount), 0) as total_amount
    FROM categories c
    LEFT JOIN expenses e ON c.id = e.category_id
    GROUP BY c.id
    HAVING total_amount > 0
    ORDER BY total_amount DESC
    LIMIT 8
");

$category_names = [];
$category_amounts = [];
while ($row = $category_chart_data->fetch_assoc()) {
    $category_names[] = $row['name'];
    $category_amounts[] = (float)$row['total_amount'];
}

// User growth data (last 12 months)
$user_growth_data = $conn->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as user_count
    FROM users
    WHERE role='user' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
");

$user_months = [];
$user_counts = [];
$cumulative_users = 0;
while ($row = $user_growth_data->fetch_assoc()) {
    $user_months[] = date('M Y', strtotime($row['month'] . '-01'));
    $cumulative_users += (int)$row['user_count'];
    $user_counts[] = $cumulative_users;
}

// Recent users
$recent_users = $conn->query("SELECT id, name, email, created_at FROM users WHERE role='user' ORDER BY created_at DESC LIMIT 5");

// Recent expenses
$recent_expenses = $conn->query("SELECT e.*, u.name as user_name, c.name as category_name 
                                 FROM expenses e 
                                 LEFT JOIN users u ON e.user_id = u.id 
                                 LEFT JOIN categories c ON e.category_id = c.id 
                                 ORDER BY e.created_at DESC LIMIT 8");

// Top spenders this month
$top_spenders = $conn->query("SELECT u.id, u.name, u.email, COALESCE(SUM(e.amount), 0) as total_spent,
                              COUNT(e.id) as expense_count
                              FROM users u
                              LEFT JOIN expenses e ON u.id = e.user_id 
                              AND DATE_FORMAT(e.expense_date, '%Y-%m') = '$current_month'
                              WHERE u.role = 'user'
                              GROUP BY u.id
                              HAVING total_spent > 0
                              ORDER BY total_spent DESC
                              LIMIT 5");

// Top categories this month
$top_categories = $conn->query("
    SELECT c.name, COUNT(e.id) as expense_count, COALESCE(SUM(e.amount), 0) as total_amount
    FROM categories c
    LEFT JOIN expenses e ON c.id = e.category_id
    AND DATE_FORMAT(e.expense_date, '%Y-%m') = '$current_month'
    GROUP BY c.id
    HAVING expense_count > 0
    ORDER BY total_amount DESC
    LIMIT 6
");

// System activity stats
$today_expenses = $conn->query("SELECT COUNT(*) as count FROM expenses WHERE DATE(expense_date) = CURDATE()")->fetch_assoc()['count'];
$new_users_today = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='user' AND DATE(created_at) = CURDATE()")->fetch_assoc()['count'];

// Linked accounts stats
try {
    $total_linked_accounts_result = $conn->query("SELECT COUNT(*) as count FROM linked_accounts");
    $total_linked_accounts = $total_linked_accounts_result ? $total_linked_accounts_result->fetch_assoc()['count'] : 0;
} catch (Exception $e) {
    $total_linked_accounts = 0;
}

try {
    $check_column = $conn->query("SHOW COLUMNS FROM linked_accounts LIKE 'last_sync'");
    if ($check_column && $check_column->num_rows > 0) {
        $active_syncs_result = $conn->query("SELECT COUNT(*) as count FROM linked_accounts WHERE DATE(last_sync) = CURDATE()");
        $active_syncs_today = $active_syncs_result ? $active_syncs_result->fetch_assoc()['count'] : 0;
    } else {
        $active_syncs_today = 0;
    }
} catch (Exception $e) {
    $active_syncs_today = 0;
}

// Average expense per user
$avg_expense_per_user = $total_users > 0 ? $total_spent / $total_users : 0;

// Average expenses per month
$avg_monthly_expenses = count($months) > 0 ? array_sum($amounts) / count($months) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - FinSight</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#4F46E5',
                        secondary: '#7C3AED',
                        accent: '#EC4899',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <!-- Mobile Menu Button -->
        <button id="mobile-menu-button" class="lg:hidden fixed top-4 left-4 z-50 bg-primary text-white p-3 rounded-lg shadow-lg">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path id="menu-icon" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                <path id="close-icon" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>

        <!-- Sidebar -->
        <aside id="sidebar" class="fixed lg:sticky top-0 left-0 h-screen w-64 bg-white border-r border-gray-200 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out z-40 overflow-y-auto">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-2xl font-bold text-gray-900">FinSight</h2>
                <p class="text-xs text-gray-500 mt-1">Administration Panel</p>
            </div>
            
            <nav class="p-4">
                <div class="space-y-1">
                    <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-white bg-primary rounded-lg">
                        Dashboard
                    </a>
                    <a href="manage_users.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        Manage Users
                    </a>
                    <a href="manage_categories.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        Categories
                    </a>
                    <a href="manage_banks.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        Bank Integrations
                    </a>
                    <a href="reports.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        Reports
                    </a>
                    <a href="analytics.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        Analytics
                    </a>
                    <a href="system_health.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        System Health
                    </a>
                    <a href="activity_logs.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        Activity Logs
                    </a>
                    <a href="notifications_manage.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        Notifications
                    </a>
                    <a href="settings.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        Settings
                    </a>
                </div>
                
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <a href="logout.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                        Logout
                    </a>
                </div>
            </nav>
        </aside>

        <!-- Overlay for mobile -->
        <div id="sidebar-overlay" class="hidden lg:hidden fixed inset-0 bg-black bg-opacity-50 z-30"></div>
        
        <!-- Main Content -->
        <main class="flex-1 p-4 lg:p-8 w-full overflow-x-hidden">
            <!-- Header -->
            <div class="mb-8 mt-16 lg:mt-0">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">Dashboard Overview</h1>
                        <p class="text-sm text-gray-500 mt-1">Comprehensive system metrics and analytics</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-right">
                            <p class="text-xs text-gray-500">Administrator</p>
                            <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($_SESSION['admin_name']); ?></p>
                        </div>
                        <div class="w-10 h-10 bg-primary text-white rounded-full flex items-center justify-center font-semibold">
                            <?php echo strtoupper(substr($_SESSION['admin_name'], 0, 1)); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Total Users</h3>
                        <span class="text-xs font-semibold text-blue-600 bg-blue-50 px-2 py-1 rounded">Active</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2"><?php echo number_format($total_users); ?></p>
                    <p class="text-xs text-gray-500">
                        <span class="text-green-600 font-semibold">+<?php echo $new_users_today; ?></span> registered today
                    </p>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Total Expenses</h3>
                        <span class="text-xs font-semibold text-purple-600 bg-purple-50 px-2 py-1 rounded">Recorded</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2"><?php echo number_format($total_expenses); ?></p>
                    <p class="text-xs text-gray-500">
                        <span class="text-green-600 font-semibold">+<?php echo $today_expenses; ?></span> added today
                    </p>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Categories</h3>
                        <span class="text-xs font-semibold text-green-600 bg-green-50 px-2 py-1 rounded">System</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2"><?php echo number_format($total_categories); ?></p>
                    <p class="text-xs text-gray-500">Active expense categories</p>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Total Spent</h3>
                        <span class="text-xs font-semibold text-orange-600 bg-orange-50 px-2 py-1 rounded">All Time</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2">₱<?php echo number_format($total_spent, 2); ?></p>
                    <p class="text-xs text-gray-500">Across all users</p>
                </div>
            </div>

            <!-- Monthly Performance Metrics -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-6 border border-blue-200">
                    <h3 class="text-sm font-medium text-blue-900 mb-3">Monthly Expenses</h3>
                    <p class="text-3xl font-bold text-blue-900 mb-2"><?php echo number_format($month_expenses); ?></p>
                    <div class="flex items-center gap-2">
                        <?php if ($expense_growth >= 0): ?>
                            <span class="text-xs font-semibold text-green-700 bg-green-100 px-2 py-1 rounded">
                                ↑ <?php echo number_format(abs($expense_growth), 1); ?>%
                            </span>
                        <?php else: ?>
                            <span class="text-xs font-semibold text-red-700 bg-red-100 px-2 py-1 rounded">
                                ↓ <?php echo number_format(abs($expense_growth), 1); ?>%
                            </span>
                        <?php endif; ?>
                        <span class="text-xs text-blue-700">from last month</span>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-6 border border-purple-200">
                    <h3 class="text-sm font-medium text-purple-900 mb-3">Monthly Spending</h3>
                    <p class="text-3xl font-bold text-purple-900 mb-2">₱<?php echo number_format($month_spent, 2); ?></p>
                    <div class="flex items-center gap-2">
                        <?php if ($spending_growth >= 0): ?>
                            <span class="text-xs font-semibold text-green-700 bg-green-100 px-2 py-1 rounded">
                                ↑ <?php echo number_format(abs($spending_growth), 1); ?>%
                            </span>
                        <?php else: ?>
                            <span class="text-xs font-semibold text-red-700 bg-red-100 px-2 py-1 rounded">
                                ↓ <?php echo number_format(abs($spending_growth), 1); ?>%
                            </span>
                        <?php endif; ?>
                        <span class="text-xs text-purple-700">from last month</span>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-6 border border-green-200">
                    <h3 class="text-sm font-medium text-green-900 mb-3">Avg per User</h3>
                    <p class="text-3xl font-bold text-green-900 mb-2">₱<?php echo number_format($avg_expense_per_user, 2); ?></p>
                    <p class="text-xs text-green-700">Total expenses / users</p>
                </div>

                <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-xl p-6 border border-orange-200">
                    <h3 class="text-sm font-medium text-orange-900 mb-3">Linked Accounts</h3>
                    <p class="text-3xl font-bold text-orange-900 mb-2"><?php echo number_format($total_linked_accounts); ?></p>
                    <p class="text-xs text-orange-700"><?php echo number_format($active_syncs_today); ?> synced today</p>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Spending Trend Chart -->
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">Spending Trends</h2>
                            <p class="text-xs text-gray-500 mt-1">Last 12 months overview</p>
                        </div>
                        <select id="trendPeriod" class="text-xs border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="amount">Amount</option>
                            <option value="count">Count</option>
                        </select>
                    </div>
                    <div class="h-80">
                        <canvas id="spendingTrendChart"></canvas>
                    </div>
                </div>

                <!-- Category Distribution Chart -->
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">Category Distribution</h2>
                            <p class="text-xs text-gray-500 mt-1">Spending by category</p>
                        </div>
                        <a href="manage_categories.php" class="text-xs text-primary hover:text-primary-600 font-semibold">Manage →</a>
                    </div>
                    <div class="h-80">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- User Growth Chart -->
            <div class="bg-white rounded-xl border border-gray-200 p-6 mb-8">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">User Growth</h2>
                        <p class="text-xs text-gray-500 mt-1">Cumulative user registrations</p>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_users); ?></p>
                        <p class="text-xs text-gray-500">Total Users</p>
                    </div>
                </div>
                <div class="h-64">
                    <canvas id="userGrowthChart"></canvas>
                </div>
            </div>

            <!-- Top Spenders & Categories -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">Top Spenders</h2>
                            <p class="text-xs text-gray-500 mt-1"><?php echo date('F Y'); ?></p>
                        </div>
                        <a href="manage_users.php" class="text-xs text-primary hover:text-primary-600 font-semibold">View All →</a>
                    </div>
                    <?php if ($top_spenders->num_rows > 0): ?>
                        <div class="space-y-4">
                            <?php $rank = 1; while ($spender = $top_spenders->fetch_assoc()): ?>
                            <div class="flex items-center gap-4 p-3 hover:bg-gray-50 rounded-lg transition-colors">
                                <div class="w-8 h-8 bg-gradient-to-br from-primary to-secondary text-white rounded-lg flex items-center justify-center text-sm font-bold">
                                    <?php echo $rank++; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($spender['name']); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo $spender['expense_count']; ?> transactions</p>
                                </div>
                                <p class="text-sm font-bold text-gray-900">₱<?php echo number_format($spender['total_spent'], 2); ?></p>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12 text-gray-400">
                            <p class="text-sm">No spending data available for this month</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">Top Categories</h2>
                            <p class="text-xs text-gray-500 mt-1"><?php echo date('F Y'); ?></p>
                        </div>
                        <a href="manage_categories.php" class="text-xs text-primary hover:text-primary-600 font-semibold">View All →</a>
                    </div>
                    <?php if ($top_categories->num_rows > 0): ?>
                        <div class="space-y-4">
                            <?php while ($cat = $top_categories->fetch_assoc()): 
                                $percentage = $month_spent > 0 ? ($cat['total_amount'] / $month_spent) * 100 : 0;
                            ?>
                            <div class="p-3 hover:bg-gray-50 rounded-lg transition-colors">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($cat['name']); ?></p>
                                    <p class="text-sm font-bold text-gray-900">₱<?php echo number_format($cat['total_amount'], 2); ?></p>
                                </div>
                                <div class="w-full bg-gray-100 rounded-full h-2 mb-2">
                                    <div class="bg-gradient-to-r from-primary to-secondary h-2 rounded-full transition-all duration-500" style="width: <?php echo min($percentage, 100); ?>%"></div>
                                </div>
                                <div class="flex items-center justify-between">
                                    <p class="text-xs text-gray-500"><?php echo number_format($cat['expense_count']); ?> expenses</p>
                                    <p class="text-xs font-semibold text-gray-700"><?php echo number_format($percentage, 1); ?>%</p>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12 text-gray-400">
                            <p class="text-sm">No category data available for this month</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">Recent Users</h2>
                            <p class="text-xs text-gray-500 mt-1">Latest registrations</p>
                        </div>
                        <a href="manage_users.php" class="text-xs text-primary hover:text-primary-600 font-semibold">View All →</a>
                    </div>
                    <?php if ($recent_users->num_rows > 0): ?>
                        <div class="space-y-4">
                            <?php while ($user = $recent_users->fetch_assoc()): ?>
                            <div class="flex items-center justify-between p-3 hover:bg-gray-50 rounded-lg transition-colors">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-blue-600 rounded-full flex items-center justify-center font-semibold text-white text-sm">
                                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($user['name']); ?></p>
                                        <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($user['email']); ?></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-gray-500 mb-1"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></p>
                                    <a href="view_user.php?id=<?php echo $user['id']; ?>" class="text-xs text-primary hover:text-primary-600 font-semibold">View →</a>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12 text-gray-400">
                            <p class="text-sm">No users registered yet</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">Recent Expenses</h2>
                            <p class="text-xs text-gray-500 mt-1">Latest transactions</p>
                        </div>
                        <a href="reports.php" class="text-xs text-primary hover:text-primary-600 font-semibold">View Report →</a>
                    </div>
                    <?php if ($recent_expenses->num_rows > 0): ?>
                        <div class="space-y-3">
                            <?php while ($expense = $recent_expenses->fetch_assoc()): ?>
                            <div class="flex items-center justify-between p-3 hover:bg-gray-50 rounded-lg transition-colors">
                                <div class="flex-1 min-w-0 mr-4">
                                    <div class="flex items-center gap-2 mb-1">
                                        <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($expense['user_name']); ?></p>
                                        <span class="inline-block text-xs font-medium text-purple-700 bg-purple-50 px-2 py-0.5 rounded">
                                            <?php echo htmlspecialchars($expense['category_name'] ?? 'N/A'); ?>
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($expense['description']); ?></p>
                                    <p class="text-xs text-gray-400 mt-1"><?php echo date('M d, Y', strtotime($expense['expense_date'])); ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-bold text-gray-900">₱<?php echo number_format($expense['amount'], 2); ?></p>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12 text-gray-400">
                            <p class="text-sm">No expenses recorded yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const menuIcon = document.getElementById('menu-icon');
        const closeIcon = document.getElementById('close-icon');

        function toggleSidebar() {
            sidebar.classList.toggle('-translate-x-full');
            sidebarOverlay.classList.toggle('hidden');
            menuIcon.classList.toggle('hidden');
            closeIcon.classList.toggle('hidden');
        }

        mobileMenuButton.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', toggleSidebar);

        // Close sidebar when clicking a link on mobile
        const sidebarLinks = sidebar.querySelectorAll('a');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 1024) {
                    toggleSidebar();
                }
            });
        });

        // Chart.js Configuration
        const chartColors = {
            primary: '#4F46E5',
            secondary: '#7C3AED',
            accent: '#EC4899',
            success: '#10B981',
            warning: '#F59E0B',
            danger: '#EF4444',
            info: '#3B82F6'
        };

        // Spending Trend Chart
        const spendingCtx = document.getElementById('spendingTrendChart').getContext('2d');
        const spendingData = {
            labels: <?php echo json_encode($months); ?>,
            datasets: [{
                label: 'Amount Spent (₱)',
                data: <?php echo json_encode($amounts); ?>,
                borderColor: chartColors.primary,
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                tension: 0.4,
                fill: true,
                borderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: chartColors.primary
            }]
        };

        const spendingCountData = {
            labels: <?php echo json_encode($months); ?>,
            datasets: [{
                label: 'Expense Count',
                data: <?php echo json_encode($expense_counts); ?>,
                borderColor: chartColors.secondary,
                backgroundColor: 'rgba(124, 58, 237, 0.1)',
                tension: 0.4,
                fill: true,
                borderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: chartColors.secondary
            }]
        };

        const spendingChart = new Chart(spendingCtx, {
            type: 'line',
            data: spendingData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 13 },
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.dataset.label.includes('Amount')) {
                                    label += '₱' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                } else {
                                    label += context.parsed.y.toLocaleString();
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Trend Period Selector
        document.getElementById('trendPeriod').addEventListener('change', function(e) {
            if (e.target.value === 'amount') {
                spendingChart.data = spendingData;
            } else {
                spendingChart.data = spendingCountData;
            }
            spendingChart.update();
        });

        // Category Distribution Chart
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        const categoryChart = new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($category_names); ?>,
                datasets: [{
                    data: <?php echo json_encode($category_amounts); ?>,
                    backgroundColor: [
                        chartColors.primary,
                        chartColors.secondary,
                        chartColors.accent,
                        chartColors.success,
                        chartColors.warning,
                        chartColors.info,
                        chartColors.danger,
                        '#6366F1'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 13 },
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return label + ': ₱' + value.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });

        // User Growth Chart
        const userGrowthCtx = document.getElementById('userGrowthChart').getContext('2d');
        const userGrowthChart = new Chart(userGrowthCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($user_months); ?>,
                datasets: [{
                    label: 'Total Users',
                    data: <?php echo json_encode($user_counts); ?>,
                    backgroundColor: 'rgba(79, 70, 229, 0.8)',
                    borderColor: chartColors.primary,
                    borderWidth: 2,
                    borderRadius: 6,
                    hoverBackgroundColor: chartColors.primary
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 13 },
                        callbacks: {
                            label: function(context) {
                                return 'Total Users: ' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            callback: function(value) {
                                return value.toLocaleString();
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>