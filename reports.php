<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/auth_user.php';

$user_id = $_SESSION['user_id'];

// Fetch user data
$user_query = "SELECT name, email, monthly_budget FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$user_name = $user['name'] ?? 'User';
$user_email = $user['email'] ?? '';
$user_initial = strtoupper(substr($user_name, 0, 1));
$monthly_budget = $user['monthly_budget'] ?? 0;

// ENHANCED DATE RANGE FILTER - GET THE PERIOD FROM URL
$filter_period = isset($_GET['period']) ? $_GET['period'] : 'year';
$custom_start = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$custom_end = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Determine date range and period name based on filter
switch ($filter_period) {
    case 'week':
        $start_date = date('Y-m-d', strtotime('-6 days'));
        $end_date = date('Y-m-d');
        $period_name = 'Last 7 Days';
        break;
        
    case 'month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        $period_name = 'This Month';
        break;
        
    case 'quarter':
        $current_month = date('n');
        $quarter_start_month = floor(($current_month - 1) / 3) * 3 + 1;
        $start_date = date('Y-' . str_pad($quarter_start_month, 2, '0', STR_PAD_LEFT) . '-01');
        $end_date = date('Y-m-t', strtotime($start_date . ' +2 months'));
        
        $quarter_num = ceil($current_month / 3);
        $period_name = 'Quarter ' . $quarter_num . ' (' . date('Y') . ')';
        break;
        
    case 'year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        $period_name = 'Year ' . date('Y');
        break;
        
    case 'custom':
        if ($custom_start && $custom_end) {
            $start_date = date('Y-m-d', strtotime($custom_start));
            $end_date = date('Y-m-d', strtotime($custom_end));
            
            if (strtotime($start_date) > strtotime($end_date)) {
                $temp = $start_date;
                $start_date = $end_date;
                $end_date = $temp;
            }
            
            $period_name = 'Custom Range';
        } else {
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-d');
            $period_name = 'Custom Range';
        }
        break;
        
    default:
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        $period_name = 'Year ' . date('Y');
        $filter_period = 'year';
}

// Total expenses in period
$total_query = "SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as transaction_count 
                FROM expenses 
                WHERE user_id = ? AND expense_date BETWEEN ? AND ?";
$stmt = $conn->prepare($total_query);
$stmt->bind_param("iss", $user_id, $start_date, $end_date);
$stmt->execute();
$total_result = $stmt->get_result()->fetch_assoc();
$total_expenses = $total_result['total'] ?? 0;
$transaction_count = $total_result['transaction_count'] ?? 0;

// Calculate days in period for average
$days_diff = max(1, (strtotime($end_date) - strtotime($start_date)) / 86400 + 1);
$avg_daily_spending = $days_diff > 0 ? $total_expenses / $days_diff : 0;

// Category breakdown
$category_query = "SELECT c.name, c.color, 
                   SUM(e.amount) as total, 
                   COUNT(e.id) as count,
                   AVG(e.amount) as avg_amount
                   FROM expenses e
                   JOIN categories c ON e.category_id = c.id
                   WHERE e.user_id = ? AND e.expense_date BETWEEN ? AND ?
                   GROUP BY c.id, c.name, c.color
                   ORDER BY total DESC";
$stmt = $conn->prepare($category_query);
$stmt->bind_param("iss", $user_id, $start_date, $end_date);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Monthly spending trend
$monthly_trend_query = "SELECT DATE_FORMAT(expense_date, '%Y-%m') as month,
                        SUM(amount) as total,
                        COUNT(*) as count
                        FROM expenses
                        WHERE user_id = ? AND expense_date BETWEEN ? AND ?
                        GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
                        ORDER BY month ASC";
