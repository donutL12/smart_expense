<?php



session_start();
require_once 'includes/db_connect.php';
require_once 'includes/auth_user.php';


$user_id = $_SESSION['user_id'];

// Fetch user data with error handling
$user_query = "SELECT name, email, monthly_budget FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// If user not found, logout
if (!$user) {
    header("Location: logout.php");
    exit();
}

// Get current month and year
$current_month = date('m');
$current_year = date('Y');
$current_date = date('F j, Y');
$current_month_name = date('F Y');

// Calculate total expenses for current month
$expense_query = "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE user_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?";
$stmt = $conn->prepare($expense_query);
$stmt->bind_param("iii", $user_id, $current_month, $current_year);
$stmt->execute();
$expense_result = $stmt->get_result()->fetch_assoc();
$total_expenses = $expense_result['total'] ?? 0;

// Calculate previous month expenses for comparison
$prev_month = date('m', strtotime('-1 month'));
$prev_year = date('Y', strtotime('-1 month'));
$prev_expense_query = "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE user_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?";
$stmt = $conn->prepare($prev_expense_query);
$stmt->bind_param("iii", $user_id, $prev_month, $prev_year);
$stmt->execute();
$prev_expense_result = $stmt->get_result()->fetch_assoc();
$prev_total_expenses = $prev_expense_result['total'] ?? 0;

// Calculate budget metrics
$monthly_budget = $user['monthly_budget'] ?? 0;
$budget_remaining = $monthly_budget - $total_expenses;
$percentage_spent = $monthly_budget > 0 ? ($total_expenses / $monthly_budget) * 100 : 0;
$savings_rate = $monthly_budget > 0 ? (($monthly_budget - $total_expenses) / $monthly_budget) * 100 : 0;

// Count active categories (categories used this month)
$category_query = "SELECT COUNT(DISTINCT category_id) as count FROM expenses WHERE user_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?";
$stmt = $conn->prepare($category_query);
$stmt->bind_param("iii", $user_id, $current_month, $current_year);
$stmt->execute();
$category_count = $stmt->get_result()->fetch_assoc()['count'] ?? 0;

