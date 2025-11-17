<?php
// set_budget.php - First-time Budget Setup

session_start();
require_once 'config/config.php';
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?error=not_logged_in");
    exit();
}

$error = '';
$success = '';

// Get current user budget
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT monthly_budget FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$current_budget = $user['monthly_budget'] ?? 0;

// Handle budget form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_budget'])) {
    $budget = clean_input($_POST['budget']);
    
    // Validation
    if (empty($budget)) {
        $error = "Please enter a budget amount";
    } elseif (!is_numeric($budget)) {
        $error = "Budget must be a valid number";
    } elseif ($budget <= 0) {
        $error = "Budget must be greater than zero";
    } elseif ($budget > 9999999.99) {
        $error = "Budget amount is too large";
    } else {
        // Update user budget
        $stmt = $conn->prepare("UPDATE users SET monthly_budget = ? WHERE id = ?");
        $stmt->bind_param("di", $budget, $user_id);
        
        if ($stmt->execute()) {
            // Try to insert into budget_history if table exists
            try {
                $current_month = date('Y-m');
                
                // Check if budget_history table exists
                $table_check = $conn->query("SHOW TABLES LIKE 'budget_history'");
                
                if ($table_check->num_rows > 0) {
                    // Table exists, insert/update record
                    $stmt2 = $conn->prepare("INSERT INTO budget_history (user_id, month, budget, created_at) 
                                             VALUES (?, ?, ?, NOW()) 
                                             ON DUPLICATE KEY UPDATE budget = ?, updated_at = NOW()");
                    $stmt2->bind_param("isdd", $user_id, $current_month, $budget, $budget);
                    $stmt2->execute();
                    $stmt2->close();
                }
            } catch (Exception $e) {
                // Log error but don't fail the operation
                error_log("Budget history error: " . $e->getMessage());
            }
            
            // Log activity
            log_activity($user_id, "Set monthly budget to " . format_money($budget));
            
            $success = "Budget set successfully! Redirecting to dashboard...";
            
            // Redirect after 2 seconds
            header("refresh:2;url=dashboard.php");
        } else {
            $error = "Failed to set budget. Please try again.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Budget - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        .budget-container {
            animation: slideIn 0.6s ease-out;
        }
        
        .alert {
            animation: fadeIn 0.3s ease-in;
        }

        .suggestion-btn {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .suggestion-btn:hover {
            transform: translateY(-2px);
        }

        .suggestion-btn:active {
            transform: translateY(0);
        }

        .input-focus-effect {
            transition: all 0.3s ease;
        }

        .input-focus-effect:focus {
            transform: translateY(-2px);
        }

        .floating-icon {
            animation: float 3s ease-in-out infinite;
        }

        .pulse-icon {
            animation: pulse 2s ease-in-out infinite;
        }

        /* Custom scrollbar for mobile */
        ::-webkit-scrollbar {
            width: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }
        
        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
        }

        /* Glass morphism effect */
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
        }

        /* Gradient text */
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body class="flex items-center justify-center p-4 sm:p-6 md:p-8">
    <!-- Background decorative elements -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-10 left-10 w-32 h-32 bg-white opacity-10 rounded-full blur-3xl"></div>
        <div class="absolute bottom-10 right-10 w-40 h-40 bg-white opacity-10 rounded-full blur-3xl"></div>
        <div class="absolute top-1/2 left-1/4 w-24 h-24 bg-white opacity-5 rounded-full blur-2xl"></div>
    </div>

    <div class="budget-container glass-effect w-full max-w-2xl rounded-2xl shadow-2xl overflow-hidden relative z-10">
        <!-- Header Section -->
        <div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-6 sm:px-8 md:px-10 py-8 sm:py-10 md:py-12 text-white relative overflow-hidden">
            <!-- Decorative background patterns -->
            <div class="absolute inset-0 opacity-10">
                <div class="absolute top-0 right-0 w-40 h-40 bg-white rounded-full transform translate-x-1/2 -translate-y-1/2"></div>
                <div class="absolute bottom-0 left-0 w-32 h-32 bg-white rounded-full transform -translate-x-1/2 translate-y-1/2"></div>
            </div>

            <div class="relative z-10">
                <div class="flex items-center justify-center mb-4">
                    <div class="w-16 h-16 sm:w-20 sm:h-20 bg-white bg-opacity-20 backdrop-blur-sm rounded-2xl flex items-center justify-center floating-icon">
                        <span class="text-4xl sm:text-5xl">💰</span>
                    </div>
                </div>
                <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-center mb-2">Set Your Budget</h1>
                <p class="text-center text-sm sm:text-base text-white text-opacity-90">Take control of your finances with smart budgeting</p>
            </div>
        </div>

        <!-- Content Section -->
        <div class="px-6 sm:px-8 md:px-10 py-6 sm:py-8 md:py-10">
            <!-- Welcome Message -->
            <div class="bg-gradient-to-r from-indigo-50 to-purple-50 rounded-xl p-4 sm:p-6 mb-6 border border-indigo-100">
                <div class="flex items-start gap-3 sm:gap-4">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center pulse-icon">
                            <span class="text-xl sm:text-2xl">👋</span>
                        </div>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-lg sm:text-xl font-bold text-gray-900 mb-1">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h2>
                        <p class="text-sm sm:text-base text-gray-600">Let's set up your monthly spending limit to start tracking your expenses effectively</p>
                    </div>
                </div>
            </div>
            
            <!-- Error/Success Alerts -->
            <?php if ($error): ?>
            <div class="alert bg-red-50 border-l-4 border-red-500 rounded-lg p-4 mb-6">
                <div class="flex items-start gap-3">
                    <span class="text-2xl flex-shrink-0">⚠️</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-red-800 mb-1">Error</p>
                        <p class="text-sm text-red-700"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert bg-green-50 border-l-4 border-green-500 rounded-lg p-4 mb-6">
                <div class="flex items-start gap-3">
                    <span class="text-2xl flex-shrink-0">✓</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-green-800 mb-1">Success!</p>
                        <p class="text-sm text-green-700"><?php echo htmlspecialchars($success); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Info Box -->
            <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 sm:p-6 mb-6">
                <div class="flex items-start gap-3 mb-3">
                    <span class="text-2xl flex-shrink-0">💡</span>
                    <h3 class="text-base sm:text-lg font-bold text-gray-900">Why Set a Budget?</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="flex items-start gap-3 bg-white rounded-lg p-3 border border-blue-100">
                        <span class="text-xl flex-shrink-0">📊</span>
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Track Spending</p>
                            <p class="text-xs text-gray-600 mt-0.5">Monitor expenses against your limit</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 bg-white rounded-lg p-3 border border-blue-100">
                        <span class="text-xl flex-shrink-0">🔔</span>
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Smart Alerts</p>
                            <p class="text-xs text-gray-600 mt-0.5">Get notified when approaching limit</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 bg-white rounded-lg p-3 border border-blue-100">
                        <span class="text-xl flex-shrink-0">🤖</span>
                        <div>
                            <p class="text-sm font-semibold text-gray-900">AI Insights</p>
                            <p class="text-xs text-gray-600 mt-0.5">Personalized money-saving tips</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 bg-white rounded-lg p-3 border border-blue-100">
                        <span class="text-xl flex-shrink-0">📈</span>
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Visual Reports</p>
                            <p class="text-xs text-gray-600 mt-0.5">See your financial health clearly</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Budget Form -->
            <form method="POST" action="" id="budgetForm" class="space-y-6">
                <div>
                    <label for="budget" class="block text-base sm:text-lg font-bold text-gray-900 mb-3">
                        Monthly Budget Amount
                    </label>
                    
                    <!-- Currency Input -->
                    <div class="relative">
                        <div class="absolute left-4 top-1/2 -translate-y-1/2 flex items-center pointer-events-none">
                            <span class="text-2xl sm:text-3xl font-bold gradient-text">₱</span>
                        </div>
                        <input type="number" 
                               id="budget" 
                               name="budget" 
                               step="0.01" 
                               min="1" 
                               max="9999999.99"
                               required 
                               placeholder="10000.00" 
                               value="<?php echo $current_budget > 0 ? number_format($current_budget, 2, '.', '') : ''; ?>"
                               autocomplete="off"
                               class="input-focus-effect w-full pl-12 sm:pl-14 pr-4 py-4 sm:py-5 text-xl sm:text-2xl md:text-3xl font-bold text-gray-900 bg-gray-50 border-2 border-gray-200 rounded-xl focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-100 transition-all">
                    </div>
                    
                    <p class="text-xs sm:text-sm text-gray-500 mt-2 px-1">Enter your expected monthly spending limit</p>
                    
                    <!-- Budget Suggestions -->
                    <div class="mt-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-700 mb-3">Quick suggestions:</p>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-3">
                            <button type="button" 
                                    class="suggestion-btn px-4 py-3 bg-gradient-to-br from-indigo-50 to-purple-50 hover:from-indigo-100 hover:to-purple-100 border-2 border-indigo-200 rounded-xl text-sm sm:text-base font-semibold text-indigo-700 hover:text-indigo-900 hover:border-indigo-300 hover:shadow-lg" 
                                    onclick="setBudget(5000)">
                                <span class="block text-lg sm:text-xl mb-1">💵</span>
                                ₱5,000
                            </button>
                            <button type="button" 
                                    class="suggestion-btn px-4 py-3 bg-gradient-to-br from-blue-50 to-cyan-50 hover:from-blue-100 hover:to-cyan-100 border-2 border-blue-200 rounded-xl text-sm sm:text-base font-semibold text-blue-700 hover:text-blue-900 hover:border-blue-300 hover:shadow-lg" 
                                    onclick="setBudget(10000)">
                                <span class="block text-lg sm:text-xl mb-1">💰</span>
                                ₱10,000
                            </button>
                            <button type="button" 
                                    class="suggestion-btn px-4 py-3 bg-gradient-to-br from-green-50 to-emerald-50 hover:from-green-100 hover:to-emerald-100 border-2 border-green-200 rounded-xl text-sm sm:text-base font-semibold text-green-700 hover:text-green-900 hover:border-green-300 hover:shadow-lg" 
                                    onclick="setBudget(20000)">
                                <span class="block text-lg sm:text-xl mb-1">💸</span>
                                ₱20,000
                            </button>
                            <button type="button" 
                                    class="suggestion-btn px-4 py-3 bg-gradient-to-br from-orange-50 to-amber-50 hover:from-orange-100 hover:to-amber-100 border-2 border-orange-200 rounded-xl text-sm sm:text-base font-semibold text-orange-700 hover:text-orange-900 hover:border-orange-300 hover:shadow-lg" 
                                    onclick="setBudget(50000)">
                                <span class="block text-lg sm:text-xl mb-1">💎</span>
                                ₱50,000
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="space-y-3 pt-2">
                    <button type="submit" 
                            name="set_budget" 
                            class="w-full px-6 py-4 sm:py-5 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white text-base sm:text-lg font-bold rounded-xl shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all duration-300 flex items-center justify-center gap-3">
                        <span class="text-xl sm:text-2xl"><?php echo $current_budget > 0 ? '✓' : '→'; ?></span>
                        <span><?php echo $current_budget > 0 ? 'Update Budget' : 'Set Budget & Continue'; ?></span>
                    </button>
                    
                    <?php if ($current_budget > 0): ?>
                    <a href="dashboard.php" class="block">
                        <button type="button" 
                                class="w-full px-6 py-4 bg-white hover:bg-gray-50 text-gray-700 text-base sm:text-lg font-semibold border-2 border-gray-200 rounded-xl shadow-sm hover:shadow-md transform hover:-translate-y-1 transition-all duration-300 flex items-center justify-center gap-3">
                            <span class="text-xl">←</span>
                            <span>Go to Dashboard</span>
                        </button>
                    </a>
                    <?php else: ?>
                    <div class="text-center pt-2">
                        <a href="dashboard.php" class="inline-flex items-center gap-2 text-sm sm:text-base text-gray-600 hover:text-gray-900 font-medium transition-colors">
                            <span>Skip for now</span>
                            <span class="text-xs bg-amber-100 text-amber-700 px-2 py-1 rounded-full">Not recommended</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Additional Tips -->
            <div class="mt-8 pt-6 border-t border-gray-200">
                <div class="flex items-start gap-3 text-xs sm:text-sm text-gray-600">
                    <span class="text-lg flex-shrink-0">💡</span>
                    <p>
                        <span class="font-semibold text-gray-900">Pro Tip:</span> 
                        Your budget should cover all monthly expenses including bills, groceries, entertainment, and emergency funds. You can always adjust it later based on your spending patterns.
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function setBudget(amount) {
            const input = document.getElementById('budget');
            input.value = amount.toFixed(2);
            
            // Add a visual feedback
            input.classList.add('ring-4', 'ring-indigo-300');
            setTimeout(() => {
                input.classList.remove('ring-4', 'ring-indigo-300');
            }, 500);
            
            input.focus();
        }
        
        // Format input on blur
        document.getElementById('budget').addEventListener('blur', function() {
            if (this.value) {
                const value = parseFloat(this.value);
                if (!isNaN(value) && value > 0) {
                    this.value = value.toFixed(2);
                }
            }
        });
        
        // Real-time validation feedback
        document.getElementById('budget').addEventListener('input', function() {
            const value = parseFloat(this.value);
            if (value > 0) {
                this.classList.remove('border-red-300', 'focus:border-red-500', 'focus:ring-red-100');
                this.classList.add('border-green-300', 'focus:border-green-500', 'focus:ring-green-100');
            }
        });
        
        // Prevent form submission on enter in suggestion buttons
        document.querySelectorAll('.suggestion-btn').forEach(btn => {
            btn.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.click();
                }
            });
        });
        
        // Auto-focus on budget input
        window.addEventListener('load', function() {
            <?php if (empty($success)): ?>
            const budgetInput = document.getElementById('budget');
            // Delay focus slightly for better UX on mobile
            setTimeout(() => {
                budgetInput.focus();
            }, 300);
            <?php endif; ?>
        });

        // Add number formatting as user types
        document.getElementById('budget').addEventListener('keyup', function(e) {
            // Allow backspace and delete
            if (e.key === 'Backspace' || e.key === 'Delete') return;
            
            const value = parseFloat(this.value);
            if (!isNaN(value) && value > 0) {
                // Show formatted preview (optional)
                console.log('Formatted:', value.toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }));
            }
        });
    </script>
</body>
</html>