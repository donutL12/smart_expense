<?php
require_once 'includes/auth_admin.php';
require_once '../includes/db_connect.php';

// Handle form submissions
$message = '';
$error = '';

// Add new category
if (isset($_POST['add_category'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    
    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $description);
        
        if ($stmt->execute()) {
            $message = "Category added successfully!";
        } else {
            $error = "Error adding category: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error = "Category name is required!";
    }
}

// Edit category
if (isset($_POST['edit_category'])) {
    $id = intval($_POST['category_id']);
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    
    if (!empty($name)) {
        $stmt = $conn->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?");
        $stmt->bind_param("ssi", $name, $description, $id);
        
        if ($stmt->execute()) {
            $message = "Category updated successfully!";
        } else {
            $error = "Error updating category: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error = "Category name is required!";
    }
}

// Delete category
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Check if category is being used
    $check = $conn->query("SELECT COUNT(*) as count FROM expenses WHERE category_id = $id");
    $usage = $check->fetch_assoc()['count'];
    
    if ($usage > 0) {
        $error = "Cannot delete category: It is being used by $usage expense(s). Please reassign those expenses first.";
    } else {
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $message = "Category deleted successfully!";
        } else {
            $error = "Error deleting category: " . $conn->error;
        }
        $stmt->close();
    }
}

