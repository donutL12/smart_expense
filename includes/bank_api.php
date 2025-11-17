<?php
/**
 * Bank API Integration Helpers
 * Handles communication with bank and e-wallet APIs
 */

class BankAPI {
    private $conn;
    private $api_configs;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
        $this->loadApiConfigs();
    }
    
    /**
     * Load API configurations for all banks
     */
    private function loadApiConfigs() {
        $stmt = $this->conn->prepare(
            "SELECT id, name, type, api_endpoint, api_key FROM banks WHERE status = 'active'"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        
        $this->api_configs = [];
        while ($row = $result->fetch_assoc()) {
            $this->api_configs[$row['id']] = $row;
        }
    }
    
    /**
     * Authenticate with bank API
     * 
     * @param int $bank_id Bank ID
     * @param string $account_number Account number
     * @param string $credentials User credentials (varies by bank)
     * @return array Authentication result
     */
    public function authenticate($bank_id, $account_number, $credentials) {
        $bank_config = $this->api_configs[$bank_id] ?? null;
        
        if (!$bank_config) {
            return [
                'success' => false,
                'error' => 'Bank not found or not configured'
            ];
        }
        
        // Different authentication flows for different bank types
        switch ($bank_config['type']) {
            case 'bank':
                return $this->authenticateBank($bank_config, $account_number, $credentials);
            case 'ewallet':
                return $this->authenticateEWallet($bank_config, $account_number, $credentials);
            default:
                return ['success' => false, 'error' => 'Unknown bank type'];
        }
    }
    
    /**
     * Authenticate with traditional bank
     */
    private function authenticateBank($config, $account_number, $credentials) {
        // In production, implement actual OAuth2 or bank-specific authentication
        // This is a simulation
        
        if (empty($config['api_endpoint'])) {
            // Simulate authentication for demo
            return [
                'success' => true,
                'access_token' => 'demo_bank_token_' . bin2hex(random_bytes(16)),
                'expires_in' => 3600,
                'refresh_token' => 'demo_refresh_' . bin2hex(random_bytes(16))
            ];
        }
        
        // Real implementation would look like:
        /*
        $ch = curl_init($config['api_endpoint'] . '/oauth/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($config['api_key'])
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'grant_type' => 'password',
                'account_number' => $account_number,
                'credentials' => $credentials
            ])
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            return array_merge(['success' => true], json_decode($response, true));
        }
        
        return ['success' => false, 'error' => 'Authentication failed'];
        */
        
        return [
            'success' => true,
            'access_token' => 'simulated_token',
            'expires_in' => 3600
        ];
    }
    
    /**
     * Authenticate with e-wallet
     */
    private function authenticateEWallet($config, $mobile_number, $credentials) {
        // E-wallets typically use mobile number + OTP
        // This is a simulation
        
        return [
            'success' => true,
            'access_token' => 'demo_ewallet_token_' . bin2hex(random_bytes(16)),
            'expires_in' => 3600
        ];
    }
    
    /**
     * Fetch transactions from bank API
     * 
     * @param int $account_id Linked account ID
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @return array Transactions
     */
    public function fetchTransactions($account_id, $start_date = null, $end_date = null) {
        // Get account details
        $stmt = $this->conn->prepare(
            "SELECT la.*, b.* FROM linked_accounts la 
             JOIN banks b ON la.bank_id = b.id 
             WHERE la.id = ?"
        );
        $stmt->bind_param("i", $account_id);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        
        if (!$account) {
            return ['success' => false, 'error' => 'Account not found'];
        }
        
        // Set default date range if not provided
        if (!$start_date) {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$end_date) {
            $end_date = date('Y-m-d');
        }
        
        // Fetch from appropriate API
        if ($account['type'] === 'bank') {
            return $this->fetchBankTransactions($account, $start_date, $end_date);
        } else {
            return $this->fetchEWalletTransactions($account, $start_date, $end_date);
        }
    }
    
    /**
     * Fetch transactions from bank
     */
    private function fetchBankTransactions($account, $start_date, $end_date) {
        // In production, call actual bank API
        // This is a simulation
        
        $transactions = $this->simulateTransactions($start_date, $end_date, 'bank');
        
        return [
            'success' => true,
            'transactions' => $transactions,
            'count' => count($transactions)
        ];
    }
    
    /**
     * Fetch transactions from e-wallet
     */
    private function fetchEWalletTransactions($account, $start_date, $end_date) {
        // In production, call actual e-wallet API
        // This is a simulation
        
        $transactions = $this->simulateTransactions($start_date, $end_date, 'ewallet');
        
        return [
            'success' => true,
            'transactions' => $transactions,
            'count' => count($transactions)
        ];
    }
    
    /**
     * Simulate transactions for demo purposes
     */
    private function simulateTransactions($start_date, $end_date, $type = 'bank') {
        $transactions = [];
        $days_diff = (strtotime($end_date) - strtotime($start_date)) / 86400;
        $num_transactions = rand(5, min(20, $days_diff * 2));
        
        $categories = [
            'Food & Dining' => ['Restaurant', 'Fast Food', 'Coffee Shop', 'Grocery Store'],
            'Transportation' => ['Gas Station', 'Taxi', 'Parking', 'Toll Fee'],
            'Shopping' => ['Department Store', 'Online Shopping', 'Clothing Store', 'Electronics'],
            'Utilities' => ['Electric Bill', 'Water Bill', 'Internet Bill', 'Phone Bill'],
            'Entertainment' => ['Movie Theater', 'Streaming Service', 'Gaming', 'Concert'],
            'Healthcare' => ['Pharmacy', 'Hospital', 'Clinic', 'Medical Test'],
            'Education' => ['Bookstore', 'Online Course', 'School Supplies', 'Tuition'],
            'Personal Care' => ['Salon', 'Gym', 'Spa', 'Beauty Products']
        ];
        
        for ($i = 0; $i < $num_transactions; $i++) {
            $days_ago = rand(0, $days_diff);
            $transaction_date = date('Y-m-d', strtotime($start_date . " +{$days_ago} days"));
            
            $category = array_rand($categories);
            $merchants = $categories[$category];
            $merchant = $merchants[array_rand($merchants)];
            
            $amount = $type === 'ewallet' ? rand(50, 1500) : rand(100, 5000);
            
            $transactions[] = [
                'date' => $transaction_date,
                'time' => sprintf('%02d:%02d:%02d', rand(0, 23), rand(0, 59), rand(0, 59)),
                'description' => $merchant,
                'merchant' => $merchant,
                'category' => $category,
                'amount' => $amount,
                'type' => 'debit',
                'status' => 'completed',
                'reference' => strtoupper($type) . '_' . date('Ymd') . '_' . bin2hex(random_bytes(4)),
                'balance_after' => rand(1000, 50000)
            ];
        }
        
        // Sort by date descending
        usort($transactions, function($a, $b) {
            return strtotime($b['date'] . ' ' . $b['time']) - strtotime($a['date'] . ' ' . $a['time']);
        });
        
        return $transactions;
    }
    
    /**
     * Verify account balance
     * 
     * @param int $account_id Linked account ID
     * @return array Balance information
     */
    public function getBalance($account_id) {
        $stmt = $this->conn->prepare(
            "SELECT la.*, b.* FROM linked_accounts la 
             JOIN banks b ON la.bank_id = b.id 
             WHERE la.id = ?"
        );
        $stmt->bind_param("i", $account_id);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        
        if (!$account) {
            return ['success' => false, 'error' => 'Account not found'];
        }
        
        // Simulate balance check
        return [
            'success' => true,
            'account_number' => '****' . substr($account['account_number'], -4),
            'available_balance' => rand(5000, 50000),
            'current_balance' => rand(5000, 50000),
            'currency' => 'PHP',
            'as_of' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Refresh API tokens for account
     * 
     * @param int $account_id Linked account ID
     * @return array Refresh result
     */
    public function refreshToken($account_id) {
        // In production, implement actual token refresh
        // This is a simulation
        
        return [
            'success' => true,
            'message' => 'Token refreshed successfully',
            'expires_in' => 3600
        ];
    }
    
    /**
     * Test API connection
     * 
     * @param int $bank_id Bank ID
     * @return array Test result
     */
    public function testConnection($bank_id) {
        $bank_config = $this->api_configs[$bank_id] ?? null;
        
        if (!$bank_config) {
            return [
                'success' => false,
                'error' => 'Bank not found'
            ];
        }
        
        // Simulate connection test
        return [
            'success' => true,
            'message' => 'Connection successful',
            'bank' => $bank_config['name'],
            'latency_ms' => rand(50, 300)
        ];
    }
    
    /**
     * Map bank category to local category
     * 
     * @param string $bank_category Bank's category name
     * @return string Local category name
     */
    public function mapCategory($bank_category) {
        $category_map = [
            // Food related
            'restaurants' => 'Food & Dining',
            'food' => 'Food & Dining',
            'dining' => 'Food & Dining',
            'groceries' => 'Food & Dining',
            
            // Transport
            'transportation' => 'Transportation',
            'gas' => 'Transportation',
            'fuel' => 'Transportation',
            'parking' => 'Transportation',
            
            // Shopping
            'shopping' => 'Shopping',
            'retail' => 'Shopping',
            'online shopping' => 'Shopping',
            
            // Bills
            'utilities' => 'Utilities',
            'bills' => 'Utilities',
            'services' => 'Utilities',
            
            // Entertainment
            'entertainment' => 'Entertainment',
            'recreation' => 'Entertainment',
            
            // Health
            'healthcare' => 'Healthcare',
            'medical' => 'Healthcare',
            'pharmacy' => 'Healthcare',
            
            // Education
            'education' => 'Education',
            'tuition' => 'Education',
            
            // Personal
            'personal care' => 'Personal Care',
            'beauty' => 'Personal Care'
        ];
        
        $normalized = strtolower(trim($bank_category));
        return $category_map[$normalized] ?? 'Other';
    }
}

/**
 * Helper function to get BankAPI instance
 */
function getBankAPI($conn) {
    return new BankAPI($conn);
}