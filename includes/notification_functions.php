<?php
/**
 * Create a notification for a user
 */
function createNotification($conn, $user_id, $type, $title, $message) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $user_id, $type, $title, $message);
        $result = $stmt->execute();
        $stmt->close(); // ✅ Close the statement
        return $result;
    } catch (Exception $e) {
        error_log("Notification creation failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Check budget and create alert if needed
 */
function checkBudgetAlert($conn, $user_id) {
    // Get user's budget and spending
    $stmt = $conn->prepare("
        SELECT 
            u.monthly_budget,
            COALESCE(SUM(e.amount), 0) as total_spent
        FROM users u
        LEFT JOIN expenses e ON u.id = e.user_id 
            AND YEAR(e.expense_date) = YEAR(CURRENT_DATE())
            AND MONTH(e.expense_date) = MONTH(CURRENT_DATE())
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close(); // ✅ Close after getting the result
    
    if (!$result || !$result['monthly_budget'] || $result['monthly_budget'] <= 0) {
        return;
    }
    
    $budget = $result['monthly_budget'];
    $spent = $result['total_spent'];
    $percentage = ($spent / $budget) * 100;
    
    // Determine which notification to send
    $notification_sent = false;
    
    if ($percentage >= 100) {
        // Check if 100% alert already sent this month
        $check_stmt = $conn->prepare("
            SELECT id FROM notifications 
            WHERE user_id = ? 
            AND type = 'budget_alert' 
            AND title LIKE ?
            AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
            LIMIT 1
        ");
        $search_title = "%Budget Exceeded%";
        $check_stmt->bind_param("is", $user_id, $search_title);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();
        
        if (!$exists) {
            createNotification(
                $conn,
                $user_id,
                'budget_alert',
                '🚨 Budget Exceeded!',
                "You've exceeded your monthly budget of ₱" . number_format($budget, 2) . ". Current spending: ₱" . number_format($spent, 2) . " (" . round($percentage) . "%)"
            );
            $notification_sent = true;
        }
    } elseif ($percentage >= 90) {
        // Check if 90% alert already sent this month
        $check_stmt = $conn->prepare("
            SELECT id FROM notifications 
            WHERE user_id = ? 
            AND type = 'budget_alert' 
            AND (title LIKE ? OR title LIKE ?)
            AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
            LIMIT 1
        ");
        $search_90 = "%90%";
        $search_100 = "%Exceeded%";
        $check_stmt->bind_param("iss", $user_id, $search_90, $search_100);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();
        
        if (!$exists) {
            createNotification(
                $conn,
                $user_id,
                'budget_alert',
                '⚠️ Budget Alert: 90% Used',
                "Warning! You've used 90% of your monthly budget. Only ₱" . number_format($budget - $spent, 2) . " remaining."
            );
            $notification_sent = true;
        }
    } elseif ($percentage >= 80) {
        // Check if 80% alert already sent this month
        $check_stmt = $conn->prepare("
            SELECT id FROM notifications 
            WHERE user_id = ? 
            AND type = 'budget_alert' 
            AND (title LIKE ? OR title LIKE ? OR title LIKE ?)
            AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
            LIMIT 1
        ");
        $search_80 = "%80%";
        $search_90 = "%90%";
        $search_100 = "%Exceeded%";
        $check_stmt->bind_param("isss", $user_id, $search_80, $search_90, $search_100);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();
        
        if (!$exists) {
            createNotification(
                $conn,
                $user_id,
                'budget_alert',
                '⚠️ Budget Alert: 80% Used',
                "Heads up! You've used 80% of your monthly budget. ₱" . number_format($budget - $spent, 2) . " remaining."
            );
            $notification_sent = true;
        }
    }
    
    return $notification_sent;
}

/**
 * Get unread notification count
 */
function getUnreadCount($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close(); // ✅ Close the statement
    return $count;
}

/**
 * Mark notification as read
 */
function markNotificationRead($conn, $notification_id, $user_id) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Mark all notifications as read
 */
function markAllNotificationsRead($conn, $user_id) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Delete notification
 */
function deleteNotification($conn, $notification_id, $user_id) {
    $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Get user notifications with pagination
 */
function getUserNotifications($conn, $user_id, $filter = 'all', $limit = 50, $offset = 0) {
    $query = "SELECT * FROM notifications WHERE user_id = ?";
    $params = [$user_id];
    $types = "i";
    
    if ($filter === 'unread') {
        $query .= " AND is_read = 0";
    } elseif ($filter === 'budget') {
        $query .= " AND type = 'budget_alert'";
    } elseif ($filter === 'expense') {
        $query .= " AND type = 'expense_alert'";
    } elseif ($filter === 'system') {
        $query .= " AND type = 'system'";
    } elseif ($filter === 'success') {
        $query .= " AND type = 'success'";
    }
    
    $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $result;
}
?>