$linked_accounts_query = "SELECT COUNT(*) as count FROM linked_accounts WHERE user_id = ? AND status = 'active'";
$stmt = $conn->prepare($linked_accounts_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$linked_accounts_count = $stmt->get_result()->fetch_assoc()['count'] ?? 0;

// Get linked accounts summary
$accounts_summary_query = "SELECT la.*, b.name as bank_name, b.logo 
FROM linked_accounts la 
JOIN banks b ON la.bank_id = b.id 
WHERE la.user_id = ? AND la.status = 'active' 
ORDER BY la.last_synced DESC 
LIMIT 3";
$stmt = $conn->prepare($accounts_summary_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$linked_accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get expense breakdown by category for pie chart
$category_breakdown_query = "SELECT c.name AS category, c.color, SUM(e.amount) AS total, COUNT(e.id) as transaction_count
FROM expenses e
JOIN categories c ON e.category_id = c.id
WHERE e.user_id = ? AND MONTH(e.expense_date) = ? AND YEAR(e.expense_date) = ?
GROUP BY c.id, c.name, c.color
ORDER BY total DESC";
$stmt = $conn->prepare($category_breakdown_query);
$stmt->bind_param("iii", $user_id, $current_month, $current_year);
$stmt->execute();
$category_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get spending over time (daily for current month)
$daily_spending_query = "SELECT DATE(expense_date) as date, SUM(amount) as total
FROM expenses
WHERE user_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?
GROUP BY DATE(expense_date)
ORDER BY date ASC";
$stmt = $conn->prepare($daily_spending_query);
$stmt->bind_param("iii", $user_id, $current_month, $current_year);
$stmt->execute();
$daily_spending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get recent transactions (last 10)
$recent_transactions_query = "SELECT e.id, e.expense_date, e.description, e.amount, c.name as category, c.color
FROM expenses e
JOIN categories c ON e.category_id = c.id
WHERE e.user_id = ?
ORDER BY e.expense_date DESC, e.id DESC
LIMIT 10";
$stmt = $conn->prepare($recent_transactions_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// AI Insights Generation
$ai_insights = [];

// Insight 1: Month-over-month comparison
if ($prev_total_expenses > 0) {
    $expense_change = (($total_expenses - $prev_total_expenses) / $prev_total_expenses) * 100;
    if (abs($expense_change) > 5) {
        $change_text = $expense_change > 0 ? "increased" : "decreased";
        $ai_insights[] = "Your spending " . $change_text . " by " . abs(round($expense_change, 1)) . "% compared to last month.";
    }
}

// Insight 2: Top spending category
if (!empty($category_breakdown)) {
    $top_category = $category_breakdown[0];
    $category_percentage = $total_expenses > 0 ? ($top_category['total'] / $total_expenses) * 100 : 0;
    if ($category_percentage > 30) {
        $ai_insights[] = $top_category['category'] . " accounts for " . round($category_percentage, 1) . "% of your total expenses this month.";
    }
}

// Insight 3: Budget warning or praise
if ($percentage_spent > 90) {
    $ai_insights[] = "⚠️ Warning: You've used " . round($percentage_spent, 1) . "% of your monthly budget. Consider reducing spending.";
} elseif ($percentage_spent < 50 && $monthly_budget > 0) {
    $ai_insights[] = "✅ Great job! You're staying well within your budget with " . round(100 - $percentage_spent, 1) . "% remaining.";
}

// AI Recommendations
$ai_recommendations = [];

// Recommendation 1: Savings potential
if (!empty($category_breakdown) && $category_breakdown[0]['total'] > $monthly_budget * 0.3) {
    $potential_savings = $category_breakdown[0]['total'] * 0.1;
    $ai_recommendations[] = "You could save ₱" . number_format($potential_savings, 2) . " by reducing " . $category_breakdown[0]['category'] . " expenses by 10%.";
}

// Recommendation 2: Budget adjustment
if ($percentage_spent > 95 || $percentage_spent < 30) {
    $suggested_budget = $total_expenses * 1.15; // 15% buffer
    $ai_recommendations[] = "Based on your spending habits, consider adjusting your monthly budget to ₱" . number_format($suggested_budget, 2) . ".";
}

// Recommendation 3: Category-specific advice
if (count($category_breakdown) > 3) {
    $lowest_category = end($category_breakdown);
    if ($lowest_category['total'] < $total_expenses * 0.05) {
        $ai_recommendations[] = "You're doing well managing " . $lowest_category['category'] . " expenses. Keep it up!";
    }
}

// Get user initials safely
$user_name = $user['name'] ?? 'User';
$user_email = $user['email'] ?? $_SESSION['email'] ?? 'user@example.com';
$user_initial = strtoupper(substr($user_name, 0, 1));
$first_name = explode(' ', $user_name)[0];

// Check for budget alerts
$budget_alert = '';
if ($percentage_spent > 80 && $percentage_spent < 100) {
    $budget_alert = "You're approaching your monthly budget limit!";
} elseif ($percentage_spent >= 100) {
    $budget_alert = "You've exceeded your monthly budget!";
}

// Find category nearing budget (if you have per-category budgets)
if (!empty($category_breakdown)) {
    foreach ($category_breakdown as $cat) {
        $cat_percentage = $monthly_budget > 0 ? ($cat['total'] / $monthly_budget) * 100 : 0;
        if ($cat_percentage > 30) {
            $budget_alert = $budget_alert ?: "Your " . $cat['category'] . " spending is significant this month.";
            break;
        }
    }
}

// Prepare chart data as JSON
$chart_categories = json_encode(array_column($category_breakdown, 'category'));
$chart_amounts = json_encode(array_column($category_breakdown, 'total'));
$chart_colors = json_encode(array_column($category_breakdown, 'color'));

// Prepare daily spending chart data
$spending_dates = json_encode(array_column($daily_spending, 'date'));
$spending_amounts = json_encode(array_column($daily_spending, 'total'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - FinSight</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" type="text/css" href="assets/css/chatbot.css">
    <link rel="stylesheet" href="assets/css/language-switcher.css">
    <script src="assets/js/language-switcher.js" defer></script>
    <style>
   @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    
    * {
        font-family: 'Inter', sans-serif;
    }
    
    /* Custom scrollbar */
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

    /* Smooth transitions */
    .nav-item {
        transition: all 0.3s ease;
    }

    /* Chart container */
    .chart-container {
        position: relative;
        height: 300px;
    }

/* Mobile menu animation */
.mobile-menu {
    transition: transform 0.3s ease-in-out;
}

/* Overlay transition */
#sidebarOverlay {
    transition: opacity 0.3s ease-in-out;
}

#sidebarOverlay.show {
    opacity: 1;
}

/* Ensure mobile sidebar is hidden by default */
@media (max-width: 1023px) {
    #mobileSidebar {
        transform: translateX(-100%);
    }
    
    #mobileSidebar.show {
        transform: translateX(0);
    }
}

/* Prevent body scroll when sidebar is open */
body.sidebar-open {
    overflow: hidden;
}
/* Stats Grid - 2x2 on mobile, 3 columns on desktop, 6 columns on extra large screens */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr); /* 2 columns on mobile */
    gap: 1rem;
}

