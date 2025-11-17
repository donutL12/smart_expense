<?php
// includes/functions.php - Helper Functions

require_once __DIR__ . '/../config/config.php';


if (!function_exists('clean_input')) {
    function clean_input($data) {
        global $conn;
        
        if (is_array($data)) {
            return array_map('clean_input', $data);
        }
        
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        
        // Use mysqli_real_escape_string if connection exists
        if (isset($conn) && $conn instanceof mysqli) {
            $data = $conn->real_escape_string($data);
        }
        
        return $data;
    }
}
// ============================================
// MONEY & DATE FORMATTING FUNCTIONS
// ============================================

// Format money with currency symbol
function format_money($amount) {
    return '₱' . number_format($amount, 2);
}

// Format date
function format_date($date) {
    return date('F d, Y', strtotime($date));
}

// Format datetime
function format_datetime($datetime) {
    return date('F d, Y h:i A', strtotime($datetime));
}

// Format percentage
function format_percentage($value, $decimals = 1) {
    return number_format($value, $decimals) . '%';
}

// Get time ago format
function time_ago($datetime) {
    $timestamp = strtotime($datetime);
    $difference = time() - $timestamp;
    
    if ($difference < 60) {
        return 'Just now';
    } elseif ($difference < 3600) {
        $minutes = floor($difference / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($difference < 86400) {
        $hours = floor($difference / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($difference < 604800) {
        $days = floor($difference / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $timestamp);
    }
}

// ============================================
// LOGGING & ACTIVITY FUNCTIONS
// ============================================

// Log system activity with error handling
function log_activity($user_id, $action) {
    global $conn;
    
    try {
        // First verify the user exists
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            // User exists, safe to log
            $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action) VALUES (?, ?)");
            $stmt->bind_param("is", $user_id, $action);
            $stmt->execute();
            $stmt->close();
            return true;
        } else {
            // User doesn't exist, log without user_id
            $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action) VALUES (NULL, ?)");
            $stmt->bind_param("s", $action);
            $stmt->execute();
            $stmt->close();
            return false;
        }
    } catch (Exception $e) {
        // Log to error log if database logging fails
        error_log("Activity logging failed: " . $e->getMessage());
        return false;
    }
}

// Debug function (only use in development)
function debug_log($data, $label = 'DEBUG') {
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("[$label] " . print_r($data, true));
    }
}

// ============================================
// BUDGET & EXPENSE FUNCTIONS
// ============================================

// Get user's total expenses for current month
function get_monthly_expenses($user_id) {
    global $conn;
    $current_month = date('Y-m');
    
    $query = "SELECT COALESCE(SUM(amount), 0) as total 
              FROM expenses 
              WHERE user_id = ? 
              AND DATE_FORMAT(expense_date, '%Y-%m') = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $user_id, $current_month);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['total'] ?? 0;
}

// Get user's budget
function get_user_budget($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT monthly_budget FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['monthly_budget'] ?? 0;
}

// Calculate budget status
function get_budget_status($user_id) {
    $budget = get_user_budget($user_id);
    $spent = get_monthly_expenses($user_id);
    $remaining = $budget - $spent;
    $percentage = $budget > 0 ? ($spent / $budget) * 100 : 0;
    
    return [
        'budget' => $budget,
        'spent' => $spent,
        'remaining' => $remaining,
        'percentage' => $percentage
    ];
}

// Calculate days until end of month
function days_until_month_end() {
    $current_day = date('j');
    $days_in_month = date('t');
    return $days_in_month - $current_day;
}

// Get daily budget remaining
function get_daily_budget($user_id) {
    $budget_status = get_budget_status($user_id);
    $days_remaining = days_until_month_end();
    
    if ($days_remaining <= 0) {
        return 0;
    }
    
    return $budget_status['remaining'] / $days_remaining;
}

