<?php
/**
 * Finsight Chatbot Engine - Data-Driven Chatbot (MySQLi Version)
 * File: includes/chatbot_engine.php
 */

class FinsightChatbot {
    private $conn;
    private $user_id;
    private $user_data;
    
    public function __construct($conn, $user_id) {
        $this->conn = $conn;
        $this->user_id = $user_id;
        $this->loadUserData();
    }
    
    private function loadUserData() {
        $stmt = $this->conn->prepare("
            SELECT id, name, email, monthly_budget, created_at
            FROM users 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $this->user_data = $result->fetch_assoc();
        $stmt->close();
    }
    
    /**
     * Main response handler
     */
    public function getResponse($message) {
        $this->logMessage($message, 'user');
        
        $message = trim($message);
        $message_lower = strtolower($message);
        
        $intent = $this->detectIntent($message_lower);
        $response = $this->generateResponse($intent, $message, $message_lower);
        
        $this->logMessage($response, 'bot');
        
        return [
            'success' => true,
            'message' => $response,
            'intent' => $intent
        ];
    }
    
    /**
     * Intent detection with keyword matching
     */
    private function detectIntent($message) {
        $intents = [
            'SHOW_BUDGET' => ['budget', 'allowance', 'limit', 'bakla', 'balance', 'magkano pa', 'natitira', 'remaining', 'left'],
            'TOTAL_SPENDING' => ['spend', 'spent', 'gastos', 'ginastos', 'total', 'magkano na', 'ilang pera', 'how much'],
            'ADD_EXPENSE' => ['add', 'dagdag', 'expense', 'gastos', 'bili', 'bumili'],
            'RECENT_EXPENSES' => ['recent', 'latest', 'last', 'kamakailan', 'huling', 'transaction'],
            'CATEGORY_SPENDING' => ['food', 'transport', 'bills', 'category', 'pagkain', 'pamasahe', 'entertainment', 'shopping'],
            'TOP_EXPENSES' => ['top', 'biggest', 'highest', 'most expensive', 'pinakamahal'],
            'DAILY_AVERAGE' => ['average', 'daily', 'per day', 'araw-araw'],
            'WEEK_SPENDING' => ['week', 'weekly', 'this week', 'linggong ito'],
            'MONTH_SPENDING' => ['month', 'monthly', 'this month', 'buwan'],
            'COMPARISON' => ['compare', 'vs', 'versus', 'last month'],
            'SAVINGS' => ['save', 'saved', 'savings', 'naipon'],
            'SET_BUDGET' => ['set budget', 'update budget', 'change budget', 'ayusin budget'],
            'HELP' => ['help', 'tulong', 'commands', 'what can you do', 'ano kaya mo'],
            'GREETING' => ['hi', 'hello', 'hey', 'kumusta', 'kamusta', 'musta'],
        ];
        
        foreach ($intents as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($message, $keyword) !== false) {
                    return $intent;
                }
            }
        }
        
