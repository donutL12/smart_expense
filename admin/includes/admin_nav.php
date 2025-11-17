<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<nav class="admin-nav">
    <div class="admin-nav-brand">
        <h2>🎯 SpendLens AI Admin</h2>
    </div>
    
    <ul class="admin-nav-links">
        <li><a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">📊 Dashboard</a></li>
        <li><a href="manage_users.php" class="<?php echo $current_page === 'manage_users.php' ? 'active' : ''; ?>">👥 Users</a></li>
        <li><a href="manage_categories.php" class="<?php echo $current_page === 'manage_categories.php' ? 'active' : ''; ?>">🏷️ Categories</a></li>
        <li><a href="reports.php" class="<?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">📈 Reports</a></li>
    </ul>
    
    <div class="admin-nav-user">
        <span>👤 <?php echo htmlspecialchars($admin_name); ?></span>
        <a href="../logout.php" class="btn btn-sm btn-danger">Logout</a>
    </div>
</nav>