// Get all categories with usage statistics
$categories = $conn->query("SELECT c.*, 
                            COUNT(e.id) as usage_count,
                            COALESCE(SUM(e.amount), 0) as total_amount,
                            u.name as created_by
                            FROM categories c
                            LEFT JOIN expenses e ON c.id = e.category_id
                            LEFT JOIN users u ON c.user_id = u.id
                            GROUP BY c.id
                            ORDER BY c.user_id IS NULL DESC, c.created_at DESC");

// Get statistics
$total_categories = $conn->query("SELECT COUNT(*) as count FROM categories")->fetch_assoc()['count'];
$default_categories = $conn->query("SELECT COUNT(*) as count FROM categories WHERE user_id IS NULL")->fetch_assoc()['count'];
$user_categories = $conn->query("SELECT COUNT(*) as count FROM categories WHERE user_id IS NOT NULL")->fetch_assoc()['count'];
$total_amount = $conn->query("SELECT COALESCE(SUM(e.amount), 0) as total FROM expenses e JOIN categories c ON e.category_id = c.id")->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories - FinSight Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
                    <a href="manage_categories.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-white bg-primary rounded-lg">
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
                        <h1 class="text-3xl font-bold text-gray-900">Manage Categories</h1>
                        <p class="text-sm text-gray-500 mt-1">Organize and manage expense categories</p>
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
            
            <!-- Alert Messages -->
            <?php if ($message): ?>
                <div class="bg-green-50 border border-green-200 text-green-800 rounded-xl p-4 mb-6 flex items-start gap-3">
                    <svg class="w-5 h-5 text-green-500 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    <span class="text-sm font-medium"><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 mb-6 flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-500 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    <span class="text-sm font-medium"><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Total Categories</h3>
                        <span class="text-xs font-semibold text-blue-600 bg-blue-50 px-2 py-1 rounded">Active</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2"><?php echo number_format($total_categories); ?></p>
                    <p class="text-xs text-gray-500">All expense categories</p>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Default Categories</h3>
                        <span class="text-xs font-semibold text-purple-600 bg-purple-50 px-2 py-1 rounded">System</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2"><?php echo number_format($default_categories); ?></p>
                    <p class="text-xs text-gray-500">Pre-defined categories</p>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">User Created</h3>
                        <span class="text-xs font-semibold text-green-600 bg-green-50 px-2 py-1 rounded">Custom</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2"><?php echo number_format($user_categories); ?></p>
                    <p class="text-xs text-gray-500">User-defined categories</p>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Total Tracked</h3>
                        <span class="text-xs font-semibold text-orange-600 bg-orange-50 px-2 py-1 rounded">Amount</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2">₱<?php echo number_format($total_amount, 0); ?></p>
                    <p class="text-xs text-gray-500">Across all categories</p>
                </div>
            </div>
            
            <!-- Add Category Section -->
            <div class="bg-white rounded-xl border border-gray-200 p-6 mb-8">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Add New Category</h2>
                        <p class="text-xs text-gray-500 mt-1">Create a new expense category</p>
                    </div>
                </div>
                <form method="POST" action="">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Category Name *</label>
                            <input type="text" id="name" name="name" required 
                                   placeholder="e.g., Food & Dining"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                        </div>
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                            <input type="text" id="description" name="description" 
                                   placeholder="Brief description (optional)"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                        </div>
                    </div>
                    <button type="submit" name="add_category" 
                            class="bg-primary hover:bg-primary-600 text-white px-6 py-3 rounded-lg font-medium transition-all hover:shadow-lg text-sm">
                        Add Category
                    </button>
                </form>
            </div>
            
            <!-- Categories List -->
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">All Categories</h2>
                        <p class="text-xs text-gray-500 mt-1">Manage existing categories</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="text" id="searchInput" placeholder="Search categories..." 
                               class="px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-primary">
                    </div>
                </div>
                
                <?php if ($categories->num_rows > 0): ?>
                    <!-- Desktop Table View -->
                    <div class="hidden lg:block overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">ID</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Category Name</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Description</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Type</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Usage</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Total Amount</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="categoryTableBody">
                                <?php 
                                $categories->data_seek(0);
                                while ($cat = $categories->fetch_assoc()): 
                                ?>
                                <tr class="hover:bg-gray-50 transition-colors category-row">
                                    <td class="px-4 py-4">
                                        <span class="inline-block bg-gray-100 text-gray-700 px-3 py-1 rounded-full text-xs font-medium">#<?php echo $cat['id']; ?></span>
                                    </td>
                                    <td class="px-4 py-4 font-semibold text-gray-900 category-name"><?php echo htmlspecialchars($cat['name']); ?></td>
                                    <td class="px-4 py-4 text-gray-600 text-sm"><?php echo htmlspecialchars($cat['description'] ?? 'N/A'); ?></td>
                                    <td class="px-4 py-4">
                                        <?php if ($cat['user_id'] === null): ?>
                                            <span class="inline-block bg-primary text-white px-3 py-1 rounded-full text-xs font-medium">Default</span>
                                        <?php else: ?>
                                            <span class="inline-block bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-medium">User: <?php echo htmlspecialchars($cat['created_by']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-2">
                                            <span class="text-gray-900 font-medium"><?php echo number_format($cat['usage_count']); ?></span>
                                            <span class="text-xs text-gray-500">expenses</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 font-bold text-gray-900">₱<?php echo number_format($cat['total_amount'], 2); ?></td>
                                    <td class="px-4 py-4">
                                        <div class="flex items-center gap-2">
                                            <button onclick="openEditModal(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['name']); ?>', '<?php echo addslashes($cat['description'] ?? ''); ?>')" 
                                                    class="bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-1.5 rounded-lg text-xs font-medium transition-all">
                                                Edit
                                            </button>
                                            <?php if ($cat['usage_count'] == 0): ?>
                                                <a href="?delete=<?php echo $cat['id']; ?>" 
                                                   onclick="return confirm('Are you sure you want to delete this category?')"
                                                   class="bg-red-50 hover:bg-red-100 text-red-700 px-3 py-1.5 rounded-lg text-xs font-medium transition-all">
                                                    Delete
                                                </a>
                                            <?php else: ?>
                                                <button disabled title="Cannot delete: Category is in use" 
                                                        class="bg-gray-100 text-gray-400 px-3 py-1.5 rounded-lg text-xs font-medium cursor-not-allowed">
                                                    Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Card View -->
                    <div class="lg:hidden space-y-4" id="categoryCardContainer">
                        <?php 
                        $categories->data_seek(0);
                        while ($cat = $categories->fetch_assoc()): 
                        ?>
                        <div class="border border-gray-200 rounded-xl p-4 hover:shadow-md transition-shadow category-card">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="inline-block bg-gray-100 text-gray-700 px-2 py-1 rounded-full text-xs font-medium">#<?php echo $cat['id']; ?></span>
                                        <?php if ($cat['user_id'] === null): ?>
                                            <span class="inline-block bg-primary text-white px-2 py-1 rounded-full text-xs font-medium">Default</span>
                                        <?php else: ?>
                                            <span class="inline-block bg-blue-100 text-blue-700 px-2 py-1 rounded-full text-xs font-medium">User</span>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="font-bold text-gray-900 text-lg category-name"><?php echo htmlspecialchars($cat['name']); ?></h3>
                                    <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($cat['description'] ?? 'No description'); ?></p>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-3 py-3 border-t border-gray-100">
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Usage</p>
                                    <p class="font-semibold text-gray-900"><?php echo number_format($cat['usage_count']); ?> expenses</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Total Amount</p>
                                    <p class="font-bold text-gray-900">₱<?php echo number_format($cat['total_amount'], 2); ?></p>
                                </div>
                            </div>

                            <?php if ($cat['user_id'] !== null): ?>
                            <div class="py-2 border-t border-gray-100">
                                <p class="text-xs text-gray-500">Created by: <span class="font-medium text-gray-700"><?php echo htmlspecialchars($cat['created_by']); ?></span></p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="flex gap-2 mt-3 pt-3 border-t border-gray-100">
                                <button onclick="openEditModal(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['name']); ?>', '<?php echo addslashes($cat['description'] ?? ''); ?>')" 
                                        class="flex-1 bg-blue-50 hover:bg-blue-100 text-blue-700 px-4 py-2 rounded-lg text-sm font-medium transition-all">
                                    Edit
                                </button>
                                <?php if ($cat['usage_count'] == 0): ?>
                                    <a href="?delete=<?php echo $cat['id']; ?>" 
                                       onclick="return confirm('Are you sure you want to delete this category?')"
                                       class="flex-1 bg-red-50 hover:bg-red-100 text-red-700 px-4 py-2 rounded-lg text-sm font-medium transition-all text-center">
                                        Delete
                                    </a>
                                <?php else: ?>
                                    <button disabled title="Cannot delete: Category is in use" 
                                            class="flex-1 bg-gray-100 text-gray-400 px-4 py-2 rounded-lg text-sm font-medium cursor-not-allowed">
                                        Delete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-16 text-gray-400">
                        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        <p class="text-lg font-medium">No categories found</p>
                        <p class="text-sm mt-2">Add your first category using the form above</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Edit Modal -->
    <div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full max-h-[90vh] overflow-y-auto shadow-2xl">
            <div class="sticky top-0 bg-white p-6 border-b border-gray-200 rounded-t-2xl">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-gray-900">Edit Category</h3>
                    <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <form method="POST" action="" class="p-6">
                <input type="hidden" name="category_id" id="edit_category_id">
                <div class="space-y-4">
                    <div>
                        <label for="edit_name" class="block text-sm font-medium text-gray<div>
                        <label for="edit_name" class="block text-sm font-medium text-gray-700 mb-2">Category Name *</label>
                        <input type="text" id="edit_name" name="name" required 
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                    </div>
                    <div>
                        <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <input type="text" id="edit_description" name="description" 
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all text-sm">
                    </div>
                </div>
                
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeEditModal()" 
                            class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-3 rounded-lg font-medium transition-all text-sm">
                        Cancel
                    </button>
                    <button type="submit" name="edit_category" 
                            class="flex-1 bg-primary hover:bg-primary-600 text-white px-6 py-3 rounded-lg font-medium transition-all hover:shadow-lg text-sm">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
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

        // Edit Modal Functions
        function openEditModal(id, name, description) {
            document.getElementById('edit_category_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_description').value = description;
            document.getElementById('editModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Close modal on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeEditModal();
            }
        });

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const categoryRows = document.querySelectorAll('.category-row');
        const categoryCards = document.querySelectorAll('.category-card');

        searchInput.addEventListener('input', (e) => {
            const searchTerm = e.target.value.toLowerCase();

            // Filter desktop table rows
            categoryRows.forEach(row => {
                const categoryName = row.querySelector('.category-name').textContent.toLowerCase();
                if (categoryName.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            // Filter mobile cards
            categoryCards.forEach(card => {
                const categoryName = card.querySelector('.category-name').textContent.toLowerCase();
                if (categoryName.includes(searchTerm)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.bg-green-50, .bg-red-50');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>