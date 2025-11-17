<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/auth_user.php';

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Fetch user data
$user_query = "SELECT id, name, email, profile_picture, created_at FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    header("Location: logout.php");
    exit();
}

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    
    if (empty($name) || empty($email)) {
        $error_message = "Name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format.";
    } else {
        $email_check = "SELECT id FROM users WHERE email = ? AND id != ?";
        $stmt = $conn->prepare($email_check);
        $stmt->bind_param("si", $email, $user_id);
        $stmt->execute();
        $email_result = $stmt->get_result();
        
        if ($email_result->num_rows > 0) {
            $error_message = "This email is already in use by another account.";
        } else {
            $update_query = "UPDATE users SET name = ?, email = ? WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("ssi", $name, $email, $user_id);
            
            if ($stmt->execute()) {
                $success_message = "Profile updated successfully!";
                $user['name'] = $name;
                $user['email'] = $email;
                $_SESSION['email'] = $email;
            } else {
                $error_message = "Failed to update profile. Please try again.";
            }
        }
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = "All password fields are required.";
    } elseif (strlen($new_password) < 6) {
        $error_message = "New password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "New passwords do not match.";
    } else {
        $password_query = "SELECT password FROM users WHERE id = ?";
        $stmt = $conn->prepare($password_query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $password_result = $stmt->get_result()->fetch_assoc();
        
        if (md5($current_password) !== $password_result['password']) {
            $error_message = "Current password is incorrect.";
        } else {
            $new_password_hash = md5($new_password);
            $update_password = "UPDATE users SET password = ? WHERE id = ?";
            $stmt = $conn->prepare($update_password);
            $stmt->bind_param("si", $new_password_hash, $user_id);
            
            if ($stmt->execute()) {
                $success_message = "Password changed successfully!";
            } else {
                $error_message = "Failed to change password. Please try again.";
            }
        }
    }
}

// Get user statistics
$stats_query = "SELECT 
    COUNT(*) as total_expenses,
    COALESCE(SUM(amount), 0) as total_spent,
    COUNT(DISTINCT category_id) as categories_used
