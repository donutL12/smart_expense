<?php
require_once 'includes/auth_admin.php';
require_once '../includes/db_connect.php';

$success = '';
$error = '';

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $monthly_budget = !empty($_POST['monthly_budget']) ? floatval($_POST['monthly_budget']) : 0;
    
    // Validate inputs
    if (empty($name) || empty($email) || empty($password) || empty($role)) {
        $error = "All fields except budget are required";
    } else {
        // Check if email already exists
        $check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check_email->bind_param("s", $email);
        $check_email->execute();
        if ($check_email->get_result()->num_rows > 0) {
            $error = "Email already exists";
        } else {
            $hashed_password = md5($password);
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, monthly_budget) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssd", $name, $email, $hashed_password, $role, $monthly_budget);
            
            if ($stmt->execute()) {
                $success = "User added successfully";
                
                // Log activity
                $log_stmt = $conn->prepare("INSERT INTO system_logs (user_id, action) VALUES (?, ?)");
                $log_action = "Admin added new user: $name ($email)";
                $log_stmt->bind_param("is", $_SESSION['admin_id'], $log_action);
                $log_stmt->execute();
            } else {
                $error = "Failed to add user";
            }
        }
    }
}

// Handle Delete User
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Prevent deleting admin accounts
    $check_role = $conn->prepare("SELECT name, email, role FROM users WHERE id = ?");
    $check_role->bind_param("i", $delete_id);
    $check_role->execute();
    $user_data = $check_role->get_result()->fetch_assoc();
    
    if ($user_data && $user_data['role'] !== 'admin') {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'user'");
        $stmt->bind_param("i", $delete_id);
        
        if ($stmt->execute()) {
            $success = "User deleted successfully";
            
            // Log activity
            $log_stmt = $conn->prepare("INSERT INTO system_logs (user_id, action) VALUES (?, ?)");
            $log_action = "Admin deleted user: " . $user_data['name'] . " (" . $user_data['email'] . ")";
            $log_stmt->bind_param("is", $_SESSION['admin_id'], $log_action);
            $log_stmt->execute();
        } else {
            $error = "Failed to delete user";
        }
    } else {
        $error = "Cannot delete admin accounts";
    }
}

// Handle Edit User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $edit_id = intval($_POST['edit_id']);
    $name = trim($_POST['edit_name']);
    $email = trim($_POST['edit_email']);
    $role = $_POST['edit_role'];
    $monthly_budget = !empty($_POST['edit_monthly_budget']) ? floatval($_POST['edit_monthly_budget']) : 0;
    
    if (empty($name) || empty($email) || empty($role)) {
        $error = "Name, email, and role are required";
    } else {
        // Check if email already exists for other users
        $check_email = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check_email->bind_param("si", $email, $edit_id);
        $check_email->execute();
        if ($check_email->get_result()->num_rows > 0) {
            $error = "Email already exists";
        } else {
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ?, monthly_budget = ? WHERE id = ?");
            $stmt->bind_param("sssdi", $name, $email, $role, $monthly_budget, $edit_id);
            
            if ($stmt->execute()) {
                $success = "User updated successfully";
                
                // Log activity
                $log_stmt = $conn->prepare("INSERT INTO system_logs (user_id, action) VALUES (?, ?)");
                $log_action = "Admin updated user: $name ($email)";
                $log_stmt->bind_param("is", $_SESSION['admin_id'], $log_action);
                $log_stmt->execute();
            } else {
                $error = "Failed to update user";
            }
        }
    }
}

// Get all users with their expense statistics
$users_query = "SELECT u.*, 
                COUNT(DISTINCT e.id) as total_expenses,
                COALESCE(SUM(e.amount), 0) as total_spent
                FROM users u
                LEFT JOIN expenses e ON u.id = e.user_id
                GROUP BY u.id
                ORDER BY u.created_at DESC";
$users = $conn->query($users_query);

