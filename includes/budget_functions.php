<?php
function checkBudgetAlerts($user_id, $conn) {
    // Get user settings
    $stmt = $conn->prepare("SELECT monthly_budget, budget_alert_threshold FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    // Get current month spending
    $current_month = date('m');
    $current_year = date('Y');
    $stmt = $conn->prepare("SELECT SUM(amount) as total FROM expenses WHERE user_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?");
    $stmt->bind_param("iii", $user_id, $current_month, $current_year);
    $stmt->execute();
    $total_spent = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    
    $percentage_spent = $user['monthly_budget'] > 0 ? ($total_spent / $user['monthly_budget']) * 100 : 0;
    
    // Check if alert threshold is reached
    if ($percentage_spent >= $user['budget_alert_threshold']) {
        // Check if notification already sent today
        $today = date('Y-m-d');
        $stmt = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND type = 'budget_alert' AND DATE(created_at) = ?");
        $stmt->bind_param("is", $user_id, $today);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows == 0) {
            // Create notification
            $title = "Budget Alert: " . round($percentage_spent, 1) . "% Used";
            $message = "You've spent ₱" . number_format($total_spent, 2) . " of your ₱" . number_format($user['monthly_budget'], 2) . " monthly budget.";
            
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, 'budget_alert', ?, ?)");
            $stmt->bind_param("iss", $user_id, $title, $message);
            $stmt->execute();
        }
    }
}
?>