        return 'UNKNOWN';
    }
    
    /**
     * Generate response based on intent
     */
    private function generateResponse($intent, $original_message, $message_lower) {
        switch ($intent) {
            case 'GREETING':
                return $this->handleGreeting();
                
            case 'SHOW_BUDGET':
                return $this->handleShowBudget();
                
            case 'TOTAL_SPENDING':
                return $this->handleTotalSpending();
                
            case 'RECENT_EXPENSES':
                return $this->handleRecentExpenses();
                
            case 'CATEGORY_SPENDING':
                return $this->handleCategorySpending($message_lower);
                
            case 'TOP_EXPENSES':
                return $this->handleTopExpenses();
                
            case 'DAILY_AVERAGE':
                return $this->handleDailyAverage();
                
            case 'WEEK_SPENDING':
                return $this->handleWeekSpending();
                
            case 'MONTH_SPENDING':
                return $this->handleMonthSpending();
                
            case 'COMPARISON':
                return $this->handleComparison();
                
            case 'SAVINGS':
                return $this->handleSavings();
                
            case 'HELP':
                return $this->handleHelp();
                
            default:
                return $this->handleUnknown();
        }
    }
    
    private function handleGreeting() {
        $name = $this->user_data['name'] ?? 'there';
        $greetings = [
            "Hi {$name}! 👋 How can I help you with your finances today?",
            "Hello {$name}! Ready to check your budget?",
            "Kumusta {$name}! What would you like to know about your expenses?"
        ];
        return $greetings[array_rand($greetings)];
    }
    
    private function handleShowBudget() {
        $stmt = $this->conn->prepare("
            SELECT 
                u.monthly_budget,
                COALESCE(SUM(e.amount), 0) as total_spent
            FROM users u
            LEFT JOIN expenses e ON u.id = e.user_id 
                AND MONTH(e.expense_date) = MONTH(CURRENT_DATE())
                AND YEAR(e.expense_date) = YEAR(CURRENT_DATE())
            WHERE u.id = ?
            GROUP BY u.id
        ");
        
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        if (!$data) {
            return "⚠️ Could not load your budget data. Please check your budget settings.";
        }
        
        $budget = floatval($data['monthly_budget']);
        $spent = floatval($data['total_spent']);
        $remaining = $budget - $spent;
        $percentage = ($budget > 0) ? round(($spent / $budget) * 100) : 0;
        
        $emoji = $percentage > 80 ? '⚠️' : ($percentage > 50 ? '⚡' : '✅');
        
        $status = $percentage > 90 ? "You're almost at your limit!" : 
                  ($percentage > 70 ? "Getting close to your budget!" : 
                  "You're doing great!");
        
        return "{$emoji} Your Budget Status\n\n" .
               "💰 Monthly Budget: ₱" . number_format($budget, 2) . "\n" .
               "📊 Total Spent: ₱" . number_format($spent, 2) . " ({$percentage}%)\n" .
               "💵 Remaining: ₱" . number_format($remaining, 2) . "\n\n" .
               $status;
    }
    
    private function handleTotalSpending() {
        $stmt = $this->conn->prepare("
            SELECT 
                COALESCE(SUM(amount), 0) as total,
                COUNT(*) as count
            FROM expenses
            WHERE user_id = ?
            AND MONTH(expense_date) = MONTH(CURRENT_DATE())
            AND YEAR(expense_date) = YEAR(CURRENT_DATE())
        ");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        $total = floatval($data['total']);
        $count = intval($data['count']);
        $average = $count > 0 ? $total / $count : 0;
        
        return "💰 This Month's Spending\n\n" .
               "Total: ₱" . number_format($total, 2) . "\n" .
               "Transactions: {$count}\n" .
               "Average per transaction: ₱" . number_format($average, 2);
    }
    
    private function handleRecentExpenses() {
        $stmt = $this->conn->prepare("
            SELECT e.description, e.amount, e.expense_date, c.name as category_name
            FROM expenses e
            LEFT JOIN categories c ON e.category_id = c.id
            WHERE e.user_id = ?
            ORDER BY e.expense_date DESC, e.id DESC
            LIMIT 5
        ");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $expenses = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($expenses)) {
            return "📝 You haven't added any expenses yet. Start tracking your spending today!";
        }
        
        $response = "📝 Your Recent Expenses\n\n";
        foreach ($expenses as $exp) {
            $date = date('M d, Y', strtotime($exp['expense_date']));
            $response .= "• {$date}: ₱" . number_format($exp['amount'], 2) . 
                        " - {$exp['description']}" . 
                        ($exp['category_name'] ? " ({$exp['category_name']})" : "") . "\n";
        }
        
        return $response;
    }
    
    private function handleCategorySpending($message) {
        // Get all categories
        $result = $this->conn->query("SELECT id, name FROM categories");
        $categories = $result->fetch_all(MYSQLI_ASSOC);
        
        $category = null;
        $category_id = null;
        
        foreach ($categories as $cat) {
            if (strpos($message, strtolower($cat['name'])) !== false) {
                $category = $cat['name'];
                $category_id = $cat['id'];
                break;
            }
        }
        
        if (!$category) {
            $cat_list = implode(', ', array_column($categories, 'name'));
            return "🏷️ Which category would you like to check?\n\nAvailable: {$cat_list}";
        }
        
        $stmt = $this->conn->prepare("
            SELECT 
                COALESCE(SUM(e.amount), 0) as total, 
                COUNT(*) as count
            FROM expenses e
            WHERE e.user_id = ?
            AND e.category_id = ?
            AND MONTH(e.expense_date) = MONTH(CURRENT_DATE())
            AND YEAR(e.expense_date) = YEAR(CURRENT_DATE())
        ");
        $stmt->bind_param("ii", $this->user_id, $category_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        return "🏷️ {$category} Spending\n\n" .
               "Total: ₱" . number_format($data['total'], 2) . "\n" .
               "Transactions: {$data['count']}";
    }
    
    private function handleTopExpenses() {
        $stmt = $this->conn->prepare("
            SELECT e.description, e.amount, e.expense_date, c.name as category_name
            FROM expenses e
            LEFT JOIN categories c ON e.category_id = c.id
            WHERE e.user_id = ?
            AND MONTH(e.expense_date) = MONTH(CURRENT_DATE())
            AND YEAR(e.expense_date) = YEAR(CURRENT_DATE())
            ORDER BY e.amount DESC
            LIMIT 5
        ");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $expenses = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($expenses)) {
            return "📊 No expenses found for this month.";
        }
        
        $response = "📊 Top 5 Expenses This Month\n\n";
        foreach ($expenses as $i => $exp) {
            $response .= ($i + 1) . ". ₱" . number_format($exp['amount'], 2) . 
                        " - {$exp['description']}" . 
                        ($exp['category_name'] ? " ({$exp['category_name']})" : "") . "\n";
        }
        
        return $response;
    }
    
    private function handleDailyAverage() {
        $stmt = $this->conn->prepare("
            SELECT 
                COALESCE(SUM(amount), 0) as total,
                COUNT(DISTINCT DATE(expense_date)) as days
            FROM expenses
            WHERE user_id = ?
            AND MONTH(expense_date) = MONTH(CURRENT_DATE())
            AND YEAR(expense_date) = YEAR(CURRENT_DATE())
        ");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        $average = $data['days'] > 0 ? $data['total'] / $data['days'] : 0;
        
        return "📈 Daily Average Spending\n\n" .
               "Average per day: ₱" . number_format($average, 2) . "\n" .
               "Days with expenses: {$data['days']}\n" .
               "Total this month: ₱" . number_format($data['total'], 2);
    }
    
    private function handleWeekSpending() {
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM expenses
            WHERE user_id = ?
            AND YEARWEEK(expense_date, 1) = YEARWEEK(CURRENT_DATE(), 1)
        ");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        return "📅 This Week's Spending\n\n" .
               "Total: ₱" . number_format($data['total'], 2);
    }
    
    private function handleMonthSpending() {
        $stmt = $this->conn->prepare("
            SELECT 
                c.name as category,
                COALESCE(SUM(e.amount), 0) as total
            FROM categories c
            LEFT JOIN expenses e ON c.id = e.category_id 
                AND e.user_id = ?
                AND MONTH(e.expense_date) = MONTH(CURRENT_DATE())
                AND YEAR(e.expense_date) = YEAR(CURRENT_DATE())
            GROUP BY c.id, c.name
            HAVING total > 0
            ORDER BY total DESC
        ");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $categories = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($categories)) {
            return "📊 No expenses recorded this month.";
        }
        
        $response = "📊 Spending by Category\n\n";
        foreach ($categories as $cat) {
            $response .= "• {$cat['category']}: ₱" . number_format($cat['total'], 2) . "\n";
        }
        
        return $response;
    }
    
    private function handleComparison() {
        $stmt = $this->conn->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN MONTH(expense_date) = MONTH(CURRENT_DATE()) 
                    AND YEAR(expense_date) = YEAR(CURRENT_DATE()) THEN amount END), 0) as current_month,
                COALESCE(SUM(CASE WHEN MONTH(expense_date) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)) 
                    AND YEAR(expense_date) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)) THEN amount END), 0) as last_month
            FROM expenses
            WHERE user_id = ?
        ");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        $diff = $data['current_month'] - $data['last_month'];
        $emoji = $diff > 0 ? '📈' : '📉';
        $status = $diff > 0 ? 'more' : 'less';
        
        return "📊 Month Comparison\n\n" .
               "This month: ₱" . number_format($data['current_month'], 2) . "\n" .
               "Last month: ₱" . number_format($data['last_month'], 2) . "\n" .
               "{$emoji} Difference: ₱" . number_format(abs($diff), 2) . " {$status}";
    }
    
    private function handleSavings() {
        $stmt = $this->conn->prepare("
            SELECT 
                u.monthly_budget,
                COALESCE(SUM(e.amount), 0) as total_spent
            FROM users u
            LEFT JOIN expenses e ON u.id = e.user_id 
                AND MONTH(e.expense_date) = MONTH(CURRENT_DATE())
                AND YEAR(e.expense_date) = YEAR(CURRENT_DATE())
            WHERE u.id = ?
            GROUP BY u.id
        ");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        $savings = $data['monthly_budget'] - $data['total_spent'];
        $emoji = $savings > 0 ? '💰' : '⚠️';
        
        return "{$emoji} Your Savings\n\n" .
               "Budget: ₱" . number_format($data['monthly_budget'], 2) . "\n" .
               "Spent: ₱" . number_format($data['total_spent'], 2) . "\n" .
               "Potential savings: ₱" . number_format($savings, 2);
    }
    
    private function handleHelp() {
        return "🤖 I can help you with:\n\n" .
               "💰 Budget & Money:\n" .
               "• 'show budget' / 'how much left'\n" .
               "• 'total spending' / 'how much spent'\n" .
               "• 'savings'\n\n" .
               "📝 Expenses:\n" .
               "• 'recent expenses' / 'last transactions'\n" .
               "• 'top expenses' / 'biggest spending'\n" .
               "• 'food spending' (any category)\n\n" .
               "📊 Analysis:\n" .
               "• 'daily average'\n" .
               "• 'this week' / 'this month'\n" .
               "• 'compare last month'\n\n" .
               "Just ask naturally! 😊";
    }
    
    private function handleUnknown() {
        $suggestions = [
            "🤔 I'm not sure what you mean. Try:\n• 'show my budget'\n• 'how much did I spend?'\n• 'recent expenses'\n\nType 'help' for all commands!",
            "❓ I didn't understand that. You can ask:\n• 'magkano pa natitira?'\n• 'show recent expenses'\n• 'help' for more",
        ];
        return $suggestions[array_rand($suggestions)];
    }
    
    /**
     * Log conversation to database
     */
    private function logMessage($message, $sender) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO chatbot_logs (user_id, message, sender, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->bind_param("iss", $this->user_id, $message, $sender);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("Chatbot log error: " . $e->getMessage());
        }
    }
}