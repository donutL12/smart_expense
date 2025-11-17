<?php
require_once 'includes/db_connect.php';
require_once 'includes/auth_user.php';

$page_title = "Chatbot Assistant";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Finsight</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/chatbot.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <div class="chatbot-container">
            <div class="chatbot-header">
                <img src="assets/img/icons/chatbot.png" alt="Chatbot">
                <div>
                    <h2>💬 Finsight Assistant</h2>
                    <p class="status">Online • Ready to help</p>
                </div>
            </div>
            
            <div class="chatbot-messages" id="chatMessages">
                <div class="message bot-message">
                    <div class="message-content">
                        <p>👋 Hi <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'there'); ?>!</p>
                        <p>I'm your financial assistant. Ask me about your budget, expenses, or type <strong>'help'</strong> to see what I can do!</p>
                    </div>
                    <span class="message-time"><?php echo date('g:i A'); ?></span>
                </div>
            </div>
            
            <div class="chatbot-input">
                <input type="text" 
                       id="chatInput" 
                       placeholder="Type your message..."
                       autocomplete="off">
                <button id="sendBtn">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                        <path d="M22 2L11 13M22 2L15 22L11 13M22 2L2 8L11 13" 
                              stroke="currentColor" 
                              stroke-width="2" 
                              stroke-linecap="round" 
                              stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
            
            <div class="quick-actions">
                <button class="quick-btn" data-message="show my budget">💰 Show Budget</button>
                <button class="quick-btn" data-message="total spending">📊 Total Spending</button>
                <button class="quick-btn" data-message="recent expenses">📝 Recent</button>
                <button class="quick-btn" data-message="help">❓ Help</button>
            </div>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    <script src="assets/js/chatbot.js"></script>
</body>
</html>