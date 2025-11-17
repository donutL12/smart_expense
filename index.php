<?php
// index.php - User Login Page (USERS ONLY)

session_start();
require_once 'config/config.php';
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';

// Redirect if already logged in as USER
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = clean_input($_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields";
    } else {
        // Check user credentials - ONLY for role='user'
        $stmt = $conn->prepare("SELECT id, name, email, password, monthly_budget FROM users WHERE email = ? AND role = 'user'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Check if password is MD5 (old format - 32 chars) or bcrypt (new format - 60 chars)
            $password_valid = false;
            
            if (strlen($user['password']) === 32) {
                // Old MD5 password - verify with MD5
                if (md5($password) === $user['password']) {
                    $password_valid = true;
                    
                    // Automatically upgrade to bcrypt for better security
                    $new_hash = password_hash($password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $update_stmt->bind_param("si", $new_hash, $user['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            } else {
                // New bcrypt password - use password_verify
                $password_valid = password_verify($password, $user['password']);
            }
            
            if ($password_valid) {
                // Set USER-SPECIFIC session variables
                $_SESSION['user_logged_in'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['last_activity'] = time();
                $_SESSION['created'] = time();
                
                // Try to send login notification email (don't fail login if email fails)
                try {
                    send_login_notification($user['email'], $user['name']);
                } catch (Exception $e) {
                    error_log("Login notification failed: " . $e->getMessage());
                }
                
                // Log activity
                log_activity($user['id'], "User logged in");
                
                // Check if user has set budget
                if (is_null($user['monthly_budget']) || $user['monthly_budget'] == 0) {
                    header("Location: set_budget.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit();
            } else {
                $error = "Invalid email or password";
            }
        } else {
            $error = "Invalid email or password";
        }
        $stmt->close();
    }
}

// Get error or success messages from URL
if (isset($_GET['error'])) {
    if ($_GET['error'] == 'not_logged_in') {
        $error = "Please log in to access that page";
    } else if ($_GET['error'] == 'session_expired') {
        $error = "Your session has expired. Please log in again.";
    } else {
        $error = htmlspecialchars($_GET['error']);
    }
}
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}
if (isset($_GET['logout']) && $_GET['logout'] == 'success') {
    $success = "You have been successfully logged out.";
}
if (isset($_GET['registered']) && $_GET['registered'] == '1') {
    $success = "Registration successful! Please login with your credentials.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#667eea',
                        secondary: '#764ba2',
                    }
                }
            }
        }
    </script>
    <style>
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        .float-animation {
            animation: float 6s ease-in-out infinite;
        }
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .gradient-animation {
            background-size: 200% 200%;
            animation: gradient 15s ease infinite;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-primary via-purple-500 to-secondary gradient-animation">
    <!-- Decorative elements -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-20 left-10 w-72 h-72 bg-white opacity-10 rounded-full blur-3xl float-animation"></div>
        <div class="absolute bottom-20 right-10 w-96 h-96 bg-purple-300 opacity-10 rounded-full blur-3xl float-animation" style="animation-delay: 2s;"></div>
    </div>

    <div class="relative min-h-screen flex items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
        <div class="w-full max-w-md">
            <!-- Login Card -->
            <div class="bg-white rounded-2xl shadow-2xl overflow-hidden backdrop-blur-sm">
                <!-- Header Section -->
                <div class="bg-gradient-to-r from-primary to-secondary px-8 py-10 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-white rounded-full mb-4 shadow-lg">
                        <span class="text-4xl">💰</span>
                    </div>
                    <h1 class="text-3xl font-bold text-white mb-2">FinSight</h1>
                    <p class="text-purple-100 text-sm">Smart Expense Tracking with AI Insights</p>
                </div>

                <!-- Form Section -->
                <div class="px-8 py-8">
                    <?php if ($error): ?>
                        <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg animate-pulse">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-red-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                </svg>
                                <p class="text-sm text-red-700 font-medium"><?php echo $error; ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="mb-6 bg-green-50 border-l-4 border-green-500 p-4 rounded-r-lg">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-green-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <p class="text-sm text-green-700 font-medium"><?php echo $success; ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" class="space-y-6">
                        <div>
                            <label for="email" class="block text-sm font-semibold text-gray-700 mb-2">
                                Email Address
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/>
                                    </svg>
                                </div>
                                <input type="email" id="email" name="email" required
                                    class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition duration-200 placeholder-gray-400"
                                    placeholder="you@example.com"
                                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div>
                            <label for="password" class="block text-sm font-semibold text-gray-700 mb-2">
                                Password
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                </div>
                                <input type="password" id="password" name="password" required
                                    class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition duration-200 placeholder-gray-400"
                                    placeholder="Enter your password">
                            </div>
                        </div>
                        
                        <button type="submit" name="login" 
                            class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-gradient-to-r from-primary to-secondary hover:from-purple-600 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transform transition duration-200 hover:scale-105 active:scale-95">
                            Sign In
                        </button>
                    </form>
                    
                    <div class="mt-6">
                        <div class="relative">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-gray-300"></div>
                            </div>
                            <div class="relative flex justify-center text-sm">
                                <span class="px-2 bg-white text-gray-500 font-medium">OR</span>
                            </div>
                        </div>
                        
                        <div class="mt-6 text-center">
                            <p class="text-sm text-gray-600">
                                Don't have an account? 
                                <a href="register.php" class="font-semibold text-primary hover:text-secondary transition duration-200">
                                    Register here
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</body>
</html>