@media (min-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr); /* 3 columns on tablet */
        gap: 1.5rem;
    }
}

@media (min-width: 1280px) {
    .stats-grid {
        grid-template-columns: repeat(6, 1fr); /* 6 columns on large screens */
    }
}


    </style>
</head>
<body class="bg-gray-50">

    <div class="flex min-h-screen">
        <!-- Sidebar - Desktop -->
        <aside class="hidden lg:flex lg:flex-col lg:w-64 bg-white border-r border-gray-200 fixed h-full z-30">
            <!-- Logo -->
            <div class="flex items-center gap-3 px-6 py-5 border-b border-gray-200">
                <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center">
                    <span class="text-white text-xl font-bold">F</span>
                </div>
                <h2 class="text-xl font-bold text-gray-900">FinSight</h2>

            </div>
            
<!-- Navigation -->
<nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
    <a href="dashboard.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg">
        <span class="text-lg"></span>
        <span><?php echo t('nav.dashboard'); ?></span>
    </a>
    <a href="all_expenses.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span><?php echo t('nav.all_expenses'); ?></span>
    </a>
    <a href="add_expense.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span><?php echo t('nav.add_expense'); ?></span>
    </a>
    <a href="categories.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span><?php echo t('nav.categories'); ?></span>
    </a>
    <a href="budget_settings.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span><?php echo t('nav.budget_settings'); ?></span>
    </a>
    <a href="notifications.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span><?php echo t('nav.notifications'); ?></span>
    </a>
    <a href="reports.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span><?php echo t('nav.reports'); ?></span>
    </a>
    <a href="profile.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span><?php echo t('nav.profile'); ?></span>
    </a>
    <a href="linked_accounts.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span><?php echo t('nav.linked_accounts'); ?></span>
    </a>
    <a href="logout.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg">
        <span class="text-lg"></span>
        <span><?php echo t('auth.logout'); ?></span>
    </a>
</nav>
            
            <!-- User Info -->
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

        <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden opacity-0 pointer-events-none"></div>

        <!-- Mobile Sidebar -->
        <aside id="mobileSidebar" class="mobile-menu fixed inset-y-0 left-0 w-64 bg-white border-r border-gray-200 z-50 lg:hidden">
            <!-- Logo -->
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
            
<!-- Navigation - Mobile Sidebar -->
<nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto h-[calc(100vh-180px)]">
    <a href="dashboard.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg">
        <span class="text-lg"></span>
        <span>Dashboard</span>
    </a>
    <a href="all_expenses.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span>All Expenses</span>
    </a>
    <a href="add_expense.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span>Add Expense</span>
    </a>
    <a href="categories.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span>Categories</span>
    </a>
    <a href="budget_settings.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span>Budget Settings</span>
    </a>
    <a href="notifications.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span>Notifications</span>
    </a>
    <a href="reports.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span>Reports & Analytics</span>
    </a>
    <a href="profile.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span>Profile Settings</span>
    </a>
    <a href="linked_accounts.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span class="text-lg"></span>
        <span>Linked Accounts</span>
    </a>
    <a href="logout.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg">
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
                        <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($user['name']); ?></p>
                        <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($user['email']); ?></p>
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
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-lg flex items-center justify-center">
                            <span class="text-white text-sm font-bold">F</span>
                        </div>
                        <h2 class="text-lg font-bold text-gray-900">FinSight</h2>
                    </div>
                    <?php if ($budget_alert): ?>
