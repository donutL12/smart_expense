<?php
require_once 'includes/auth_admin.php';
require_once '../includes/db_connect.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_users.php');
    exit;
}

$user_id = intval($_GET['id']);

// Get user details
$user_query = $conn->prepare("
    SELECT u.*, 
           COUNT(DISTINCT e.id) as expense_count,
           COALESCE(SUM(e.amount), 0) as total_spent
    FROM users u
    LEFT JOIN expenses e ON u.id = e.user_id
    WHERE u.id = ? AND u.role = 'user'
    GROUP BY u.id
");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();

if ($user_result->num_rows === 0) {
    header('Location: manage_users.php');
    exit;
}

$user = $user_result->fetch_assoc();

// Get user's expenses
$expenses = $conn->prepare("
    SELECT e.*, c.name as category_name
    FROM expenses e
    LEFT JOIN categories c ON e.category_id = c.id
    WHERE e.user_id = ?
    ORDER BY e.expense_date DESC, e.created_at DESC
    LIMIT 50
");
$expenses->bind_param("i", $user_id);
$expenses->execute();
$expenses_result = $expenses->get_result();

// Get monthly spending pattern
$monthly_spending = $conn->prepare("
    SELECT DATE_FORMAT(expense_date, '%Y-%m') as month,
           COUNT(*) as count,
           SUM(amount) as total
    FROM expenses
    WHERE user_id = ?
    AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
    ORDER BY month ASC
");
$monthly_spending->bind_param("i", $user_id);
$monthly_spending->execute();
$monthly_result = $monthly_spending->get_result();

// Get category breakdown
$category_breakdown = $conn->prepare("
    SELECT c.name, COUNT(e.id) as count, COALESCE(SUM(e.amount), 0) as total
    FROM categories c
    LEFT JOIN expenses e ON c.id = e.category_id AND e.user_id = ?
    WHERE e.id IS NOT NULL
    GROUP BY c.id, c.name
    ORDER BY total DESC
");
$category_breakdown->bind_param("i", $user_id);
$category_breakdown->execute();
$category_result = $category_breakdown->get_result();

// Calculate budget usage percentage
$budget_percentage = $user['monthly_budget'] > 0 ? ($user['total_spent'] / $user['monthly_budget']) * 100 : 0;

// Get current month spending
$current_month = date('Y-m');
$month_spent_query = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE user_id = ? AND DATE_FORMAT(expense_date, '%Y-%m') = ?");
$month_spent_query->bind_param("is", $user_id, $current_month);
$month_spent_query->execute();
$month_spent = $month_spent_query->get_result()->fetch_assoc()['total'];
$current_month_percentage = $user['monthly_budget'] > 0 ? ($month_spent / $user['monthly_budget']) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile - <?php echo htmlspecialchars($user['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
                        <div class="flex items-center gap-3 mb-2">
                            <a href="manage_users.php" class="text-gray-400 hover:text-gray-600 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                </svg>
                            </a>
                            <h1 class="text-3xl font-bold text-gray-900">User Profile</h1>
                        </div>
                        <p class="text-sm text-gray-500">Detailed analytics and activity overview</p>
                    </div>
                </div>
            </div>

            <!-- User Info Card -->
            <div class="bg-white rounded-xl border border-gray-200 p-6 mb-8">
                <div class="flex flex-col sm:flex-row items-start sm:items-center gap-6">
                    <div class="w-20 h-20 bg-gradient-to-br from-primary to-secondary rounded-2xl flex items-center justify-center text-white text-3xl font-bold shadow-lg">
                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-2xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($user['name']); ?></h2>
                        <p class="text-gray-600 mb-3"><?php echo htmlspecialchars($user['email']); ?></p>
                        <div class="flex flex-wrap gap-2">
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-700 bg-blue-50 px-3 py-1.5 rounded-lg border border-blue-200">
                                ID: #<?php echo $user['id']; ?>
                            </span>
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-green-700 bg-green-50 px-3 py-1.5 rounded-lg border border-green-200">
                                Joined: <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                            </span>
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-purple-700 bg-purple-50 px-3 py-1.5 rounded-lg border border-purple-200">
                                Active User
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Stats Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Monthly Budget</h3>
                        <span class="text-xs font-semibold text-purple-600 bg-purple-50 px-2 py-1 rounded">Allocated</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2">₱<?php echo number_format($user['monthly_budget'], 2); ?></p>
                    <p class="text-xs text-gray-500 mb-4">Budget per month</p>
                    
                    <div class="pt-4 border-t border-gray-100">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-medium text-gray-600">Current Month Usage</span>
                            <span class="text-sm font-bold <?php echo $current_month_percentage > 90 ? 'text-red-600' : ($current_month_percentage > 75 ? 'text-yellow-600' : 'text-green-600'); ?>">
                                <?php echo number_format($current_month_percentage, 1); ?>%
                            </span>
                        </div>
                        <div class="w-full bg-gray-100 rounded-full h-2.5 overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-500 <?php echo $current_month_percentage > 90 ? 'bg-gradient-to-r from-red-500 to-red-600' : ($current_month_percentage > 75 ? 'bg-gradient-to-r from-yellow-500 to-orange-500' : 'bg-gradient-to-r from-green-500 to-green-600'); ?>" 
                                 style="width: <?php echo min($current_month_percentage, 100); ?>%"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-2">₱<?php echo number_format($month_spent, 2); ?> spent this month</p>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Total Spent</h3>
                        <span class="text-xs font-semibold text-red-600 bg-red-50 px-2 py-1 rounded">All Time</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2">₱<?php echo number_format($user['total_spent'], 2); ?></p>
                    <p class="text-xs text-gray-500">Lifetime expenses</p>
                </div>
                
                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Total Expenses</h3>
                        <span class="text-xs font-semibold text-blue-600 bg-blue-50 px-2 py-1 rounded">Records</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2"><?php echo number_format($user['expense_count']); ?></p>
                    <p class="text-xs text-gray-500">Transactions recorded</p>
                </div>
                
                <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide">Average Expense</h3>
                        <span class="text-xs font-semibold text-green-600 bg-green-50 px-2 py-1 rounded">Per Transaction</span>
                    </div>
                    <p class="text-4xl font-bold text-gray-900 mb-2">
                        ₱<?php echo $user['expense_count'] > 0 ? number_format($user['total_spent'] / $user['expense_count'], 2) : '0.00'; ?>
                    </p>
                    <p class="text-xs text-gray-500">Average per expense</p>
                </div>
            </div>
            
            <!-- Charts -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">Monthly Spending Pattern</h2>
                            <p class="text-xs text-gray-500 mt-1">Last 6 months overview</p>
                        </div>
                    </div>
                    <div class="h-80">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">Category Breakdown</h2>
                            <p class="text-xs text-gray-500 mt-1">Spending by category</p>
                        </div>
                    </div>
                    <div class="h-80">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Recent Expenses -->
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Recent Expenses</h2>
                        <p class="text-xs text-gray-500 mt-1">Latest 50 transactions</p>
                    </div>
                    <span class="inline-flex items-center text-xs font-semibold text-primary bg-blue-50 px-3 py-2 rounded-lg border border-blue-200">
                        <?php echo $expenses_result->num_rows; ?> Expenses
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <?php if ($expenses_result->num_rows > 0): ?>
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Description</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Category</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php while ($expense = $expenses_result->fetch_assoc()): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-4 text-sm text-gray-600 whitespace-nowrap">
                                        <div class="font-medium text-gray-900"><?php echo date('M d', strtotime($expense['expense_date'])); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo date('Y', strtotime($expense['expense_date'])); ?></div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($expense['description']); ?></div>
                                        <div class="text-xs text-gray-500 sm:hidden mt-1">
                                            <span class="inline-block bg-blue-50 text-blue-700 px-2 py-1 rounded-lg text-xs font-medium border border-blue-200">
                                                <?php echo htmlspecialchars($expense['category_name']); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 hidden sm:table-cell">
                                        <span class="inline-block bg-blue-50 text-blue-700 px-3 py-1.5 rounded-lg text-xs font-medium border border-blue-200">
                                            <?php echo htmlspecialchars($expense['category_name']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <span class="font-bold text-gray-900 text-lg">₱<?php echo number_format($expense['amount'], 2); ?></span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="text-center py-16">
                            <div class="text-6xl mb-4 opacity-20">📭</div>
                            <p class="text-gray-500 text-lg font-medium">No expenses recorded yet</p>
                            <p class="text-gray-400 text-sm mt-2">This user hasn't created any expense entries</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
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

        const sidebarLinks = sidebar.querySelectorAll('a');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 1024) {
                    toggleSidebar();
                }
            });
        });

        // Chart.js Configuration
        const chartColors = {
            primary: '#4F46E5',
            secondary: '#7C3AED',
            accent: '#EC4899',
            success: '#10B981',
            warning: '#F59E0B',
            danger: '#EF4444',
            info: '#3B82F6'
        };

        // Monthly Spending Chart
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        const monthlyData = <?php 
            $months = [];
            $totals = [];
            while ($row = $monthly_result->fetch_assoc()) {
                $months[] = date('M Y', strtotime($row['month'] . '-01'));
                $totals[] = $row['total'];
            }
            echo json_encode(['months' => $months, 'totals' => $totals]);
        ?>;
        
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: monthlyData.months,
                datasets: [{
                    label: 'Monthly Spending',
                    data: monthlyData.totals,
                    borderColor: chartColors.primary,
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: chartColors.primary,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { 
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 13 },
                        callbacks: {
                            label: function(context) {
                                return 'Amount: ₱' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.05)' },
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    },
                    x: { 
                        grid: { display: false }
                    }
                }
            }
        });
        
        // Category Chart
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        const categoryData = <?php 
            $categories = [];
            $cat_totals = [];
            while ($row = $category_result->fetch_assoc()) {
                $categories[] = $row['name'];
                $cat_totals[] = $row['total'];
            }
            echo json_encode(['categories' => $categories, 'totals' => $cat_totals]);
        ?>;
        
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: categoryData.categories,
                datasets: [{
                    data: categoryData.totals,
                    backgroundColor: [
                        chartColors.primary,
                        chartColors.secondary,
                        chartColors.accent,
                        chartColors.success,
                        chartColors.warning,
                        chartColors.info,
                        chartColors.danger,
                        '#6366F1',
                        '#8B5CF6',
                        '#EC4899'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            padding: 15,
                            font: { size: 12 },
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 13 },
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = '₱' + context.parsed.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1) + '%';
                                return label + ': ' + value + ' (' + percentage + ')';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>