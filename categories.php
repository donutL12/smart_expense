<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/auth_user.php';

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

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $color = $_POST['color'];
    
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Category name is required.";
    }
    
    // Check if category already exists
    $check_query = "SELECT id FROM categories WHERE name = ? AND (user_id = ? OR user_id IS NULL)";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("si", $name, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $errors[] = "A category with this name already exists.";
    }
    
    if (empty($errors)) {
        $insert_query = "INSERT INTO categories (name, description, color, user_id) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("sssi", $name, $description, $color, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Category added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add category.";
        }
    } else {
        $_SESSION['errors'] = $errors;
    }
    
    header("Location: categories.php");
    exit();
}

// Handle Edit Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $category_id = intval($_POST['category_id']);
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $color = $_POST['color'];
    
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Category name is required.";
    }
    
    // Check if user owns this category
    $check_owner = "SELECT user_id FROM categories WHERE id = ?";
    $stmt = $conn->prepare($check_owner);
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result && $result['user_id'] != $user_id && $result['user_id'] !== null) {
        $errors[] = "You don't have permission to edit this category.";
    }
    
    if (empty($errors)) {
        $update_query = "UPDATE categories SET name = ?, description = ?, color = ? WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("sssii", $name, $description, $color, $category_id, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Category updated successfully!";
        } else {
            $_SESSION['error'] = "Failed to update category.";
        }
    } else {
        $_SESSION['errors'] = $errors;
    }
    
    header("Location: categories.php");
    exit();
}

// Handle Delete Category
if (isset($_POST['delete_category'])) {
    $category_id = intval($_POST['category_id']);
    
    // Check if user owns this category
    $check_owner = "SELECT user_id FROM categories WHERE id = ?";
    $stmt = $conn->prepare($check_owner);
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result && $result['user_id'] == $user_id) {
        // Check if category has expenses
        $check_expenses = "SELECT COUNT(*) as count FROM expenses WHERE category_id = ? AND user_id = ?";
        $stmt = $conn->prepare($check_expenses);
        $stmt->bind_param("ii", $category_id, $user_id);
        $stmt->execute();
        $expense_count = $stmt->get_result()->fetch_assoc()['count'];
        
        if ($expense_count > 0) {
            $_SESSION['error'] = "Cannot delete category with existing expenses. Please reassign or delete those expenses first.";
        } else {
            $delete_query = "DELETE FROM categories WHERE id = ? AND user_id = ?";
            $stmt = $conn->prepare($delete_query);
            $stmt->bind_param("ii", $category_id, $user_id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Category deleted successfully!";
            } else {
                $_SESSION['error'] = "Failed to delete category.";
            }
        }
    } else {
        $_SESSION['error'] = "You don't have permission to delete this category.";
    }
    
    header("Location: categories.php");
    exit();
}

// Fetch all categories (system + user's custom categories)
$categories_query = "SELECT c.id, c.name, c.description, c.color, c.user_id,
    COUNT(e.id) as expense_count,
    COALESCE(SUM(e.amount), 0) as total_spent
FROM categories c
LEFT JOIN expenses e ON c.id = e.category_id AND e.user_id = ?
WHERE c.user_id IS NULL OR c.user_id = ?
GROUP BY c.id, c.name, c.description, c.color, c.user_id
ORDER BY c.user_id DESC, c.name ASC";
$stmt = $conn->prepare($categories_query);
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Separate system and custom categories
$system_categories = array_filter($categories, fn($cat) => $cat['user_id'] === null);
$custom_categories = array_filter($categories, fn($cat) => $cat['user_id'] !== null);

