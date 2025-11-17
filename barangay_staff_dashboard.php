<?php 
include '../controls/connection.php';

// --- Handle Confirm Report ---
if (isset($_GET['confirm'])) {
    $report_id = intval($_GET['confirm']);
    $user_id = intval($_GET['user_id']);
    
    // 1. Fetch user data
    $user_res = $conn->query("SELECT * FROM users WHERE user_id=$user_id");
    if ($user_row = $user_res->fetch_assoc()) {
        // 2. Insert into archive
        $stmt = $conn->prepare("INSERT INTO archive (firstname, middlename, lastname, fb_link, location, date_created, email, password, contact, role, rating, credit_score, is_verified, profile_picture) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "sssssssssiiiis",
            $user_row['firstname'],
            $user_row['middlename'],
            $user_row['lastname'],
            $user_row['fb_link'],
            $user_row['location'],
            $user_row['date_created'],
            $user_row['email'],
            $user_row['password'],
            $user_row['contact'],
            $user_row['role'],
            $user_row['rating'],
            $user_row['credit_score'],
            $user_row['is_verified'],
            $user_row['profile_picture']
        );
        $stmt->execute();
        
        // 3. Optionally delete the user from users table
        $conn->query("DELETE FROM users WHERE user_id=$user_id");

        // 4. Delete the report
        $conn->query("DELETE FROM reports WHERE report_id=$report_id");

        header("Location: barangay_staff_dashboard.php?success=User archived successfully");
        exit();
    }
}

// --- Handle Reject Report ---
if (isset($_GET['reject'])) {
    $report_id = intval($_GET['reject']);
    $conn->query("DELETE FROM reports WHERE report_id=$report_id");
    header("Location: barangay_staff_dashboard.php?success=Report rejected successfully");
    exit();
}


// Fetch pending verification requests
$verification_query = "SELECT v.request_id, v.user_id, v.id_proof, v.supporting_doc, v.status, u.firstname, u.lastname 
                       FROM verification_requests v 
                       JOIN users u ON v.user_id = u.user_id
                       WHERE v.status = 'pending'";
$verification_result = $conn->query($verification_query);

// Fetch pending reports
$report_query = "SELECT r.report_id, r.user_id, r.reason, r.additional_details, r.attachment, r.status, 
                        u.firstname, u.lastname 
                 FROM reports r 
                 JOIN users u ON r.user_id = u.user_id 
                 WHERE r.status = 'pending'";
$report_result = $conn->query($report_query);

// Fetch accepted hires
$hires_query = "SELECT h.*, 
                       e.firstname AS employer_firstname, e.middlename AS employer_middlename, e.lastname AS employer_lastname,
                       l.firstname AS laborer_firstname, l.middlename AS laborer_middlename, l.lastname AS laborer_lastname
                FROM hires h
                JOIN users e ON h.employer_id = e.user_id
                JOIN users l ON h.laborer_id = l.user_id
                WHERE h.status='accepted'
                ORDER BY h.created_at DESC";
$hires_result = $conn->query($hires_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Barangay Staff Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body {
        font-family: 'Inter', sans-serif;
    }
    .nav-button.active {
        background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%);
        box-shadow: 0 4px 12px rgba(13, 148, 136, 0.3);
    }
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .gradient-bg {
        background: linear-gradient(135deg, #0f766e 0%, #0d9488 50%, #14b8a6 100%);
    }
    @media (max-width: 768px) {
        .sidebar {
            position: fixed;
            left: -100%;
            top: 0;
            height: 100vh;
            z-index: 50;
            transition: left 0.3s ease;
        }
        .sidebar.active {
            left: 0;
        }
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 40;
        }
        .overlay.active {
            display: block;
        }
    }
</style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">

<!-- Mobile Menu Overlay -->
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<!-- Mobile Menu Button -->
<button onclick="toggleSidebar()" class="md:hidden fixed top-4 left-4 z-50 bg-teal-600 text-white p-3 rounded-lg shadow-lg">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
    </svg>
</button>

