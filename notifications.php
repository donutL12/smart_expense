<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/auth_user.php';
require_once 'includes/functions.php';

$user_id = $_SESSION['user_id'];
$page_title = "Notifications";

// Mark notification as read
if (isset($_POST['mark_read']) && isset($_POST['notification_id'])) {
    $notif_id = filter_var($_POST['notification_id'], FILTER_SANITIZE_NUMBER_INT);
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notif_id, $user_id);
    $stmt->execute();
    header("Location: notifications.php");
    exit();
}

// Mark all as read
if (isset($_POST['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    header("Location: notifications.php");
    exit();
}

// Delete notification
if (isset($_POST['delete_notification']) && isset($_POST['notification_id'])) {
    $notif_id = filter_var($_POST['notification_id'], FILTER_SANITIZE_NUMBER_INT);
    $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notif_id, $user_id);
    $stmt->execute();
    header("Location: notifications.php");
    exit();
}

// Get filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Build query based on filter
$query = "SELECT * FROM notifications WHERE user_id = ?";
$params = [$user_id];
$types = "i";

if ($filter === 'unread') {
    $query .= " AND is_read = 0";
} elseif ($filter === 'budget') {
    $query .= " AND type = 'budget_alert'";
} elseif ($filter === 'expense') {
    $query .= " AND type = 'expense_alert'";
} elseif ($filter === 'system') {
    $query .= " AND type = 'system'";
}

$query .= " ORDER BY created_at DESC LIMIT 50";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$notifications = $result->fetch_all(MYSQLI_ASSOC);

// Get unread count
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$unread_count = $stmt->get_result()->fetch_assoc()['count'];

// Get user info
$user_query = "SELECT name, email FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$user_name = $user['name'] ?? 'User';
$user_email = $user['email'] ?? '';
$user_initial = strtoupper(substr($user_name, 0, 1));

function getNotificationIcon($type) {
    switch($type) {
        case 'budget_alert': return '💰';
        case 'expense_alert': return '⚠️';
        case 'system': return 'ℹ️';
        case 'success': return '✅';
        default: return '🔔';
    }
}

function getNotificationColor($type) {
    switch($type) {
        case 'budget_alert': return 'from-amber-100 to-amber-50';
        case 'expense_alert': return 'from-red-100 to-red-50';
        case 'system': return 'from-blue-100 to-blue-50';
        case 'success': return 'from-green-100 to-green-50';
        default: return 'from-gray-100 to-gray-50';
    }
}

function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M d, Y', $timestamp);
}
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
        <!-- Desktop Sidebar -->
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
                <a href="notifications.php" class="nav-item flex items-center justify-between px-4 py-3 text-sm font-medium text-white bg-indigo-600 rounded-lg transition-all">
                    <span>Notifications</span>
                    <?php if ($unread_count > 0): ?>
                    <span class="bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
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
                <a href="notifications.php" class="nav-item flex items-center justify-between px-4 py-3 text-sm font-medium text-white bg-indigo-600 rounded-lg transition-all">
                    <span>Notifications</span>
                    <?php if ($unread_count > 0): ?>
                    <span class="bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
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
                        <h2 class="text-lg font-bold text-gray-900">Notifications</h2>
                        <?php if ($unread_count > 0): ?>
                        <span class="bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="w-6"></div>
                </div>
            </header>

            <div class="p-4 md:p-6 lg:p-8 max-w-5xl mx-auto">
                <!-- Page Header -->
                <div class="mb-6 md:mb-8">
                    <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-2">🔔 Notifications</h1>
                    <p class="text-gray-600">Stay updated with your spending alerts and system updates</p>
                </div>

                <!-- Filters and Actions -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <!-- Filters -->
                        <div class="flex flex-wrap gap-2">
                            <a href="notifications.php?filter=all" 
                               class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?php echo $filter === 'all' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                                All (<?php echo count($notifications); ?>)
                            </a>
                            <a href="notifications.php?filter=unread" 
                               class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?php echo $filter === 'unread' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                                Unread (<?php echo $unread_count; ?>)
                            </a>
                            <a href="notifications.php?filter=budget" 
                               class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?php echo $filter === 'budget' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                                💰 Budget
                            </a>
                            <a href="notifications.php?filter=expense" 
                               class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?php echo $filter === 'expense' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                                ⚠️ Expenses
                            </a>
                            <a href="notifications.php?filter=system" 
                               class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?php echo $filter === 'system' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                                ℹ️ System
                            </a>
                        </div>

                        <!-- Actions -->
                        <?php if ($unread_count > 0): ?>
                        <form method="POST" action="">
                            <button type="submit" name="mark_all_read" 
                                    class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 transition-colors">
                                ✓ Mark All as Read
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Notifications List -->
                <div class="space-y-4">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $notification): ?>
                        <div class="bg-white rounded-xl shadow-sm border <?php echo $notification['is_read'] ? 'border-gray-200' : 'border-indigo-300 bg-gradient-to-r from-indigo-50/30 to-white'; ?> overflow-hidden transition-all hover:shadow-md">
                            <div class="p-4 md:p-6">
                                <div class="flex gap-4">
                                    <!-- Icon -->
                                    <div class="flex-shrink-0">
                                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br <?php echo getNotificationColor($notification['type']); ?> flex items-center justify-center text-2xl">
                                            <?php echo getNotificationIcon($notification['type']); ?>
                                        </div>
                                    </div>

                                    <!-- Content -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-start justify-between gap-4 mb-2">
                                            <h3 class="text-base md:text-lg font-semibold text-gray-900">
                                                <?php echo htmlspecialchars($notification['title']); ?>
                                            </h3>
                                            <?php if (!$notification['is_read']): ?>
                                            <span class="flex-shrink-0 w-2 h-2 bg-indigo-600 rounded-full mt-2"></span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <p class="text-sm md:text-base text-gray-700 mb-3 leading-relaxed">
                                            <?php echo htmlspecialchars($notification['message']); ?>
                                        </p>
                                        
                                        <div class="flex flex-wrap items-center gap-4 text-xs text-gray-500">
                                            <span class="flex items-center gap-1">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <?php echo timeAgo($notification['created_at']); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex-shrink-0 flex flex-col gap-2">
                                        <?php if (!$notification['is_read']): ?>
                                        <form method="POST" action="">
                                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                            <button type="submit" name="mark_read" 
                                                    class="p-2 text-green-600 hover:bg-green-50 rounded-lg transition-colors"
                                                    title="Mark as read">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" action="" onsubmit="return confirm('Delete this notification?');">
                                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                            <button type="submit" name="delete_notification" 
                                                    class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                                    title="Delete">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Empty State -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center">
                            <div class="flex flex-col items-center">
                                <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center text-4xl mb-4">
                                    🔔
                                </div>
                                <h3 class="text-xl font-semibold text-gray-900 mb-2">No notifications yet</h3>
                                <p class="text-gray-600 mb-6">When you receive notifications, they'll appear here</p>
                                <a href="dashboard.php" class="px-6 py-3 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                                    Go to Dashboard
                                </a>
                            </div>
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