// Predefined color palette
$color_palette = [
    '#667eea', '#764ba2', '#f093fb', '#f5576c', '#4facfe', 
    '#00f2fe', '#43e97b', '#38f9d7', '#fa709a', '#fee140',
    '#30cfd0', '#330867', '#a8edea', '#fed6e3', '#fbc2eb'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - FinSight</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
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

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

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

        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease forwards;
        }

        .animate-slide-in {
            animation: slideIn 0.6s ease forwards;
        }

        .animate-pulse-slow {
            animation: pulse 2s infinite;
        }

        .animate-slide-down {
            animation: slideDown 0.3s ease forwards;
        }

        /* Staggered animation delays */
        .stagger-1 { animation-delay: 0.1s; opacity: 0; }
        .stagger-2 { animation-delay: 0.2s; opacity: 0; }
        .stagger-3 { animation-delay: 0.3s; opacity: 0; }
        .stagger-4 { animation-delay: 0.4s; opacity: 0; }
        .stagger-5 { animation-delay: 0.5s; opacity: 0; }
        .stagger-6 { animation-delay: 0.6s; opacity: 0; }

        /* Mobile menu */
        .mobile-menu {
            transition: transform 0.3s ease-in-out;
        }

        .mobile-menu.hidden {
            transform: translateX(-100%);
        }

        /* Color picker styles */
        .color-option {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .color-option::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0);
            width: 16px;
            height: 16px;
            background: white;
            border-radius: 50%;
            transition: transform 0.3s ease;
        }

        .color-option.selected {
            transform: scale(1.15);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
        }

        .color-option.selected::after {
            transform: translate(-50%, -50%) scale(1);
        }

        /* Modal backdrop */
        .modal-backdrop {
            backdrop-filter: blur(4px);
        }

        /* Card hover effects */
        .category-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .category-card:hover {
            transform: translateY(-4px);
        }

        /* Smooth nav transitions */
        .nav-item {
            transition: all 0.2s ease;
        }
        /* Enhanced mobile menu */