<div class="relative">
    <a href="notifications.php" class="text-gray-700 hover:text-gray-900 inline-block">
        <span class="text-xl">🔔</span>
        <span class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 rounded-full text-xs text-white flex items-center justify-center">1</span>
    </a>
</div>
                    <?php else: ?>
                    <div class="w-6"></div>
                    <?php endif; ?>
                </div>
            </header>

            <div class="p-4 md:p-6 lg:p-8">
                <!-- Page Header -->
                <div class="mb-6 md:mb-8">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
<h1 class="text-2xl md:text-3xl font-bold text-gray-900"><?php echo t('dashboard.welcome_back', ['name' => htmlspecialchars($first_name)]); ?></h1>
<p class="text-gray-600 mt-1"><?php echo t('dashboard.subtitle'); ?></p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="flex items-center gap-2 px-4 py-2 bg-white rounded-lg border border-gray-200 shadow-sm">
                                <span class="text-lg"></span>
                                <span class="text-sm font-medium text-gray-700"><?php echo $current_date; ?></span>
                            </div>
                            <?php if ($budget_alert): ?>
<div class="hidden md:block relative">
    <a href="notifications.php" class="p-2 bg-white rounded-lg border border-gray-200 shadow-sm hover:bg-gray-50 transition-all inline-block relative" title="<?php echo htmlspecialchars($budget_alert); ?>">
        <span class="text-xl">🔔</span>
        <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 rounded-full text-xs text-white flex items-center justify-center font-semibold">1</span>
    </a>
</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($budget_alert): ?>
                <!-- Alert Banner -->
                <div class="mb-6 bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-start gap-3">
                    <span class="text-2xl">⚠️</span>
                    <p class="text-amber-800 font-medium flex-1"><?php echo htmlspecialchars($budget_alert); ?></p>
                </div>
                <?php endif; ?>

                <!-- Stats Grid -->
                <div class="stats-grid mb-6 md:mb-8">
                    <!-- Total Expenses -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                        <div class="flex items-center justify-between mb-4">
                            
                        </div>
<h3 class="text-2xl font-bold text-gray-900 mb-1">₱<?php echo number_format($total_expenses, 2); ?></h3>
<p class="text-sm text-gray-600"><?php echo t('dashboard.total_expenses'); ?></p>
<p class="text-xs text-gray-500 mt-1"><?php echo t('dashboard.this_month'); ?></p>
                    </div>

                    <!-- Monthly Budget -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                        <div class="flex items-center justify-between mb-4">
                        
                        </div>
 <h3 class="text-2xl font-bold text-gray-900 mb-1">₱<?php echo number_format($monthly_budget, 2); ?></h3>
<p class="text-sm text-gray-600"><?php echo t('dashboard.monthly_budget'); ?></p>
<p class="text-xs text-gray-500 mt-1"><?php echo t('dashboard.target'); ?></p>
                    </div>

                    <!-- Remaining Budget -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                        <div class="flex items-center justify-between mb-4">
                            
                        </div>
<h3 class="text-2xl font-bold text-gray-900 mb-1">₱<?php echo number_format($budget_remaining,2); ?></h3>
<p class="text-sm text-gray-600"><?php echo t('dashboard.remaining_budget'); ?></p>
<p class="text-xs text-gray-500 mt-1"><?php echo t('dashboard.available'); ?></p>
                    </div>

                    <!-- Active Categories -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                        <div class="flex items-center justify-between mb-4">
                            
                        </div>
<h3 class="text-2xl font-bold text-gray-900 mb-1"><?php echo $category_count; ?></h3>
<p class="text-sm text-gray-600"><?php echo t('dashboard.active_categories'); ?></p>
<p class="text-xs text-gray-500 mt-1"><?php echo t('dashboard.in_use'); ?></p>
                    </div>

                    <!-- Savings Rate -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                        <div class="flex items-center justify-between mb-4">
                           
                        </div>
