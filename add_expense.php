<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/auth_user.php';
require_once 'includes/budget_functions.php';
require_once 'includes/notification_functions.php'; 

$user_id = $_SESSION['user_id'];

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
$first_name = explode(' ', $user_name)[0];

// Fetch monthly BUDGET and current spending (not account balance)
$budget_query = "SELECT monthly_budget FROM users WHERE id = ?";
$stmt = $conn->prepare($budget_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$budget_result = $stmt->get_result()->fetch_assoc();
$monthly_budget = floatval($budget_result['monthly_budget'] ?? 0);
$stmt->close();

// Get current month's spending
$current_month = date('m');
$current_year = date('Y');
$spending_query = "SELECT COALESCE(SUM(amount), 0) as total_spent FROM expenses WHERE user_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?";
$stmt = $conn->prepare($spending_query);
$stmt->bind_param("iii", $user_id, $current_month, $current_year);
$stmt->execute();
$spending_result = $stmt->get_result()->fetch_assoc();
$total_spent = floatval($spending_result['total_spent'] ?? 0);
$stmt->close();

// Calculate remaining budget
$current_balance = $monthly_budget - $total_spent;

// Fetch all categories
$categories_query = "SELECT id, name, color FROM categories ORDER BY name ASC";
$categories_result = $conn->query($categories_query);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $amount = floatval($_POST['amount']);
    $description = trim($_POST['description']);
    $category_id = intval($_POST['category_id']);
    $expense_date = $_POST['expense_date'];
    
    // RE-FETCH BUDGET to ensure we have the latest value
    $budget_check_query = "SELECT monthly_budget FROM users WHERE id = ?";
    $stmt = $conn->prepare($budget_check_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $budget_check_result = $stmt->get_result()->fetch_assoc();
    $monthly_budget_check = floatval($budget_check_result['monthly_budget'] ?? 0);
    $stmt->close();
    
    // Get current month spending
    $current_month = date('m');
    $current_year = date('Y');
    $spending_check_query = "SELECT COALESCE(SUM(amount), 0) as total_spent FROM expenses WHERE user_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?";
    $stmt = $conn->prepare($spending_check_query);
    $stmt->bind_param("iii", $user_id, $current_month, $current_year);
    $stmt->execute();
    $total_spent_check = floatval($stmt->get_result()->fetch_assoc()['total_spent'] ?? 0);
    $stmt->close();
    
    $remaining_budget = $monthly_budget_check - $total_spent_check;
    
    // Validation
    $errors = [];
    
    if ($amount <= 0) {
        $errors[] = "Amount must be greater than zero.";
    }
    
    // Check if expense exceeds remaining monthly budget
    if ($amount > $remaining_budget) {
        $errors[] = "This expense would exceed your monthly budget! Your budget is ₱" . number_format($monthly_budget_check, 2) . " and you've spent ₱" . number_format($total_spent_check, 2) . " this month. You only have ₱" . number_format($remaining_budget, 2) . " remaining.";
    }
    if (empty($description)) {
        $errors[] = "Description is required.";
    }
    
    if ($category_id <= 0) {
        $errors[] = "Please select a category.";
    }
    
    if (empty($expense_date)) {
        $errors[] = "Expense date is required.";
    }
    
    // If no errors, insert expense and deduct from balance
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Insert expense
            $insert_query = "INSERT INTO expenses (user_id, category_id, amount, description, expense_date) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("iidss", $user_id, $category_id, $amount, $description, $expense_date);
            $stmt->execute();
            $expense_id = $stmt->insert_id;
            
            // Deduct from linked account (assuming single active account, or proportionally)
            $deduct_query = "UPDATE linked_accounts SET account_balance = account_balance - ? WHERE user_id = ? AND is_active = 1 LIMIT 1";
            $stmt = $conn->prepare($deduct_query);
            $stmt->bind_param("di", $amount, $user_id);
            $stmt->execute();
            
            // Get category name for notification
            $cat_stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
            $cat_stmt->bind_param("i", $category_id);
            $cat_stmt->execute();
            $category_name = $cat_stmt->get_result()->fetch_assoc()['name'] ?? 'Uncategorized';
            
            // Create success notification
            createNotification(
                $conn,
                $user_id,
                'success',
                'Expense Added Successfully',
                "Added ₱" . number_format($amount, 2) . " for " . htmlspecialchars($description) . " in " . $category_name . " category"
            );
            
            // Check if budget alert should be sent
            checkBudgetAlert($conn, $user_id);
            
            $conn->commit();
            
            $_SESSION['success'] = "Expense added successfully! New balance: ₱" . number_format($current_balance - $amount, 2);
            header("Location: dashboard.php");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Failed to add expense. Please try again.";
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
    }
}

