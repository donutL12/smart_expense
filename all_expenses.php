<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/auth_user.php';

$user_id = $_SESSION['user_id'];

// Get filter parameters
$filter_category = isset($_GET['category']) ? intval($_GET['category']) : 0;
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Fetch user data
$user_query = "SELECT name, email FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Get user initials
$user_name = $user['name'] ?? 'User';
$user_email = $user['email'] ?? '';
$user_initial = strtoupper(substr($user_name, 0, 1));

// Build WHERE clause for filters
$where_conditions = ["e.user_id = ?"];
$params = [$user_id];
$types = "i";

if ($filter_category > 0) {
    $where_conditions[] = "e.category_id = ?";
    $params[] = $filter_category;
    $types .= "i";
}

if ($filter_month) {
    $where_conditions[] = "DATE_FORMAT(e.expense_date, '%Y-%m') = ?";
    $params[] = $filter_month;
    $types .= "s";
}

if ($search_query) {
    $where_conditions[] = "e.description LIKE ?";
    $params[] = "%$search_query%";
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Count total expenses for pagination
$count_query = "SELECT COUNT(*) as total FROM expenses e WHERE $where_clause";
$stmt = $conn->prepare($count_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total_expenses = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_expenses / $per_page);

// Fetch expenses with filters
$expense_query = "SELECT e.id, e.expense_date, e.description, e.amount, c.name as category, c.color
FROM expenses e
LEFT JOIN categories c ON e.category_id = c.id
WHERE $where_clause
ORDER BY e.expense_date DESC, e.id DESC
LIMIT ? OFFSET ?";

$stmt = $conn->prepare($expense_query);
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get all categories for filter dropdown
$categories_query = "SELECT id, name FROM categories ORDER BY name ASC";
$categories_result = $conn->query($categories_query);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Calculate totals for current filter
$total_query = "SELECT COALESCE(SUM(amount), 0) as total FROM expenses e WHERE $where_clause";
$stmt = $conn->prepare($total_query);
array_pop($params); // Remove offset
array_pop($params); // Remove per_page
$types = substr($types, 0, -2); // Remove last two type chars
$stmt->bind_param($types, ...$params);
$stmt->execute();
$filtered_total = $stmt->get_result()->fetch_assoc()['total'];

// Handle delete action
if (isset($_POST['delete_expense'])) {
    $expense_id = intval($_POST['expense_id']);
    $delete_query = "DELETE FROM expenses WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("ii", $expense_id, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Expense deleted successfully!";
    } else {
        $_SESSION['error'] = "Failed to delete expense.";
    }
    
    header("Location: all_expenses.php" . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Expenses - FinSight</title>
    <script src="https://cdn.tailwindcss.com"></script>
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

        /* Mobile menu animation */
        .mobile-menu {
            transition: transform 0.3s ease-in-out;
        }

        /* Hover effects for table rows */
        .expense-row {
            transition: all 0.2s ease;
        }

        .expense-row:hover {
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        /* Prevent body scroll when sidebar is open */
        body.sidebar-open {
            overflow: hidden;
        }

        /* Overlay transition */
        .overlay-transition {
            transition: opacity 0.3s ease-in-out;
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
                <a href="dashboard.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg"></span>
                    <span>Dashboard</span>
                </a>
                <a href="all_expenses.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg">
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
        <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden opacity-0 pointer-events-none overlay-transition"></div>

        <!-- Mobile Sidebar -->
        <aside id="mobileSidebar" class="mobile-menu fixed inset-y-0 left-0 w-64 bg-white border-r border-gray-200 z-50 lg:hidden transform -translate-x-full">
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
            
            <!-- Navigation - Same as Desktop -->
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto h-[calc(100vh-180px)]">
                <a href="dashboard.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg"></span>
                    <span>Dashboard</span>
                </a>
                <a href="all_expenses.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg">
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

        <!-- Main Content -->
        <main class="flex-1 lg:ml-64">
            <!-- Mobile Header -->
            <header class="lg:hidden sticky top-0 z-20 bg-white border-b border-gray-200 px-4 py-3 shadow-sm">
                <div class="flex items-center justify-between">
                    <button id="openSidebar" class="text-gray-700 hover:text-gray-900 p-2 hover:bg-gray-100 rounded-lg transition-all">
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
                    <div class="w-10"></div>
                </div>
            </header>

            <div class="p-4 md:p-6 lg:p-8">
                <!-- Page Header -->
                <div class="mb-6 md:mb-8">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <h1 class="text-2xl md:text-3xl font-bold text-gray-900">All Expenses</h1>
                            <p class="text-gray-600 mt-1">View and manage all your expense records</p>
                        </div>
                        <a href="add_expense.php" class="inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-medium rounded-lg hover:from-indigo-700 hover:to-purple-700 transition-all shadow-md hover:shadow-lg">
                            <span class="mr-2">➕</span>
                            <span>Add New Expense</span>
                        </a>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="mb-6 bg-green-50 border border-green-200 rounded-xl p-4 flex items-start gap-3 animate-pulse">
                    <span class="text-2xl">✅</span>
                    <p class="text-green-800 font-medium flex-1"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></p>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-start gap-3">
                    <span class="text-2xl">⚠️</span>
                    <p class="text-red-800 font-medium flex-1"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></p>
                </div>
                <?php endif; ?>

                <!-- Filters Section -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 md:p-6 mb-6">
                    <form method="GET" action="all_expenses.php" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div>
                                <label for="search" class="block text-sm font-semibold text-gray-700 mb-2">
                                     Search
                                </label>
                                <input type="text" id="search" name="search" placeholder="Search description..." 
                                       value="<?php echo htmlspecialchars($search_query); ?>"
                                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all">
                            </div>
                            
                            <div>
                                <label for="category" class="block text-sm font-semibold text-gray-700 mb-2">
                                     Category
                                </label>
                                <select id="category" name="category" 
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all">
                                    <option value="0">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="month" class="block text-sm font-semibold text-gray-700 mb-2">
                                     Month
                                </label>
                                <input type="month" id="month" name="month" value="<?php echo htmlspecialchars($filter_month); ?>"
                                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all">
                            </div>
                            
                            <div class="flex items-end gap-2">
                                <button type="submit" 
                                        class="flex-1 px-4 py-2.5 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 transition-all">
                                    Apply
                                </button>
                                <a href="all_expenses.php" 
                                   class="flex-1 px-4 py-2.5 bg-gray-200 text-gray-700 font-medium rounded-lg hover:bg-gray-300 transition-all text-center">
                                    Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-2 gap-4 md:gap-6 mb-6">
                    <div class="bg-gradient-to-br from-purple-500 to-purple-700 rounded-xl shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-4">
                            
                        </div>
                        <h3 class="text-3xl font-bold mb-1">₱<?php echo number_format($filtered_total, 2); ?></h3>
                        <p class="text-purple-100">Total Expenses</p>
                        <p class="text-xs text-purple-200 mt-1">Current Filter</p>
                    </div>
                    
                    <div class="bg-gradient-to-br from-pink-500 to-pink-700 rounded-xl shadow-md p-6 text-white">
                        <div class="flex items-center justify-between mb-4">
                            
                        </div>
                        <h3 class="text-3xl font-bold mb-1"><?php echo $total_expenses; ?></h3>
                        <p class="text-pink-100">Transactions</p>
                        <p class="text-xs text-pink-200 mt-1">Total Count</p>
                    </div>
                </div>

                <!-- Expenses Table -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <?php if (empty($expenses)): ?>
                    <div class="flex flex-col items-center justify-center py-16 px-4 text-center">
                        <div class="text-7xl mb-4">📭</div>
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">No expenses found</h3>
                        <p class="text-gray-600 mb-6">Try adjusting your filters or add a new expense to get started.</p>
                        <a href="add_expense.php" 
                           class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-medium rounded-lg hover:from-indigo-700 hover:to-purple-700 transition-all shadow-md">
                            <span class="mr-2">➕</span>
                            <span>Add Your First Expense</span>
                        </a>
                    </div>
                    <?php else: ?>
                    <!-- Desktop Table View -->
                    <div class="hidden md:block overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                <tr>
                                    <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase"> Date</th>
                                    <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase"> Description</th>
                                    <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase"> Category</th>
                                    <th class="text-right py-4 px-6 text-xs font-semibold text-gray-600 uppercase"> Amount</th>
                                    <th class="text-center py-4 px-6 text-xs font-semibold text-gray-600 uppercase"> Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($expenses as $expense): ?>
                                <tr class="expense-row hover:bg-gray-50">
                                    <td class="py-4 px-6 text-sm font-medium text-gray-900">
                                        <?php echo date('M d, Y', strtotime($expense['expense_date'])); ?>
                                    </td>
                                    <td class="py-4 px-6">
                                        <p class="text-sm text-gray-900"><?php echo htmlspecialchars($expense['description']); ?></p>
                                    </td>
                                    <td class="py-4 px-6">
                                        <?php if ($expense['category']): ?>
                                        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold"
                                              style="background-color: <?php echo htmlspecialchars($expense['color'] ?? '#ccc'); ?>20; color: <?php echo htmlspecialchars($expense['color'] ?? '#666'); ?>;">
                                            <span class="w-2 h-2 rounded-full" style="background-color: <?php echo htmlspecialchars($expense['color'] ?? '#ccc'); ?>"></span>
                                            <?php echo htmlspecialchars($expense['category']); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">
                                            Uncategorized
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-6 text-right">
                                        <span class="text-sm font-bold text-gray-900">₱<?php echo number_format($expense['amount'], 2); ?></span>
                                    </td>
                                    <td class="py-4 px-6">
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="edit_expense.php?id=<?php echo $expense['id']; ?>" 
                                               class="inline-flex items-center justify-center w-8 h-8 text-blue-600 hover:bg-blue-50 rounded-lg transition-all"
                                               title="Edit">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                </svg>
                                            </a>
                                            <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to delete this expense?');">
                                                <input type="hidden" name="expense_id" value="<?php echo $expense['id']; ?>">
                                                <button type="submit" name="delete_expense"
                                                        class="inline-flex items-center justify-center w-8 h-8 text-red-600 hover:bg-red-50 rounded-lg transition-all"
                                                        title="Delete">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Card View -->
                    <div class="md:hidden divide-y divide-gray-100">
                        <?php foreach ($expenses as $expense): ?>
                        <div class="p-4 hover:bg-gray-50 transition-all">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex-1">
                                    <p class="text-sm font-semibold text-gray-900 mb-1">
                                        <?php echo htmlspecialchars($expense['description']); ?>
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        <?php echo date('M d, Y', strtotime($expense['expense_date'])); ?>
                                    </p>
                                </div>
                                <div class="text-right ml-3">
                                    <p class="text-lg font-bold text-gray-900">₱<?php echo number_format($expense['amount'], 2); ?></p>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between">
                                <div>
                                    <?php if ($expense['category']): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold"
                                          style="background-color: <?php echo htmlspecialchars($expense['color'] ?? '#ccc'); ?>20; color: <?php echo htmlspecialchars($expense['color'] ?? '#666'); ?>;">
                                        <span class="w-1.5 h-1.5 rounded-full" style="background-color: <?php echo htmlspecialchars($expense['color'] ?? '#ccc'); ?>"></span>
                                        <?php echo htmlspecialchars($expense['category']); ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">
                                        Uncategorized
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex items-center gap-2">
                                    <a href="edit_expense.php?id=<?php echo $expense['id']; ?>" 
                                       class="inline-flex items-center justify-center w-9 h-9 text-blue-600 hover:bg-blue-50 rounded-lg transition-all">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                    </a>
                                    <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to delete this expense?');">
                                        <input type="hidden" name="expense_id" value="<?php echo $expense['id']; ?>">
                                        <button type="submit" name="delete_expense"
                                                class="inline-flex items-center justify-center w-9 h-9 text-red-600 hover:bg-red-50 rounded-lg transition-all">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <p class="text-sm text-gray-600">
                        Showing page <span class="font-semibold"><?php echo $page; ?></span> of <span class="font-semibold"><?php echo $total_pages; ?></span>
                    </p>
                    
                    <div class="flex items-center gap-2">
                        <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&category=<?php echo $filter_category; ?>&month=<?php echo $filter_month; ?>&search=<?php echo urlencode($search_query); ?>" 
                           class="px-4 py-2 bg-white border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-all">
                            Previous
                        </a>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                        <a href="?page=<?php echo $i; ?>&category=<?php echo $filter_category; ?>&month=<?php echo $filter_month; ?>&search=<?php echo urlencode($search_query); ?>" 
                           class="hidden sm:inline-flex px-4 py-2 <?php echo $i == $page ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'; ?> font-medium rounded-lg transition-all">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&category=<?php echo $filter_category; ?>&month=<?php echo $filter_month; ?>&search=<?php echo urlencode($search_query); ?>" 
                           class="px-4 py-2 bg-white border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-all">
                            Next
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Mobile Sidebar Toggle
        const openSidebar = document.getElementById('openSidebar');
        const closeSidebar = document.getElementById('closeSidebar');
        const mobileSidebar = document.getElementById('mobileSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        function showSidebar() {
            mobileSidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.remove('opacity-0', 'pointer-events-none');
            document.body.classList.add('sidebar-open');
        }
        
        function hideSidebar() {
            mobileSidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('opacity-0', 'pointer-events-none');
            document.body.classList.remove('sidebar-open');
        }
        
        openSidebar.addEventListener('click', showSidebar);
        closeSidebar.addEventListener('click', hideSidebar);
        sidebarOverlay.addEventListener('click', hideSidebar);
        
        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !mobileSidebar.classList.contains('-translate-x-full')) {
                hideSidebar();
            }
        });
        
        // Auto-hide success messages after 5 seconds
        const successAlert = document.querySelector('.animate-pulse');
        if (successAlert) {
            setTimeout(() => {
                successAlert.style.transition = 'opacity 0.5s ease-out';
                successAlert.style.opacity = '0';
                setTimeout(() => successAlert.remove(), 500);
            }, 5000);
        }
    </script>
</body>
</html>