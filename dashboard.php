<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

include "DBConn.php";

$user_data = [
    'User ID' => $_SESSION['user_id'],
    'Username' => $_SESSION['username'],
    'Email' => $_SESSION['email'],
    'First Name' => $_SESSION['name'] ?? 'Not provided',
    'Last Name' => $_SESSION['surname'] ?? 'Not provided',
    'Full Name' => trim(($_SESSION['name'] ?? '') . ' ' . ($_SESSION['surname'] ?? '')),
    'Address' => $_SESSION['address'] ?? 'Not provided'
];

// If user is admin, show pending registrations for verification
$pending_users = [];
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') {
    $pending_query = "SELECT user_id, username, email, name, surname, address, registration_date FROM users WHERE verification_status = 'pending' AND user_id != " . $_SESSION['user_id'] . " ORDER BY registration_date DESC";
    $pending_result = $connect->query($pending_query);
    if ($pending_result) {
        while ($row = $pending_result->fetch_assoc()) {
            $pending_users[] = $row;
        }
    }
}

// Handle verification actions (admin only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_action']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') {
    $user_id = intval($_POST['user_id']);
    $action = $_POST['verify_action'];
    
    if ($action == 'verify') {
        $update_stmt = $connect->prepare("UPDATE users SET verification_status = 'verified' WHERE user_id = ?");
        $update_stmt->bind_param("i", $user_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        header('Location: dashboard.php?msg=verified');
        exit();
    } elseif ($action == 'reject') {
        $update_stmt = $connect->prepare("UPDATE users SET verification_status = 'rejected' WHERE user_id = ?");
        $update_stmt->bind_param("i", $user_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        header('Location: dashboard.php?msg=rejected');
        exit();
    }
}

$message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'verified') $message = '<div class="message success">✓ User has been verified successfully!</div>';
    if ($_GET['msg'] == 'rejected') $message = '<div class="message error">✗ User has been rejected.</div>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Clothing Store</title>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: Arial, sans-serif;
        background: #e9ecef;
        padding: 20px;
    }

    .dashboard-container {
        max-width: 1000px;
        margin: 0 auto;
    }

  
    .welcome-banner, .data-table, .pending-section {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

  
    .welcome-banner {
        background: #007bff;
        color: white;
    }

    .welcome-banner h1 {
        font-size: 24px;
        margin-bottom: 5px;
    }

   
    .logout-btn, .shop-btn {
        display: inline-block;
        padding: 8px 20px;
        border-radius: 5px;
        text-decoration: none;
        margin-top: 10px;
        margin-right: 8px;
        font-size: 14px;
    }

    .shop-btn {
        background: #28a745;
        color: white;
    }

    .logout-btn {
        background: #dc3545;
        color: white;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th {
        background: #343a40;
        color: white;
        padding: 10px;
        text-align: left;
    }

    td {
        padding: 10px;
        border-bottom: 1px solid #dee2e6;
    }

    tr:hover {
        background: #f8f9fa;
    }

    .verify-btn {
        background: #28a745;
        color: white;
        border: none;
        padding: 5px 12px;
        border-radius: 4px;
        cursor: pointer;
    }

    .reject-btn {
        background: #dc3545;
        color: white;
        border: none;
        padding: 5px 12px;
        border-radius: 4px;
        cursor: pointer;
    }

    .badge {
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: bold;
        color: white;
    }

    .badge-verified { background: #28a745; }
    .badge-pending { background: #ffc107; color: #333; }
    .badge-rejected { background: #dc3545; }

    
    .success {
        background: #d4edda;
        color: #155724;
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 15px;
    }

    .error {
        background: #f8d7da;
        color: #721c24;
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 15px;
    }

    
    @media (max-width: 600px) {
        .welcome-banner h1 { font-size: 18px; }
        th, td { font-size: 12px; padding: 6px; }
    }
</style>
</head>
<body>
    <div class="dashboard-container">
        <?php echo $message; ?>
        
        <!-- Welcome Banner with required format: "User John Doe is logged in" -->
        <div class="welcome-banner">
            <h1>User <?php echo htmlspecialchars(($_SESSION['name'] ?? '') . ' ' . ($_SESSION['surname'] ?? '')); ?> is logged in</h1>
            <p>Welcome to your Clothing Store dashboard!</p>
            <a href="products.php" class="shop-btn">Shop Now</a>
            <a href="login.php?logout=1" class="logout-btn">Logout</a>
        </div>
        
        <!-- User Data Table (Associative Array Approach as required) -->
        <div class="data-table">
            <h2>Your Profile Information</h2>
            <table>
                <thead>
                    <tr>
                        <th>Field</th>
                        <th>Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($user_data as $field => $value): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($field); ?></strong></td>
                        <td><?php echo htmlspecialchars($value ?: 'Not provided'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
       
        <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin'): ?>
        <div class="pending-section">
            <h2>👥 Pending User Verifications</h2>
            <?php if (count($pending_users) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Name</th>
                            <th>Address</th>
                            <th>Registration Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_users as $pending): ?>
                        <tr>
                            <td><?php echo $pending['user_id']; ?></td>
                            <td><?php echo htmlspecialchars($pending['username']); ?></td>
                            <td><?php echo htmlspecialchars($pending['email']); ?></td>
                            <td><?php echo htmlspecialchars(($pending['name'] ?? '') . ' ' . ($pending['surname'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars(substr($pending['address'] ?? '', 0, 50)); ?></td>
                            <td><?php echo date('M d, Y', strtotime($pending['registration_date'])); ?></td>
                            <td>
                                <form method="POST" style="display: inline-block;">
                                    <input type="hidden" name="user_id" value="<?php echo $pending['user_id']; ?>">
                                    <button type="submit" name="verify_action" value="verify" class="verify-btn" onclick="return confirm('Verify this user?')">Verify</button>
                                    <button type="submit" name="verify_action" value="reject" class="reject-btn" onclick="return confirm('Reject this user?')">Reject</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <p>No pending verifications. All users have been reviewed.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>