$current_date = date('F j, Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Expense - FinSight</title>
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

        .mobile-menu.active {
            transform: translateX(0);
        }

        /* Balance card animation */
        @keyframes balancePulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }


        /* Amount preview animation */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .amount-preview {
            animation: slideIn 0.3s ease;
        }

        /* Input focus effects */
        .form-input:focus {
            transform: translateY(-2px);
        }

        /* Success state */
        .input-success {
            border-color: #10b981 !important;
            background-color: #ecfdf5 !important;
        }

        /* Error state */
        .input-error {
            border-color: #ef4444 !important;
            background-color: #fef2f2 !important;
        }

        /* Category chip hover */
        .category-chip {
            transition: all 0.3s ease;
        }

        .category-chip:hover {
            transform: translateY(-4px) scale(1.05);
        }

        /* Balance insufficient warning */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }

        .shake {
            animation: shake 0.5s ease-in-out;
        }

        /* Responsive sidebar */
       /* Responsive sidebar */
.mobile-menu {
    transition: transform 0.3s ease-in-out;
}

.mobile-menu.active {
    transform: translateX(0) !important;
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
                <a href="all_expenses.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg"></span>
                    <span>All Expenses</span>
                </a>
                <a href="add_expense.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg">
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
                <a href="notifications.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
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
        <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden"></div>

        <!-- Mobile Sidebar -->
        <aside id="mobileSidebar" class="mobile-menu fixed inset-y-0 left-0 w-64 bg-white border-r border-gray-200 z-50 lg:hidden -translate-x-full">
            <!-- Logo -->
            <div class="flex items-center justify-between px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-200">
                <div class="flex items-center gap-2 sm:gap-3">
                    <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center">
                        <span class="text-white text-lg sm:text-xl font-bold">F</span>
                    </div>
                    <h2 class="text-lg sm:text-xl font-bold text-gray-900">FinSight</h2>
                </div>
                <button id="closeSidebar" class="text-gray-500 hover:text-gray-700 p-1">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Navigation -->
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto h-[calc(100vh-180px)]">
                <a href="dashboard.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg"></span>
                    <span>Dashboard</span>
                </a>
                <a href="all_expenses.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg"></span>
                    <span>All Expenses</span>
                </a>
                <a href="add_expense.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg">
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
                    <span>Reports</span>
                </a>
                <a href="profile.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg"></span>
                    <span>Profile</span>
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
        <main class="flex-1 lg:ml-64 w-full">
            <!-- Mobile Header -->
            <header class="lg:hidden sticky top-0 z-20 bg-white border-b border-gray-200 px-4 py-3">
                <div class="flex items-center justify-between">
                    <button id="openSidebar" class="text-gray-700 hover:text-gray-900 p-2 -ml-2">
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

            <div class="p-4 md:p-6 lg:p-8 max-w-7xl mx-auto">
                <!-- GCash-style Balance Card -->
                <div class="mb-6 balance-card">
                    <div class="bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-800 rounded-2xl shadow-xl p-6 md:p-8 text-white relative overflow-hidden">
                        <!-- Background Pattern -->
                        <div class="absolute inset-0 opacity-10">
                            <div class="absolute top-0 right-0 w-64 h-64 bg-white rounded-full -mr-32 -mt-32"></div>
                            <div class="absolute bottom-0 left-0 w-48 h-48 bg-white rounded-full -ml-24 -mb-24"></div>
                        </div>
                        
                        <div class="relative z-10">
                            <div class="flex items-center justify-between mb-6">
                                <div>
                                    <p class="text-blue-200 text-sm mb-1">Available Balance</p>
                                    <h2 class="text-3xl md:text-4xl lg:text-5xl font-bold" id="displayBalance">₱<?php echo number_format($current_balance, 2); ?></h2>
                                </div>
                                <div class="bg-white bg-opacity-20 rounded-full p-3 backdrop-blur-sm">
                                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                    </svg>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-6 text-sm">
                                <div>
                                    <p class="text-blue-200 mb-1">Account Holder</p>
                                    <p class="font-semibold"><?php echo htmlspecialchars($first_name); ?></p>
                                </div>
                                <div class="h-8 w-px bg-blue-400"></div>
                                <div>
                                    <p class="text-blue-200 mb-1">Last Updated</p>
                                    <p class="font-semibold"><?php echo date('M d, Y'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Page Header -->
                <div class="mb-6 md:mb-8">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <h1 class="text-2xl md:text-3xl font-bold text-gray-900"> Add New Expense</h1>
                            <p class="text-gray-600 mt-1">Track your spending by adding a new expense</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="flex items-center gap-2 px-4 py-2 bg-white rounded-lg border border-gray-200 shadow-sm">
                                <span class="text-lg"></span>
                                <span class="text-sm font-medium text-gray-700"><?php echo $current_date; ?></span>
                            </div>
                            <a href="all_expenses.php" class="hidden md:inline-flex items-center gap-2 px-4 py-2 bg-white text-gray-700 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors shadow-sm">
                                <span class="text-lg"></span>
                                <span class="text-sm font-medium">View All</span>
                            </a>
                        </div>
                    </div>
                </div>

                <?php if (isset($_SESSION['errors'])): ?>
                <!-- Error Alert -->
                <div class="mb-6 bg-red-50 border-2 border-red-200 rounded-xl p-4 shake">
                    <div class="flex items-start gap-3">
                        <span class="text-2xl">⚠️</span>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-red-800 mb-2">Please fix the following errors:</p>
                            <ul class="space-y-1">
                                <?php foreach ($_SESSION['errors'] as $error): ?>
                                <li class="text-sm text-red-700 flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 bg-red-500 rounded-full"></span>
                                    <?php echo htmlspecialchars($error); ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php unset($_SESSION['errors']); ?>
                <?php endif; ?>

                <!-- Main Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Form Card (Spans 2 columns on large screens) -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 md:p-8">
                            <form method="POST" action="add_expense.php" id="expenseForm">
                                <div class="space-y-6">
                                    <!-- Amount & Date Row -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                                        <!-- Amount -->
                                        <div>
                                            <label for="amount" class="block text-sm font-semibold text-gray-900 mb-2">
                                                 Amount (₱)
                                            </label>
                                            <input 
                                                type="number" 
                                                id="amount" 
                                                name="amount" 
                                                step="0.01" 
                                                min="0.01" 
                                                placeholder="0.00" 
                                                value="<?php echo isset($_POST['amount']) ? htmlspecialchars($_POST['amount']) : ''; ?>"
                                                class="form-input w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-lg text-lg font-bold text-indigo-600 focus:border-indigo-500 focus:bg-white focus:outline-none transition-all"
                                                required
                                                autofocus
                                            >
                                            <p class="mt-2 text-xs text-gray-500">Enter the amount you spent</p>
                                            
                                            <!-- Amount Preview & Balance Check -->
                                            <div id="amountPreview" class="hidden mt-4">
                                                <div class="p-4 rounded-lg text-center" id="amountPreviewCard">
                                                    <p class="text-xs opacity-90 mb-1" id="previewLabel">You're spending</p>
                                                    <p id="amountPreviewValue" class="text-2xl font-bold">₱0.00</p>
                                                    <p id="remainingBalance" class="text-xs mt-2"></p>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Date -->
                                        <div>
                                            <label for="expense_date" class="block text-sm font-semibold text-gray-900 mb-2">
                                                 Date
                                            </label>
                                            <input 
                                                type="date" 
                                                id="expense_date" 
                                                name="expense_date" 
                                                value="<?php echo isset($_POST['expense_date']) ? htmlspecialchars($_POST['expense_date']) : date('Y-m-d'); ?>"
                                                max="<?php echo date('Y-m-d'); ?>"
                                                class="form-input w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-lg focus:border-indigo-500 focus:bg-white focus:outline-none transition-all"
                                                required
                                            >
                                            <p class="mt-2 text-xs text-gray-500">When did this expense occur?</p>
                                        </div>
                                    </div>

                                    <!-- Category -->
                                    <div>
                                        <label for="category_id" class="block text-sm font-semibold text-gray-900 mb-2">
                                             Category
                                        </label>
                                        <select 
                                            id="category_id" 
                                            name="category_id" 
                                            class="form-input w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-lg focus:border-indigo-500 focus:bg-white focus:outline-none transition-all"
                                            required
                                        >
                                            <option value="">-- Select a Category --</option>
                                            <?php foreach ($categories as $category): ?>
                                            <option 
                                                value="<?php echo $category['id']; ?>"
                                                <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $category['id']) ? 'selected' : ''; ?>
                                            >
                                                <?php echo htmlspecialchars($category['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="mt-2 text-xs text-gray-500">Choose the category that best fits this expense</p>
                                    </div>

                                    <!-- Description -->
                                    <div>
                                        <label for="description" class="block text-sm font-semibold text-gray-900 mb-2">
                                             Description
                                        </label>
                                        <textarea 
                                            id="description" 
                                            name="description" 
                                            rows="4" 
                                            placeholder="E.g., Lunch at restaurant, Gas refill, Monthly Netflix subscription..."
                                            class="form-input w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-lg focus:border-indigo-500 focus:bg-white focus:outline-none transition-all resize-none"
                                            required
                                        ><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                                        <p class="mt-2 text-xs text-gray-500">Provide details about this expense</p>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="flex flex-col sm:flex-row gap-3 pt-4">
                                        <button 
                                            type="submit" 
                                            name="add_expense" 
                                            id="submitBtn"
                                            class="flex-1 px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-semibold rounded-lg hover:from-indigo-700 hover:to-purple-700 focus:outline-none focus:ring-4 focus:ring-indigo-200 transition-all shadow-lg hover:shadow-xl disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                             Add Expense
                                        </button>
                                        <a 
                                            href="dashboard.php" 
                                            class="flex-1 px-6 py-3 bg-white text-gray-700 font-semibold border-2 border-gray-200 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200 transition-all text-center"
                                        >
                                             Cancel
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Sidebar Info -->
                    <div class="space-y-6">
                        <!-- Quick Tips -->
                        

                        <!-- Available Categories -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                            <h3 class="text-base sm:text-lg font-bold text-gray-900 mb-3 sm:mb-4 flex items-center gap-2">
                                 Available Categories
                            </h3>
                            <div class="grid grid-cols-2 gap-2 sm:gap-3 mb-3 sm:mb-4">
                                <?php foreach (array_slice($categories, 0, 6) as $category): ?>
                                <div class="category-chip p-2 sm:p-3 rounded-lg text-center text-xs sm:text-sm font-semibold border-2 cursor-default" 
                                     style="background-color: <?php echo htmlspecialchars($category['color'] ?? '#667eea'); ?>15; border-color: <?php echo htmlspecialchars($category['color'] ?? '#667eea'); ?>40; color: <?php echo htmlspecialchars($category['color'] ?? '#667eea'); ?>;">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </div>
                                <?php endforeach; ?>
                                <?php if (count($categories) > 6): ?>
                                <div class="p-2 sm:p-3 rounded-lg text-center text-xs sm:text-sm font-semibold bg-gray-100 text-gray-600 border-2 border-gray-200">
                                    +<?php echo count($categories) - 6; ?> more
                                </div>
                                <?php endif; ?>
                            </div>
                            <a href="categories.php" class="block w-full px-3 sm:px-4 py-2 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 transition-colors text-center text-xs sm:text-sm">
                                Manage Categories
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Store current balance for validation
        const CURRENT_BALANCE = <?php echo $current_balance; ?>;
        
        // Mobile sidebar toggle
        const openSidebar = document.getElementById('openSidebar');
        const closeSidebar = document.getElementById('closeSidebar');
        const mobileSidebar = document.getElementById('mobileSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

      function toggleSidebar() {
    mobileSidebar.classList.toggle('active');
    sidebarOverlay.classList.toggle('hidden');
    document.body.style.overflow = mobileSidebar.classList.contains('active') ? 'hidden' : '';
}

        openSidebar?.addEventListener('click', toggleSidebar);
        closeSidebar?.addEventListener('click', toggleSidebar);
        sidebarOverlay?.addEventListener('click', toggleSidebar);

        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !mobileSidebar.classList.contains('hidden')) {
                toggleSidebar();
            }
        });

        // Enhanced amount preview with balance validation
        const amountInput = document.getElementById('amount');
        const amountPreview = document.getElementById('amountPreview');
        const amountPreviewValue = document.getElementById('amountPreviewValue');
        const amountPreviewCard = document.getElementById('amountPreviewCard');
        const remainingBalance = document.getElementById('remainingBalance');
        const previewLabel = document.getElementById('previewLabel');
        const submitBtn = document.getElementById('submitBtn');
        const displayBalance = document.getElementById('displayBalance');

        if (amountInput && amountPreviewValue) {
            amountInput.addEventListener('input', function() {
                const value = parseFloat(this.value) || 0;
                
                if (value > 0) {
                    amountPreview.classList.remove('hidden');
                    amountPreviewValue.textContent = '₱' + value.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    
                    const remaining = CURRENT_BALANCE - value;
                    
                    // Check if sufficient balance
                    if (value > CURRENT_BALANCE) {
                        // Insufficient balance - Red warning
                        amountPreviewCard.className = 'p-4 rounded-lg text-center bg-red-50 border-2 border-red-300';
                        amountPreviewValue.className = 'text-2xl font-bold text-red-600';
                        previewLabel.className = 'text-xs mb-1 text-red-700 font-semibold';
                        previewLabel.textContent = '⚠️ Insufficient Balance!';
                        remainingBalance.className = 'text-xs mt-2 text-red-700 font-semibold';
                        remainingBalance.textContent = 'You need ₱' + (value - CURRENT_BALANCE).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' more';
                        
                        this.classList.remove('input-success');
                        this.classList.add('input-error');
                        submitBtn.disabled = true;
                        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                        
                        // Shake animation
                        amountPreviewCard.classList.add('shake');
                        setTimeout(() => amountPreviewCard.classList.remove('shake'), 500);
                    } else {
                        // Sufficient balance - Green success
                        amountPreviewCard.className = 'p-4 rounded-lg text-center bg-gradient-to-br from-green-500 to-emerald-600 text-white';
                        amountPreviewValue.className = 'text-2xl font-bold text-white';
                        previewLabel.className = 'text-xs opacity-90 mb-1';
                        previewLabel.textContent = '✓ You\'re spending';
                        remainingBalance.className = 'text-xs mt-2 opacity-90';
                        remainingBalance.textContent = 'Remaining: ₱' + remaining.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                        
                        this.classList.add('input-success');
                        this.classList.remove('input-error');
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                } else {
                    amountPreview.classList.add('hidden');
                    this.classList.remove('input-success', 'input-error');
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            });
            
            // Trigger on page load if value exists
            if (amountInput.value) {
                amountInput.dispatchEvent(new Event('input'));
            }
        }

        // Form validation with balance check
        const expenseForm = document.getElementById('expenseForm');
        
        if (expenseForm) {
            expenseForm.addEventListener('submit', function(e) {
                const amount = parseFloat(document.getElementById('amount').value) || 0;
                const description = document.getElementById('description').value.trim();
                const categoryId = document.getElementById('category_id').value;
                const expenseDate = document.getElementById('expense_date').value;
                
                let errors = [];
                
                if (!amount || amount <= 0) {
                    errors.push('Please enter a valid amount greater than zero.');
                }
                
                // Balance validation
                if (amount > CURRENT_BALANCE) {
                    errors.push('Insufficient balance! You need ₱' + (amount - CURRENT_BALANCE).toFixed(2) + ' more to complete this transaction.');
                }
                
                if (!description) {
                    errors.push('Please provide a description for this expense.');
                }
                
                if (!categoryId) {
                    errors.push('Please select a category.');
                }
                
                if (!expenseDate) {
                    errors.push('Please select an expense date.');
                }
                
                if (errors.length > 0) {
                    e.preventDefault();
                    
                    // Show error modal
                    const errorMsg = errors.map(err => '• ' + err).join('\n');
                    alert('⚠️ Please fix the following errors:\n\n' + errorMsg);
                    
                    // Shake the balance card if insufficient funds
                    if (amount > CURRENT_BALANCE && displayBalance) {
                        displayBalance.parentElement.parentElement.parentElement.classList.add('shake');
                        setTimeout(() => {
                            displayBalance.parentElement.parentElement.parentElement.classList.remove('shake');
                        }, 500);
                    }
                }
            });
        }

        // Auto-focus amount input on desktop
        if (window.innerWidth >= 768 && amountInput) {
            amountInput.focus();
        }

        // Category select enhancement
        const categorySelect = document.getElementById('category_id');
        
        if (categorySelect) {
            categorySelect.addEventListener('change', function() {
                if (this.value) {
                    this.classList.add('input-success');
                } else {
                    this.classList.remove('input-success');
                }
            });
            
            if (categorySelect.value) {
                categorySelect.classList.add('input-success');
            }
        }

        // Description textarea enhancement
        const descriptionTextarea = document.getElementById('description');
        
        if (descriptionTextarea) {
            descriptionTextarea.addEventListener('input', function() {
                if (this.value.trim().length > 0) {
                    this.classList.add('input-success');
                } else {
                    this.classList.remove('input-success');
                }
            });
            
            if (descriptionTextarea.value.trim()) {
                descriptionTextarea.classList.add('input-success');
            }
        }

        // Date input enhancement
        const dateInput = document.getElementById('expense_date');
        
        if (dateInput) {
            dateInput.addEventListener('change', function() {
                if (this.value) {
                    this.classList.add('input-success');
                } else {
                    this.classList.remove('input-success');
                }
            });
            
            if (dateInput.value) {
                dateInput.classList.add('input-success');
            }
        }

        // Responsive: Adjust sidebar behavior on window resize
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
               if (window.innerWidth >= 1024) {
    // Desktop view - close mobile sidebar
    if (mobileSidebar.classList.contains('active')) {
        mobileSidebar.classList.remove('active');
        sidebarOverlay.classList.add('hidden');
        document.body.style.overflow = '';
    }
}
            }, 250);
        });

        // Prevent body scroll when mobile menu is open
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    const isActive = mobileSidebar.classList.contains('active');
                    document.body.style.overflow = isActive ? 'hidden' : '';
                }
            });
        });

        observer.observe(mobileSidebar, { attributes: true });

        // Add smooth scroll behavior
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    </script>
</body>
</html>