$stmt = $conn->prepare($monthly_trend_query);
$stmt->bind_param("iss", $user_id, $start_date, $end_date);
$stmt->execute();
$monthly_trend = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get selected month for Monthly Detailed Report
$selected_month = isset($_GET['selected_month']) ? $_GET['selected_month'] : date('Y-m');
$selected_month_start = $selected_month . '-01';
$selected_month_end = date('Y-m-t', strtotime($selected_month_start));

// Fetch data for selected month
$selected_month_query = "SELECT DATE_FORMAT(expense_date, '%Y-%m-%d') as day,
                         SUM(amount) as total
                         FROM expenses
                         WHERE user_id = ? AND expense_date BETWEEN ? AND ?
                         GROUP BY DATE_FORMAT(expense_date, '%Y-%m-%d')
                         ORDER BY day ASC";
$stmt = $conn->prepare($selected_month_query);
$stmt->bind_param("iss", $user_id, $selected_month_start, $selected_month_end);
$stmt->execute();
$selected_month_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate total for selected month
$selected_month_total_query = "SELECT COALESCE(SUM(amount), 0) as total 
                                FROM expenses 
                                WHERE user_id = ? AND expense_date BETWEEN ? AND ?";
$stmt = $conn->prepare($selected_month_total_query);
$stmt->bind_param("iss", $user_id, $selected_month_start, $selected_month_end);
$stmt->execute();
$selected_month_total = $stmt->get_result()->fetch_assoc()['total'];

// Get selected year for Year-over-Year Analysis
$selected_year = isset($_GET['selected_year']) ? $_GET['selected_year'] : date('Y');
$year_start = $selected_year . '-01-01';
$year_end = $selected_year . '-12-31';

// Fetch monthly data for selected year
$year_data_query = "SELECT DATE_FORMAT(expense_date, '%Y-%m') as month,
                    SUM(amount) as total
                    FROM expenses
                    WHERE user_id = ? AND expense_date BETWEEN ? AND ?
                    GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
                    ORDER BY month ASC";
$stmt = $conn->prepare($year_data_query);
$stmt->bind_param("iss", $user_id, $year_start, $year_end);
$stmt->execute();
$year_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Create full year data with all 12 months
$full_year_data = [];
for ($i = 1; $i <= 12; $i++) {
    $month_key = $selected_year . '-' . str_pad($i, 2, '0', STR_PAD_LEFT);
    $full_year_data[$month_key] = 0;
}
foreach ($year_data as $data) {
    $full_year_data[$data['month']] = $data['total'];
}

// Prepare chart data
$chart_categories = json_encode(array_column($categories, 'name'));
$chart_amounts = json_encode(array_column($categories, 'total'));
$chart_colors = json_encode(array_column($categories, 'color'));

// Calculate growth comparison
$current_month = date('Y-m');
$previous_month = date('Y-m', strtotime('-1 month'));

$current_month_query = "SELECT COALESCE(SUM(amount), 0) as total FROM expenses 
                        WHERE user_id = ? AND DATE_FORMAT(expense_date, '%Y-%m') = ?";
$stmt = $conn->prepare($current_month_query);
$stmt->bind_param("is", $user_id, $current_month);
$stmt->execute();
$current_month_total = $stmt->get_result()->fetch_assoc()['total'];

$stmt = $conn->prepare($current_month_query);
$stmt->bind_param("is", $user_id, $previous_month);
$stmt->execute();
$previous_month_total = $stmt->get_result()->fetch_assoc()['total'];

