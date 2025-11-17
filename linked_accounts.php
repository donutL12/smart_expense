<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/auth_user.php';

$user_id = $_SESSION['user_id'];

// Handle account deletion
if (isset($_POST['delete_account'])) {
    $account_id = filter_var($_POST['account_id'], FILTER_VALIDATE_INT);
    if ($account_id) {
        // Soft delete: mark as inactive
        $delete_query = "UPDATE linked_accounts SET status = 'inactive', updated_at = NOW() WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($delete_query);
        $stmt->bind_param("ii", $account_id, $user_id);
        if ($stmt->execute()) {
            $success_message = "Account unlinked successfully!";
        } else {
            $error_message = "Failed to unlink account.";
        }
    }
}

// Handle new account linking
if (isset($_POST['link_account'])) {
    $bank_id = filter_var($_POST['bank_id'], FILTER_VALIDATE_INT);
    // Replace FILTER_SANITIZE_STRING with htmlspecialchars and trim
    $account_number = htmlspecialchars(trim($_POST['account_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $account_name = htmlspecialchars(trim($_POST['account_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    
    if ($bank_id && $account_number && $account_name) {
        // Check if account exists (active or inactive)
        $check_query = "SELECT id, status FROM linked_accounts WHERE user_id = ? AND bank_id = ? AND account_number = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("iis", $user_id, $bank_id, $account_number);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        
        if ($existing) {
            if ($existing['status'] === 'active') {
                $error_message = "This account is already linked!";
            } else {
                // Reactivate the inactive account
                $reactivate_query = "UPDATE linked_accounts SET status = 'active', account_name = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($reactivate_query);
                $stmt->bind_param("si", $account_name, $existing['id']);
                if ($stmt->execute()) {
                    $success_message = "Account reactivated successfully!";
                } else {
                    $error_message = "Failed to reactivate account.";
                }
            }
        } else {
            // Insert new account
            $insert_query = "INSERT INTO linked_accounts (user_id, bank_id, account_number, account_name, status, created_at) VALUES (?, ?, ?, ?, 'active', NOW())";
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("iiss", $user_id, $bank_id, $account_number, $account_name);
            if ($stmt->execute()) {
                $success_message = "Account linked successfully!";
            } else {
                $error_message = "Failed to link account. Please try again.";
            }
        }
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// Get all available banks
$banks_query = "SELECT * FROM banks WHERE status = 'active' ORDER BY name ASC";
$banks = $conn->query($banks_query)->fetch_all(MYSQLI_ASSOC);

// Get user's linked accounts
$linked_accounts_query = "SELECT la.*, b.name as bank_name, b.logo, b.type 
FROM linked_accounts la 
JOIN banks b ON la.bank_id = b.id 
WHERE la.user_id = ? AND la.status = 'active' 
ORDER BY la.created_at DESC";
$stmt = $conn->prepare($linked_accounts_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$linked_accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get user info
$user_query = "SELECT name, email FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$user_initial = strtoupper(substr($user['name'], 0, 1));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Linked Accounts - FinSight</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        * { font-family: 'Inter', sans-serif; }
        
        /* Enhanced Navigation Responsiveness */
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

        /* Responsive sidebar widths */
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
        <!-- Sidebar (same as dashboard.php) -->
<aside class="hidden lg:flex lg:flex-col lg:w-64 bg-white border-r border-gray-200 fixed h-full z-30">
            <div class="flex items-center gap-3 px-6 py-5 border-b border-gray-200">
                <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center">
                    <span class="text-white text-xl font-bold">F</span>
                </div>
                <h2 class="text-xl font-bold text-gray-900">FinSight</h2>
            </div>
            
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                <a href="dashboard.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
                    <span>Dashboard</span>
                </a>
                <a href="all_expenses.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
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
                <a href="linked_accounts.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-white bg-indigo-600 rounded-lg transition-all">
                    <span>Linked Accounts</span>
                </a>
                <a href="logout.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg transition-all mt-4 border-t border-gray-200 pt-5">
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
                <a href="dashboard.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
                    <span>Dashboard</span>
                </a>
                <a href="all_expenses.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-all">
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
                <a href="linked_accounts.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-white bg-indigo-600 rounded-lg transition-all">
                    <span>Linked Accounts</span>
                </a>
                <a href="logout.php" class="nav-item flex items-center px-4 py-3 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg transition-all mt-4 border-t border-gray-200 pt-5">
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
                    <div class="w-6"></div>
                </div>
            </header>

            <div class="p-4 md:p-6 lg:p-8">
                <!-- Page Header -->
                <div class="mb-6 md:mb-8">
                    <h1 class="text-2xl md:text-3xl font-bold text-gray-900"> Linked Accounts</h1>
                    <p class="text-gray-600 mt-1">Connect your bank accounts and e-wallets for automatic expense tracking</p>
                </div>

                <?php if (isset($success_message)): ?>
                <div class="mb-6 bg-green-50 border border-green-200 rounded-xl p-4 flex items-start gap-3">
                    <span class="text-2xl">✅</span>
                    <p class="text-green-800 font-medium flex-1"><?php echo htmlspecialchars($success_message); ?></p>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-start gap-3">
                    <span class="text-2xl">❌</span>
                    <p class="text-red-800 font-medium flex-1"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
                <?php endif; ?>

                <!-- Link New Account -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">Link New Account</h2>
                    <form method="POST" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Select Bank/E-Wallet</label>
                                <select name="bank_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="">Choose a bank or e-wallet...</option>
                                    <?php foreach ($banks as $bank): ?>
                                    <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['name']); ?> (<?php echo ucfirst($bank['type']); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Account Number/Mobile</label>
                                <input type="text" name="account_number" required placeholder="Enter account number or mobile number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Account Name/Nickname</label>
                            <input type="text" name="account_name" required placeholder="e.g., Primary Savings, GCash Main" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <p class="text-sm text-blue-800"><strong>Note:</strong> Account linking is secure and read-only. FinSight will never have access to withdraw or transfer funds.</p>
                        </div>
                        <button type="submit" name="link_account" class="w-full md:w-auto px-6 py-2.5 bg-indigo-600 text-white rounded-lg font-medium hover:bg-indigo-700 transition-colors">
                            🔗 Link Account
                        </button>
                    </form>
                </div>

                <!-- Linked Accounts List -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-gray-900">Your Linked Accounts (<?php echo count($linked_accounts); ?>)</h2>
                        <?php if (!empty($linked_accounts)): ?>
                        <a href="sync_accounts.php" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 transition-colors">
                            🔄 Sync All Accounts
                        </a>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($linked_accounts)): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($linked_accounts as $account): ?>
                        <div class="border border-gray-200 rounded-xl p-5 hover:shadow-md transition-shadow">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-12 h-12 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-xl flex items-center justify-center text-2xl">
                                        <?php echo $account['type'] === 'bank' ? '🏦' : '💳'; ?>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-900"><?php echo htmlspecialchars($account['bank_name']); ?></h3>
                                        <p class="text-xs text-gray-500"><?php echo ucfirst($account['type']); ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="space-y-2 mb-4">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600">Account:</span>
                                    <span class="font-medium text-gray-900"><?php echo htmlspecialchars($account['account_name']); ?></span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600">Number:</span>
                                    <span class="font-mono text-gray-900">****<?php echo htmlspecialchars(substr($account['account_number'], -4)); ?></span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600">Last Synced:</span>
                                    <span class="text-gray-900">
                                        <?php 
                                        if ($account['last_synced']) {
                                            echo date('M d, Y', strtotime($account['last_synced']));
                                        } else {
                                            echo 'Never';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600">Status:</span>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                        <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
                                        Active
                                    </span>
                                </div>
                            </div>

                            <div class="flex gap-2 pt-4 border-t border-gray-200">
                                <form method="GET" action="sync_accounts.php" class="flex-1">
                                    <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                    <button type="submit" class="w-full px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors">
                                        🔄 Sync
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to unlink this account?');">
                                    <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                    <button type="submit" name="delete_account" class="px-3 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition-colors">
                                        🗑️
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                        <span class="text-6xl mb-4">🏦</span>
                        <p class="text-sm mb-2">No accounts linked yet</p>
                        <p class="text-xs text-gray-500">Link your first account above to get started!</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
      <script>
        // Enhanced Mobile sidebar toggle
        const openSidebar = document.getElementById('openSidebar');
        const closeSidebar = document.getElementById('closeSidebar');
        const mobileSidebar = document.getElementById('mobileSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function openMobileSidebar() {
            mobileSidebar.classList.remove('hidden', '-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeMobileSidebar() {
            mobileSidebar.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
            document.body.style.overflow = '';
            setTimeout(() => {
                mobileSidebar.classList.add('hidden');
            }, 300);
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
    </script>
</body>
</html>