.mobile-menu {
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.mobile-menu.hidden {
    transform: translateX(-100%);
}

/* Smooth scrolling for nav */
nav {
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f1f5f9;
}

/* Icon consistency */
.nav-item i {
    flex-shrink: 0;
}
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <!-- Sidebar - Desktop -->
        <aside class="hidden lg:flex lg:flex-col lg:w-64 bg-white border-r border-gray-200 fixed h-full z-30">
            <!-- Logo -->
            <div class="flex items-center gap-3 px-6 py-5 border-b border-gray-200">
                <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                    <span class="text-white text-xl font-bold">F</span>
                </div>
                <h2 class="text-xl font-bold text-gray-900">FinSight</h2>
            </div>
            
            <!-- Navigation -->
<!-- Navigation -->
<nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
    <a href="dashboard.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg group">
        <span>Dashboard</span>
    </a>
    <a href="all_expenses.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg group">
        <span>All Expenses</span>
    </a>
    <a href="add_expense.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg group">
        <span>Add Expense</span>
    </a>
    <a href="categories.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg shadow-sm">
        <span>Categories</span>
    </a>
    <a href="budget_settings.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg group">
        <span>Budget Settings</span>
    </a>
    <a href="notifications.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg group">
        <span>Notifications</span>
    </a>
    <a href="reports.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg group">
        <span>Reports & Analytics</span>
    </a>
    <a href="profile.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg group">
        <span>Profile Settings</span>
    </a>
    <a href="linked_accounts.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg group">
        <span>Linked Accounts</span>
    </a>
    <a href="logout.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg group">
        <span>Logout</span>
    </a>
</nav>
            
            <!-- User Info -->
            <div class="p-4 border-t border-gray-200">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold shadow-md">
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
        <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden modal-backdrop"></div>

        <!-- Mobile Sidebar -->
        <aside id="mobileSidebar" class="mobile-menu hidden fixed inset-y-0 left-0 w-64 bg-white border-r border-gray-200 z-50 lg:hidden transform -translate-x-full shadow-2xl">
            <!-- Logo -->
            <div class="flex items-center justify-between px-6 py-5 border-b border-gray-200">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                        <span class="text-white text-xl font-bold">F</span>
                    </div>
                    <h2 class="text-xl font-bold text-gray-900">FinSight</h2>
                </div>
                <button id="closeSidebar" class="text-gray-500 hover:text-gray-700 p-1 hover:bg-gray-100 rounded-lg transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
   <!-- Navigation -->
<!-- Navigation -->
<nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto h-[calc(100vh-180px)]">
    <a href="dashboard.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Dashboard</span>
    </a>
    <a href="all_expenses.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>All Expenses</span>
    </a>
    <a href="add_expense.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Add Expense</span>
    </a>
    <a href="categories.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg shadow-sm">
        <span>Categories</span>
    </a>
    <a href="budget_settings.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Budget Settings</span>
    </a>
    <a href="notifications.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Notifications</span>
    </a>
    <a href="reports.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Reports & Analytics</span>
    </a>
    <a href="profile.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Profile Settings</span>
    </a>
    <a href="linked_accounts.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Linked Accounts</span>
    </a>
    <a href="logout.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg">
        <span>Logout</span>
    </a>
</nav>
            
            <!-- User Info -->
            <div class="p-4 border-t border-gray-200">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold shadow-md">
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
            <header class="lg:hidden sticky top-0 z-20 bg-white border-b border-gray-200 shadow-sm">
                <div class="flex items-center justify-between px-4 py-3">
                    <button id="openSidebar" class="text-gray-700 hover:text-gray-900 p-2 hover:bg-gray-100 rounded-lg transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-lg flex items-center justify-center shadow-md">
                            <span class="text-white text-sm font-bold">F</span>
                        </div>
                        <h2 class="text-lg font-bold text-gray-900">FinSight</h2>
                    </div>
                    <div class="w-10"></div>
                </div>
            </header>

            <div class="p-4 md:p-6 lg:p-8">
                <!-- Page Header -->
                <div class="mb-6 md:mb-8 animate-fade-in-up">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h1 class="text-2xl md:text-3xl font-bold text-gray-900 flex items-center gap-3">
                                <span class="text-3xl md:text-4xl animate-pulse-slow"></span>
                                Expense Categories
                            </h1>
                            <p class="text-gray-600 mt-1">Organize and manage your spending categories</p>
                        </div>
                        <button onclick="openAddModal()" class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-200">
                            <span class="text-xl">➕</span>
                            <span>Add Category</span>
                        </button>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="mb-6 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-xl p-4 flex items-center gap-3 animate-slide-down shadow-sm">
                    <span class="text-2xl">✅</span>
                    <span class="text-green-800 font-medium"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                <div class="mb-6 bg-gradient-to-r from-red-50 to-rose-50 border border-red-200 rounded-xl p-4 flex items-center gap-3 animate-slide-down shadow-sm">
                    <span class="text-2xl">⚠️</span>
                    <span class="text-red-800 font-medium"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['errors'])): ?>
                <div class="mb-6 bg-gradient-to-r from-red-50 to-rose-50 border border-red-200 rounded-xl p-4 animate-slide-down shadow-sm">
                    <div class="flex items-start gap-3">
                        <span class="text-2xl">⚠️</span>
                        <div class="flex-1">
                            <p class="text-red-800 font-semibold mb-2">Please fix the following errors:</p>
                            <ul class="list-disc list-inside space-y-1 text-red-700">
                                <?php foreach ($_SESSION['errors'] as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php unset($_SESSION['errors']); ?>
                <?php endif; ?>

                <!-- Category Statistics Summary -->
<!-- Category Statistics Summary -->
<div class="grid grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6 mb-6 md:mb-8">
    <div class="bg-gradient-to-br from-purple-500 to-purple-700 rounded-2xl p-6 text-white shadow-lg hover:shadow-xl transition-all duration-300 hover:scale-105 animate-fade-in-up stagger-1">
        <div class="flex items-center justify-between mb-4">
            <div class="text-4xl animate-pulse-slow">📊</div>
            <div class="text-right">
                <div class="text-3xl font-bold"><?php echo count($categories); ?></div>
                <div class="text-sm opacity-90">Total Categories</div>
            </div>
        </div>
        <div class="h-1 bg-white bg-opacity-30 rounded-full overflow-hidden">
            <div class="h-full bg-white rounded-full" style="width: 100%"></div>
        </div>
    </div>

    <div class="bg-gradient-to-br from-pink-500 to-rose-700 rounded-2xl p-6 text-white shadow-lg hover:shadow-xl transition-all duration-300 hover:scale-105 animate-fade-in-up stagger-2">
        <div class="flex items-center justify-between mb-4">
            <div class="text-4xl animate-pulse-slow">🎨</div>
            <div class="text-right">
                <div class="text-3xl font-bold"><?php echo count($custom_categories); ?></div>
                <div class="text-sm opacity-90">Custom Categories</div>
            </div>
        </div>
        <div class="h-1 bg-white bg-opacity-30 rounded-full overflow-hidden">
            <div class="h-full bg-white rounded-full" style="width: <?php echo count($categories) > 0 ? (count($custom_categories) / count($categories) * 100) : 0; ?>%"></div>
        </div>
    </div>

    <div class="bg-gradient-to-br from-blue-500 to-cyan-600 rounded-2xl p-6 text-white shadow-lg hover:shadow-xl transition-all duration-300 hover:scale-105 animate-fade-in-up stagger-3 col-span-2 lg:col-span-1">
        <div class="flex items-center justify-between mb-4">
            <div class="text-4xl animate-pulse-slow">💰</div>
            <div class="text-right">
                <div class="text-3xl font-bold">₱<?php echo number_format(array_sum(array_column($categories, 'total_spent')), 0); ?></div>
                <div class="text-sm opacity-90">Total Spending</div>
            </div>
        </div>
        <div class="h-1 bg-white bg-opacity-30 rounded-full overflow-hidden">
            <div class="h-full bg-white rounded-full" style="width: 100%"></div>
        </div>
    </div>
</div>

                <!-- Custom Categories Section -->
                <?php if (!empty($custom_categories)): ?>
                <div class="mb-6 md:mb-8 animate-slide-in">
                    <div class="flex items-center gap-3 mb-4">
                        <h2 class="text-xl md:text-2xl font-bold text-gray-900 flex items-center gap-2">
                            <span class="text-2xl"></span>
                            My Custom Categories
                        </h2>
                        </div>
                    <div class="bg-gray-100 text-gray-600 px-3 py-1.5 rounded-full text-sm font-medium">
                        <?php echo count($custom_categories); ?> categories
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6">
                    <?php foreach ($custom_categories as $category): ?>
                    <div class="category-card bg-white rounded-2xl shadow-lg overflow-hidden border border-gray-200 animate-fade-in-up">
                        <div class="p-4" style="background: linear-gradient(135deg, <?php echo htmlspecialchars($category['color']); ?> 0%, <?php echo htmlspecialchars($category['color']); ?>dd 100%);">
                            <div class="flex items-start justify-between">
                                <h3 class="text-xl font-bold text-white"><?php echo htmlspecialchars($category['name']); ?></h3>
                                <span class="bg-white bg-opacity-30 backdrop-blur-sm text-white text-xs font-semibold px-3 py-1 rounded-full border border-white border-opacity-40">
                                    Custom
                                </span>
                            </div>
                        </div>
                        
                        <div class="p-5">
                            <p class="text-gray-600 text-sm mb-4 min-h-[40px]">
                                <?php echo htmlspecialchars($category['description'] ?: 'No description provided'); ?>
                            </p>
                            
                            <div class="grid grid-cols-2 gap-3 mb-4">
                                <div class="bg-gray-50 rounded-xl p-3 text-center transition-transform hover:scale-105">
                                    <div class="text-xs text-gray-500 mb-1">Transactions</div>
                                    <div class="text-2xl font-bold text-gray-900"><?php echo $category['expense_count']; ?></div>
                                </div>
                                <div class="bg-gray-50 rounded-xl p-3 text-center transition-transform hover:scale-105">
                                    <div class="text-xs text-gray-500 mb-1">Total Spent</div>
                                    <div class="text-xl font-bold text-gray-900">₱<?php echo number_format($category['total_spent'], 0); ?></div>
                                </div>
                            </div>
                            
                            <div class="flex gap-2 pt-4 border-t border-gray-200">
                                <button onclick='openEditModal(<?php echo json_encode($category); ?>)' 
                                        class="flex-1 bg-gray-100 hover:bg-indigo-600 text-gray-700 hover:text-white font-medium py-2.5 rounded-lg transition-all duration-200 flex items-center justify-center gap-2">
                                    <span class="text-lg">✏️</span>
                                    <span>Edit</span>
                                </button>
                                <form method="POST" class="flex-1" onsubmit="return confirm('Are you sure you want to delete this category? This cannot be undone.');">
                                    <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                    <button type="submit" name="delete_category" 
                                            class="w-full bg-gray-100 hover:bg-red-600 text-gray-700 hover:text-white font-medium py-2.5 rounded-lg transition-all duration-200 flex items-center justify-center gap-2">
                                        <span class="text-lg">🗑️</span>
                                        <span>Delete</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="mb-8 bg-white rounded-2xl shadow-sm border border-gray-200 p-12 text-center animate-fade-in-up">
                <div class="text-7xl mb-4 opacity-50">🎨</div>
                <h3 class="text-xl font-bold text-gray-900 mb-2">No Custom Categories Yet</h3>
                <p class="text-gray-600 mb-6">Create your first custom category to organize your expenses better!</p>
                <button onclick="openAddModal()" class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-200">
                    <span class="text-xl">➕</span>
                    <span>Create First Category</span>
                </button>
            </div>
            <?php endif; ?>

            <!-- System Categories Section -->
            <?php if (!empty($system_categories)): ?>
            <div class="animate-slide-in">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl md:text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <span class="text-2xl"></span>
                         Categories
                    </h2>
                    <div class="bg-gray-100 text-gray-600 px-3 py-1.5 rounded-full text-sm font-medium">
                        <?php echo count($system_categories); ?> categories
                    </div>
                </div>

               <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6">
                    <?php foreach ($system_categories as $category): ?>
                    <div class="category-card bg-white rounded-2xl shadow-lg overflow-hidden border border-gray-200 animate-fade-in-up">
                        <div class="p-4" style="background: linear-gradient(135deg, <?php echo htmlspecialchars($category['color']); ?> 0%, <?php echo htmlspecialchars($category['color']); ?>dd 100%);">
                            <div class="flex items-start justify-between">
                                <h3 class="text-xl font-bold text-white"><?php echo htmlspecialchars($category['name']); ?></h3>
                                <span class="bg-white bg-opacity-30 backdrop-blur-sm text-white text-xs font-semibold px-3 py-1 rounded-full border border-white border-opacity-40">
                                    System
                                </span>
                            </div>
                        </div>
                        
                        <div class="p-5">
                            <p class="text-gray-600 text-sm mb-4 min-h-[40px]">
                                <?php echo htmlspecialchars($category['description'] ?: 'System-defined category'); ?>
                            </p>
                            
                            <div class="grid grid-cols-2 gap-3">
                                <div class="bg-gray-50 rounded-xl p-3 text-center transition-transform hover:scale-105">
                                    <div class="text-xs text-gray-500 mb-1">Transactions</div>
                                    <div class="text-2xl font-bold text-gray-900"><?php echo $category['expense_count']; ?></div>
                                </div>
                                <div class="bg-gray-50 rounded-xl p-3 text-center transition-transform hover:scale-105">
                                    <div class="text-xs text-gray-500 mb-1">Total Spent</div>
                                    <div class="text-xl font-bold text-gray-900">₱<?php echo number_format($category['total_spent'], 0); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Add Category Modal -->
<div id="addModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center modal-backdrop" onclick="if(event.target === this) closeAddModal()">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 rounded-t-2xl z-10">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center text-2xl shadow-lg">
                        ➕
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900">Add New Category</h2>
                        <p class="text-sm text-gray-600">Create a custom category for your expenses</p>
                    </div>
                </div>
                <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-full p-2 transition-all duration-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>

        <form method="POST" action="categories.php" class="p-6">
            <div class="space-y-5">
                <div>
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-900 mb-2">
                        <span class="text-lg">📝</span>
                        Category Name *
                    </label>
                    <input type="text" name="name" required
                           placeholder="E.g., Entertainment, Groceries, Travel"
                           class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 transition-all duration-200 outline-none">
                </div>

                <div>
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-900 mb-2">
                        <span class="text-lg">💬</span>
                        Description
                    </label>
                    <textarea name="description" rows="3"
                              placeholder="Add a brief description for this category (optional)..."
                              class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 transition-all duration-200 outline-none resize-none"></textarea>
                </div>

                <div>
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-900 mb-2">
                        <span class="text-lg">🎨</span>
                        Choose Color *
                    </label>
                    <p class="text-sm text-gray-600 mb-3">Select a color to represent this category</p>
                    <input type="hidden" id="add_color" name="color" value="<?php echo $color_palette[0]; ?>">
                    <div class="grid grid-cols-5 sm:grid-cols-8 gap-3 p-4 bg-gray-50 rounded-xl">
                        <?php foreach ($color_palette as $index => $color): ?>
                        <div class="color-option <?php echo $index === 0 ? 'selected' : ''; ?> w-12 h-12 rounded-xl cursor-pointer transition-all duration-300 hover:scale-110"
                             style="background: <?php echo $color; ?>;"
                             onclick="selectColor('add', '<?php echo $color; ?>', this)">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="add_color_preview" class="mt-4 p-4 rounded-xl text-center font-semibold text-white shadow-lg transition-all duration-300"
                         style="background: <?php echo $color_palette[0]; ?>;">
                        Selected Color Preview
                    </div>
                </div>
            </div>

            <div class="flex gap-3 mt-6 pt-6 border-t border-gray-200">
                <button type="submit" name="add_category"
                        class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-semibold py-3 rounded-xl shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-200 flex items-center justify-center gap-2">
                    <span class="text-xl">✓</span>
                    <span>Create Category</span>
                </button>
                <button type="button" onclick="closeAddModal()"
                        class="px-6 py-3 border-2 border-gray-300 text-gray-700 font-semibold rounded-xl hover:bg-gray-50 transition-all duration-200">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Category Modal -->
<div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center modal-backdrop" onclick="if(event.target === this) closeEditModal()">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 rounded-t-2xl z-10">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center text-2xl shadow-lg">
                        ✏️
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900">Edit Category</h2>
                        <p class="text-sm text-gray-600">Update your category details</p>
                    </div>
                </div>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-full p-2 transition-all duration-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>

        <form method="POST" action="categories.php" class="p-6">
            <input type="hidden" id="edit_category_id" name="category_id">
            
            <div class="space-y-5">
                <div>
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-900 mb-2">
                        <span class="text-lg">📝</span>
                        Category Name *
                    </label>
                    <input type="text" id="edit_name" name="name" required
                           class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 transition-all duration-200 outline-none">
                </div>

                <div>
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-900 mb-2">
                        <span class="text-lg">💬</span>
                        Description
                    </label>
                    <textarea id="edit_description" name="description" rows="3"
                              class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl focus:border-indigo-600 focus:ring-4 focus:ring-indigo-100 transition-all duration-200 outline-none resize-none"></textarea>
                </div>

                <div>
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-900 mb-2">
                        <span class="text-lg">🎨</span>
                        Choose Color *
                    </label>
                    <input type="hidden" id="edit_color" name="color">
                    <div class="grid grid-cols-5 sm:grid-cols-8 gap-3 p-4 bg-gray-50 rounded-xl" id="editColorPicker">
                        <?php foreach ($color_palette as $color): ?>
                        <div class="color-option w-12 h-12 rounded-xl cursor-pointer transition-all duration-300 hover:scale-110"
                             style="background: <?php echo $color; ?>;"
                             onclick="selectColor('edit', '<?php echo $color; ?>', this)">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="flex gap-3 mt-6 pt-6 border-t border-gray-200">
                <button type="submit" name="edit_category"
                        class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-semibold py-3 rounded-xl shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-200 flex items-center justify-center gap-2">
                    <span class="text-xl">✓</span>
                    <span>Update Category</span>
                </button>
                <button type="button" onclick="closeEditModal()"
                        class="px-6 py-3 border-2 border-gray-300 text-gray-700 font-semibold rounded-xl hover:bg-gray-50 transition-all duration-200">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Modal Functions
    function openAddModal() {
        document.getElementById('addModal').classList.remove('hidden');
        document.getElementById('addModal').classList.add('flex');
    }

    function closeAddModal() {
        document.getElementById('addModal').classList.add('hidden');
        document.getElementById('addModal').classList.remove('flex');
    }

    function openEditModal(category) {
        document.getElementById('edit_category_id').value = category.id;
        document.getElementById('edit_name').value = category.name;
        document.getElementById('edit_description').value = category.description || '';
        document.getElementById('edit_color').value = category.color;
        
        // Select the correct color
        const colorOptions = document.querySelectorAll('#editColorPicker .color-option');
        colorOptions.forEach(option => {
            option.classList.remove('selected');
            if (option.style.background.includes(category.color) || 
                option.style.background === category.color) {
                option.classList.add('selected');
            }
        });
        
        document.getElementById('editModal').classList.remove('hidden');
        document.getElementById('editModal').classList.add('flex');
    }

    function closeEditModal() {
        document.getElementById('editModal').classList.add('hidden');
        document.getElementById('editModal').classList.remove('flex');
    }

    function selectColor(formType, color, element) {
        document.getElementById(formType + '_color').value = color;
        
        // Remove selected class from siblings
        const siblings = element.parentElement.querySelectorAll('.color-option');
        siblings.forEach(sibling => sibling.classList.remove('selected'));
        
        // Add selected class to clicked element
        element.classList.add('selected');
        
        // Update color preview for add modal
        if (formType === 'add') {
            const preview = document.getElementById('add_color_preview');
            if (preview) {
                preview.style.background = color;
            }
        }
    }

    // Mobile sidebar toggle
    const openSidebar = document.getElementById('openSidebar');
    const closeSidebar = document.getElementById('closeSidebar');
    const mobileSidebar = document.getElementById('mobileSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        mobileSidebar.classList.toggle('hidden');
        mobileSidebar.classList.toggle('-translate-x-full');
        sidebarOverlay.classList.toggle('hidden');
        document.body.classList.toggle('overflow-hidden');
    }

    openSidebar?.addEventListener('click', toggleSidebar);
    closeSidebar?.addEventListener('click', toggleSidebar);
    sidebarOverlay?.addEventListener('click', toggleSidebar);

    // Close modals on ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeAddModal();
            closeEditModal();
        }
    });
</script>
</body>
</html>