<h3 class="text-2xl font-bold text-gray-900 mb-1"><?php echo number_format($savings_rate, 1); ?>%</h3>
<p class="text-sm text-gray-600"><?php echo t('dashboard.savings_rate'); ?></p>
<p class="text-xs text-gray-500 mt-1"><?php echo t('dashboard.this_month'); ?></p>
                    </div>

                                      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-indigo-700 rounded-xl flex items-center justify-center text-2xl">
                                🏦
                            </div>
                        </div>
<h3 class="text-2xl font-bold text-gray-900 mb-1"><?php echo $linked_accounts_count; ?></h3>
<p class="text-sm text-gray-600"><?php echo t('dashboard.linked_accounts'); ?></p>
<p class="text-xs text-gray-500 mt-1"><?php echo $linked_accounts_count > 0 ? t('dashboard.active') : t('dashboard.offline'); ?></p>
                    </div>
                </div>

                <!-- Budget Overview -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6 md:mb-8">
<h2 class="text-xl font-bold text-gray-900 mb-4"><?php echo t('dashboard.monthly_budget_overview'); ?></h2>
<div class="space-y-3">
    <div class="flex justify-between items-center text-sm">
        <span class="font-medium text-gray-700">₱<?php echo number_format($total_expenses, 2); ?> <?php echo t('dashboard.spent'); ?></span>
        <span class="text-gray-600"><?php echo t('dashboard.of'); ?> ₱<?php echo number_format($monthly_budget, 2); ?> <?php echo t('dashboard.budget'); ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
                            <?php
                            $bar_color = $percentage_spent > 90 ? 'bg-red-500' : ($percentage_spent > 70 ? 'bg-amber-500' : 'bg-green-500');
                            ?>
                            <div class="<?php echo $bar_color; ?> h-4 rounded-full transition-all duration-500" style="width: <?php echo min($percentage_spent, 100); ?>%"></div>
                        </div>
                        <div class="text-center">
<span class="inline-block px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-lg text-sm font-semibold">
    <?php echo t('dashboard.budget_used', ['percentage' => number_format($percentage_spent, 1)]); ?>
