<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/auth_user.php';

$user_id = $_SESSION['user_id'];
$sync_results = [];
$total_imported = 0;
$total_errors = 0;

// Function to simulate bank API transaction fetch
function fetchBankTransactions($account) {
    // In production, this would call actual bank APIs
    // For demo purposes, we'll simulate transactions
    $transactions = [];
    
    // Simulate 5-10 random transactions
    $num_transactions = rand(5, 10);
    $categories = ['Food & Dining', 'Transportation', 'Shopping', 'Utilities', 'Entertainment', 'Healthcare'];
    $descriptions = [
        'Grocery Store Purchase',
        'Restaurant Payment',
        'Gas Station',
        'Online Shopping',
        'Utility Bill Payment',
        'Movie Theater',
        'Pharmacy',
        'Coffee Shop',
        'Taxi Fare',
        'Supermarket'
    ];
    
    for ($i = 0; $i < $num_transactions; $i++) {
        $date = date('Y-m-d', strtotime('-' . rand(1, 30) . ' days'));
        $transactions[] = [
            'date' => $date,
            'description' => $descriptions[array_rand($descriptions)],
            'amount' => rand(50, 5000),
            'category' => $categories[array_rand($categories)],
            'reference' => 'TXN' . time() . rand(1000, 9999)
        ];
    }
    
    return $transactions;
}

// Function to import transaction to expenses
function importTransaction($conn, $user_id, $account_id, $transaction) {
    // Check if transaction already imported
    $check_query = "SELECT id FROM expenses WHERE user_id = ? AND reference_number = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("is", $user_id, $transaction['reference']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'Duplicate transaction'];
    }
    
    // Get category ID
    $category_query = "SELECT id FROM categories WHERE name = ? AND (user_id = ? OR user_id IS NULL) LIMIT 1";
    $stmt = $conn->prepare($category_query);
    $stmt->bind_param("si", $transaction['category'], $user_id);
    $stmt->execute();
    $category_result = $stmt->get_result()->fetch_assoc();
    
    if (!$category_result) {
        // Create category if doesn't exist
        $colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
        $color = $colors[array_rand($colors)];
        $create_cat = "INSERT INTO categories (user_id, name, color) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($create_cat);
        $stmt->bind_param("iss", $user_id, $transaction['category'], $color);
        $stmt->execute();
        $category_id = $conn->insert_id;
    } else {
        $category_id = $category_result['id'];
    }
    
    // Insert expense
    $insert_query = "INSERT INTO expenses (user_id, category_id, amount, description, expense_date, reference_number, source, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, 'auto_sync', NOW())";
    $stmt = $conn->prepare($insert_query);
    $source = "Linked Account";
    $stmt->bind_param("iidsss", $user_id, $category_id, $transaction['amount'], $transaction['description'], $transaction['date'], $transaction['reference']);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Imported successfully'];
    } else {
        return ['success' => false, 'message' => 'Database error'];
    }
}