<div class="flex h-screen">
    <!-- Enhanced Sidebar -->
    <aside class="sidebar w-72 md:w-72 gradient-bg text-white shadow-2xl flex flex-col">
        <div class="p-4 md:p-6 border-b border-teal-600">
            <div class="flex items-center space-x-3 mb-2">
                <div class="w-10 h-10 md:w-12 md:h-12 bg-white rounded-xl flex items-center justify-center shadow-lg">
                    <svg class="w-6 h-6 md:w-7 md:h-7 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                    </svg>
                </div>
                <div>
                    <h1 class="text-xl md:text-2xl font-bold">Barangay Panel</h1>
                    <p class="text-teal-200 text-xs md:text-sm">Staff Dashboard</p>
                </div>
            </div>
        </div>
        
        <nav class="flex-1 p-4 space-y-2 overflow-y-auto">
            <button onclick="showSection('verification')" class="nav-button w-full text-left p-3 rounded-xl transition-all duration-200 flex items-center space-x-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="font-medium">Verification</span>
            </button>
            
            <button onclick="showSection('reports')" class="nav-button w-full text-left p-3 rounded-xl transition-all duration-200 flex items-center space-x-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <span class="font-medium">Reports</span>
            </button>
            
            <button onclick="showSection('hires')" class="nav-button w-full text-left p-3 rounded-xl transition-all duration-200 flex items-center space-x-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                <span class="font-medium">Accepted Hires</span>
            </button>
            
            <button onclick="showSection('announcements')" class="nav-button w-full text-left p-3 rounded-xl transition-all duration-200 flex items-center space-x-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                </svg>
                <span class="font-medium">Announcements</span>
            </button>
        </nav>
        
        <div class="p-4 border-t border-teal-600">
            <a href="../controls/logout.php" class="w-full flex items-center justify-center space-x-2 p-3 rounded-xl bg-red-500 transition-all duration-200 shadow-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                <span class="font-semibold">Logout</span>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 p-4 md:p-8 overflow-auto">
        <!-- Verification Section -->
        <div id="verification" class="section hidden">
            <div class="mb-4 md:mb-6">
                <h2 class="text-2xl md:text-3xl font-bold text-gray-800 mb-2">Verification Applications</h2>
                <p class="text-sm md:text-base text-gray-600">Review and process user verification requests</p>
            </div>
            
            <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gradient-to-r from-teal-50 to-cyan-50">
                            <tr>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">User ID</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Name</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">ID Proof</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Supporting Doc</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php while ($row = $verification_result->fetch_assoc()): ?>
                            <tr>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <span class="text-xs md:text-sm font-medium text-gray-900">#<?php echo $row['user_id']; ?></span>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <div class="text-xs md:text-sm font-medium text-gray-900"><?php echo htmlspecialchars($row['firstname'] . " " . $row['lastname']); ?></div>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <a href="uploads/<?php echo $row['id_proof']; ?>" target="_blank" class="inline-flex items-center px-2 md:px-3 py-1 rounded-lg text-xs md:text-sm font-medium text-blue-600 bg-blue-50">
                                        <svg class="w-3 h-3 md:w-4 md:h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                        View ID
                                    </a>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <a href="uploads/<?php echo $row['supporting_doc']; ?>" target="_blank" class="inline-flex items-center px-2 md:px-3 py-1 rounded-lg text-xs md:text-sm font-medium text-purple-600 bg-purple-50">
                                        <svg class="w-3 h-3 md:w-4 md:h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        View Doc
                                    </a>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <span class="badge bg-yellow-100 text-yellow-800"><?php echo ucfirst($row['status']); ?></span>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap text-xs md:text-sm">
                                    <div class="flex flex-col md:flex-row md:items-center space-y-1 md:space-y-0 md:space-x-2">
                                        <a href="view_user.php?user_id=<?php echo $row['user_id']; ?>" class="text-gray-600 font-medium">View</a>
                                        <span class="hidden md:inline text-gray-300">|</span>
                                        <a href="../controls/admin/approve_verification.php?request_id=<?php echo $row['request_id']; ?>" class="text-green-600 font-medium">Approve</a>
                                        <span class="hidden md:inline text-gray-300">|</span>
                                        <a href="../controls/admin/reject_verification.php?request_id=<?php echo $row['request_id']; ?>" class="text-red-600 font-medium">Reject</a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Reports Section -->
        <div id="reports" class="section">
            <div class="mb-4 md:mb-6">
                <h2 class="text-2xl md:text-3xl font-bold text-gray-800 mb-2">Pending Reports</h2>
                <p class="text-sm md:text-base text-gray-600">Review and take action on user reports</p>
            </div>
            
            <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gradient-to-r from-red-50 to-orange-50">
                            <tr>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Report ID</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">User ID</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Name</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Reason</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Details</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Attachment</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php while ($row = $report_result->fetch_assoc()): ?>
                            <tr>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <span class="text-xs md:text-sm font-medium text-gray-900">#<?php echo $row['report_id']; ?></span>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <span class="text-xs md:text-sm text-gray-700">#<?php echo $row['user_id']; ?></span>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <div class="text-xs md:text-sm font-medium text-gray-900"><?php echo htmlspecialchars($row['firstname'] . " " . $row['lastname']); ?></div>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <span class="badge bg-orange-100 text-orange-800"><?php echo ucfirst($row['reason']); ?></span>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4">
                                    <div class="text-xs md:text-sm text-gray-700 max-w-xs"><?php echo htmlspecialchars($row['additional_details']); ?></div>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap text-center">
                                    <?php if (!empty($row['attachment']) && file_exists("uploads/reports/" . basename($row['attachment']))): ?>
                                        <a href="uploads/reports/<?php echo basename($row['attachment']); ?>" target="_blank" class="inline-flex items-center px-2 md:px-3 py-1 rounded-lg text-xs md:text-sm font-medium text-indigo-600 bg-indigo-50">
                                            <svg class="w-3 h-3 md:w-4 md:h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path>
                                            </svg>
                                            View
                                        </a>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs md:text-sm italic">No attachment</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <span class="badge bg-yellow-100 text-yellow-800"><?php echo ucfirst($row['status']); ?></span>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap text-xs md:text-sm">
                                    <div class="flex flex-col md:flex-row md:items-center space-y-1 md:space-y-0 md:space-x-2">
                                        <a href="view_user.php?user_id=<?php echo $row['user_id']; ?>" class="text-gray-600 font-medium">View</a>
                                        <span class="hidden md:inline text-gray-300">|</span>
                                        <a href="?confirm=<?php echo $row['report_id']; ?>&user_id=<?php echo $row['user_id']; ?>&reason=<?php echo $row['reason']; ?>" class="text-green-600 font-medium">Confirm</a>
                                        <span class="hidden md:inline text-gray-300">|</span>
                                        <a href="?reject=<?php echo $row['report_id']; ?>" class="text-red-600 font-medium" onclick="return confirm('Are you sure you want to reject this report?')">Reject</a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Accepted Hires Section -->
        <div id="hires" class="section hidden">
            <div class="mb-4 md:mb-6">
                <h2 class="text-2xl md:text-3xl font-bold text-gray-800 mb-2">Accepted Hires</h2>
                <p class="text-sm md:text-base text-gray-600">Monitor confirmed employment agreements</p>
            </div>
            
            <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gradient-to-r from-green-50 to-emerald-50">
                            <tr>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Employer</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Laborer</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Message</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Meeting Location</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Date/Time</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php while ($hire = $hires_result->fetch_assoc()): ?>
                            <tr>
                                <td class="px-3 md:px-6 py-3 md:py-4">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-8 w-8 md:h-10 md:w-10 bg-gradient-to-br from-blue-400 to-blue-600 rounded-full flex items-center justify-center text-white font-semibold text-xs md:text-base">
                                            <?php echo strtoupper(substr($hire['employer_firstname'], 0, 1)); ?>
                                        </div>
                                        <div class="ml-2 md:ml-3">
                                            <div class="text-xs md:text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($hire['employer_firstname'] . " " . $hire['employer_middlename'] . " " . $hire['employer_lastname']); ?>
                                            </div>
                                            </div>
                                    </div>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-8 w-8 md:h-10 md:w-10 bg-gradient-to-br from-teal-400 to-teal-600 rounded-full flex items-center justify-center text-white font-semibold text-xs md:text-base">
                                            <?php echo strtoupper(substr($hire['laborer_firstname'], 0, 1)); ?>
                                        </div>
                                        <div class="ml-2 md:ml-3">
                                            <div class="text-xs md:text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($hire['laborer_firstname'] . " " . $hire['laborer_middlename'] . " " . $hire['laborer_lastname']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4">
                                    <div class="text-xs md:text-sm text-gray-700 max-w-xs"><?php echo htmlspecialchars($hire['message']); ?></div>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4">
                                    <div class="flex items-center text-xs md:text-sm text-gray-700">
                                        <svg class="w-3 h-3 md:w-4 md:h-4 mr-1 md:mr-2 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        <span class="break-words"><?php echo htmlspecialchars($hire['meeting_location']); ?></span>
                                    </div>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <span class="badge bg-green-100 text-green-800"><?php echo ucfirst($hire['status']); ?></span>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <div class="flex items-center text-xs md:text-sm text-gray-700">
                                        <svg class="w-3 h-3 md:w-4 md:h-4 mr-1 md:mr-2 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        <span class="break-words"><?php echo date('F j, Y, g:i A', strtotime($hire['created_at'])); ?></span>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Announcements Section -->
        <div id="announcements" class="section hidden">
            <div class="mb-4 md:mb-6">
                <h2 class="text-2xl md:text-3xl font-bold text-gray-800 mb-2">Barangay Announcements</h2>
                <p class="text-sm md:text-base text-gray-600">Manage community announcements and updates</p>
            </div>

            <?php
            // --- Handle Add, Delete, Edit for Barangay Announcements ---

            // ✅ ADD ANNOUNCEMENT
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_announcement'])) {
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $image_path = null;

                // --- Handle image upload ---
                if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . "/../uploads/announcements/";
                    $web_dir = "uploads/announcements/";

                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $file_tmp = $_FILES['image']['tmp_name'];
                    $file_name = time() . "_" . basename($_FILES['image']['name']);
                    $file_path = $upload_dir . $file_name;
                    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                        if (move_uploaded_file($file_tmp, $file_path)) {
                            $image_path = $web_dir . $file_name;
                        } else {
                            echo "<div class='bg-red-50 border-l-4 border-red-500 p-3 md:p-4 mb-3 md:mb-4 rounded'><p class='text-sm md:text-base text-red-700'>❌ Failed to save uploaded image.</p></div>";
                        }
                    } else {
                        echo "<div class='bg-red-50 border-l-4 border-red-500 p-3 md:p-4 mb-3 md:mb-4 rounded'><p class='text-sm md:text-base text-red-700'>⚠️ Invalid file type. Only JPG, PNG, or GIF allowed.</p></div>";
                    }
                }

                if (!empty($title) && !empty($content)) {
                    $stmt = $conn->prepare("INSERT INTO barangay_announcements (title, content, image_path) VALUES (?,?,?)");
                    $stmt->bind_param("sss", $title, $content, $image_path);
                    $stmt->execute();
                    $stmt->close();

                    echo "<div class='bg-green-50 border-l-4 border-green-500 p-3 md:p-4 mb-3 md:mb-4 rounded'><p class='text-sm md:text-base text-green-700 font-medium'>✅ Announcement added successfully!</p></div>";
                } else {
                    echo "<div class='bg-red-50 border-l-4 border-red-500 p-3 md:p-4 mb-3 md:mb-4 rounded'><p class='text-sm md:text-base text-red-700'>⚠️ Title and content are required.</p></div>";
                }
            }

            // ✅ DELETE ANNOUNCEMENT
            if (isset($_POST['delete_announcement'])) {
                $id = intval($_POST['announcement_id']);

                $res = $conn->query("SELECT image_path FROM barangay_announcements WHERE announcement_id=$id");
                if ($row = $res->fetch_assoc()) {
                    $file_to_delete = __DIR__ . "/../" . $row['image_path'];
                    if (!empty($row['image_path']) && file_exists($file_to_delete)) {
                        unlink($file_to_delete);
                    }
                }

                $conn->query("DELETE FROM barangay_announcements WHERE announcement_id=$id");
                echo "<div class='bg-red-50 border-l-4 border-red-500 p-3 md:p-4 mb-3 md:mb-4 rounded'><p class='text-sm md:text-base text-red-700 font-medium'>🗑️ Announcement deleted.</p></div>";
            }

            // ✅ EDIT ANNOUNCEMENT
            if (isset($_POST['edit_announcement'])) {
                $id = intval($_POST['announcement_id']);
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $image_path = $_POST['existing_image'];

                if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . "/../uploads/announcements/";
                    $web_dir = "uploads/announcements/";

                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $file_tmp = $_FILES['image']['tmp_name'];
                    $file_name = time() . "_" . basename($_FILES['image']['name']);
                    $file_path = $upload_dir . $file_name;
                    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                        if (move_uploaded_file($file_tmp, $file_path)) {
                            $image_path = $web_dir . $file_name;
                        } else {
                            echo "<div class='bg-red-50 border-l-4 border-red-500 p-3 md:p-4 mb-3 md:mb-4 rounded'><p class='text-sm md:text-base text-red-700'>❌ Failed to update image file.</p></div>";
                        }
                    } else {
                        echo "<div class='bg-red-50 border-l-4 border-red-500 p-3 md:p-4 mb-3 md:mb-4 rounded'><p class='text-sm md:text-base text-red-700'>⚠️ Invalid image file type.</p></div>";
                    }
                }

                $stmt = $conn->prepare("UPDATE barangay_announcements SET title=?, content=?, image_path=? WHERE announcement_id=?");
                $stmt->bind_param("sssi", $title, $content, $image_path, $id);
                $stmt->execute();
                $stmt->close();

                echo "<div class='bg-blue-50 border-l-4 border-blue-500 p-3 md:p-4 mb-3 md:mb-4 rounded'><p class='text-sm md:text-base text-blue-700 font-medium'>✏️ Announcement updated successfully!</p></div>";
            }

            // ✅ FETCH ALL ANNOUNCEMENTS
            $ann_query = "SELECT * FROM barangay_announcements ORDER BY date_posted DESC";
            $ann_result = $conn->query($ann_query);
            ?>

            <div class="bg-white rounded-2xl shadow-lg p-4 md:p-6 mb-4 md:mb-6">
                <h4 class="text-lg md:text-xl font-bold text-gray-800 mb-3 md:mb-4 flex items-center">
                    <svg class="w-5 h-5 md:w-6 md:h-6 mr-2 text-teal-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add New Announcement
                </h4>
                <form method="POST" enctype="multipart/form-data">
                    <div class="grid grid-cols-1 gap-3 md:gap-4">
                        <div>
                            <label class="block text-xs md:text-sm font-semibold text-gray-700 mb-2">Title</label>
                            <input type="text" name="title" required class="w-full p-2 md:p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-transparent transition text-sm md:text-base">
                        </div>
                        <div>
                            <label class="block text-xs md:text-sm font-semibold text-gray-700 mb-2">Content</label>
                            <textarea name="content" required rows="4" class="w-full p-2 md:p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-transparent transition text-sm md:text-base"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs md:text-sm font-semibold text-gray-700 mb-2">Image (optional)</label>
                            <input type="file" name="image" accept="image/*" class="w-full p-2 md:p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-transparent transition text-sm md:text-base file:mr-2 md:file:mr-4 file:py-1 md:file:py-2 file:px-2 md:file:px-4 file:rounded-lg file:border-0 file:text-xs md:file:text-sm file:bg-teal-50 file:text-teal-700">
                        </div>
                    </div>
                    <button type="submit" name="add_announcement" class="mt-3 md:mt-4 bg-gradient-to-r from-teal-600 to-teal-700 text-white px-4 md:px-6 py-2 md:py-3 rounded-lg font-semibold shadow-lg transition-all duration-200 flex items-center text-sm md:text-base w-full md:w-auto justify-center">
                        <svg class="w-4 h-4 md:w-5 md:h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                        </svg>
                        Post Announcement
                    </button>
                </form>
            </div>

            <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gradient-to-r from-purple-50 to-pink-50">
                            <tr>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Title</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Content</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Image</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Date Posted</th>
                                <th class="px-3 md:px-6 py-3 md:py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php while($ann=$ann_result->fetch_assoc()): ?>
                            <tr>
                                <td class="px-3 md:px-6 py-3 md:py-4">
                                    <div class="text-xs md:text-sm font-bold text-gray-900 break-words"><?php echo htmlspecialchars($ann['title']); ?></div>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4">
                                    <div class="text-xs md:text-sm text-gray-700 max-w-md break-words"><?php echo nl2br(htmlspecialchars($ann['content'])); ?></div>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4">
                                    <?php if (!empty($ann['image_path'])): ?>
                                        <img src="<?php echo '../' . htmlspecialchars($ann['image_path']); ?>" class="w-16 h-16 md:w-20 md:h-20 object-cover rounded-lg shadow-md">
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs md:text-sm italic">No image</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                    <div class="flex items-center text-xs md:text-sm text-gray-700">
                                        <svg class="w-3 h-3 md:w-4 md:h-4 mr-1 md:mr-2 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        <span class="hidden md:inline"><?php echo date('M j, Y, g:i A', strtotime($ann['date_posted'])); ?></span>
                                        <span class="md:hidden"><?php echo date('M j, Y', strtotime($ann['date_posted'])); ?></span>
                                    </div>
                                </td>
                                <td class="px-3 md:px-6 py-3 md:py-4 text-center">
                                    <div class="flex flex-col md:flex-row md:items-center md:justify-center space-y-1 md:space-y-0 md:space-x-2">
                                        <button type="button" onclick="toggleEditForm(<?php echo $ann['announcement_id']; ?>)" class="inline-flex items-center justify-center px-2 md:px-3 py-1 rounded-lg text-xs md:text-sm font-medium text-blue-600 bg-blue-50 transition-colors">
                                            <svg class="w-3 h-3 md:w-4 md:h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                            Edit
                                        </button>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="announcement_id" value="<?php echo $ann['announcement_id']; ?>">
                                            <button type="submit" name="delete_announcement" class="inline-flex items-center justify-center px-2 md:px-3 py-1 rounded-lg text-xs md:text-sm font-medium text-red-600 bg-red-50 transition-colors w-full md:w-auto" onclick="return confirm('Delete this announcement?');">
                                                <svg class="w-3 h-3 md:w-4 md:h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr id="editForm<?php echo $ann['announcement_id']; ?>" class="hidden bg-gradient-to-r from-gray-50 to-blue-50">
                                <td colspan="5" class="px-3 md:px-6 py-4 md:py-6">
                                    <div class="bg-white p-4 md:p-6 rounded-xl shadow-inner">
                                        <h5 class="text-base md:text-lg font-bold text-gray-800 mb-3 md:mb-4">Edit Announcement</h5>
                                        <form method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="announcement_id" value="<?php echo $ann['announcement_id']; ?>">
                                            <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($ann['image_path']); ?>">
                                            <div class="grid grid-cols-1 gap-3 md:gap-4">
                                                <div>
                                                    <label class="block text-xs md:text-sm font-semibold text-gray-700 mb-2">Title</label>
                                                    <input type="text" name="title" value="<?php echo htmlspecialchars($ann['title']); ?>" required class="w-full p-2 md:p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition text-sm md:text-base">
                                                </div>
                                                <div>
                                                    <label class="block text-xs md:text-sm font-semibold text-gray-700 mb-2">Content</label>
                                                    <textarea name="content" rows="4" required class="w-full p-2 md:p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition text-sm md:text-base"><?php echo htmlspecialchars($ann['content']); ?></textarea>
                                                </div>
                                                <div>
                                                    <label class="block text-xs md:text-sm font-semibold text-gray-700 mb-2">Image (optional - leave empty to keep current)</label>
                                                    <input type="file" name="image" accept="image/*" class="w-full p-2 md:p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition text-sm md:text-base file:mr-2 md:file:mr-4 file:py-1 md:file:py-2 file:px-2 md:file:px-4 file:rounded-lg file:border-0 file:text-xs md:file:text-sm file:bg-blue-50 file:text-blue-700">
                                                </div>
                                            </div>
                                            <div class="flex flex-col md:flex-row md:items-center space-y-2 md:space-y-0 md:space-x-3 mt-3 md:mt-4">
                                                <button type="submit" name="edit_announcement" class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-4 md:px-6 py-2 rounded-lg font-semibold shadow-lg transition-all duration-200 text-sm md:text-base w-full md:w-auto">Update Announcement</button>
                                                <button type="button" onclick="toggleEditForm(<?php echo $ann['announcement_id']; ?>)" class="bg-gray-300 text-gray-800 px-3 md:px-4 py-2 rounded-lg font-medium transition-colors text-sm md:text-base w-full md:w-auto">Cancel</button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('overlay');
    sidebar.classList.toggle('active');
    overlay.classList.toggle('active');
}

function showSection(id) {
    document.querySelectorAll('.section').forEach(s => s.classList.add('hidden'));
    document.getElementById(id).classList.remove('hidden');
    
    // Update active button
    document.querySelectorAll('.nav-button').forEach(btn => btn.classList.remove('active'));
    event.target.closest('button').classList.add('active');
    
    // Close sidebar on mobile after selecting
    if (window.innerWidth < 768) {
        toggleSidebar();
    }
}

function toggleEditForm(id) {
    const form = document.getElementById('editForm'+id);
    form.classList.toggle('hidden');
}

// Show reports section by default and set active button
document.addEventListener('DOMContentLoaded', function() {
    showSection('reports');
    document.querySelectorAll('.nav-button')[1].classList.add('active');
});
</script>
</body>
</html>