</span>
                        </div>
                    </div>
                </div>

                <!-- Charts Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 md:mb-8">
                    <!-- Spending by Category -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-xl font-bold text-gray-900 mb-4">Spending by Category</h2>
                        <?php if (!empty($category_breakdown)): ?>
                        <div class="chart-container">
                            <canvas id="categoryChart"></canvas>
                        </div>
                        <div class="mt-4 space-y-2 max-h-48 overflow-y-auto">
                            <?php foreach ($category_breakdown as $cat): ?>
                            <div class="flex items-center justify-between text-sm">
                                <div class="flex items-center gap-2">
                                    <div class="w-3 h-3 rounded-full" style="background-color: <?php echo htmlspecialchars($cat['color']); ?>"></div>
                                    <span class="font-medium text-gray-700"><?php echo htmlspecialchars($cat['category']); ?></span>
                                </div>
                                <span class="text-gray-900 font-semibold">₱<?php echo number_format($cat['total'], 2); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                            <span class="text-6xl mb-4">📊</span>
                            <p class="text-sm">No expenses recorded yet</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Daily Spending Trend -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-xl font-bold text-gray-900 mb-4">Daily Spending Trend</h2>
                        <?php if (!empty($daily_spending)): ?>
                        <div class="chart-container">
                            <canvas id="trendChart"></canvas>
                        </div>
                        <?php else: ?>
                        <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                            <span class="text-6xl mb-4">📈</span>
                            <p class="text-sm">No spending data available</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-gray-900">Recent Transactions</h2>
                        <a href="all_expenses.php" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">View All →</a>
                    </div>
                    <?php if (!empty($recent_transactions)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Date</th>
                                    <th class="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Description</th>
                                    <th class="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase hidden md:table-cell">Category</th>
                                    <th class="text-right py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($recent_transactions as $transaction): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="py-3 px-4 text-sm text-gray-600">
                                        <?php echo date('M d, Y', strtotime($transaction['expense_date'])); ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($transaction['description']); ?></p>
                                        <p class="text-xs text-gray-500 md:hidden mt-1">
                                            <span class="inline-flex items-center gap-1">
                                                <span class="w-2 h-2 rounded-full" style="background-color: <?php echo htmlspecialchars($transaction['color']); ?>"></span>
                                                <?php echo htmlspecialchars($transaction['category']); ?>
                                            </span>
                                        </p>
                                    </td>
                                    <td class="py-3 px-4 hidden md:table-cell">
                                        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium" 
                                              style="background-color: <?php echo htmlspecialchars($transaction['color']); ?>20; color: <?php echo htmlspecialchars($transaction['color']); ?>">
                                            <span class="w-2 h-2 rounded-full" style="background-color: <?php echo htmlspecialchars($transaction['color']); ?>"></span>
                                            <?php echo htmlspecialchars($transaction['category']); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <span class="text-sm font-semibold text-gray-900">₱<?php echo number_format($transaction['amount'], 2); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                        <span class="text-6xl mb-4">📝</span>
                        <p class="text-sm mb-4">No transactions yet</p>
                        <a href="add_expense.php" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">
                            Add Your First Expense
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Linked Accounts Section -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mt-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-gray-900">Linked Accounts</h2>
                        <a href="linked_accounts.php" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">Manage →</a>
                    </div>
                    <?php if (!empty($linked_accounts)): ?>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <?php foreach ($linked_accounts as $account): ?>
                        <div class="border border-gray-200 rounded-lg p-4 hover:border-indigo-300 transition-colors">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-10 h-10 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-lg flex items-center justify-center text-xl">
                                    🏦
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($account['bank_name']); ?></p>
                                    <p class="text-xs text-gray-500">****<?php echo htmlspecialchars(substr($account['account_number'], -4)); ?></p>
                                </div>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-500">Last synced:</span>
                                <span class="text-gray-700 font-medium">
                                    <?php 
                                    if ($account['last_synced']) {
                                        echo date('M d, Y', strtotime($account['last_synced']));
                                    } else {
                                        echo 'Never';
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                        <span class="text-6xl mb-4">🏦</span>
                        <p class="text-sm mb-4">No accounts linked yet</p>
                        <a href="linked_accounts.php" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">
                            Link Your First Account
                        </a>
                    </div>
                    <?php endif; ?>
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
    
    function showSidebar() {
        mobileSidebar.classList.add('show');
        sidebarOverlay.classList.add('show');
        sidebarOverlay.classList.remove('pointer-events-none');
        document.body.classList.add('sidebar-open');
    }
    
    function hideSidebar() {
        mobileSidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
        sidebarOverlay.classList.add('pointer-events-none');
        document.body.classList.remove('sidebar-open');
    }
    
    openSidebar.addEventListener('click', showSidebar);
    closeSidebar.addEventListener('click', hideSidebar);
    sidebarOverlay.addEventListener('click', hideSidebar);

    console.log('Chatbot script loading...');

// Wait for DOM to be ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initChatbot);
} else {
    initChatbot();
}

function initChatbot() {
    console.log('Initializing chatbot...');
    
    const chatbotToggle = document.getElementById('chatbotToggle');
    const chatbotWindow = document.getElementById('chatbotWindow');
    const minimizeChat = document.getElementById('minimizeChat');
    const chatMessages = document.getElementById('chatMessages');
    const chatInput = document.getElementById('chatInput');
    const sendBtn = document.getElementById('sendBtn');
    const quickBtns = document.querySelectorAll('.quick-action-btn');
    const chatIcon = document.querySelector('.chat-icon');
    const closeIcon = document.querySelector('.close-icon');
    const chatHeader = document.getElementById('chatHeader');

    if (!chatbotToggle) {
        console.error('Chatbot toggle button not found!');
        return;
    }

    console.log('Chatbot elements found:', {
        toggle: !!chatbotToggle,
        window: !!chatbotWindow,
        minimize: !!minimizeChat
    });

    let isChatOpen = false;
    let dragState = {
        isDragging: false,
        startX: 0,
        startY: 0,
        startLeft: 0,
        startTop: 0,
        hasMoved: false
    };

    // Toggle chat window - Simple click handler
    chatbotToggle.addEventListener('click', function(e) {
        console.log('Toggle clicked, hasMoved:', dragState.hasMoved);
        
        if (dragState.hasMoved) {
            dragState.hasMoved = false;
            return;
        }

        isChatOpen = !isChatOpen;
        console.log('Chat open:', isChatOpen);
        
        if (isChatOpen) {
            chatbotWindow.style.display = 'flex';
            chatIcon.style.display = 'none';
            closeIcon.style.display = 'block';
            setTimeout(() => chatInput.focus(), 100);
        } else {
            chatbotWindow.style.display = 'none';
            chatIcon.style.display = 'block';
            closeIcon.style.display = 'none';
        }
    });

    // Minimize chat
    minimizeChat.addEventListener('click', function(e) {
        e.stopPropagation();
        console.log('Minimize clicked');
        chatbotWindow.style.display = 'none';
        chatIcon.style.display = 'block';
        closeIcon.style.display = 'none';
        isChatOpen = false;
    });

    // Make button draggable
    chatbotToggle.addEventListener('mousedown', function(e) {
        startDrag(e, 'button');
    });

    chatbotToggle.addEventListener('touchstart', function(e) {
        startDrag(e, 'button');
    }, { passive: true });

    function startDrag(e, element) {
        console.log('Start drag:', element);
        dragState.isDragging = true;
        dragState.hasMoved = false;
        
        const touch = e.type === 'touchstart' ? e.touches[0] : e;
        dragState.startX = touch.clientX;
        dragState.startY = touch.clientY;
        
        const rect = chatbotToggle.getBoundingClientRect();
        dragState.startLeft = rect.left;
        dragState.startTop = rect.top;

        chatbotToggle.classList.add('is-dragging');

        document.addEventListener('mousemove', onDrag);
        document.addEventListener('touchmove', onDrag, { passive: true });
        document.addEventListener('mouseup', stopDrag);
        document.addEventListener('touchend', stopDrag);
    }

    function onDrag(e) {
        if (!dragState.isDragging) return;
        
        e.preventDefault();
        const touch = e.type === 'touchmove' ? e.touches[0] : e;
        const deltaX = touch.clientX - dragState.startX;
        const deltaY = touch.clientY - dragState.startY;

        // If moved more than 3 pixels, consider it a drag
        if (Math.abs(deltaX) > 3 || Math.abs(deltaY) > 3) {
            dragState.hasMoved = true;
        }
        
        const newLeft = dragState.startLeft + deltaX;
        const newTop = dragState.startTop + deltaY;

        const maxX = window.innerWidth - chatbotToggle.offsetWidth;
        const maxY = window.innerHeight - chatbotToggle.offsetHeight;

        chatbotToggle.style.left = Math.max(0, Math.min(newLeft, maxX)) + 'px';
        chatbotToggle.style.top = Math.max(0, Math.min(newTop, maxY)) + 'px';
        chatbotToggle.style.right = 'auto';
        chatbotToggle.style.bottom = 'auto';
    }

    function stopDrag() {
        console.log('Stop drag, hasMoved:', dragState.hasMoved);
        dragState.isDragging = false;
        chatbotToggle.classList.remove('is-dragging');
        
        document.removeEventListener('mousemove', onDrag);
        document.removeEventListener('touchmove', onDrag);
        document.removeEventListener('mouseup', stopDrag);
        document.removeEventListener('touchend', stopDrag);
    }

    // Make window draggable by header
    let windowDragState = { isDragging: false };
    
    chatHeader.addEventListener('mousedown', function(e) {
        if (e.target.closest('.btn-minimize-chat')) return;
        
        windowDragState.isDragging = true;
        const touch = e;
        windowDragState.startX = touch.clientX;
        windowDragState.startY = touch.clientY;
        
        const rect = chatbotWindow.getBoundingClientRect();
        windowDragState.startLeft = rect.left;
        windowDragState.startTop = rect.top;

        document.addEventListener('mousemove', onWindowDrag);
        document.addEventListener('mouseup', stopWindowDrag);
    });

    function onWindowDrag(e) {
        if (!windowDragState.isDragging) return;
        
        const deltaX = e.clientX - windowDragState.startX;
        const deltaY = e.clientY - windowDragState.startY;

        const newLeft = windowDragState.startLeft + deltaX;
        const newTop = windowDragState.startTop + deltaY;

        const maxX = window.innerWidth - chatbotWindow.offsetWidth;
        const maxY = window.innerHeight - chatbotWindow.offsetHeight;

        chatbotWindow.style.left = Math.max(0, Math.min(newLeft, maxX)) + 'px';
        chatbotWindow.style.top = Math.max(0, Math.min(newTop, maxY)) + 'px';
        chatbotWindow.style.right = 'auto';
        chatbotWindow.style.bottom = 'auto';
    }

    function stopWindowDrag() {
        windowDragState.isDragging = false;
        document.removeEventListener('mousemove', onWindowDrag);
        document.removeEventListener('mouseup', stopWindowDrag);
    }

    // Send message
    function sendMessage() {
        const message = chatInput.value.trim();
        if (!message) return;
        
        console.log('Sending message:', message);
        addMessage(message, 'user');
        chatInput.value = '';
        
        showTyping();
        
        setTimeout(() => {
            removeTyping();
            addMessage('Thanks for your message! This is a demo response.', 'bot');
        }, 1000);
    }

    function addMessage(text, sender) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${sender}-message`;
        
        const time = new Date().toLocaleTimeString('en-US', { 
            hour: 'numeric', 
            minute: '2-digit' 
        });
        
        messageDiv.innerHTML = `
            <div class="message-bubble">
                <p>${text.replace(/\n/g, '<br>')}</p>
            </div>
            <span class="message-timestamp">${time}</span>
        `;
        
        chatMessages.appendChild(messageDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function showTyping() {
        const typingDiv = document.createElement('div');
        typingDiv.className = 'message bot-message typing-indicator';
        typingDiv.id = 'typingIndicator';
        typingDiv.innerHTML = `
            <div class="message-bubble">
                <div class="typing-dots">
                    <span></span><span></span><span></span>
                </div>
            </div>
        `;
        chatMessages.appendChild(typingDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function removeTyping() {
        const typing = document.getElementById('typingIndicator');
        if (typing) typing.remove();
    }

    sendBtn.addEventListener('click', sendMessage);
    chatInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') sendMessage();
    });

    quickBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            chatInput.value = btn.dataset.message;
            sendMessage();
        });
    });

    console.log('Chatbot initialized successfully!');
}
    
    // Close sidebar on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && mobileSidebar.classList.contains('show')) {
            hideSidebar();
        }
    });

    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (window.innerWidth >= 1024) {
                hideSidebar();
            }
        }, 250);
    });
        // Chart.js - Category Pie Chart
        <?php if (!empty($category_breakdown)): ?>
        const categoryCtx = document.getElementById('categoryChart');
        if (categoryCtx) {
            new Chart(categoryCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo $chart_categories; ?>,
                    datasets: [{
                        data: <?php echo $chart_amounts; ?>,
                        backgroundColor: <?php echo $chart_colors; ?>,
                        borderWidth: 2,
                        borderColor: '#ffffff'
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
                                    return context.label + ': ₱' + context.parsed.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                }
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>

        // Chart.js - Daily Spending Line Chart
        <?php if (!empty($daily_spending)): ?>
        const trendCtx = document.getElementById('trendChart');
        if (trendCtx) {
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo $spending_dates; ?>,
                    datasets: [{
                        label: 'Daily Spending',
                        data: <?php echo $spending_amounts; ?>,
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointBackgroundColor: '#6366f1',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2
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
                                    return 'Amount: ₱' + context.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
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
                            }
                        },
                        x: {
                            ticks: {
                                callback: function(value, index) {
                                    const date = new Date(this.getLabelForValue(value));
                                    return date.getDate();
                                }
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
    </script>
    <?php include 'includes/chatbot_widget.php'; ?>
</body>
</html>