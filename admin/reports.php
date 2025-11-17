<?php
require_once 'includes/auth_admin.php';
require_once '../includes/db_connect.php';

// Date range filter (default: last 30 days)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// User filter
$user_filter = isset($_GET['user_id']) && $_GET['user_id'] != '' ? intval($_GET['user_id']) : null;

// Build query conditions
$date_condition = "e.expense_date BETWEEN '$start_date' AND '$end_date'";
$user_condition = $user_filter ? "AND e.user_id = $user_filter" : "";

// Get all users for filter dropdown
$all_users = $conn->query("SELECT id, name FROM users WHERE role='user' ORDER BY name ASC");

// Summary statistics
$summary_query = "SELECT 
    COUNT(DISTINCT e.user_id) as active_users,
    COUNT(e.id) as total_transactions,
    COALESCE(SUM(e.amount), 0) as total_spent,
    COALESCE(AVG(e.amount), 0) as avg_transaction
FROM expenses e
WHERE $date_condition $user_condition";
$summary = $conn->query($summary_query)->fetch_assoc();

// Daily spending trend
$daily_trend = $conn->query("SELECT 
    DATE_FORMAT(e.expense_date, '%Y-%m-%d') as date,
    COUNT(*) as transaction_count,
    SUM(e.amount) as total_amount
FROM expenses e
WHERE $date_condition $user_condition
GROUP BY DATE_FORMAT(e.expense_date, '%Y-%m-%d')
ORDER BY date ASC");

// Category breakdown
$total_for_percentage_query = "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date BETWEEN '$start_date' AND '$end_date'" . ($user_filter ? " AND user_id = $user_filter" : "");
$total_for_percentage_result = $conn->query($total_for_percentage_query)->fetch_assoc();
$total_for_percentage = $total_for_percentage_result['total'];

$category_breakdown = $conn->query("SELECT 
    c.name as category,
    COUNT(e.id) as count,
    COALESCE(SUM(e.amount), 0) as total,
    CASE 
        WHEN $total_for_percentage > 0 THEN ROUND((COALESCE(SUM(e.amount), 0) / $total_for_percentage) * 100, 2)
        ELSE 0 
    END as percentage
FROM expenses e
LEFT JOIN categories c ON e.category_id = c.id
WHERE $date_condition $user_condition
GROUP BY c.id, c.name
ORDER BY total DESC");

// Top spenders
$top_spenders = $conn->query("SELECT 
    u.id, u.name, u.email,
    COUNT(e.id) as transaction_count,
    COALESCE(SUM(e.amount), 0) as total_spent,
    COALESCE(AVG(e.amount), 0) as avg_spent
FROM users u
LEFT JOIN expenses e ON u.id = e.user_id AND e.expense_date BETWEEN '$start_date' AND '$end_date'
WHERE u.role = 'user' " . ($user_filter ? "AND u.id = $user_filter" : "") . "
GROUP BY u.id, u.name, u.email
HAVING total_spent > 0
ORDER BY total_spent DESC
LIMIT 10");

// Monthly comparison
$monthly_comparison = $conn->query("SELECT 
    DATE_FORMAT(expense_date, '%Y-%m') as month,
    COUNT(*) as transactions,
    SUM(amount) as total,
    AVG(amount) as average
FROM expenses
WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) " . ($user_filter ? "AND user_id = $user_filter" : "") . "
GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
ORDER BY month ASC");

// Budget utilization
$budget_utilization = $conn->query("SELECT 
    u.id, u.name, u.monthly_budget,
    COALESCE(SUM(e.amount), 0) as spent,
    CASE 
        WHEN u.monthly_budget > 0 THEN ROUND((COALESCE(SUM(e.amount), 0) / u.monthly_budget) * 100, 2)
        ELSE 0
    END as utilization_percentage
FROM users u
LEFT JOIN expenses e ON u.id = e.user_id 
    AND DATE_FORMAT(e.expense_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
WHERE u.role = 'user' AND u.monthly_budget > 0 " . ($user_filter ? "AND u.id = $user_filter" : "") . "
GROUP BY u.id, u.name, u.monthly_budget
ORDER BY utilization_percentage DESC
LIMIT 10");

// Export functionality
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="expense_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'User', 'Category', 'Description', 'Amount']);
    
    $export_query = "SELECT 
        e.expense_date, u.name, c.name as category, e.description, e.amount
    FROM expenses e
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN categories c ON e.category_id = c.id
    WHERE $date_condition $user_condition
    ORDER BY e.expense_date DESC";
    
    $export_result = $conn->query($export_query);
    while ($row = $export_result->fetch_assoc()) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - FinSight Admin</title>
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
                    <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
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
                    <a href="reports.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-white bg-primary rounded-lg">
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
                        <h1 class="text-3xl font-bold text-gray-900">Reports & Analytics</h1>
                        <p class="text-sm text-gray-500 mt-1">Comprehensive expense analysis and insights</p>
                    </div>
                    <a href="?export=csv&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?><?php echo $user_filter ? '&user_id='.$user_filter : ''; ?>" 
                       class="bg-primary hover:bg-opacity-90 text-white px-6 py-3 rounded-lg font-medium transition-all flex items-center gap-2 justify-center lg:justify-start shadow-sm hover:shadow-md">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Export CSV
                    </a>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="bg-white rounded-xl border border-gray-200 p-6 mb-8 shadow-sm">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Filter Options</h2>
                <form method="GET" action="">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                        <div class="flex flex-col gap-2">
                            <label class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Start Date</label>
                            <input type="date" name="start_date" value="<?php echo $start_date; ?>" 
                                   class="px-4 py-2.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all">
                        </div>
                        
                        <div class="flex flex-col gap-2">
                            <label class="text-xs font-semibold text-gray-600 uppercase tracking-wide">End Date</label>
                            <input type="date" name="end_date" value="<?php echo $end_date; ?>" 
                                   class="px-4 py-2.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all">
                        </div>
                        
                        <div class="flex flex-col gap-2">
                            <label class="text-xs font-semibold text-gray-600 uppercase tracking-wide">User Filter</label>
                            <select name="user_id" class="px-4 py-2.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all">
                                <option value="">All Users</option>
                                <?php while ($u = $all_users->fetch_assoc()): ?>
                                    <option value="<?php echo $u['id']; ?>" <?php echo $user_filter == $u['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($u['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="flex flex-col gap-2 justify-end">
                            <button type="submit" class="bg-primary hover:bg-opacity-90 text-white px-6 py-2.5 rounded-lg font-medium transition-all text-sm shadow-sm hover:shadow-md">
                                Apply Filters
                            </button>
                        </div>
                        
                        <div class="flex flex-col gap-2 justify-end">
                            <a href="reports.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2.5 rounded-lg font-medium transition-all text-center text-sm">
                                Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Summary Stats -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Active Users</h3>
                        <span class="text-xs font-semibold text-blue-600 bg-blue-50 px-2 py-1 rounded">Engaged</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2"><?php echo number_format($summary['active_users']); ?></p>
                    <p class="text-xs text-gray-500">Users with transactions</p>
                </div>
                
                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Transactions</h3>
                        <span class="text-xs font-semibold text-purple-600 bg-purple-50 px-2 py-1 rounded">Total</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2"><?php echo number_format($summary['total_transactions']); ?></p>
                    <p class="text-xs text-gray-500">Total expense records</p>
                </div>
                
                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Total Spent</h3>
                        <span class="text-xs font-semibold text-orange-600 bg-orange-50 px-2 py-1 rounded">Amount</span>
                    </div>
                    <p class="text-3xl lg:text-4xl font-bold text-gray-900 mb-2">₱<?php echo number_format($summary['total_spent'], 2); ?></p>
                    <p class="text-xs text-gray-500">Combined expenses</p>
                </div>
                
                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Average</h3>
                        <span class="text-xs font-semibold text-green-600 bg-green-50 px-2 py-1 rounded">Per Txn</span>
                    </div>
                    <p class="text-3xl lg:text-4xl font-bold text-gray-900 mb-2">₱<?php echo number_format($summary['avg_transaction'], 2); ?></p>
                    <p class="text-xs text-gray-500">Per transaction</p>
                </div>
            </div>
            
            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <!-- Daily Trend Chart -->
                <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Daily Spending Trend</h3>
                            <p class="text-xs text-gray-500 mt-1">Expense patterns over time</p>
                        </div>
                    </div>
                    <div class="h-80">
                        <canvas id="dailyTrendChart"></canvas>
                    </div>
                </div>
                
                <!-- Category Distribution -->
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Categories</h3>
                            <p class="text-xs text-gray-500 mt-1">Spending distribution</p>
                        </div>
                    </div>
                    <div class="h-80">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Monthly Comparison -->
            <div class="bg-white rounded-xl border border-gray-200 p-6 mb-8 shadow-sm">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">6-Month Comparison</h3>
                        <p class="text-xs text-gray-500 mt-1">Historical spending analysis</p>
                    </div>
                </div>
                <div class="h-80">
                    <canvas id="monthlyComparisonChart"></canvas>
                </div>
            </div>
            
            <!-- Tables Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Top Spenders -->
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Top Spenders</h3>
                            <p class="text-xs text-gray-500 mt-1">Highest spending users in period</p>
                        </div>
                        <a href="manage_users.php" class="text-xs text-primary hover:text-primary-600 font-semibold">View All →</a>
                    </div>
                    <?php if ($top_spenders->num_rows > 0): ?>
                        <div class="space-y-3">
                            <?php 
                            $rank = 1;
                            while ($spender = $top_spenders->fetch_assoc()): 
                            ?>
                            <div class="flex items-center gap-4 p-4 hover:bg-gray-50 rounded-lg transition-colors">
                                <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gradient-to-br <?php echo $rank <= 3 ? 'from-primary to-secondary text-white' : 'from-gray-200 to-gray-300 text-gray-600'; ?> font-bold text-sm">
                                    <?php echo $rank; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 truncate text-sm"><?php echo htmlspecialchars($spender['name']); ?></p>
                                    <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($spender['email']); ?></p>
                                    <p class="text-xs text-gray-600 mt-1"><?php echo number_format($spender['transaction_count']); ?> transactions</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-primary text-base">₱<?php echo number_format($spender['total_spent'], 2); ?></p>
                                    <a href="view_user.php?id=<?php echo $spender['id']; ?>" class="text-xs text-blue-600 hover:underline">View →</a>
                                </div>
                            </div>
                            <?php 
                            $rank++;
                            endwhile; 
                            ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12 text-gray-400">
                            <svg class="w-16 h-16 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                            </svg>
                            <p class="text-sm">No spending data in selected period</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Budget Utilization -->
                <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Budget Utilization</h3>
                            <p class="text-xs text-gray-500 mt-1">Current month progress</p>
                        </div>
                    </div>
                    <?php if ($budget_utilization->num_rows > 0): ?>
                        <div class="space-y-4">
                            <?php while ($budget = $budget_utilization->fetch_assoc()): 
                                $remaining = $budget['monthly_budget'] - $budget['spent'];
                                $percentage = $budget['utilization_percentage'];
                                $bar_class = $percentage >= 100 ? 'bg-gradient-to-r from-red-500 to-red-600' : ($percentage >= 80 ? 'bg-gradient-to-r from-yellow-500 to-orange-500' : 'bg-gradient-to-r from-green-500 to-teal-500');
                            ?>
                            <div class="p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                <div class="flex items-center justify-between mb-3">
                                    <p class="font-semibold text-gray-900 text-sm"><?php echo htmlspecialchars($budget['name']); ?></p>
                                    <span class="text-xs font-bold px-2 py-1 rounded <?php echo $percentage >= 100 ? 'text-red-700 bg-red-100' : ($percentage >= 80 ? 'text-yellow-700 bg-yellow-100' : 'text-green-700 bg-green-100'); ?>">
                                        <?php echo number_format($percentage, 1); ?>%
                                    </span>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mb-3 text-xs">
                                    <div>
                                        <span class="text-gray-500 block mb-1">Budget:</span>
                                        <p class="font-semibold text-gray-900">₱<?php echo number_format($budget['monthly_budget'], 2); ?></p>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 block mb-1">Spent:</span>
                                        <p class="font-semibold text-gray-900">₱<?php echo number_format($budget['spent'], 2); ?></p>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 block mb-1">Remaining:</span>
                                        <p class="font-semibold <?php echo $remaining < 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                            ₱<?php echo number_format($remaining, 2); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                                    <div class="h-full <?php echo $bar_class; ?> transition-all duration-500 rounded-full" style="width: <?php echo min($percentage, 100); ?>%"></div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12 text-gray-400">
                            <svg class="w-16 h-16 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p class="text-sm">No budget data available</p>
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

        mobileMenuButton.addEventListener('click', () => {
            sidebar.classList.toggle('-translate-x-full');
            sidebarOverlay.classList.toggle('hidden');
            menuIcon.classList.toggle('hidden');
            closeIcon.classList.toggle('hidden');
        });

        sidebarOverlay.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
            menuIcon.classList.remove('hidden');
            closeIcon.classList.add('hidden');
        });

        // Chart.js configurations
        const chartConfig = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        padding: 15,
                        font: {
                            size: 11,
                            family: 'system-ui'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    titleFont: {
                        size: 13
                    },
                    bodyFont: {
                        size: 12
                    },
                    cornerRadius: 8
                }
            }
        };

        // Daily Trend Chart
        const dailyTrendCtx = document.getElementById('dailyTrendChart').getContext('2d');
        const dailyTrendData = {
            labels: [
                <?php 
                $daily_trend->data_seek(0);
                $dates = [];
                while ($row = $daily_trend->fetch_assoc()) {
                    $dates[] = "'" . date('M d', strtotime($row['date'])) . "'";
                }
                echo implode(',', $dates);
                ?>
            ],
            datasets: [{
                label: 'Daily Spending',
                data: [
                    <?php 
                    $daily_trend->data_seek(0);
                    $amounts = [];
                    while ($row = $daily_trend->fetch_assoc()) {
                        $amounts[] = $row['total_amount'];
                    }
                    echo implode(',', $amounts);
                    ?>
                ],
                borderColor: 'rgb(79, 70, 229)',
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                tension: 0.4,
                fill: true,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: 'rgb(79, 70, 229)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            }]
        };

        new Chart(dailyTrendCtx, {
            type: 'line',
            data: dailyTrendData,
            options: {
                ...chartConfig,
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
                },
                plugins: {
                    ...chartConfig.plugins,
                    tooltip: {
                        ...chartConfig.plugins.tooltip,
                        callbacks: {
                            label: function(context) {
                                return 'Amount: ₱' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                        }
                    }
                }
            }
        });

        // Category Distribution Chart
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        const categoryData = {
            labels: [
                <?php 
                $category_breakdown->data_seek(0);
                $cat_labels = [];
                while ($row = $category_breakdown->fetch_assoc()) {
                    $cat_labels[] = "'" . addslashes($row['category']) . "'";
                }
                echo implode(',', $cat_labels);
                ?>
            ],
            datasets: [{
                data: [
                    <?php 
                    $category_breakdown->data_seek(0);
                    $cat_amounts = [];
                    while ($row = $category_breakdown->fetch_assoc()) {
                        $cat_amounts[] = $row['total'];
                    }
                    echo implode(',', $cat_amounts);
                    ?>
                ],
                backgroundColor: [
                    'rgba(79, 70, 229, 0.8)',
                    'rgba(124, 58, 237, 0.8)',
                    'rgba(236, 72, 153, 0.8)',
                    'rgba(59, 130, 246, 0.8)',
                    'rgba(16, 185, 129, 0.8)',
                    'rgba(245, 158, 11, 0.8)',
                    'rgba(239, 68, 68, 0.8)',
                    'rgba(139, 92, 246, 0.8)',
                    'rgba(6, 182, 212, 0.8)',
                    'rgba(251, 146, 60, 0.8)'
                ],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        };

        new Chart(categoryCtx, {
            type: 'doughnut',
            data: categoryData,
            options: {
                ...chartConfig,
                cutout: '60%',
                plugins: {
                    ...chartConfig.plugins,
                    tooltip: {
                        ...chartConfig.plugins.tooltip,
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

        // Monthly Comparison Chart
        const monthlyCtx = document.getElementById('monthlyComparisonChart').getContext('2d');
        const monthlyData = {
            labels: [
                <?php 
                $monthly_comparison->data_seek(0);
                $month_labels = [];
                while ($row = $monthly_comparison->fetch_assoc()) {
                    $month_labels[] = "'" . date('M Y', strtotime($row['month'] . '-01')) . "'";
                }
                echo implode(',', $month_labels);
                ?>
            ],
            datasets: [{
                label: 'Total Spending',
                data: [
                    <?php 
                    $monthly_comparison->data_seek(0);
                    $month_amounts = [];
                    while ($row = $monthly_comparison->fetch_assoc()) {
                        $month_amounts[] = $row['total'];
                    }
                    echo implode(',', $month_amounts);
                    ?>
                ],
                backgroundColor: 'rgba(124, 58, 237, 0.8)',
                borderColor: 'rgb(124, 58, 237)',
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false
            },
            {
                label: 'Average per Transaction',
                data: [
                    <?php 
                    $monthly_comparison->data_seek(0);
                    $month_averages = [];
                    while ($row = $monthly_comparison->fetch_assoc()) {
                        $month_averages[] = $row['average'];
                    }
                    echo implode(',', $month_averages);
                    ?>
                ],
                backgroundColor: 'rgba(236, 72, 153, 0.8)',
                borderColor: 'rgb(236, 72, 153)',
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false
            }]
        };

        new Chart(monthlyCtx, {
            type: 'bar',
            data: monthlyData,
            options: {
                ...chartConfig,
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
                },
                plugins: {
                    ...chartConfig.plugins,
                    tooltip: {
                        ...chartConfig.plugins.tooltip,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ₱' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>