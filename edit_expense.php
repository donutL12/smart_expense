<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/auth_user.php';

$user_id = $_SESSION['user_id'];

// Get expense ID from URL
if (!isset($_GET['id'])) {
    $_SESSION['error'] = "No expense specified.";
    header("Location: all_expenses.php");
    exit();
}

$expense_id = intval($_GET['id']);

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

// Fetch the expense (ensure it belongs to the user)
$expense_query = "SELECT e.id, e.amount, e.description, e.category_id, e.expense_date, c.name as category_name, c.color as category_color
FROM expenses e
LEFT JOIN categories c ON e.category_id = c.id
WHERE e.id = ? AND e.user_id = ?";
$stmt = $conn->prepare($expense_query);
$stmt->bind_param("ii", $expense_id, $user_id);
$stmt->execute();
$expense_result = $stmt->get_result();

if ($expense_result->num_rows === 0) {
    $_SESSION['error'] = "Expense not found or you don't have permission to edit it.";
    header("Location: all_expenses.php");
    exit();
}

$expense = $expense_result->fetch_assoc();

// Fetch all categories
$categories_query = "SELECT id, name, color FROM categories ORDER BY name ASC";
$categories_result = $conn->query($categories_query);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_expense'])) {
    $amount = floatval($_POST['amount']);
    $description = trim($_POST['description']);
    $category_id = intval($_POST['category_id']);
    $expense_date = $_POST['expense_date'];
    
    // Validation
    $errors = [];
    
    if ($amount <= 0) {
        $errors[] = "Amount must be greater than zero.";
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
    
    // If no errors, update expense
    if (empty($errors)) {
        $update_query = "UPDATE expenses 
        SET amount = ?, description = ?, category_id = ?, expense_date = ? 
        WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("dssiii", $amount, $description, $category_id, $expense_date, $expense_id, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Expense updated successfully!";
            header("Location: all_expenses.php");
            exit();
        } else {
            $errors[] = "Failed to update expense. Please try again.";
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Expense - FinSight</title>
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
    transition: all 0.2s ease;
    white-space: nowrap;
}

.nav-item:hover {
    transform: translateX(4px);
}

.nav-item:active {
    transform: scale(0.98);
}

/* Mobile sidebar smooth animation */
.mobile-menu {
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.mobile-menu.hidden {
    transform: translateX(-100%);
}


        /* Form animations */
        .form-input:focus {
            transform: translateY(-2px);
            transition: transform 0.2s ease;
        }

        /* Alert animation */
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-banner {
            animation: slideDown 0.3s ease;
        }
        @media (max-width: 1023px) {
    #mobileSidebar {
        width: 280px;
        max-width: 85vw;
    }
}

@media (min-width: 1024px) {
    aside {
        width: 280px;
    }
    
    main {
        margin-left: 280px;
    }
}

/* Touch-friendly tap targets on mobile */
@media (max-width: 768px) {
    .nav-item {
        padding: 14px 16px;
        font-size: 15px;
    }
}

/* Smooth overlay fade */
#sidebarOverlay {
    transition: opacity 0.3s ease;
}

#sidebarOverlay.hidden {
    opacity: 0;
    pointer-events: none;
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
    <a href="dashboard.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Dashboard</span>
    </a>
    <a href="all_expenses.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-white bg-indigo-600 rounded-lg transition-all">
        <span>All Expenses</span>
    </a>
    <a href="add_expense.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Add Expense</span>
    </a>
    <a href="categories.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Categories</span>
    </a>
    <a href="budget_settings.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Budget Settings</span>
    </a>
    <a href="notifications.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Notifications</span>
    </a>
    <a href="reports.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Reports & Analytics</span>
    </a>
    <a href="profile.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Profile Settings</span>
    </a>
    <a href="linked_accounts.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Linked Accounts</span>
    </a>
    <a href="logout.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg transition-all mt-4 border-t border-gray-200 pt-5">
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
        <aside id="mobileSidebar" class="mobile-menu hidden fixed inset-y-0 left-0 w-64 bg-white border-r border-gray-200 z-50 lg:hidden transform -translate-x-full">
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
            
            <!-- Navigation -->
 <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto h-[calc(100vh-180px)]">
    <a href="dashboard.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Dashboard</span>
    </a>
    <a href="all_expenses.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-white bg-indigo-600 rounded-lg transition-all">
        <span>All Expenses</span>
    </a>
    <a href="add_expense.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Add Expense</span>
    </a>
    <a href="categories.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Categories</span>
    </a>
    <a href="budget_settings.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Budget Settings</span>
    </a>
    <a href="notifications.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Notifications</span>
    </a>
    <a href="reports.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Reports & Analytics</span>
    </a>
    <a href="profile.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Profile Settings</span>
    </a>
    <a href="linked_accounts.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
        <span>Linked Accounts</span>
    </a>
    <a href="logout.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg transition-all mt-4 border-t border-gray-200 pt-5">
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
                    <div class="w-6"></div>
                </div>
            </header>

            <div class="p-4 md:p-6 lg:p-8">
                <!-- Page Header -->
                <div class="mb-6">
                    <a href="all_expenses.php" class="inline-flex items-center gap-2 text-sm font-medium text-indigo-600 hover:text-indigo-700 mb-4">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                        Back to All Expenses
                    </a>
                    
                    <div class="bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl shadow-lg p-6 md:p-8 text-white">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <h1 class="text-2xl md:text-3xl font-bold mb-2 flex items-center gap-2">
                                    <span></span> Edit Expense
                                </h1>
                                <p class="text-indigo-100">Update your expense details and keep your budget on track</p>
                            </div>
                            <div class="inline-flex items-center gap-2 px-4 py-2 bg-white bg-opacity-20 backdrop-blur-sm rounded-lg text-sm font-semibold">
                                <span>ID:</span>
                                <span>#<?php echo $expense['id']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($_SESSION['errors'])): ?>
                <!-- Error Alert -->
                <div class="alert-banner mb-6 bg-red-50 border border-red-200 rounded-xl p-4">
                    <div class="flex gap-3">
                        <span class="text-2xl">⚠️</span>
                        <div class="flex-1">
                            <p class="text-red-800 font-semibold mb-2">Please fix the following errors:</p>
                            <ul class="space-y-1">
                                <?php foreach ($_SESSION['errors'] as $error): ?>
                                <li class="text-sm text-red-700 flex items-start gap-2">
                                    <span class="mt-1">•</span>
                                    <span><?php echo htmlspecialchars($error); ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php unset($_SESSION['errors']); ?>
                <?php endif; ?>

                <!-- Main Content Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Edit Form - Takes 2 columns on large screens -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 md:p-8">
                            <h2 class="text-xl font-bold text-gray-900 mb-6 flex items-center gap-2">
                                <span class="text-2xl"> </span>
                                <span>Expense Information</span>
                            </h2>
                            
                            <form method="POST" action="edit_expense.php?id=<?php echo $expense_id; ?>" id="expenseForm">
                                <div class="space-y-6">
                                    <!-- Amount -->
                                    <div>
                                        <label for="amount" class="block text-sm font-semibold text-gray-700 mb-2">
                                             Amount
                                        </label>
                                        <div class="relative">
                                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-indigo-600 font-bold text-lg">₱</span>
                                            <input 
                                                type="number" 
                                                id="amount" 
                                                name="amount" 
                                                step="0.01" 
                                                min="0.01" 
                                                placeholder="0.00"
                                                value="<?php echo isset($_POST['amount']) ? htmlspecialchars($_POST['amount']) : htmlspecialchars($expense['amount']); ?>"
                                                required
                                                autofocus
                                                class="form-input w-full pl-10 pr-4 py-3 border-2 border-gray-200 rounded-lg focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 text-lg font-semibold transition-all bg-gray-50 focus:bg-white"
                                            >
                                        </div>
                                        <p class="mt-2 text-xs text-gray-500">Enter the amount you spent</p>
                                    </div>

                                    <!-- Expense Date -->
                                    <div>
                                        <label for="expense_date" class="block text-sm font-semibold text-gray-700 mb-2">
                                             Expense Date
                                        </label>
                                        <input 
                                            type="date" 
                                            id="expense_date" 
                                            name="expense_date"
                                            value="<?php echo isset($_POST['expense_date']) ? htmlspecialchars($_POST['expense_date']) : htmlspecialchars($expense['expense_date']); ?>"
                                            max="<?php echo date('Y-m-d'); ?>"
                                            required
                                            class="form-input w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 transition-all bg-gray-50 focus:bg-white"
                                        >
                                        <p class="mt-2 text-xs text-gray-500">When did this expense occur?</p>
                                    </div>

                                    <!-- Category -->
                                    <div>
                                        <label for="category_id" class="block text-sm font-semibold text-gray-700 mb-2">
                                             Category
                                        </label>
                                        <select 
                                            id="category_id" 
                                            name="category_id" 
                                            required
                                            class="form-input w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 transition-all bg-gray-50 focus:bg-white appearance-none"
                                            style="background-image: url('data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 12 12%27%3E%3Cpath fill=%27%236366f1%27 d=%27M6 9L1 4h10z%27/%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 1rem center;"
                                        >
                                            <option value="">-- Select a Category --</option>
                                            <?php foreach ($categories as $category): ?>
                                            <option 
                                                value="<?php echo $category['id']; ?>"
                                                data-color="<?php echo htmlspecialchars($category['color']); ?>"
                                                <?php 
                                                $selected_category = isset($_POST['category_id']) ? $_POST['category_id'] : $expense['category_id'];
                                                echo ($selected_category == $category['id']) ? 'selected' : ''; 
                                                ?>
                                            >
                                                <?php echo htmlspecialchars($category['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="mt-2 text-xs text-gray-500">Choose the category that best fits this expense</p>
                                    </div>

                                    <!-- Description -->
                                    <div>
                                        <label for="description" class="block text-sm font-semibold text-gray-700 mb-2">
                                             Description
                                        </label>
                                        <textarea 
                                            id="description" 
                                            name="description" 
                                            rows="4"
                                            placeholder="E.g., Lunch at restaurant, Gas refill, Monthly Netflix subscription..."
                                            required
                                            class="form-input w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 transition-all resize-none bg-gray-50 focus:bg-white"
                                        ><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : htmlspecialchars($expense['description']); ?></textarea>
                                        <p class="mt-2 text-xs text-gray-500">Provide details about this expense</p>
                                    </div>
                                </div>

                                <!-- Form Actions -->
                                <div class="flex flex-col sm:flex-row gap-3 mt-8 pt-6 border-t-2 border-gray-100">
                                    <button 
                                        type="submit" 
                                        name="update_expense"
                                        class="flex-1 flex items-center justify-center gap-2 px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-lg font-semibold hover:from-indigo-700 hover:to-purple-700 focus:ring-4 focus:ring-indigo-200 transition-all transform hover:-translate-y-0.5 shadow-md hover:shadow-lg"
                                    >
                                        <span class="text-lg">✅</span>
                                        <span>Update Expense</span>
                                    </button>
                                    <a 
                                        href="all_expenses.php"
                                        class="flex-1 flex items-center justify-center gap-2 px-6 py-3 bg-gray-100 text-gray-700 rounded-lg font-semibold hover:bg-gray-200 focus:ring-4 focus:ring-gray-200 transition-all border-2 border-gray-200"
                                    >
                                        <span class="text-lg">❌</span>
                                        <span>Cancel</span>
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Sidebar Info - Takes 1 column on large screens -->
                    <div class="space-y-6">
                        <!-- Original Details Card -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                                <span class="text-xl"></span>
                                <span>Original Details</span>
                            </h3>
                            <div class="space-y-4">
                                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                                    <span class="text-sm text-gray-600">Original Amount</span>
                                    <span class="text-lg font-bold text-indigo-600">₱<?php echo number_format($expense['amount'], 2); ?></span>
                                </div>
                                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                                    <span class="text-sm text-gray-600">Original Date</span>
                                    <span class="text-sm font-semibold text-gray-900"><?php echo date('M d, Y', strtotime($expense['expense_date'])); ?></span>
                                </div>
                                <div class="flex justify-between items-start">
                                    <span class="text-sm text-gray-600">Category</span>
                                    <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold" 
                                          style="background-color: <?php echo htmlspecialchars($expense['category_color']); ?>20; color: <?php echo htmlspecialchars($expense['category_color']); ?>">
                                        <span class="w-2 h-2 rounded-full" style="background-color: <?php echo htmlspecialchars($expense['category_color']); ?>"></span>
                                        <?php echo htmlspecialchars($expense['category_name']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Warning Card -->
                        <div class="bg-gradient-to-br from-amber-50 to-orange-50 border border-amber-200 rounded-xl p-6">
                            <h4 class="text-base font-bold text-amber-900 mb-2 flex items-center gap-2">
                                <span class="text-xl">⚠️</span>
                                <span>Important Note</span>
                            </h4>
                            <p class="text-sm text-amber-800 leading-relaxed">
                                Changes to this expense will affect your budget calculations and reports. Make sure the information is accurate before updating.
                            </p>
                        </div>

                        <!-- Tips Card -->
                        <div class="bg-gradient-to-br from-green-50 to-emerald-50 border border-green-200 rounded-xl p-6">
                            <h4 class="text-base font-bold text-green-900 mb-2 flex items-center gap-2">
                                <span class="text-xl">💡</span>
                                <span>Quick Tips</span>
                            </h4>
                            <ul class="space-y-2">
                                <li class="text-sm text-green-800 flex items-start gap-2">
                                    <span class="mt-1">•</span>
                                    <span>Double-check the amount for accuracy</span>
                                </li>
                                <li class="text-sm text-green-800 flex items-start gap-2">
                                    <span class="mt-1">•</span>
                                    <span>Choose the most appropriate category</span>
                                </li>
                                <li class="text-sm text-green-800 flex items-start gap-2">
                                    <span class="mt-1">•</span>
                                    <span>Add detailed descriptions for better tracking</span>
                                </li>
                                <li class="text-sm text-green-800 flex items-start gap-2">
                                    <span class="mt-1">•</span>
                                    <span>Verify the date is correct</span>
                                </li>
                            </ul>
                        </div>
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

function openMobileSidebar() {
    mobileSidebar.classList.remove('hidden', '-translate-x-full');
    sidebarOverlay.classList.remove('hidden');
    document.body.style.overflow = 'hidden'; // Prevent background scroll
}

function closeMobileSidebar() {
    mobileSidebar.classList.add('-translate-x-full');
    sidebarOverlay.classList.add('hidden');
    document.body.style.overflow = ''; // Restore scroll
    setTimeout(() => {
        mobileSidebar.classList.add('hidden');
    }, 300); // Match transition duration
}

openSidebar?.addEventListener('click', openMobileSidebar);
closeSidebar?.addEventListener('click', closeMobileSidebar);
sidebarOverlay?.addEventListener('click', closeMobileSidebar);

// Close sidebar on navigation (mobile only)
if (window.innerWidth < 1024) {
    document.querySelectorAll('#mobileSidebar .nav-item').forEach(link => {
        link.addEventListener('click', () => {
            closeMobileSidebar();
        });
    });
}

// Handle window resize
let resizeTimer;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
        if (window.innerWidth >= 1024) {
            closeMobileSidebar();
        }
    }, 250);
});

        // Auto-format amount on blur
        const amountInput = document.getElementById('amount');
        if (amountInput) {
            amountInput.addEventListener('blur', function() {
                if (this.value) {
                    this.value = parseFloat(this.value).toFixed(2);
                }
            });
        }

        // Prevent future dates
        const dateInput = document.getElementById('expense_date');
        if (dateInput) {
            dateInput.max = new Date().toISOString().split('T')[0];
        }

        // Track form changes
        let formChanged = false;
        const form = document.getElementById('expenseForm');
        const formElements = form.querySelectorAll('input, select, textarea');
        
        formElements.forEach(element => {
            element.addEventListener('change', () => {
                formChanged = true;
            });
        });

        // Warn before leaving with unsaved changes
        window.addEventListener('beforeunload', (e) => {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        form.addEventListener('submit', () => {
            formChanged = false;
        });

        // Category select color preview
        const categorySelect = document.getElementById('category_id');
        if (categorySelect) {
            categorySelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const color = selectedOption.getAttribute('data-color');
                if (color) {
                    this.style.borderColor = color;
                }
            });
            
            // Trigger on page load if category is selected
            if (categorySelect.value) {
                const event = new Event('change');
                categorySelect.dispatchEvent(event);
            }
        }
    </script>
</body>
</html>