// Get summary statistics
$total_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='user'")->fetch_assoc()['count'];
$total_admins = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='admin'")->fetch_assoc()['count'];
$new_users_today = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='user' AND DATE(created_at) = CURDATE()")->fetch_assoc()['count'];
$total_user_budgets = $conn->query("SELECT COALESCE(SUM(monthly_budget), 0) as total FROM users WHERE role='user'")->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - FinSight Admin</title>
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
                    <a href="manage_users.php" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-white bg-primary rounded-lg">
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
                        <h1 class="text-3xl font-bold text-gray-900">User Management</h1>
                        <p class="text-sm text-gray-500 mt-1">View and manage all system users</p>
                    </div>
                    <button onclick="openAddModal()" class="bg-primary hover:bg-primary-600 text-white px-6 py-3 rounded-lg font-medium transition-all hover:shadow-lg flex items-center gap-2 self-start lg:self-auto">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Add New User
                    </button>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if ($success): ?>
                <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6 flex items-start gap-3">
                    <svg class="w-5 h-5 text-green-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-green-800"><?php echo htmlspecialchars($success); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6 flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-red-800"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-blue-600 bg-blue-50 px-2 py-1 rounded">Users</span>
                    </div>
                    <p class="text-3xl font-bold text-gray-900 mb-1"><?php echo number_format($total_users); ?></p>
                    <p class="text-xs text-gray-500">
                        <span class="text-green-600 font-semibold">+<?php echo $new_users_today; ?></span> today
                    </p>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-purple-50 rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-purple-600 bg-purple-50 px-2 py-1 rounded">Admins</span>
                    </div>
                    <p class="text-3xl font-bold text-gray-900 mb-1"><?php echo number_format($total_admins); ?></p>
                    <p class="text-xs text-gray-500">System administrators</p>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-green-50 rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-green-600 bg-green-50 px-2 py-1 rounded">Budgets</span>
                    </div>
                    <p class="text-3xl font-bold text-gray-900 mb-1">₱<?php echo number_format($total_user_budgets, 2); ?></p>
                    <p class="text-xs text-gray-500">Total user budgets</p>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-orange-50 rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-orange-600 bg-orange-50 px-2 py-1 rounded">Total</span>
                    </div>
                    <p class="text-3xl font-bold text-gray-900 mb-1"><?php echo number_format($users->num_rows); ?></p>
                    <p class="text-xs text-gray-500">All system accounts</p>
                </div>
            </div>

            <!-- Users Table -->
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">All Users</h2>
                            <p class="text-sm text-gray-500 mt-1"><?php echo $users->num_rows; ?> total accounts</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <input type="text" id="searchInput" placeholder="Search users..." class="px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            <select id="roleFilter" class="px-4 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="">All Roles</option>
                                <option value="user">Users</option>
                                <option value="admin">Admins</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Desktop Table View -->
                <div class="hidden lg:block overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">User</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Role</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Budget</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Expenses</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Total Spent</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Joined</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100" id="userTableBody">
                            <?php 
                            $users->data_seek(0);
                            while ($user = $users->fetch_assoc()): 
                            ?>
                            <tr class="hover:bg-gray-50 transition-colors user-row" data-name="<?php echo strtolower(htmlspecialchars($user['name'])); ?>" data-email="<?php echo strtolower(htmlspecialchars($user['email'])); ?>" data-role="<?php echo $user['role']; ?>">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 bg-gradient-to-br from-primary to-secondary rounded-full flex items-center justify-center font-semibold text-white text-sm">
                                            <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($user['name']); ?></p>
                                            <p class="text-xs text-gray-500">#<?php echo $user['id']; ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($user['email']); ?></td>
                                <td class="px-6 py-4">
                                    <?php if ($user['role'] === 'admin'): ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                            </svg>
                                            Admin
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                                            </svg>
                                            User
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">₱<?php echo number_format($user['monthly_budget'] ?? 0, 2); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-600"><?php echo number_format($user['total_expenses']); ?></td>
                                <td class="px-6 py-4 text-sm font-bold text-primary">₱<?php echo number_format($user['total_spent'], 2); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-500"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <button onclick='openEditModal(<?php echo json_encode($user); ?>)' 
                                                class="p-2 text-amber-600 hover:bg-amber-50 rounded-lg transition-colors" title="Edit">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </button>
                                        <a href="view_user.php?id=<?php echo $user['id']; ?>" 
                                           class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="View">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 5.043 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                            </svg>
                                        </a>
                                        <?php if ($user['role'] !== 'admin'): ?>
                                            <a href="?delete_id=<?php echo $user['id']; ?>" 
                                               onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')"
                                               class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="lg:hidden space-y-4">
                    <?php 
                    $users->data_seek(0);
                    while ($user = $users->fetch_assoc()): 
                    ?>
                    <div class="border border-gray-200 rounded-xl p-5 hover:shadow-md transition-shadow">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center gap-3 flex-1">
                                <div class="w-12 h-12 bg-gradient-to-br from-primary to-secondary rounded-full flex items-center justify-center font-semibold text-white">
                                    <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-bold text-gray-900 text-base truncate"><?php echo htmlspecialchars($user['name']); ?></h3>
                                    <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($user['email']); ?></p>
                                </div>
                            </div>
                            <?php if ($user['role'] === 'admin'): ?>
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800 shrink-0">
                                    Admin
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 shrink-0">
                                    User
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-3 py-3 border-t border-b border-gray-100 mb-3">
                            <div>
                                <p class="text-xs text-gray-500 mb-1">Monthly Budget</p>
                                <p class="font-semibold text-gray-900">₱<?php echo number_format($user['monthly_budget'] ?? 0, 2); ?></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 mb-1">Expenses</p>
                                <p class="font-semibold text-gray-900"><?php echo number_format($user['total_expenses']); ?></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 mb-1">Total Spent</p>
                                <p class="font-bold text-primary">₱<?php echo number_format($user['total_spent'], 2); ?></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 mb-1">Joined</p>
                                <p class="text-sm text-gray-800"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></p>
                            </div>
                        </div>
                        
                        <div class="flex gap-2">
                            <button onclick='openEditModal(<?php echo json_encode($user); ?>)' 
                                    class="flex-1 bg-amber-50 hover:bg-amber-100 text-amber-700 px-4 py-2.5 rounded-lg text-sm font-medium transition-all flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                                Edit
                            </button>
                            <a href="view_user.php?id=<?php echo $user['id']; ?>" 
                               class="flex-1 bg-blue-50 hover:bg-blue-100 text-blue-700 px-4 py-2.5 rounded-lg text-sm font-medium transition-all flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                                View
                            </a>
                            <?php if ($user['role'] !== 'admin'): ?>
                                <a href="?delete_id=<?php echo $user['id']; ?>" 
                                   onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')"
                                   class="bg-red-50 hover:bg-red-100 text-red-700 px-3 py-2.5 rounded-lg text-sm font-medium transition-all flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Add User Modal -->
    <div id="addModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full max-h-[90vh] overflow-y-auto shadow-2xl">
            <div class="sticky top-0 bg-white p-6 border-b border-gray-200 rounded-t-2xl z-10">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-gray-900">Add New User</h3>
                    <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <form method="POST" action="" class="p-6">
                <div class="space-y-4">
                    <div>
                        <label for="name" class="block text-sm font-semibold text-gray-700 mb-2">Full Name *</label>
                        <input type="text" id="name" name="name" required 
                               placeholder="John Doe"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all">
                    </div>
                    
                    <div>
                        <label for="email" class="block text-sm font-semibold text-gray-700 mb-2">Email Address *</label>
                        <input type="email" id="email" name="email" required 
                               placeholder="john@example.com"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all">
                    </div>
                    
                    <div>
                        <label for="password" class="block text-sm font-semibold text-gray-700 mb-2">Password *</label>
                        <input type="password" id="password" name="password" required 
                               placeholder="Minimum 6 characters"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all">
                    </div>
                    
                    <div>
                        <label for="role" class="block text-sm font-semibold text-gray-700 mb-2">Role *</label>
                        <select id="role" name="role" required 
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all">
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="monthly_budget" class="block text-sm font-semibold text-gray-700 mb-2">Monthly Budget (Optional)</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-500">₱</span>
                            <input type="number" id="monthly_budget" name="monthly_budget" step="0.01" 
                                   placeholder="0.00"
                                   class="w-full pl-8 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all">
                        </div>
                    </div>
                </div>
                
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeAddModal()" 
                            class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-3 rounded-lg font-medium transition-all">
                        Cancel
                    </button>
                    <button type="submit" name="add_user" 
                            class="flex-1 bg-primary hover:bg-primary-600 text-white px-6 py-3 rounded-lg font-medium transition-all hover:shadow-lg">
                        Add User
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full max-h-[90vh] overflow-y-auto shadow-2xl">
            <div class="sticky top-0 bg-white p-6 border-b border-gray-200 rounded-t-2xl z-10">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-gray-900">Edit User</h3>
                    <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <form method="POST" action="" class="p-6">
                <input type="hidden" id="edit_id" name="edit_id">
                
                <div class="space-y-4">
                    <div>
                        <label for="edit_name" class="block text-sm font-semibold text-gray-700 mb-2">Full Name *</label>
                        <input type="text" id="edit_name" name="edit_name" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all">
                    </div>
                    
                    <div>
                        <label for="edit_email" class="block text-sm font-semibold text-gray-700 mb-2">Email Address *</label>
                        <input type="email" id="edit_email" name="edit_email" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all">
                    </div>
                    
                    <div>
                        <label for="edit_role" class="block text-sm font-semibold text-gray-700 mb-2">Role *</label>
                        <select id="edit_role" name="edit_role" required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all">
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="edit_monthly_budget" class="block text-sm font-semibold text-gray-700 mb-2">Monthly Budget</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-500">₱</span>
                            <input type="number" id="edit_monthly_budget" name="edit_monthly_budget" step="0.01"
                                   class="w-full pl-8 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-all">
                        </div>
                    </div>
                </div>
                
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeEditModal()" 
                            class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-3 rounded-lg font-medium transition-all">
                        Cancel
                    </button>
                    <button type="submit" name="edit_user" 
                            class="flex-1 bg-primary hover:bg-primary-600 text-white px-6 py-3 rounded-lg font-medium transition-all hover:shadow-lg">
                        Update User
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

        // Modal functions
        function openAddModal() {
            document.getElementById('addModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        function openEditModal(user) {
            document.getElementById('edit_id').value = user.id;
            document.getElementById('edit_name').value = user.name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_monthly_budget').value = user.monthly_budget || 0;
            document.getElementById('editModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Close modals on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddModal();
                closeEditModal();
            }
        });

        // Close modals when clicking outside
        document.getElementById('addModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddModal();
            }
        });

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });

        // Search and Filter functionality
        const searchInput = document.getElementById('searchInput');
        const roleFilter = document.getElementById('roleFilter');
        const userRows = document.querySelectorAll('.user-row');

        function filterUsers() {
            const searchTerm = searchInput.value.toLowerCase();
            const roleValue = roleFilter.value.toLowerCase();

            userRows.forEach(row => {
                const name = row.dataset.name;
                const email = row.dataset.email;
                const role = row.dataset.role;

                const matchesSearch = name.includes(searchTerm) || email.includes(searchTerm);
                const matchesRole = roleValue === '' || role === roleValue;

                if (matchesSearch && matchesRole) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        searchInput.addEventListener('input', filterUsers);
        roleFilter.addEventListener('change', filterUsers);

        // Auto-dismiss alerts after 5 seconds
        const alerts = document.querySelectorAll('.bg-green-50, .bg-red-50');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });
    </script>
</body>
</html>