// Get expense count for user
function get_expense_count($user_id, $period = 'all') {
    global $conn;
    
    $query = "SELECT COUNT(*) as count FROM expenses WHERE user_id = ?";
    
    if ($period === 'month') {
        $query .= " AND MONTH(expense_date) = MONTH(CURRENT_DATE()) AND YEAR(expense_date) = YEAR(CURRENT_DATE())";
    } elseif ($period === 'week') {
        $query .= " AND WEEK(expense_date) = WEEK(CURRENT_DATE()) AND YEAR(expense_date) = YEAR(CURRENT_DATE())";
    } elseif ($period === 'today') {
        $query .= " AND DATE(expense_date) = CURDATE()";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['count'] ?? 0;
}

// Get top spending category
function get_top_category($user_id) {
    global $conn;
    
    $query = "SELECT c.name, SUM(e.amount) as total 
              FROM expenses e 
              JOIN categories c ON e.category_id = c.id 
              WHERE e.user_id = ? 
              AND MONTH(e.expense_date) = MONTH(CURRENT_DATE()) 
              AND YEAR(e.expense_date) = YEAR(CURRENT_DATE())
              GROUP BY c.id 
              ORDER BY total DESC 
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row;
}

// Check if budget alert should be sent
function should_send_budget_alert($user_id) {
    $budget_status = get_budget_status($user_id);
    $percentage = $budget_status['percentage'];
    
    // Send alert at 80%, 90%, and 100%
    if ($percentage >= 80 && $percentage < 85) return true;
    if ($percentage >= 90 && $percentage < 95) return true;
    if ($percentage >= 100) return true;
    
    return false;
}

// ============================================
// USER MANAGEMENT FUNCTIONS
// ============================================

// Check if user exists
function user_exists($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

// Get user by ID
function get_user_by_id($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    return $user;
}

// Get user by email
function get_user_by_email($email) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    return $user;
}

// ============================================
// BROWSER & DEVICE DETECTION
// ============================================

// Get browser name from user agent
function get_browser_name($user_agent) {
    if (strpos($user_agent, 'Firefox') !== false) return 'Firefox';
    if (strpos($user_agent, 'Chrome') !== false) return 'Chrome';
    if (strpos($user_agent, 'Safari') !== false) return 'Safari';
    if (strpos($user_agent, 'Edge') !== false) return 'Edge';
    if (strpos($user_agent, 'Opera') !== false) return 'Opera';
    return 'Unknown Browser';
}

// Get device type from user agent
function get_device_type($user_agent) {
    if (strpos($user_agent, 'Mobile') !== false) return 'Mobile';
    if (strpos($user_agent, 'Tablet') !== false) return 'Tablet';
    return 'Desktop';
}

// ============================================
// VALIDATION & SANITIZATION FUNCTIONS
// ============================================

// Validate email format
function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Sanitize string input
function sanitize_string($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// Generate random token - only define if not already defined
if (!function_exists('generate_token')) {
    function generate_token($length = 32) {
        return bin2hex(random_bytes($length));
    }
}

// Truncate text
function truncate_text($text, $length = 50, $append = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $append;
}
// ============================================
// EMAIL FUNCTIONS
// ============================================

// Send welcome email to new users
function send_welcome_email($email, $name) {
    try {
        $subject = "Welcome to " . SITE_NAME . "!";
        
        $message = "
        <html>
        <head>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    margin: 0;
                    padding: 0;
                }
                .container { 
                    max-width: 600px; 
                    margin: 0 auto; 
                    padding: 20px; 
                    background: #ffffff;
                }
                .header { 
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                    color: white; 
                    padding: 30px; 
                    text-align: center; 
                    border-radius: 10px 10px 0 0; 
                }
                .header h1 {
                    margin: 0;
                    font-size: 28px;
                }
                .content { 
                    background: #f9f9f9; 
                    padding: 30px; 
                    border-radius: 0 0 10px 10px; 
                }
                .content h2 {
                    color: #667eea;
                    margin-top: 0;
                }
                .content ul {
                    padding-left: 20px;
                }
                .content li {
                    margin-bottom: 10px;
                }
                .button { 
                    display: inline-block; 
                    padding: 12px 30px; 
                    background: #667eea; 
                    color: white !important; 
                    text-decoration: none; 
                    border-radius: 5px; 
                    margin-top: 20px; 
                    font-weight: bold;
                }
                .footer {
                    margin-top: 30px; 
                    padding-top: 20px;
                    border-top: 1px solid #ddd;
                    color: #666; 
                    font-size: 14px;
                    text-align: center;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>💰 Welcome to SpendLens AI!</h1>
                </div>
                <div class='content'>
                    <h2>Hello " . htmlspecialchars($name) . "!</h2>
                    <p>Thank you for registering with SpendLens AI. We're excited to help you take control of your finances!</p>
                    
                    <p><strong>With SpendLens AI, you can:</strong></p>
                    <ul>
                        <li>📊 Track your expenses easily and efficiently</li>
                        <li>💵 Set and manage monthly budgets</li>
                        <li>🤖 Get AI-powered financial insights</li>
                        <li>📈 Visualize your spending patterns</li>
                        <li>🎯 Achieve your financial goals</li>
                    </ul>
                    
                    <p>Get started by logging in and adding your first expense!</p>
                    
                    <div style='text-align: center;'>
                        <a href='" . SITE_URL . "/index.php' class='button'>Login Now</a>
                    </div>
                    
                    <div class='footer'>
                        <p>If you have any questions, feel free to contact our support team.</p>
                        <p>&copy; " . date('Y') . " " . SITE_NAME . ". All rights reserved.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        // Headers for HTML email
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        
        // Try to get domain from SITE_URL, fallback to example.com
        $domain = parse_url(SITE_URL, PHP_URL_HOST);
        if (empty($domain)) {
            $domain = 'example.com';
        }
        
        $headers .= "From: " . SITE_NAME . " <noreply@" . $domain . ">" . "\r\n";
        $headers .= "Reply-To: noreply@" . $domain . "\r\n";
        
        // Send email
        $result = @mail($email, $subject, $message, $headers);
        
        if (!$result) {
            // Log error but don't fail registration
            error_log("Failed to send welcome email to: $email");
        }
        
        return $result;
        
    } catch (Exception $e) {
        // Log error but don't fail registration
        error_log("Welcome email error: " . $e->getMessage());
        return false;
    }
}

// Send password reset email
function send_password_reset_email($email, $name, $reset_token) {
    try {
        $subject = "Password Reset Request - " . SITE_NAME;
        $reset_link = SITE_URL . "/reset_password.php?token=" . $reset_token;
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #667eea; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; padding: 12px 30px; background: #667eea; color: white !important; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Password Reset Request</h1>
                </div>
                <div class='content'>
                    <h2>Hello " . htmlspecialchars($name) . ",</h2>
                    <p>We received a request to reset your password for your " . SITE_NAME . " account.</p>
                    <p>Click the button below to reset your password:</p>
                    <div style='text-align: center;'>
                        <a href='" . $reset_link . "' class='button'>Reset Password</a>
                    </div>
                    <p>Or copy and paste this link into your browser:</p>
                    <p style='word-break: break-all; color: #667eea;'>" . $reset_link . "</p>
                    <div class='warning'>
                        <strong>⚠️ Security Notice:</strong>
                        <p>This link will expire in 1 hour. If you didn't request a password reset, please ignore this email and your password will remain unchanged.</p>
                    </div>
                    <p style='margin-top: 30px; color: #666; font-size: 14px;'>
                        © " . date('Y') . " " . SITE_NAME . ". All rights reserved.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        
        $domain = parse_url(SITE_URL, PHP_URL_HOST);
        if (empty($domain)) {
            $domain = 'example.com';
        }
        
        $headers .= "From: " . SITE_NAME . " <noreply@" . $domain . ">" . "\r\n";
        
        $result = @mail($email, $subject, $message, $headers);
        
        if (!$result) {
            error_log("Failed to send password reset email to: $email");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Password reset email error: " . $e->getMessage());
        return false;
    }
}

// Send budget alert email
function send_budget_alert_email($email, $name, $budget_status) {
    try {
        $percentage = round($budget_status['percentage'], 1);
        $subject = "Budget Alert - " . $percentage . "% of Monthly Budget Used";
        
        $alert_level = 'warning';
        $alert_color = '#ffc107';
        if ($percentage >= 100) {
            $alert_level = 'danger';
            $alert_color = '#dc3545';
        } elseif ($percentage >= 90) {
            $alert_level = 'danger';
            $alert_color = '#dc3545';
        }
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: " . $alert_color . "; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .stats { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .stat-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
                .button { display: inline-block; padding: 12px 30px; background: #667eea; color: white !important; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⚠️ Budget Alert</h1>
                </div>
                <div class='content'>
                    <h2>Hello " . htmlspecialchars($name) . ",</h2>
                    <p>You've used <strong>" . $percentage . "%</strong> of your monthly budget.</p>
                    <div class='stats'>
                        <div class='stat-item'>
                            <span>Monthly Budget:</span>
                            <strong>" . format_money($budget_status['budget']) . "</strong>
                        </div>
                        <div class='stat-item'>
                            <span>Amount Spent:</span>
                            <strong>" . format_money($budget_status['spent']) . "</strong>
                        </div>
                        <div class='stat-item'>
                            <span>Remaining:</span>
                            <strong style='color: " . ($budget_status['remaining'] < 0 ? '#dc3545' : '#28a745') . ";'>" . format_money($budget_status['remaining']) . "</strong>
                        </div>
                    </div>
                    <p>Visit your dashboard to review your expenses and adjust your spending.</p>
                    <div style='text-align: center;'>
                        <a href='" . SITE_URL . "/dashboard.php' class='button'>View Dashboard</a>
                    </div>
                    <p style='margin-top: 30px; color: #666; font-size: 14px;'>
                        © " . date('Y') . " " . SITE_NAME . ". All rights reserved.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        
        $domain = parse_url(SITE_URL, PHP_URL_HOST);
        if (empty($domain)) {
            $domain = 'example.com';
        }
        
        $headers .= "From: " . SITE_NAME . " <noreply@" . $domain . ">" . "\r\n";
        
        $result = @mail($email, $subject, $message, $headers);
        
        if (!$result) {
            error_log("Failed to send budget alert email to: $email");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Budget alert email error: " . $e->getMessage());
        return false;
    }
}

// Send login notification email
function send_login_notification($email, $name) {
    try {
        $subject = "New Login to Your Account - " . SITE_NAME;
        
        // Get login information
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $browser = get_browser_name($user_agent);
        $device = get_device_type($user_agent);
        $login_time = date('F d, Y h:i A');
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #667eea; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .info-box { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #667eea; }
                .info-item { padding: 8px 0; display: flex; }
                .info-label { font-weight: bold; min-width: 120px; color: #666; }
                .info-value { color: #333; }
                .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .button { display: inline-block; padding: 12px 30px; background: #dc3545; color: white !important; text-decoration: none; border-radius: 5px; margin: 10px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🔐 New Login Detected</h1>
                </div>
                <div class='content'>
                    <h2>Hello " . htmlspecialchars($name) . ",</h2>
                    <p>We detected a new login to your " . SITE_NAME . " account. If this was you, no action is needed.</p>
                    
                    <div class='info-box'>
                        <h3 style='margin-top: 0; color: #667eea;'>Login Details:</h3>
                        <div class='info-item'>
                            <span class='info-label'>Date & Time:</span>
                            <span class='info-value'>" . $login_time . "</span>
                        </div>
                        <div class='info-item'>
                            <span class='info-label'>IP Address:</span>
                            <span class='info-value'>" . htmlspecialchars($ip_address) . "</span>
                        </div>
                        <div class='info-item'>
                            <span class='info-label'>Device:</span>
                            <span class='info-value'>" . htmlspecialchars($device) . "</span>
                        </div>
                        <div class='info-item'>
                            <span class='info-label'>Browser:</span>
                            <span class='info-value'>" . htmlspecialchars($browser) . "</span>
                        </div>
                    </div>
                    
                    <div class='warning'>
                        <strong>⚠️ Wasn't you?</strong>
                        <p style='margin: 10px 0 0 0;'>If you didn't log in, please secure your account immediately by changing your password.</p>
                        <div style='text-align: center;'>
                            <a href='" . SITE_URL . "/change_password.php' class='button'>Change Password</a>
                        </div>
                    </div>
                    
                    <p style='margin-top: 30px; color: #666; font-size: 14px; text-align: center;'>
                        © " . date('Y') . " " . SITE_NAME . ". All rights reserved.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        
        $domain = parse_url(SITE_URL, PHP_URL_HOST);
        if (empty($domain)) {
            $domain = 'example.com';
        }
        
        $headers .= "From: " . SITE_NAME . " <noreply@" . $domain . ">" . "\r\n";
        
        $result = @mail($email, $subject, $message, $headers);
        
        if (!$result) {
            error_log("Failed to send login notification email to: $email");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Login notification email error: " . $e->getMessage());
        return false;
    }
}
?>

<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_email($to, $to_name, $subject, $html_body, $alt_body = '') {
    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';
    
    $mail = new PHPMailer(true);
    
    try {
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
        $mail->addAddress($to, $to_name);
        $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = $alt_body ?: strip_tags($html_body);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>
