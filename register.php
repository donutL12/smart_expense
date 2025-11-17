<?php
// register.php - Enhanced User Registration Page with Email Support

session_start();

if (!defined('SPENDLENS_APP')) {
    define('SPENDLENS_APP', true);
}

require_once 'config/config.php';
require_once 'includes/db_connect.php';
require_once 'includes/functions.php';

// Import PHPMailer classes at the top of the file
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

// Enhanced email function with PHPMailer (if available) or fallback to PHP mail()
function send_welcome_email_enhanced($email, $name) {
    // Check if PHPMailer is available
    $phpmailer_available = file_exists(__DIR__ . '/includes/PHPMailer/PHPMailer.php');
    
    if ($phpmailer_available && SMTP_ENABLED) {
        // Use PHPMailer
        try {
            require_once __DIR__ . '/includes/PHPMailer/Exception.php';
            require_once __DIR__ . '/includes/PHPMailer/PHPMailer.php';
            require_once __DIR__ . '/includes/PHPMailer/SMTP.php';
            
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            
            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($email, $name);
            $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Welcome to ' . SITE_NAME . '!';
            $mail->Body = get_welcome_email_html($name);
            $mail->AltBody = get_welcome_email_text($name);
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("PHPMailer Error: {$mail->ErrorInfo}");
            return false;
        }
    } else {
        // Fallback to PHP mail() function
        return send_welcome_email($email, $name);
    }
}