FROM expenses 
WHERE user_id = ?";
$stmt = $conn->prepare($stats_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

$user_name = $user['name'] ?? 'User';
$user_email = $user['email'];
$user_initial = strtoupper(substr($user_name, 0, 1));
$username = explode('@', $user_email)[0];
$member_since = date('F j, Y', strtotime($user['created_at']));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings - FinSight</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
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

        .mobile-menu {
            transition: transform 0.3s ease-in-out;
        }

        .mobile-menu.hidden {
            transform: translateX(-100%);
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

        .alert {
            animation: slideDown 0.3s ease;
        }

        .gradient-border {
            background: linear-gradient(white, white) padding-box,
                        linear-gradient(135deg, #667eea 0%, #764ba2 100%) border-box;
            border: 2px solid transparent;
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
    <a href="dashboard.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Dashboard</span>
    </a>
    <a href="all_expenses.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>All Expenses</span>
    </a>
    <a href="add_expense.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Add Expense</span>
    </a>
    <a href="categories.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
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
    <a href="profile.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg">
        <span>Profile Settings</span>
    </a>
    <a href="linked_accounts.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Linked Accounts</span>
    </a>
    <a href="logout.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg">
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
        <aside id="mobileSidebar" class="mobile-menu hidden fixed inset-y-0 left-0 w-64 bg-white border-r border-gray-200 z-50 lg:hidden transform -translate-x-full">
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
    <a href="dashboard.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Dashboard</span>
    </a>
    <a href="all_expenses.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>All Expenses</span>
    </a>
    <a href="add_expense.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Add Expense</span>
    </a>
    <a href="categories.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
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
    <a href="profile.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg">
        <span>Profile Settings</span>
    </a>
    <a href="linked_accounts.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
        <span>Linked Accounts</span>
    </a>
    <a href="logout.php" class="nav-item flex items-center px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg">
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
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-lg flex items-center justify-center">
                            <span class="text-white text-sm font-bold">F</span>
                        </div>
                        <h2 class="text-lg font-bold text-gray-900">Profile</h2>
                    </div>
                    <div class="w-6"></div>
                </div>
            </header>

            <div class="p-4 md:p-6 lg:p-8 max-w-6xl mx-auto">
                <!-- Page Header -->
                <div class="mb-6 md:mb-8">
                    <h1 class="text-2xl md:text-3xl font-bold text-gray-900"> Profile Settings</h1>
                    <p class="text-gray-600 mt-1">Manage your account information and preferences</p>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                <div class="alert mb-6 bg-green-50 border border-green-200 rounded-xl p-4 flex items-start gap-3">
                    <span class="text-2xl">✅</span>
                    <div class="flex-1">
                        <p class="text-green-800 font-medium"><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-green-600 hover:text-green-800">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="alert mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-start gap-3">
                    <span class="text-2xl">❌</span>
                    <div class="flex-1">
                        <p class="text-red-800 font-medium"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <?php endif; ?>

                <!-- Profile Header Card -->
                <div class="bg-gradient-to-br from-indigo-600 to-purple-600 rounded-2xl shadow-lg p-6 md:p-8 mb-6 text-white">
                    <div class="flex flex-col md:flex-row items-center gap-6">
                        <div class="w-24 h-24 md:w-32 md:h-32 bg-white rounded-full flex items-center justify-center text-indigo-600 text-4xl md:text-5xl font-bold shadow-xl ring-4 ring-white ring-opacity-30">
                            <?php echo $user_initial; ?>
                        </div>
                        <div class="flex-1 text-center md:text-left">
                            <h2 class="text-2xl md:text-3xl font-bold mb-2"><?php echo htmlspecialchars($user_name); ?></h2>
                            <div class="space-y-1 text-indigo-100">
                                <p class="flex items-center justify-center md:justify-start gap-2">
                                    <span>📧</span>
                                    <span class="text-sm md:text-base"><?php echo htmlspecialchars($user_email); ?></span>
                                </p>
                                <p class="flex items-center justify-center md:justify-start gap-2">
                                    <span>👤</span>
                                    <span class="text-sm md:text-base">@<?php echo htmlspecialchars($username); ?></span>
                                </p>
                                <p class="flex items-center justify-center md:justify-start gap-2">
                                    <span>📅</span>
                                    <span class="text-sm md:text-base">Member since <?php echo $member_since; ?></span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Settings Sections Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Personal Information -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 md:p-8">
                        <div class="flex items-center gap-3 mb-6">
                            <h2 class="text-xl font-bold text-gray-900">Personal Information</h2>
                        </div>

                        <form method="POST" action="" class="space-y-5">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
                                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all">
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all">
                            </div>

                            <div>
                                <label for="username" class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                                <input type="text" id="username" value="<?php echo htmlspecialchars($username); ?>" disabled
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50 text-gray-500 cursor-not-allowed">
                                <p class="mt-2 text-xs text-gray-500 flex items-center gap-1">
                                    <span>ℹ️</span>
                                    <span>Username cannot be changed after registration</span>
                                </p>
                            </div>

                            <button type="submit" name="update_profile" 
                                class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-3 px-6 rounded-lg font-medium hover:from-indigo-700 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition-all transform hover:scale-[1.02]">
                                 Update Profile
                            </button>
                        </form>
                    </div>

                    <!-- Change Password -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 md:p-8">
                        <div class="flex items-center gap-3 mb-6">
                           
                            <h2 class="text-xl font-bold text-gray-900">Change Password</h2>
                        </div>

                        <form method="POST" action="" class="space-y-5">
                            <div>
                                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                                <input type="password" id="current_password" name="current_password" placeholder="Enter current password" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all">
                            </div>

                            <div>
                                <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                                <input type="password" id="new_password" name="new_password" placeholder="Enter new password (min. 6 characters)" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all">
                            </div>

                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter new password" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all">
                                <div id="password-match-message" class="mt-2 text-xs hidden"></div>
                            </div>

                            <button type="submit" name="change_password"
                                class="w-full bg-gradient-to-r from-green-600 to-emerald-600 text-white py-3 px-6 rounded-lg font-medium hover:from-green-700 hover:to-emerald-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-all transform hover:scale-[1.02]">
                                 Update Password
                            </button>
                        </form>
                    </div>
                </div>

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
            mobileSidebar.classList.remove('hidden', '-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function hideSidebar() {
            mobileSidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
            document.body.style.overflow = '';
            setTimeout(() => {
                mobileSidebar.classList.add('hidden');
            }, 300);
        }

        openSidebar?.addEventListener('click', showSidebar);
        closeSidebar?.addEventListener('click', hideSidebar);
        sidebarOverlay?.addEventListener('click', hideSidebar);

        // Password Match Validation
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const matchMessage = document.getElementById('password-match-message');

        function checkPasswordMatch() {
            if (confirmPassword.value === '') {
                matchMessage.classList.add('hidden');
                return;
            }

            matchMessage.classList.remove('hidden');
            
            if (newPassword.value === confirmPassword.value) {
                matchMessage.textContent = '✅ Passwords match';
                matchMessage.className = 'mt-2 text-xs text-green-600 flex items-center gap-1';
            } else {
                matchMessage.textContent = '❌ Passwords do not match';
                matchMessage.className = 'mt-2 text-xs text-red-600 flex items-center gap-1';
            }
        }

        confirmPassword?.addEventListener('input', checkPasswordMatch);
        newPassword?.addEventListener('input', checkPasswordMatch);

        // Delete Account Confirmation
        function confirmDeleteAccount() {
            if (confirm('⚠️ WARNING: This action cannot be undone!\n\nAre you absolutely sure you want to delete your account? All your expenses, categories, and data will be permanently deleted.')) {
                if (confirm('Please confirm one more time. Type YES in the next prompt to proceed.')) {
                    const confirmation = prompt('Type "DELETE" to confirm account deletion:');
                    if (confirmation === 'DELETE') {
                        window.location.href = 'delete_account.php';
                    } else {
                        alert('Account deletion cancelled.');
                    }
                }
            }
        }

        // Auto-hide alerts after 5 seconds
        document.querySelectorAll('.alert').forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
    </script>
</body>
</html>