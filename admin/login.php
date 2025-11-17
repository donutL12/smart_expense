<?php
// admin/login.php - Admin Login Page (ADMINS ONLY)

session_start();

// If already logged in as admin, redirect to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: dashboard.php");
    exit();
}

require_once '../config/config.php';
require_once '../includes/db_connect.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    
    if (!empty($email) && !empty($password)) {
        // Query ONLY for admin role
        $stmt = $conn->prepare("SELECT id, name, email, password FROM users WHERE email = ? AND role = 'admin'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $admin = $result->fetch_assoc();
            
            // Verify password (using MD5 as per your schema)
            if (md5($password) === $admin['password']) {
                // Set ADMIN-SPECIFIC session variables
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_last_activity'] = time();
                $_SESSION['admin_created'] = time();
                
                // Log admin login
                $log_stmt = $conn->prepare("INSERT INTO system_logs (user_id, action) VALUES (?, 'Admin login')");
                $log_stmt->bind_param("i", $admin['id']);
                $log_stmt->execute();
                
                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Invalid email or password.";
            }
        } else {
            $error = "Invalid email or password.";
        }
        $stmt->close();
    } else {
        $error = "Please fill in all fields.";
    }
}

// Get error or success messages from URL
if (isset($_GET['error'])) {
    if ($_GET['error'] == 'not_logged_in') {
        $error = "Please log in to access the admin panel";
    } else if ($_GET['error'] == 'session_expired') {
        $error = "Your session has expired. Please log in again.";
    } else {
        $error = htmlspecialchars($_GET['error']);
    }
}
if (isset($_GET['logout']) && $_GET['logout'] == 'success') {
    $success = "You have been successfully logged out.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .admin-login-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
        }
        
        .admin-login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .admin-login-header h1 {
            color: #667eea;
            margin-bottom: 5px;
            font-size: 28px;
        }
        
        .admin-login-header p {
            color: #666;
            font-size: 14px;
        }
        
        .admin-badge {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-error {
            background: #fee;
            color: #c33;
            border-left: 4px solid #c33;
        }
        
        .alert-success {
            background: #efe;
            color: #3c3;
            border-left: 4px solid #3c3;
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
        }
        
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        
        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="admin-login-container">
        <div class="admin-login-header">
            <span class="admin-badge">ADMIN PANEL</span>
            <h1>💰 FinSight</h1>
            <p>Administrator Access Only</p>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="email">Admin Email</label>
                <input type="email" id="email" name="email" required placeholder="admin@spendlens.com">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="Enter your password">
            </div>
            
            <button type="submit" class="btn-login">Login to Admin Panel</button>
        </form>
        
        <div class="back-link">
            <a href="../index.php">← Back to User Login</a>
        </div>
    </div>
</body>
</html>