// Get HTML email template
function get_welcome_email_html($name) {
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #ffffff; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .header h1 { margin: 0; font-size: 32px; }
            .content { background: #f9f9f9; padding: 40px 30px; border-radius: 0 0 10px 10px; }
            .content h2 { color: #667eea; margin-top: 0; }
            .feature-box { background: white; padding: 20px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #667eea; }
            .feature-box h3 { color: #667eea; margin-top: 0; font-size: 18px; }
            .button { display: inline-block; padding: 15px 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white !important; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: bold; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 14px; text-align: center; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>💰 Welcome to SpendLens AI!</h1>
                <p style='margin: 10px 0 0 0; font-size: 16px;'>Your Smart Expense Tracking Journey Begins</p>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($name) . "!</h2>
                <p>Thank you for joining SpendLens AI. We're thrilled to help you take control of your financial future!</p>
                
                <div class='feature-box'>
                    <h3>🎯 What You Can Do:</h3>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li><strong>Track Expenses:</strong> Log your spending with ease</li>
                        <li><strong>Set Budgets:</strong> Create monthly spending limits</li>
                        <li><strong>AI Insights:</strong> Get intelligent financial recommendations</li>
                        <li><strong>Visualize Data:</strong> See beautiful charts and reports</li>
                        <li><strong>Achieve Goals:</strong> Stay on track with your finances</li>
                    </ul>
                </div>
                
                <div class='feature-box'>
                    <h3>🚀 Getting Started:</h3>
                    <ol style='margin: 10px 0; padding-left: 20px;'>
                        <li>Log in to your account</li>
                        <li>Set your monthly budget</li>
                        <li>Add your first expense</li>
                        <li>Explore AI-powered insights</li>
                    </ol>
                </div>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . SITE_URL . "/index.php' class='button'>Get Started Now</a>
                </div>
                
                <div class='footer'>
                    <p><strong>Need Help?</strong><br>Contact our support team</p>
                    <p style='margin-top: 20px;'>&copy; " . date('Y') . " " . SITE_NAME . ". All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
}

// Get plain text email
function get_welcome_email_text($name) {
    return "Welcome to " . SITE_NAME . "!\n\n" .
           "Hello " . $name . ",\n\n" .
           "Thank you for joining SpendLens AI. We're excited to help you track your expenses and achieve your financial goals!\n\n" .
           "Get started by logging in at: " . SITE_URL . "/index.php\n\n" .
           "Best regards,\n" .
           SITE_NAME . " Team";
}

// Handle registration form submission
// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    // Get and sanitize input
    $name = clean_input($_POST['name']);
    $email = clean_input($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = "Please fill in all fields";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = "Email already registered. Please use a different email or login.";
            $stmt->close();
        } else {
            $stmt->close();
            
            // Hash password securely
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert new user
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'user')");
            $stmt->bind_param("sss", $name, $email, $hashed_password);
            
            if ($stmt->execute()) {
                $user_id = $conn->insert_id;
                
                // Try to send welcome email (don't fail registration if email fails)
                try {
                    send_welcome_email_enhanced($email, $name);
                } catch (Exception $e) {
                    error_log("Welcome email failed: " . $e->getMessage());
                }
                
                // Log activity
                log_activity($user_id, "New user registered: " . $name);
                
                $success = "Registration successful! Please login with your credentials.";
                
                // Redirect to login after 2 seconds
                header("refresh:2;url=index.php?registered=1");
            } else {
                $error = "Registration failed: " . $conn->error;
                error_log("Registration error: " . $conn->error);
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo SITE_NAME; ?></title>
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
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .slide-in {
            animation: slideIn 0.3s ease-out;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-primary via-purple-500 to-secondary gradient-animation">
    <!-- Decorative elements -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-20 left-10 w-72 h-72 bg-white opacity-10 rounded-full blur-3xl float-animation"></div>
        <div class="absolute bottom-20 right-10 w-96 h-96 bg-purple-300 opacity-10 rounded-full blur-3xl float-animation" style="animation-delay: 2s;"></div>
        <div class="absolute top-1/2 left-1/2 w-64 h-64 bg-pink-300 opacity-10 rounded-full blur-3xl float-animation" style="animation-delay: 4s;"></div>
    </div>

    <div class="relative min-h-screen flex items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
        <div class="w-full max-w-md">

            <!-- Registration Card -->
            <div class="bg-white rounded-2xl shadow-2xl overflow-hidden backdrop-blur-sm slide-in">
                <!-- Header Section -->
                <div class="bg-gradient-to-r from-primary to-secondary px-8 py-10 text-center">
                    <div class="inline-flex items-center justify-center w-20 h-20 bg-white rounded-full mb-4 shadow-lg">
                        <span class="text-5xl">💰</span>
                    </div>
                    <h1 class="text-3xl font-bold text-white mb-2">Join FinSight</h1>
                    <p class="text-purple-100 text-sm">Start your journey to financial freedom</p>
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
                    
                    <form method="POST" action="" class="space-y-5" id="registerForm">
                        <!-- Full Name -->
                        <div>
                            <label for="name" class="block text-sm font-semibold text-gray-700 mb-2">
                                Full Name
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                </div>
                                <input type="text" id="name" name="name" required
                                    class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition duration-200 placeholder-gray-400"
                                    placeholder=""
                                    value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                            </div>
                        </div>
                        
                        <!-- Email -->
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
                        
                        <!-- Password -->
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
                                    class="block w-full pl-10 pr-10 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition duration-200 placeholder-gray-400"
                                    placeholder="Create a strong password">
                                <button type="button" onclick="togglePassword('password')" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                    <svg id="password-eye" class="h-5 w-5 text-gray-400 hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">Must be at least 6 characters long</p>
                        </div>
                        
                        <!-- Confirm Password -->
                        <div>
                            <label for="confirm_password" class="block text-sm font-semibold text-gray-700 mb-2">
                                Confirm Password
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                                <input type="password" id="confirm_password" name="confirm_password" required
                                    class="block w-full pl-10 pr-10 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition duration-200 placeholder-gray-400"
                                    placeholder="Re-enter your password">
                                <button type="button" onclick="togglePassword('confirm_password')" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                    <svg id="confirm_password-eye" class="h-5 w-5 text-gray-400 hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Terms and Conditions -->
                        <div class="flex items-start">
                            <div class="flex items-center h-5">
                                <input id="terms" name="terms" type="checkbox" required
                                    class="w-4 h-4 border-gray-300 rounded text-primary focus:ring-primary">
                            </div>
                            <div class="ml-3 text-sm">
                                <label for="terms" class="text-gray-600">
                                    I agree to the <a href="#" class="text-primary hover:text-secondary font-semibold">Terms and Conditions</a> and <a href="#" class="text-primary hover:text-secondary font-semibold">Privacy Policy</a>
                                </label>
                            </div>
                        </div>
                        
                        <button type="submit" name="register" 
                            class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-gradient-to-r from-primary to-secondary hover:from-purple-600 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transform transition duration-200 hover:scale-105 active:scale-95">
                            Create Account
                        </button>
                    </form>
                    
                    <div class="mt-6">
                        <div class="relative">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-gray-300"></div>
                            </div>
                            <div class="relative flex justify-center text-sm">
                                <span class="px-2 bg-white text-gray-500 font-medium">Already registered?</span>
                            </div>
                        </div>
                        
                        <div class="mt-6 text-center">
                            <a href="index.php" class="font-semibold text-primary hover:text-secondary transition duration-200">
                                Sign in to your account →
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Text -->
            <p class="mt-8 text-center text-sm text-white opacity-90">
                🔒 Your data is secure and encrypted
            </p>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const eye = document.getElementById(fieldId + '-eye');
            
            if (field.type === 'password') {
                field.type = 'text';
                eye.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>';
            } else {
                field.type = 'password';
                eye.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
            }
        }

        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const terms = document.getElementById('terms').checked;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (!terms) {
                e.preventDefault();
                alert('Please accept the Terms and Conditions');
                return false;
            }
        });
    </script>
</body>
</html>