// Handle sync request
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $account_id = isset($_GET['account_id']) ? filter_var($_GET['account_id'], FILTER_VALIDATE_INT) : null;
    
    if ($account_id) {
        // Sync single account
        $account_query = "SELECT la.*, b.name as bank_name FROM linked_accounts la 
                          JOIN banks b ON la.bank_id = b.id 
                          WHERE la.id = ? AND la.user_id = ? AND la.status = 'active'";
        $stmt = $conn->prepare($account_query);
        $stmt->bind_param("ii", $account_id, $user_id);
        $stmt->execute();
        $accounts = [$stmt->get_result()->fetch_assoc()];
    } else {
        // Sync all accounts
        $accounts_query = "SELECT la.*, b.name as bank_name FROM linked_accounts la 
                           JOIN banks b ON la.bank_id = b.id 
                           WHERE la.user_id = ? AND la.status = 'active'";
        $stmt = $conn->prepare($accounts_query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    // Process each account
    foreach ($accounts as $account) {
        if (!$account) continue;
        
        $account_result = [
            'account_name' => $account['account_name'],
            'bank_name' => $account['bank_name'],
            'transactions' => [],
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0
        ];
        
        // Fetch transactions from bank
        $transactions = fetchBankTransactions($account);
        
        // Import each transaction
        foreach ($transactions as $transaction) {
            $result = importTransaction($conn, $user_id, $account['id'], $transaction);
            
            $transaction['status'] = $result['success'] ? 'imported' : 'skipped';
            $transaction['message'] = $result['message'];
            $account_result['transactions'][] = $transaction;
            
            if ($result['success']) {
                $account_result['imported']++;
                $total_imported++;
            } elseif (strpos($result['message'], 'Duplicate') !== false) {
                $account_result['skipped']++;
            } else {
                $account_result['errors']++;
                $total_errors++;
            }
        }
        
        // Update last synced timestamp
        $update_query = "UPDATE linked_accounts SET last_synced = NOW() WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("i", $account['id']);
        $stmt->execute();
        
        $sync_results[] = $account_result;
    }
}

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
    <title>Sync Accounts - FinSight</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        * { font-family: 'Inter', sans-serif; }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .animate-spin {
            animation: spin 1s linear infinite;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside class="hidden lg:flex lg:flex-col lg:w-64 bg-white border-r border-gray-200 fixed h-full z-30">
            <div class="flex items-center gap-3 px-6 py-5 border-b border-gray-200">
                <div class="w-10 h-10 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center">
                    <span class="text-white text-xl font-bold">F</span>
                </div>
                <h2 class="text-xl font-bold text-gray-900">FinSight</h2>
            </div>
            
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg">🏠</span>
                    <span>Dashboard</span>
                </a>
                <a href="all_expenses.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg">📋</span>
                    <span>All Expenses</span>
                </a>
                <a href="add_expense.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg">➕</span>
                    <span>Add Expense</span>
                </a>
                <a href="categories.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg">🏷️</span>
                    <span>Categories</span>
                </a>
                <a href="budget_settings.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
    <span class="text-lg">⚙️</span>
    <span>Budget Settings</span>
</a>
<a href="notifications.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                    <span class="text-lg">🔔</span>
                    <span>Notifications</span>
                </a>
                <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg">📊</span>
                    <span>Reports & Analytics</span>
                </a>
                <a href="profile.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg">👤</span>
                    <span>Profile Settings</span>
                </a>
                 <a href="linked_accounts.php" class="nav-item flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                    <span class="text-lg">🏦</span>
                    <span>Linked Accounts</span>
                </a>
                <a href="logout.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg">
                    <span class="text-lg">🚪</span>
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
            <div class="p-4 md:p-6 lg:p-8">
                <!-- Page Header -->
                <div class="mb-6 md:mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <a href="linked_accounts.php" class="text-gray-600 hover:text-gray-900">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                        </a>
                        <h1 class="text-2xl md:text-3xl font-bold text-gray-900">🔄 Account Sync</h1>
                    </div>
                    <p class="text-gray-600">Importing transactions from your linked accounts</p>
                </div>

                <!-- Sync Summary -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-700 rounded-xl flex items-center justify-center text-2xl">
                                ✅
                            </div>
                            <div>
                                <h3 class="text-2xl font-bold text-gray-900"><?php echo $total_imported; ?></h3>
                                <p class="text-sm text-gray-600">Imported</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-700 rounded-xl flex items-center justify-center text-2xl">
                                ⏭️
                            </div>
                            <div>
                                <h3 class="text-2xl font-bold text-gray-900"><?php echo array_sum(array_column($sync_results, 'skipped')); ?></h3>
                                <p class="text-sm text-gray-600">Skipped</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-red-500 to-red-700 rounded-xl flex items-center justify-center text-2xl">
                                ❌
                            </div>
                            <div>
                                <h3 class="text-2xl font-bold text-gray-900"><?php echo $total_errors; ?></h3>
                                <p class="text-sm text-gray-600">Errors</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sync Results by Account -->
                <?php foreach ($sync_results as $result): ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h2 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($result['bank_name']); ?></h2>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($result['account_name']); ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-600">
                                <span class="text-green-600 font-semibold"><?php echo $result['imported']; ?> imported</span>
                                <span class="text-gray-400 mx-1">•</span>
                                <span class="text-blue-600 font-semibold"><?php echo $result['skipped']; ?> skipped</span>
                                <?php if ($result['errors'] > 0): ?>
                                <span class="text-gray-400 mx-1">•</span>
                                <span class="text-red-600 font-semibold"><?php echo $result['errors']; ?> errors</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <?php if (!empty($result['transactions'])): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Date</th>
                                    <th class="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Description</th>
                                    <th class="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Category</th>
                                    <th class="text-right py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Amount</th>
                                    <th class="text-center py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($result['transactions'] as $transaction): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4 text-sm text-gray-600">
                                        <?php echo date('M d, Y', strtotime($transaction['date'])); ?>
                                    </td>
                                    <td class="py-3 px-4 text-sm text-gray-900">
                                        <?php echo htmlspecialchars($transaction['description']); ?>
                                    </td>
                                    <td class="py-3 px-4 text-sm text-gray-700">
                                        <?php echo htmlspecialchars($transaction['category']); ?>
                                    </td>
                                    <td class="py-3 px-4 text-right text-sm font-semibold text-gray-900">
                                        ₱<?php echo number_format($transaction['amount'], 2); ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <?php if ($transaction['status'] === 'imported'): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                            ✅ Imported
                                        </span>
                                        <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-medium">
                                            ⏭️ Skipped
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <!-- Actions -->
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="linked_accounts.php" class="px-6 py-3 bg-gray-600 text-white rounded-lg font-medium hover:bg-gray-700 transition-colors text-center">
                        ← Back to Accounts
                    </a>
                    <a href="dashboard.php" class="px-6 py-3 bg-indigo-600 text-white rounded-lg font-medium hover:bg-indigo-700 transition-colors text-center">
                        Go to Dashboard →
                    </a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>