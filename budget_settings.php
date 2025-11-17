<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/auth_user.php';
require_once 'includes/functions.php';

$user_id = $_SESSION['user_id'];
$page_title = "Budget Settings";
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_monthly_budget'])) {
        $monthly_budget = filter_var($_POST['monthly_budget'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        
        if ($monthly_budget > 0) {
            $stmt = $conn->prepare("UPDATE users SET monthly_budget = ? WHERE id = ?");
            $stmt->bind_param("di", $monthly_budget, $user_id);
            
            if ($stmt->execute()) {
                $success_message = "Monthly budget updated successfully!";
            } else {
                $error_message = "Failed to update monthly budget.";
            }
        } else {
            $error_message = "Please enter a valid budget amount.";
        }
    }
    
    // Add/Update Category Budget
    if (isset($_POST['save_category_budget'])) {
        $category_id = filter_var($_POST['category_id'], FILTER_SANITIZE_NUMBER_INT);
        $category_budget = filter_var($_POST['category_budget'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        
        if ($category_budget >= 0) {
            // Check if category budget exists
            $check_stmt = $conn->prepare("SELECT id FROM category_budgets WHERE user_id = ? AND category_id = ?");
            $check_stmt->bind_param("ii", $user_id, $category_id);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->num_rows > 0;
            
            if ($exists) {
                $stmt = $conn->prepare("UPDATE category_budgets SET budget_limit = ?, updated_at = NOW() WHERE user_id = ? AND category_id = ?");
                $stmt->bind_param("dii", $category_budget, $user_id, $category_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO category_budgets (user_id, category_id, budget_limit) VALUES (?, ?, ?)");
                $stmt->bind_param("iid", $user_id, $category_id, $category_budget);
            }
            
            if ($stmt->execute()) {
                $success_message = "Category budget saved successfully!";
            } else {
                $error_message = "Failed to save category budget.";
            }
        }
    }
    
    // Delete Category Budget
    if (isset($_POST['delete_category_budget'])) {
        $category_id = filter_var($_POST['category_id'], FILTER_SANITIZE_NUMBER_INT);
        
        $stmt = $conn->prepare("DELETE FROM category_budgets WHERE user_id = ? AND category_id = ?");
        $stmt->bind_param("ii", $user_id, $category_id);
        
        if ($stmt->execute()) {
            $success_message = "Category budget removed successfully!";
        } else {
            $error_message = "Failed to remove category budget.";
        }
    }
    
    // Update Alert Settings
    if (isset($_POST['update_alert_settings'])) {
        $budget_alert_threshold = filter_var($_POST['budget_alert_threshold'], FILTER_SANITIZE_NUMBER_INT);
        $email_alerts = isset($_POST['email_alerts']) ? 1 : 0;
        $weekly_report = isset($_POST['weekly_report']) ? 1 : 0;
        $monthly_report = isset($_POST['monthly_report']) ? 1 : 0;
        
        $stmt = $conn->prepare("UPDATE users SET budget_alert_threshold = ?, email_alerts = ?, weekly_report = ?, monthly_report = ? WHERE id = ?");
        $stmt->bind_param("iiiii", $budget_alert_threshold, $email_alerts, $weekly_report, $monthly_report, $user_id);
        
        if ($stmt->execute()) {
            $success_message = "Alert settings updated successfully!";
        } else {
            $error_message = "Failed to update alert settings.";
        }
    }
}

// Fetch user data
$user_query = "SELECT name, email, monthly_budget, budget_alert_threshold, email_alerts, weekly_report, monthly_report FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$user_name = $user['name'] ?? 'User';
$user_email = $user['email'] ?? '';
$user_initial = strtoupper(substr($user_name, 0, 1));

// Get current month expenses
$current_month = date('m');
$current_year = date('Y');
$expense_query = "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE user_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?";
$stmt = $conn->prepare($expense_query);
$stmt->bind_param("iii", $user_id, $current_month, $current_year);
$stmt->execute();
$total_expenses = $stmt->get_result()->fetch_assoc()['total'];

// Get categories with budgets and spending
$categories_query = "
    SELECT 
        c.id,
        c.name,
        c.color,
        cb.budget_limit,
        COALESCE(SUM(e.amount), 0) as spent
    FROM categories c
    LEFT JOIN category_budgets cb ON c.id = cb.category_id AND cb.user_id = ?
    LEFT JOIN expenses e ON c.id = e.category_id AND e.user_id = ? 
        AND MONTH(e.expense_date) = ? AND YEAR(e.expense_date) = ?
    GROUP BY c.id, c.name, c.color, cb.budget_limit
    ORDER BY c.name ASC
";
$stmt = $conn->prepare($categories_query);
$stmt->bind_param("iiii", $user_id, $user_id, $current_month, $current_year);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$monthly_budget = $user['monthly_budget'] ?? 0;
$budget_remaining = $monthly_budget - $total_expenses;
$percentage_spent = $monthly_budget > 0 ? ($total_expenses / $monthly_budget) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - FinSight</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        * { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <!-- Desktop Sidebar -->
        <aside class="hidden lg:flex lg:flex-col lg:w-64 bg-white border-r border-gray-200 fixed h-full z-30">
            <div class="flex items-center gap-3 px-6 py-5 border-b border-gray-200">
                <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center">
                    <span class="text-white text-xl font-bold">F</span>
                </div>
                <h2 class="text-xl font-bold text-gray-900">FinSight</h2>
            </div>
            
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Dashboard</span>
                </a>
                <a href="all_expenses.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>All Expenses</span>
                </a>
                <a href="add_expense.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Add Expense</span>
                </a>
                <a href="categories.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Categories</span>
                </a>
                <a href="budget_settings.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg">
                    <span class="text-lg"></span>
                    <span>Budget Settings</span>
                </a>
                <a href="notifications.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Notifications</span>
                </a>
                <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Reports & Analytics</span>
                </a>
                <a href="profile.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Profile</span>
                </a>
                <a href="linked_accounts.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Linked Accounts</span>
                </a>
                <a href="logout.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                    <span class="text-lg"></span>
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
        <div id="sidebarOverlay" class="hidden fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden"></div>

        <!-- Mobile Sidebar -->
        <aside id="mobileSidebar" class="fixed top-0 left-0 h-full w-64 bg-white z-50 transform -translate-x-full transition-transform duration-300 ease-in-out lg:hidden">
            <div class="flex items-center justify-between px-6 py-5 border-b border-gray-200">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center">
                        <span class="text-white text-xl font-bold">F</span>
                    </div>
                    <h2 class="text-xl font-bold text-gray-900">FinSight</h2>
                </div>
                <button id="closeSidebar" class="text-gray-500 hover:text-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto h-[calc(100vh-180px)]">
                <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Dashboard</span>
                </a>
                <a href="all_expenses.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>All Expenses</span>
                </a>
                <a href="add_expense.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Add Expense</span>
                </a>
                <a href="categories.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Categories</span>
                </a>
                <a href="budget_settings.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg">
                    <span class="text-lg"></span>
                    <span>Budget Settings</span>
                </a>
                <a href="notifications.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Notifications</span>
                </a>
                <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Reports & Analytics</span>
                </a>
                <a href="profile.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Profile</span>
                </a>
                <a href="linked_accounts.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg"></span>
                    <span>Linked Accounts</span>
                </a>
                <a href="logout.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                    <span class="text-lg"></span>
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

        <!-- Main Content -->
        <main class="flex-1 lg:ml-64">
            <!-- Mobile Header -->
            <header class="lg:hidden sticky top-0 z-20 bg-white border-b border-gray-200 px-4 py-3">
                <div class="flex items-center justify-between">
                    <button id="openSidebar" class="text-gray-700 hover:text-gray-900">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                    <h2 class="text-lg font-bold text-gray-900">Budget Settings</h2>
                    <div class="w-6"></div>
                </div>
            </header>

            <div class="p-4 md:p-6 lg:p-8 max-w-6xl mx-auto">
                <!-- Page Header -->
                <div class="mb-6 md:mb-8">
                    <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-2"> Budget Settings</h1>
                    <p class="text-gray-600">Manage your monthly budget, category limits, and spending alerts</p>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                <div class="mb-6 bg-green-50 border border-green-200 rounded-xl p-4 flex items-center gap-3">
                    <span class="text-2xl">✅</span>
                    <p class="text-green-800 font-medium"><?php echo htmlspecialchars($success_message); ?></p>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-center gap-3">
                    <span class="text-2xl">❌</span>
                    <p class="text-red-800 font-medium"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
                <?php endif; ?>

                <!-- Current Budget Overview -->
                <div class="bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl shadow-lg p-6 md:p-8 text-white mb-6">
                    <h2 class="text-xl font-bold mb-6">Current Budget Status</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <p class="text-indigo-100 text-sm mb-2">Monthly Budget</p>
                            <p class="text-3xl font-bold">₱<?php echo number_format($monthly_budget, 2); ?></p>
                        </div>
                        <div>
                            <p class="text-indigo-100 text-sm mb-2">Spent This Month</p>
                            <p class="text-3xl font-bold">₱<?php echo number_format($total_expenses, 2); ?></p>
                        </div>
                        <div>
                            <p class="text-indigo-100 text-sm mb-2">Remaining</p>
                            <p class="text-3xl font-bold">₱<?php echo number_format($budget_remaining, 2); ?></p>
                        </div>
                    </div>
                    <div class="mt-6">
                        <div class="flex justify-between text-sm mb-2">
                            <span>Budget Usage</span>
                            <span><?php echo number_format($percentage_spent, 1); ?>%</span>
                        </div>
                        <div class="w-full bg-white/20 rounded-full h-3 overflow-hidden">
                            <div class="bg-white h-3 rounded-full transition-all duration-500" 
                                 style="width: <?php echo min($percentage_spent, 100); ?>%"></div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Monthly Budget Settings -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div class="flex items-center gap-3 mb-6">
                            
                            <div>
                                <h2 class="text-xl font-bold text-gray-900">Monthly Budget</h2>
                                <p class="text-sm text-gray-600">Set your total monthly spending limit</p>
                            </div>
                        </div>

                        <form method="POST" action="" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Budget Amount (₱)
                                </label>
                                <input type="number" 
                                       name="monthly_budget" 
                                       step="0.01" 
                                       min="0"
                                       value="<?php echo $monthly_budget; ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-600 focus:border-transparent"
                                       required>
                            </div>

                            <button type="submit" 
                                    name="update_monthly_budget"
                                    class="w-full px-6 py-3 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                                Update Monthly Budget
                            </button>
                        </form>
                    </div>

                    <!-- Alert Settings -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="w-12 h-12 bg-gradient-to-br from-amber-500 to-amber-700 rounded-xl flex items-center justify-center text-2xl">
                                🔔
                            </div>
                            <div>
                                <h2 class="text-xl font-bold text-gray-900">Alert Settings</h2>
                                <p class="text-sm text-gray-600">Configure your spending notifications</p>
                            </div>
                        </div>

                        <form method="POST" action="" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Alert Threshold (%)
                                </label>
                                <select name="budget_alert_threshold" 
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-600 focus:border-transparent">
                                    <option value="50" <?php echo ($user['budget_alert_threshold'] ?? 80) == 50 ? 'selected' : ''; ?>>50% of budget</option>
                                    <option value="60" <?php echo ($user['budget_alert_threshold'] ?? 80) == 60 ? 'selected' : ''; ?>>60% of budget</option>
                                    <option value="70" <?php echo ($user['budget_alert_threshold'] ?? 80) == 70 ? 'selected' : ''; ?>>70% of budget</option>
                                    <option value="80" <?php echo ($user['budget_alert_threshold'] ?? 80) == 80 ? 'selected' : ''; ?>>80% of budget</option>
                                    <option value="90" <?php echo ($user['budget_alert_threshold'] ?? 80) == 90 ? 'selected' : ''; ?>>90% of budget</option>
                                    <option value="95" <?php echo ($user['budget_alert_threshold'] ?? 80) == 95 ? 'selected' : ''; ?>>95% of budget</option>
                                </select>
                            </div>

                            <div class="space-y-3">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" 
                                           name="email_alerts" 
                                           <?php echo ($user['email_alerts'] ?? 1) ? 'checked' : ''; ?>
                                           class="w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-600">
                                    <span class="text-sm text-gray-700">Send email alerts for budget warnings</span>
                                </label>

                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" 
                                           name="weekly_report" 
                                           <?php echo ($user['weekly_report'] ?? 0) ? 'checked' : ''; ?>
                                           class="w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-600">
                                    <span class="text-sm text-gray-700">Receive weekly spending summary</span>
                                </label>

                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" 
                                           name="monthly_report" 
                                           <?php echo ($user['monthly_report'] ?? 1) ? 'checked' : ''; ?>
                                           class="w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-600">
                                    <span class="text-sm text-gray-700">Receive monthly financial report</span>
                                </label>
                            </div>

                            <button type="submit" 
                                    name="update_alert_settings"
                                    class="w-full px-6 py-3 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                                Save Alert Settings
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Category Budgets -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mt-6">
                    <div class="flex items-center gap-3 mb-6">
                        <div>
                            <h2 class="text-xl font-bold text-gray-900">Category Budgets</h2>
                            <p class="text-sm text-gray-600">Set spending limits for individual categories</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <?php foreach ($categories as $category): ?>
                        <div class="border border-gray-200 rounded-lg p-4 hover:border-indigo-300 transition-colors">
                            <form method="POST" action="" class="space-y-3">
                                <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" 
                                         style="background-color: <?php echo htmlspecialchars($category['color']); ?>20;">
                                        <div class="w-4 h-4 rounded-full" 
                                             style="background-color: <?php echo htmlspecialchars($category['color']); ?>;"></div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-semibold text-gray-900"><?php echo htmlspecialchars($category['name']); ?></h3>
                                        <?php if ($category['budget_limit']): ?>
                                            <p class="text-sm text-gray-600">
                                                ₱<?php echo number_format($category['spent'], 2); ?> / ₱<?php echo number_format($category['budget_limit'], 2); ?>
                                                <span class="text-xs <?php echo ($category['spent'] / $category['budget_limit'] * 100) > 90 ? 'text-red-600' : 'text-gray-500'; ?>">
                                                    (<?php echo number_format(($category['spent'] / $category['budget_limit']) * 100, 1); ?>%)
                                                </span>
                                            </p>
                                        <?php else: ?>
                                            <p class="text-sm text-gray-500">No budget set</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($category['budget_limit']): ?>
                                <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                                    <?php 
                                    $category_percent = $category['budget_limit'] > 0 ? ($category['spent'] / $category['budget_limit']) * 100 : 0;
                                    $bar_color = $category_percent > 90 ? 'bg-red-500' : ($category_percent > 70 ? 'bg-amber-500' : 'bg-green-500');
                                    ?>
                                    <div class="<?php echo $bar_color; ?> h-2 rounded-full transition-all duration-500" 
                                         style="width: <?php echo min($category_percent, 100); ?>%"></div>
                                </div>
                                <?php endif; ?>

                                <div class="flex gap-2">
                                    <input type="number" 
                                           name="category_budget" 
                                           step="0.01" 
                                           min="0"
                                           value="<?php echo $category['budget_limit'] ?? ''; ?>"
                                           placeholder="Enter budget amount"
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-600 focus:border-transparent text-sm">
                                    
                                    <button type="submit" 
                                            name="save_category_budget"
                                            class="px-4 py-2 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 transition-colors text-sm">
                                        Save
                                    </button>

                                    <?php if ($category['budget_limit']): ?>
                                    <button type="submit" 
                                            name="delete_category_budget"
                                            class="px-4 py-2 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 transition-colors text-sm">
                                        Remove
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Mobile sidebar toggle
        const openSidebar = document.getElementById('openSidebar');
        const closeSidebar = document.getElementById('closeSidebar');
        const mobileSidebar = document.getElementById('mobileSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        openSidebar?.addEventListener('click', () => {
            mobileSidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
        });

        closeSidebar?.addEventListener('click', () => {
            mobileSidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        });

        sidebarOverlay?.addEventListener('click', () => {
            mobileSidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        });
    </script>
</body>
</html>