$growth = 0;
if ($previous_month_total > 0) {
    $growth = (($current_month_total - $previous_month_total) / $previous_month_total) * 100;
}
$is_increase = $growth > 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - FinSight</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .nav-item {
            transition: all 0.3s ease;
        }

        .chart-container {
            position: relative;
            height: 350px;
            width: 100%;
            max-width: 100%;
        }

        .chart-container canvas {
            max-width: 100% !important;
            height: auto !important;
        }

        .mobile-menu {
            transition: transform 0.3s ease-in-out;
        }

        .mobile-menu.hidden {
            transform: translateX(-100%);
        }

        .filter-btn {
            transition: all 0.3s ease;
        }

        .filter-btn:hover {
            transform: translateY(-2px);
        }

        @media (max-width: 640px) {
            .chart-container {
                height: 280px;
                padding: 0 8px;
            }
            
            .bg-white.rounded-xl.shadow-sm {
                padding: 1rem !important;
            }
            
            .filter-btn {
                font-size: 0.75rem;
                padding: 0.5rem 0.75rem;
            }
        }

        body {
            overflow-x: hidden;
            max-width: 100vw;
        }

        main {
            overflow-x: hidden;
            max-width: 100vw;
        }

        .grid {
            width: 100%;
            max-width: 100%;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <!-- Sidebar - Desktop -->
        <aside class="hidden lg:flex lg:flex-col lg:w-64 bg-white border-r border-gray-200 fixed h-full z-30">
            <div class="flex items-center gap-3 px-6 py-5 border-b border-gray-200">
                <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center">
                    <span class="text-white text-xl font-bold">F</span>
                </div>
                <h2 class="text-xl font-bold text-gray-900">FinSight</h2>
            </div>
            
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                <a href="dashboard.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span>Dashboard</span>
                </a>
                <a href="all_expenses.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span>All Expenses</span>
                </a>
                <a href="add_expense.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span>Add Expense</span>
                </a>
                <a href="categories.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span>Categories</span>
                </a>
                <a href="budget_settings.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span>Budget Settings</span>
                </a>
                <a href="notifications.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span>Notifications</span>
                </a>
                <a href="reports.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg">
                    <span>Reports & Analytics</span>
                </a>
                <a href="profile.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span>Profile Settings</span>
                </a>
                <a href="linked_accounts.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span>Linked Accounts</span>
                </a>
                <a href="logout.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg">
                    <span>Logout</span>
                </a>
            </nav>
            
            <div class="p-4 border-t border-gray-200">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold">
                        <?php echo $user_initial; ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($user_name); ?></p>
                        <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($user_email); ?></p>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Mobile Sidebar Overlay -->
        <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

        <!-- Mobile Sidebar -->
        <aside id="mobileSidebar" class="mobile-menu fixed inset-y-0 left-0 w-72 bg-white border-r border-gray-200 z-50 lg:hidden transform -translate-x-full transition-transform duration-300 ease-in-out shadow-2xl">
            <div class="flex flex-col h-full">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-200 bg-gradient-to-r from-indigo-50 to-purple-50">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                            <span class="text-white text-xl font-bold">F</span>
                        </div>
                        <h2 class="text-xl font-bold text-gray-900">FinSight</h2>
                    </div>
                    <button id="closeSidebar" class="text-gray-500 hover:text-gray-700 hover:bg-white rounded-lg p-2 transition-all">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto">
                    <a href="dashboard.php" class="nav-item flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gradient-to-r hover:from-indigo-50 hover:to-purple-50 hover:text-indigo-700 rounded-xl transition-all group">
                        <span>Dashboard</span>
                    </a>
                    <a href="all_expenses.php" class="nav-item flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gradient-to-r hover:from-indigo-50 hover:to-purple-50 hover:text-indigo-700 rounded-xl transition-all group">
                        <span>All Expenses</span>
                    </a>
                    <a href="add_expense.php" class="nav-item flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gradient-to-r hover:from-indigo-50 hover:to-purple-50 hover:text-indigo-700 rounded-xl transition-all group">
                        <span>Add Expense</span>
                    </a>
                    <a href="categories.php" class="nav-item flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gradient-to-r hover:from-indigo-50 hover:to-purple-50 hover:text-indigo-700 rounded-xl transition-all group">
                        <span>Categories</span>
                    </a>
                    <a href="budget_settings.php" class="nav-item flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gradient-to-r hover:from-indigo-50 hover:to-purple-50 hover:text-indigo-700 rounded-xl transition-all group">
                        <span>Budget Settings</span>
                    </a>
                    <a href="notifications.php" class="nav-item flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gradient-to-r hover:from-indigo-50 hover:to-purple-50 hover:text-indigo-700 rounded-xl transition-all group">
                        <span>Notifications</span>
                    </a>
                    <a href="reports.php" class="nav-item flex items-center gap-3 px-4 py-3 text-sm font-medium text-white bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl shadow-lg">
                        <span>Reports & Analytics</span>
                    </a>
                    <a href="profile.php" class="nav-item flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gradient-to-r hover:from-indigo-50 hover:to-purple-50 hover:text-indigo-700 rounded-xl transition-all group">
                        <span>Profile Settings</span>
                    </a>
                    <a href="linked_accounts.php" class="nav-item flex items-center gap-3 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gradient-to-r hover:from-indigo-50 hover:to-purple-50 hover:text-indigo-700 rounded-xl transition-all group">
                        <span>Linked Accounts</span>
                    </a>
                    
                    <div class="pt-4 pb-2">
                        <div class="border-t border-gray-200"></div>
                    </div>
                    
                    <a href="logout.php" class="nav-item flex items-center gap-3 px-4 py-3 text-sm font-medium text-red-600 hover:bg-red-50 rounded-xl transition-all group">
                        <span>Logout</span>
                    </a>
                </nav>
                
                <div class="p-4 border-t border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                    <div class="flex items-center gap-3 p-3 bg-white rounded-xl shadow-sm">
                        <div class="w-12 h-12 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold shadow-md">
                            <?php echo $user_initial; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($user_name); ?></p>
                            <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($user_email); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 lg:ml-64">
            <!-- Mobile Header -->
            <header class="lg:hidden sticky top-0 z-20 bg-white border-b border-gray-200 shadow-sm">
                <div class="px-4 py-4">
                    <div class="flex items-center justify-between">
                        <button id="openSidebar" class="text-gray-700 hover:text-indigo-600 hover:bg-indigo-50 p-2 rounded-lg transition-all">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                            </svg>
                        </button>
                        <div class="flex items-center gap-2">
                            <div class="w-9 h-9 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-lg flex items-center justify-center shadow-md">
                                <span class="text-white text-sm font-bold">F</span>
                            </div>
                            <div class="flex flex-col">
                                <h2 class="text-base font-bold text-gray-900 leading-tight">Reports</h2>
                                <p class="text-xs text-gray-500">Analytics</p>
                            </div>
                        </div>
                        <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-sm shadow-md">
                            <?php echo $user_initial; ?>
                        </div>
                    </div>
                </div>
            </header>

            <div class="p-4 md:p-6 lg:p-8">
                <!-- Page Header -->
                <div class="mb-6 md:mb-8">
                    <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-2">Reports & Analytics</h1>
                    <p class="text-gray-600">Comprehensive insights into your spending patterns</p>
                </div>

                <!-- Currently Viewing Period Display -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 md:p-5 mb-6">
                    <div class="flex items-center justify-between">
                        <div class="flex-1">
                            <p class="text-xs text-gray-500 mb-1">Currently Viewing</p>
                            <p class="text-base md:text-lg font-bold text-gray-900">
                                <span class="text-indigo-600"><?php echo htmlspecialchars($period_name); ?></span>
                            </p>
                            <p class="text-xs md:text-sm text-gray-500 mt-1">
                                <?php echo date('M d, Y', strtotime($start_date)); ?> - <?php echo date('M d, Y', strtotime($end_date)); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-6 md:mb-8">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-purple-700 rounded-xl flex items-center justify-center text-2xl">
                                💸
                            </div>
                        </div>
                        <h3 class="text-2xl md:text-3xl font-bold text-gray-900 mb-1">₱<?php echo number_format($total_expenses, 2); ?></h3>
                        <p class="text-sm text-gray-600">Total Spending</p>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-700 rounded-xl flex items-center justify-center text-2xl">
                                📝
                            </div>
                        </div>
                        <h3 class="text-2xl md:text-3xl font-bold text-gray-900 mb-1"><?php echo $transaction_count; ?></h3>
                        <p class="text-sm text-gray-600">Total Transactions</p>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-700 rounded-xl flex items-center justify-center text-2xl">
                                📊
                            </div> 
                        </div>
                        <h3 class="text-2xl md:text-3xl font-bold text-gray-900 mb-1">₱<?php echo number_format($avg_daily_spending, 2); ?></h3>
                        <p class="text-sm text-gray-600">Avg Daily Spending</p>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-gradient-to-br from-orange-500 to-orange-700 rounded-xl flex items-center justify-center text-2xl">
                                🏷️
                            </div>
                        </div>
                        <h3 class="text-2xl md:text-3xl font-bold text-gray-900 mb-1"><?php echo count($categories); ?>
                        <h3>
                        <p class="text-sm text-gray-600">Active Categories</p>
                    </div>
                </div>

                <!-- Period Filter Buttons -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 md:p-6 mb-6 md:mb-8">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Filter by Period</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:flex lg:flex-wrap gap-3">
                        <a href="?period=week" class="filter-btn px-4 py-2.5 text-sm font-medium rounded-lg text-center <?php echo $filter_period === 'week' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            Last 7 Days
                        </a>
                        <a href="?period=month" class="filter-btn px-4 py-2.5 text-sm font-medium rounded-lg text-center <?php echo $filter_period === 'month' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            This Month
                        </a>
                        <a href="?period=quarter" class="filter-btn px-4 py-2.5 text-sm font-medium rounded-lg text-center <?php echo $filter_period === 'quarter' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            This Quarter
                        </a>
                        <a href="?period=year" class="filter-btn px-4 py-2.5 text-sm font-medium rounded-lg text-center <?php echo $filter_period === 'year' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            This Year
                        </a>
                        <button onclick="toggleCustomDatePicker()" class="filter-btn px-4 py-2.5 text-sm font-medium rounded-lg text-center <?php echo $filter_period === 'custom' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                            Custom Range
                        </button>
                    </div>

                    <!-- Custom Date Picker -->
                    <div id="customDatePicker" class="mt-4 p-4 bg-gray-50 rounded-lg <?php echo $filter_period !== 'custom' ? 'hidden' : ''; ?>">
                        <form method="GET" action="" class="flex flex-col sm:flex-row gap-3">
                            <input type="hidden" name="period" value="custom">
                            <div class="flex-1">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                                <input type="date" name="start_date" value="<?php echo $custom_start; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            <div class="flex-1">
                                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                                <input type="date" name="end_date" value="<?php echo $custom_end; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="w-full sm:w-auto px-6 py-2 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                                    Apply
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Charts Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 md:gap-8 mb-6 md:mb-8">
                    <!-- Category Breakdown Chart -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Category Breakdown</h3>
                        <?php if (!empty($categories)): ?>
                            <div class="chart-container">
                                <canvas id="categoryChart"></canvas>
                            </div>
                        <?php else: ?>
                            <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                                <svg class="w-16 h-16 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                </svg>
                                <p class="text-lg font-medium">No data available</p>
                                <p class="text-sm">Add expenses to see category breakdown</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Growth Comparison Chart -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Growth Comparison</h3>
                        <div class="chart-container">
                            <canvas id="growthChart"></canvas>
                        </div>
                    </div>
                </div>



</div>
            <!-- End Charts Grid - Row 1 -->

            <!-- Charts Grid - Row 2 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 md:gap-8 mb-6 md:mb-8">
                <!-- Monthly Detailed Report -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
                        <h3 class="text-lg font-semibold text-gray-900">Monthly Detailed Report</h3>
                        <form method="GET" action="" class="w-full sm:w-auto">
                            <?php if (isset($_GET['period'])): ?>
                                <input type="hidden" name="period" value="<?php echo htmlspecialchars($_GET['period']); ?>">
                            <?php endif; ?>
                            <?php if (isset($_GET['start_date'])): ?>
                                <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($_GET['start_date']); ?>">
                            <?php endif; ?>
                            <?php if (isset($_GET['end_date'])): ?>
                                <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($_GET['end_date']); ?>">
                            <?php endif; ?>
                            <select name="selected_month" onchange="this.form.submit()" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <?php
                                // Generate months for the past 12 months
                                for ($i = 0; $i < 12; $i++) {
                                    $month_value = date('Y-m', strtotime("-$i months"));
                                    $month_label = date('F Y', strtotime("-$i months"));
                                    $selected = ($month_value === $selected_month) ? 'selected' : '';
                                    echo "<option value='$month_value' $selected>$month_label</option>";
                                }
                                ?>
                            </select>
                        </form>
                    </div>
                    <div class="mb-4">
                        <p class="text-sm text-gray-600">Total for <?php echo date('F Y', strtotime($selected_month)); ?>: <span class="font-bold text-gray-900">₱<?php echo number_format($selected_month_total, 2); ?></span></p>
                    </div>
                    <div class="chart-container">
                        <canvas id="monthlyDetailChart"></canvas>
                    </div>
                </div>

                <!-- Year-over-Year Analysis -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
                        <h3 class="text-lg font-semibold text-gray-900">Year-over-Year Analysis</h3>
                        <form method="GET" action="" class="w-full sm:w-auto">
                            <?php if (isset($_GET['period'])): ?>
                                <input type="hidden" name="period" value="<?php echo htmlspecialchars($_GET['period']); ?>">
                            <?php endif; ?>
                            <?php if (isset($_GET['start_date'])): ?>
                                <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($_GET['start_date']); ?>">
                            <?php endif; ?>
                            <?php if (isset($_GET['end_date'])): ?>
                                <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($_GET['end_date']); ?>">
                            <?php endif; ?>
                            <?php if (isset($_GET['selected_month'])): ?>
                                <input type="hidden" name="selected_month" value="<?php echo htmlspecialchars($_GET['selected_month']); ?>">
                            <?php endif; ?>
                            <select name="selected_year" onchange="this.form.submit()" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <?php
                                $current_year = date('Y');
                                for ($year = $current_year; $year >= $current_year - 5; $year--) {
                                    $selected = ($year == $selected_year) ? 'selected' : '';
                                    echo "<option value='$year' $selected>$year</option>";
                                }
                                ?>
                            </select>
                        </form>
                    </div>
                    <div class="chart-container">
                        <canvas id="yearlyChart"></canvas>
                    </div>
                </div>
            </div>
            <!-- End Charts Grid - Row 2 -->
                <!-- Charts Grid - Row 3 -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 md:gap-8 mb-6 md:mb-8">
    <!-- Expenses by Category (Donut Chart) -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Expenses by Category</h3>
        <?php if (!empty($categories)): ?>
            <div class="chart-container">
                <canvas id="expensesCategoryChart"></canvas>
            </div>
        <?php else: ?>
            <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                <svg class="w-16 h-16 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path>
                </svg>
                <p class="text-lg font-medium">No data available</p>
                <p class="text-sm">Add expenses to see distribution</p>
            </div>
        <?php endif; ?>
    </div>


                <!-- Category Details Table -->
                <?php if (!empty($categories)): ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Category Details</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="text-left py-3 px-4 text-sm font-semibold text-gray-700">Category</th>
                                    <th class="text-right py-3 px-4 text-sm font-semibold text-gray-700">Total</th>
                                    <th class="text-right py-3 px-4 text-sm font-semibold text-gray-700">Count</th>
                                    <th class="text-right py-3 px-4 text-sm font-semibold text-gray-700">Avg Amount</th>
                                    <th class="text-right py-3 px-4 text-sm font-semibold text-gray-700">% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $category): 
                                    $percentage = ($total_expenses > 0) ? ($category['total'] / $total_expenses * 100) : 0;
                                ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50">
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-2">
                                            <div class="w-3 h-3 rounded-full" style="background-color: <?php echo htmlspecialchars($category['color']); ?>"></div>
                                            <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($category['name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-right text-sm text-gray-900 font-medium">₱<?php echo number_format($category['total'], 2); ?></td>
                                    <td class="py-3 px-4 text-right text-sm text-gray-600"><?php echo $category['count']; ?></td>
                                    <td class="py-3 px-4 text-right text-sm text-gray-600">₱<?php echo number_format($category['avg_amount'], 2); ?></td>
                                    <td class="py-3 px-4 text-right text-sm text-gray-600"><?php echo number_format($percentage, 1); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
    <script>
        // Mobile menu toggle
        const openSidebar = document.getElementById('openSidebar');
        const closeSidebar = document.getElementById('closeSidebar');
        const mobileSidebar = document.getElementById('mobileSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function showSidebar() {
            mobileSidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function hideSidebar() {
            mobileSidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
            document.body.style.overflow = '';
        }

        openSidebar?.addEventListener('click', showSidebar);
        closeSidebar?.addEventListener('click', hideSidebar);
        sidebarOverlay?.addEventListener('click', hideSidebar);

        // Custom date picker toggle
        function toggleCustomDatePicker() {
            const picker = document.getElementById('customDatePicker');
            picker.classList.toggle('hidden');
        }

        // Chart configurations
        const chartColors = {
            primary: '#4F46E5',
            secondary: '#7C3AED',
            success: '#10B981',
            danger: '#EF4444',
            warning: '#F59E0B',
            info: '#3B82F6'
        };

        // Category Breakdown Chart (Vertical Bar Chart)
        <?php if (!empty($categories)): ?>
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        const categoryChart = new Chart(categoryCtx, {
            type: 'bar',
            data: {
                labels: <?php echo $chart_categories; ?>,
                datasets: [{
                    label: 'Spending',
                    data: <?php echo $chart_amounts; ?>,
                    backgroundColor: <?php echo $chart_colors; ?>,
                    borderRadius: 8,
                    barThickness: 40
                }]
            },
            options: {
                indexAxis: 'x',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '₱' + context.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString('en-PH');
                            }
                        },
                        grid: {
                            display: true,
                            color: '#F3F4F6'
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
        <?php endif; ?>

        // Growth Comparison Chart (Lollipop-style)
        const growthCtx = document.getElementById('growthChart').getContext('2d');
        const currentMonth = '<?php echo date('F Y'); ?>';
        const previousMonth = '<?php echo date('F Y', strtotime('-1 month')); ?>';
        const currentTotal = <?php echo $current_month_total; ?>;
        const previousTotal = <?php echo $previous_month_total; ?>;
        const growth = <?php echo $growth; ?>;
        const isIncrease = <?php echo $is_increase ? 'true' : 'false'; ?>;

        const growthChart = new Chart(growthCtx, {
            type: 'bar',
            data: {
                labels: [previousMonth, currentMonth],
                datasets: [{
                    label: 'Spending',
                    data: [previousTotal, currentTotal],
                    backgroundColor: [
                        'rgba(148, 163, 184, 0.8)',
                        isIncrease ? 'rgba(239, 68, 68, 0.8)' : 'rgba(16, 185, 129, 0.8)'
                    ],
                    borderRadius: 8,
                    barThickness: 60
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
                        callbacks: {
                            label: function(context) {
                                return '₱' + context.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2});
                            }
                        }
                    },
                    title: {
                        display: true,
                        text: (isIncrease ? '↑ ' : '↓ ') + Math.abs(growth).toFixed(1) + '% ' + (isIncrease ? 'increase' : 'decrease'),
                        color: isIncrease ? '#EF4444' : '#10B981',
                        font: {
                            size: 16,
                            weight: 'bold'
                        },
                        padding: {
                            bottom: 20
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString('en-PH');
                            }
                        },
                        grid: {
                            display: true,
                            color: '#F3F4F6'
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

        // Monthly Detailed Report Chart (Vertical Bar Chart)
        const monthlyDetailCtx = document.getElementById('monthlyDetailChart').getContext('2d');
        const selectedMonthData = <?php echo json_encode($selected_month_data); ?>;
        
        // Generate all days for the selected month
        const selectedMonthStart = new Date('<?php echo $selected_month_start; ?>');
        const selectedMonthEnd = new Date('<?php echo $selected_month_end; ?>');
        const daysInMonth = [];
        const monthlyDetailAmounts = [];
        
        for (let d = new Date(selectedMonthStart); d <= selectedMonthEnd; d.setDate(d.getDate() + 1)) {
            const dateStr = d.toISOString().split('T')[0];
            daysInMonth.push(d.getDate());
            
            const dataPoint = selectedMonthData.find(item => item.day === dateStr);
            monthlyDetailAmounts.push(dataPoint ? parseFloat(dataPoint.total) : 0);
        }

        const monthlyDetailChart = new Chart(monthlyDetailCtx, {
            type: 'bar',
            data: {
                labels: daysInMonth,
                datasets: [{
                    label: 'Daily Spending',
                    data: monthlyDetailAmounts,
                    backgroundColor: 'rgba(99, 102, 241, 0.8)',
                    borderRadius: 6,
                    barThickness: 'flex',
                    maxBarThickness: 30
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
                        callbacks: {
                            label: function(context) {
                                return '₱' + context.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2});
                            },
                            title: function(context) {
                                return 'Day ' + context[0].label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString('en-PH');
                            }
                        },
                        grid: {
                            display: true,
                            color: '#F3F4F6'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 15
                        }
                    }
                }
            }
        });

        // Year-over-Year Analysis Chart (Vertical Bar Chart)
        const yearlyCtx = document.getElementById('yearlyChart').getContext('2d');
        const fullYearData = <?php echo json_encode(array_values($full_year_data)); ?>;
        const monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        const yearlyChart = new Chart(yearlyCtx, {
            type: 'bar',
            data: {
                labels: monthLabels,
                datasets: [{
                    label: '<?php echo $selected_year; ?> Spending',
                    data: fullYearData,
                    backgroundColor: 'rgba(124, 58, 237, 0.8)',
                    borderRadius: 8,
                    barThickness: 40
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '₱' + context.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString('en-PH');
                            }
                        },
                        grid: {
                            display: true,
                            color: '#F3F4F6'
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
        // Expenses by Category Chart (Donut Chart)
<?php if (!empty($categories)): ?>
const expensesCategoryCtx = document.getElementById('expensesCategoryChart').getContext('2d');
const expensesCategoryChart = new Chart(expensesCategoryCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo $chart_categories; ?>,
        datasets: [{
            data: <?php echo $chart_amounts; ?>,
            backgroundColor: <?php echo $chart_colors; ?>,
            borderWidth: 2,
            borderColor: '#ffffff',
            hoverOffset: 15
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'bottom',
                labels: {
                    padding: 15,
                    usePointStyle: true,
                    font: {
                        size: 12
                    }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.parsed || 0;
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((value / total) * 100).toFixed(1);
                        return label + ': ₱' + value.toLocaleString('en-PH', {minimumFractionDigits: 2}) + ' (' + percentage + '%)';
                    }
                }
            }
        },
        cutout: '65%'
    }
});
<?php endif; ?>
    </script>
</body>
</html>