<?php
/**
 * API Sync Endpoint
 * Handles automatic synchronization of linked bank accounts
 * 
 * Endpoints:
 * POST /api/sync.php?action=sync_account - Sync single account
 * POST /api/sync.php?action=sync_all - Sync all user accounts
 * GET /api/sync.php?action=status - Get sync status
 * GET /api/sync.php?action=history - Get sync history
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/db_connect.php';
require_once '../includes/auth_check.php';

// API Response Helper
function sendResponse($success, $message, $data = null, $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit();
}

// Validate API authentication
function validateApiAuth($conn) {
    $headers = getallheaders();
    $token = null;
    
    // Check for Authorization header
    if (isset($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            $token = $matches[1];
        }
    }
    
    // Check for API key in header or query
    if (!$token && isset($headers['X-API-Key'])) {
        $token = $headers['X-API-Key'];
    }
    if (!$token && isset($_GET['api_key'])) {
        $token = $_GET['api_key'];
    }
    
    // For session-based auth (web app)
    if (!$token && isset($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    
    if (!$token) {
        sendResponse(false, 'Authentication required', null, 401);
    }
    
    // Validate token (implement your token validation logic)
    $stmt = $conn->prepare("SELECT user_id FROM api_tokens WHERE token = ? AND expires_at > NOW() AND status = 'active'");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!$result) {
        sendResponse(false, 'Invalid or expired token', null, 401);
    }
    
    return $result['user_id'];
}

// Fetch transactions from bank API (simulation)
function fetchBankTransactions($account) {
    // In production, integrate with actual bank APIs
    // This is a simulation for demonstration
    
    $transactions = [];
    $num_transactions = rand(3, 8);
    
    $categories = [
        'Food & Dining',
        'Transportation',
        'Shopping',
        'Utilities',
        'Entertainment',
        'Healthcare',
        'Education',
        'Personal Care'
    ];
    
    $descriptions = [
        'Grocery Store',
        'Restaurant',
        'Gas Station',
        'Online Shopping',
        'Electric Bill',
        'Water Bill',
        'Movie Theater',
        'Pharmacy',
        'Coffee Shop',
        'Taxi',
        'Bookstore',
        'Gym',
        'Salon'
    ];
    
    for ($i = 0; $i < $num_transactions; $i++) {
        $days_ago = rand(1, 30);
        $transactions[] = [
            'date' => date('Y-m-d', strtotime("-{$days_ago} days")),
            'description' => $descriptions[array_rand($descriptions)],
            'amount' => rand(50, 3000),
            'category' => $categories[array_rand($categories)],
            'reference' => 'TXN' . time() . rand(10000, 99999) . $i,
            'type' => 'debit',
            'merchant' => $descriptions[array_rand($descriptions)]
        ];
    }
    
    // Sort by date descending
    usort($transactions, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    return $transactions;
}

// Import transaction to expenses
function importTransaction($conn, $user_id, $account_id, $transaction) {
    // Check for duplicate
    $check_stmt = $conn->prepare("SELECT id FROM expenses WHERE user_id = ? AND reference_number = ?");
    $check_stmt->bind_param("is", $user_id, $transaction['reference']);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        return [
            'success' => false,
            'reason' => 'duplicate',
            'message' => 'Transaction already exists'
        ];
    }
    
    // Get or create category
    $cat_stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND (user_id = ? OR user_id IS NULL) LIMIT 1");
    $cat_stmt->bind_param("si", $transaction['category'], $user_id);
    $cat_stmt->execute();
    $cat_result = $cat_stmt->get_result()->fetch_assoc();
    
    if (!$cat_result) {
        $colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];
        $color = $colors[array_rand($colors)];
        
        $create_cat = $conn->prepare("INSERT INTO categories (user_id, name, color) VALUES (?, ?, ?)");
        $create_cat->bind_param("iss", $user_id, $transaction['category'], $color);
        $create_cat->execute();
        $category_id = $conn->insert_id;
    } else {
        $category_id = $cat_result['id'];
    }
    
    // Insert expense
    $insert_stmt = $conn->prepare(
        "INSERT INTO expenses (user_id, category_id, amount, description, expense_date, reference_number, source, notes, created_at) 
         VALUES (?, ?, ?, ?, ?, ?, 'auto_sync', ?, NOW())"
    );
    
    $notes = json_encode([
        'merchant' => $transaction['merchant'] ?? '',
        'type' => $transaction['type'] ?? 'debit',
        'synced_at' => date('Y-m-d H:i:s')
    ]);
    
    $insert_stmt->bind_param(
        "iidssss",
        $user_id,
        $category_id,
        $transaction['amount'],
        $transaction['description'],
        $transaction['date'],
        $transaction['reference'],
        $notes
    );
    
    if ($insert_stmt->execute()) {
        return [
            'success' => true,
            'expense_id' => $conn->insert_id,
            'message' => 'Transaction imported successfully'
        ];
    } else {
        return [
            'success' => false,
            'reason' => 'error',
            'message' => 'Failed to import transaction'
        ];
    }
}

// Log sync operation
function logSync($conn, $user_id, $account_id, $found, $imported, $skipped, $failed, $status, $error = null) {
    $stmt = $conn->prepare(
        "INSERT INTO sync_logs (user_id, account_id, transactions_found, transactions_imported, 
         transactions_skipped, transactions_failed, status, error_message, sync_date) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    
    $stmt->bind_param("iiiiiiss", $user_id, $account_id, $found, $imported, $skipped, $failed, $status, $error);
    $stmt->execute();
    
    return $conn->insert_id;
}

// Main API Router
try {
    $user_id = validateApiAuth($conn);
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'sync_account':
            // Sync single account
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendResponse(false, 'Method not allowed', null, 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $account_id = $input['account_id'] ?? $_POST['account_id'] ?? null;
            
            if (!$account_id) {
                sendResponse(false, 'Account ID required', null, 400);
            }
            
            // Verify account ownership
            $stmt = $conn->prepare(
                "SELECT la.*, b.name as bank_name, b.type 
                 FROM linked_accounts la 
                 JOIN banks b ON la.bank_id = b.id 
                 WHERE la.id = ? AND la.user_id = ? AND la.status = 'active'"
            );
            $stmt->bind_param("ii", $account_id, $user_id);
            $stmt->execute();
            $account = $stmt->get_result()->fetch_assoc();
            
            if (!$account) {
                sendResponse(false, 'Account not found or access denied', null, 404);
            }
            
            // Fetch transactions
            $transactions = fetchBankTransactions($account);
            $found = count($transactions);
            $imported = 0;
            $skipped = 0;
            $failed = 0;
            $imported_transactions = [];
            $skipped_transactions = [];
            
            foreach ($transactions as $transaction) {
                $result = importTransaction($conn, $user_id, $account_id, $transaction);
                
                if ($result['success']) {
                    $imported++;
                    $imported_transactions[] = array_merge($transaction, [
                        'expense_id' => $result['expense_id']
                    ]);
                } elseif ($result['reason'] === 'duplicate') {
                    $skipped++;
                    $skipped_transactions[] = $transaction;
                } else {
                    $failed++;
                }
            }
            
            // Update last synced
            $update_stmt = $conn->prepare("UPDATE linked_accounts SET last_synced = NOW() WHERE id = ?");
            $update_stmt->bind_param("i", $account_id);
            $update_stmt->execute();
            
            // Log sync
            $status = $failed > 0 ? 'partial' : 'success';
            $log_id = logSync($conn, $user_id, $account_id, $found, $imported, $skipped, $failed, $status);
            
            sendResponse(true, 'Account synced successfully', [
                'account' => [
                    'id' => $account['id'],
                    'name' => $account['account_name'],
                    'bank' => $account['bank_name']
                ],
                'sync_summary' => [
                    'transactions_found' => $found,
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'failed' => $failed
                ],
                'imported_transactions' => $imported_transactions,
                'skipped_transactions' => $skipped_transactions,
                'log_id' => $log_id
            ]);
            break;
            
        case 'sync_all':
            // Sync all user accounts
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendResponse(false, 'Method not allowed', null, 405);
            }
            
            // Get all active accounts
            $stmt = $conn->prepare(
                "SELECT la.*, b.name as bank_name, b.type 
                 FROM linked_accounts la 
                 JOIN banks b ON la.bank_id = b.id 
                 WHERE la.user_id = ? AND la.status = 'active'"
            );
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            if (empty($accounts)) {
                sendResponse(false, 'No active accounts found', null, 404);
            }
            
            $sync_results = [];
            $total_imported = 0;
            $total_skipped = 0;
            $total_failed = 0;
            
            foreach ($accounts as $account) {
                $transactions = fetchBankTransactions($account);
                $found = count($transactions);
                $imported = 0;
                $skipped = 0;
                $failed = 0;
                
                foreach ($transactions as $transaction) {
                    $result = importTransaction($conn, $user_id, $account['id'], $transaction);
                    
                    if ($result['success']) {
                        $imported++;
                    } elseif ($result['reason'] === 'duplicate') {
                        $skipped++;
                    } else {
                        $failed++;
                    }
                }
                
                // Update last synced
                $update_stmt = $conn->prepare("UPDATE linked_accounts SET last_synced = NOW() WHERE id = ?");
                $update_stmt->bind_param("i", $account['id']);
                $update_stmt->execute();
                
                // Log sync
                $status = $failed > 0 ? 'partial' : 'success';
                logSync($conn, $user_id, $account['id'], $found, $imported, $skipped, $failed, $status);
                
                $sync_results[] = [
                    'account_id' => $account['id'],
                    'account_name' => $account['account_name'],
                    'bank_name' => $account['bank_name'],
                    'transactions_found' => $found,
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'failed' => $failed
                ];
                
                $total_imported += $imported;
                $total_skipped += $skipped;
                $total_failed += $failed;
            }
            
            sendResponse(true, 'All accounts synced successfully', [
                'accounts_synced' => count($accounts),
                'total_summary' => [
                    'imported' => $total_imported,
                    'skipped' => $total_skipped,
                    'failed' => $total_failed
                ],
                'account_results' => $sync_results
            ]);
            break;
            
        case 'status':
            // Get sync status
            $account_id = $_GET['account_id'] ?? null;
            
            if ($account_id) {
                // Single account status
                $stmt = $conn->prepare(
                    "SELECT la.*, b.name as bank_name, 
                     (SELECT COUNT(*) FROM expenses WHERE user_id = ? AND source = 'auto_sync') as synced_expenses
                     FROM linked_accounts la 
                     JOIN banks b ON la.bank_id = b.id 
                     WHERE la.id = ? AND la.user_id = ?"
                );
                $stmt->bind_param("iii", $user_id, $account_id, $user_id);
            } else {
                // All accounts status
                $stmt = $conn->prepare(
                    "SELECT la.*, b.name as bank_name, b.type,
                     (SELECT COUNT(*) FROM expenses WHERE user_id = ? AND source = 'auto_sync') as total_synced
                     FROM linked_accounts la 
                     JOIN banks b ON la.bank_id = b.id 
                     WHERE la.user_id = ? AND la.status = 'active'"
                );
                $stmt->bind_param("ii", $user_id, $user_id);
            }
            
            $stmt->execute();
            $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            sendResponse(true, 'Sync status retrieved', [
                'accounts' => $accounts,
                'total_accounts' => count($accounts)
            ]);
            break;
            
        case 'history':
            // Get sync history
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
            $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
            $account_id = $_GET['account_id'] ?? null;
            
            if ($account_id) {
                $stmt = $conn->prepare(
                    "SELECT sl.*, la.account_name, b.name as bank_name 
                     FROM sync_logs sl
                     JOIN linked_accounts la ON sl.account_id = la.id
                     JOIN banks b ON la.bank_id = b.id
                     WHERE sl.user_id = ? AND sl.account_id = ?
                     ORDER BY sl.sync_date DESC
                     LIMIT ? OFFSET ?"
                );
                $stmt->bind_param("iiii", $user_id, $account_id, $limit, $offset);
            } else {
                $stmt = $conn->prepare(
                    "SELECT sl.*, la.account_name, b.name as bank_name 
                     FROM sync_logs sl
                     JOIN linked_accounts la ON sl.account_id = la.id
                     JOIN banks b ON la.bank_id = b.id
                     WHERE sl.user_id = ?
                     ORDER BY sl.sync_date DESC
                     LIMIT ? OFFSET ?"
                );
                $stmt->bind_param("iii", $user_id, $limit, $offset);
            }
            
            $stmt->execute();
            $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // Get total count
            $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM sync_logs WHERE user_id = ?");
            $count_stmt->bind_param("i", $user_id);
            $count_stmt->execute();
            $total = $count_stmt->get_result()->fetch_assoc()['total'];
            
            sendResponse(true, 'Sync history retrieved', [
                'history' => $history,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total
                ]
            ]);
            break;
            
        default:
            sendResponse(false, 'Invalid action', null, 400);
    }
    
} catch (Exception $e) {
    error_log("API Sync Error: " . $e->getMessage());
    sendResponse(false, 'An error occurred: ' . $e->getMessage(), null, 500);
}