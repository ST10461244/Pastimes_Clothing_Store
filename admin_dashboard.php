<?php
session_start();
include "DBConn.php";

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit();
}

$message = '';
$message_type = '';

// Handle Verify Customer (from pending registrations)
if (isset($_GET['verify_id'])) {
    $verify_id = intval($_GET['verify_id']);
    $verify_stmt = $connect->prepare("UPDATE users SET verification_status = 'verified' WHERE user_id = ?");
    $verify_stmt->bind_param("i", $verify_id);
    if ($verify_stmt->execute()) {
        $message = "✓ Customer verified successfully! They can now log in.";
        $message_type = "success";
    } else {
        $message = "Error verifying customer: " . $connect->error;
        $message_type = "error";
    }
    $verify_stmt->close();
}

// Handle Reject Customer
if (isset($_GET['reject_id'])) {
    $reject_id = intval($_GET['reject_id']);
    $reject_stmt = $connect->prepare("UPDATE users SET verification_status = 'rejected' WHERE user_id = ?");
    $reject_stmt->bind_param("i", $reject_id);
    if ($reject_stmt->execute()) {
        $message = "✗ Customer rejected.";
        $message_type = "success";
    } else {
        $message = "Error rejecting customer: " . $connect->error;
        $message_type = "error";
    }
    $reject_stmt->close();
}

// Handle Add Customer
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_customer'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $name = trim($_POST['name']);
    $surname = trim($_POST['surname']);
    $address = trim($_POST['address']);
    
    $errors = [];
    
    if (empty($username)) $errors[] = "Username required";
    if (empty($email)) $errors[] = "Email required";
    if (empty($password)) $errors[] = "Password required";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email";
    if (strlen($password) < 6) $errors[] = "Password must be 6+ characters";
    
    if (empty($errors)) {
        $check_stmt = $connect->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $check_stmt->bind_param("ss", $username, $email);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            $message = "Username or email already exists!";
            $message_type = "error";
        } else {
            $password_hash = md5($password);
            $insert_stmt = $connect->prepare("INSERT INTO users (username, email, password_hash, name, surname, address, verification_status, user_role) VALUES (?, ?, ?, ?, ?, ?, 'verified', 'user')");
            $insert_stmt->bind_param("ssssss", $username, $email, $password_hash, $name, $surname, $address);
            
            if ($insert_stmt->execute()) {
                $message = "✅ Customer added successfully!";
                $message_type = "success";
            } else {
                $message = "Error adding customer: " . $connect->error;
                $message_type = "error";
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// Handle Update Customer
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_customer'])) {
    $user_id = intval($_POST['user_id']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $name = trim($_POST['name']);
    $surname = trim($_POST['surname']);
    $address = trim($_POST['address']);
    
    $update_stmt = $connect->prepare("UPDATE users SET username = ?, email = ?, name = ?, surname = ?, address = ? WHERE user_id = ?");
    $update_stmt->bind_param("sssssi", $username, $email, $name, $surname, $address, $user_id);
    
    if ($update_stmt->execute()) {
        $message = "✏️ Customer updated successfully!";
        $message_type = "success";
    } else {
        $message = "Error updating customer: " . $connect->error;
        $message_type = "error";
    }
    $update_stmt->close();
}

// Handle Delete Customer
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Don't allow admin to delete themselves
    if ($delete_id != $_SESSION['admin_id']) {
        $delete_stmt = $connect->prepare("DELETE FROM users WHERE user_id = ?");
        $delete_stmt->bind_param("i", $delete_id);
        if ($delete_stmt->execute()) {
            $message = "🗑️ Customer deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting customer: " . $connect->error;
            $message_type = "error";
        }
        $delete_stmt->close();
    } else {
        $message = "You cannot delete your own admin account!";
        $message_type = "error";
    }
}

// Fetch all customers
$customers_query = "SELECT user_id, username, email, name, surname, address, verification_status, user_role, registration_date FROM users ORDER BY user_id DESC";
$customers_result = $connect->query($customers_query);

// Fetch pending verifications
$pending_query = "SELECT user_id, username, email, name, surname, registration_date FROM users WHERE verification_status = 'pending' ORDER BY registration_date DESC";
$pending_result = $connect->query($pending_query);

// Fetch customer for editing (if edit clicked)
$edit_customer = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $edit_query = $connect->prepare("SELECT * FROM users WHERE user_id = ?");
    $edit_query->bind_param("i", $edit_id);
    $edit_query->execute();
    $edit_result = $edit_query->get_result();
    $edit_customer = $edit_result->fetch_assoc();
    $edit_query->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Clothing Store</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }

        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .admin-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .admin-header h1 {
            font-size: 24px;
        }

        .admin-header p {
            color: #ccc;
            margin-top: 5px;
        }

        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            transition: transform 0.2s;
        }

        .logout-btn:hover {
            transform: scale(1.05);
        }

        .message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .section h2 {
            color: #1a1a2e;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        input, textarea, select {
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            width: 100%;
            box-sizing: border-box;
        }

        textarea {
            resize: vertical;
        }

        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }

        button:hover {
            opacity: 0.9;
        }

        .btn-danger {
            background: #e74c3c;
        }

        .btn-warning {
            background: #f39c12;
        }

        .btn-success {
            background: #27ae60;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-verified {
            background: #27ae60;
            color: white;
        }

        .badge-pending {
            background: #f39c12;
            color: white;
        }

        .badge-rejected {
            background: #e74c3c;
            color: white;
        }

        .pending-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }

        .pending-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            border-left: 4px solid #f39c12;
        }

        .pending-card h4 {
            margin-bottom: 10px;
            color: #333;
        }

        .pending-card p {
            color: #666;
            font-size: 13px;
            margin: 5px 0;
        }

        .pending-actions {
            margin-top: 10px;
        }

        .action-buttons a, .action-buttons button {
            display: inline-block;
            padding: 5px 10px;
            margin: 2px;
            font-size: 12px;
            text-decoration: none;
            border-radius: 5px;
        }

        .modal {
            display: flex;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            width: 500px;
            max-width: 90%;
        }

        .modal-content h3 {
            margin-bottom: 20px;
        }

        .close-modal {
            float: right;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }

        .close-modal:hover {
            color: #333;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <div>
                <h1>Admin Dashboard</h1>
                <p>Welcome, <?php echo htmlspecialchars($_SESSION['admin_name']); ?> (<?php echo htmlspecialchars($_SESSION['admin_username']); ?>)</p>
            </div>
            <a href="admin_login.php?logout=1" class="logout-btn" onclick="sessionStorage.clear();">Logout</a>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Pending Verifications Section -->
        <?php if ($pending_result->num_rows > 0): ?>
        <div class="section">
            <h2>Pending Customer Verifications</h2>
            <div class="pending-grid">
                <?php while ($pending = $pending_result->fetch_assoc()): ?>
                <div class="pending-card">
                    <h4><?php echo htmlspecialchars($pending['username']); ?></h4>
                    <p><?php echo htmlspecialchars($pending['email']); ?></p>
                    <p><?php echo htmlspecialchars($pending['name'] . ' ' . $pending['surname']); ?></p>
                    <p>Registered: <?php echo date('M d, Y', strtotime($pending['registration_date'])); ?></p>
                    <div class="pending-actions">
                        <a href="?verify_id=<?php echo $pending['user_id']; ?>" class="btn-success" style="padding: 5px 10px; border-radius: 5px; text-decoration: none; color: white; background: #27ae60; display: inline-block;">Verify</a>
                        <a href="?reject_id=<?php echo $pending['user_id']; ?>" class="btn-danger" style="padding: 5px 10px; border-radius: 5px; text-decoration: none; color: white; background: #e74c3c; display: inline-block;" onclick="return confirm('Reject this customer?')">Reject</a>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Add Customer Form -->
        <div class="section">
            <h2>Add New Customer</h2>
            <form method="POST">
                <div class="form-grid">
                    <input type="text" name="username" placeholder="Username *" required>
                    <input type="email" name="email" placeholder="Email *" required>
                    <input type="password" name="password" placeholder="Password (min 6 chars) *" required>
                    <input type="text" name="name" placeholder="First Name">
                    <input type="text" name="surname" placeholder="Last Name">
                    <textarea name="address" placeholder="Address" rows="2"></textarea>
                </div>
                <button type="submit" name="add_customer" style="margin-top: 15px;">Add Customer</button>
            </form>
        </div>

        <!-- Edit Customer Modal -->
        <?php if ($edit_customer): ?>
        <div class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="window.location.href='admin_dashboard.php'">&times;</span>
                <h3>Edit Customer</h3>
                <form method="POST">
                    <input type="hidden" name="user_id" value="<?php echo $edit_customer['user_id']; ?>">
                    <input type="text" name="username" value="<?php echo htmlspecialchars($edit_customer['username']); ?>" required>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($edit_customer['email']); ?>" required>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($edit_customer['name'] ?? ''); ?>">
                    <input type="text" name="surname" value="<?php echo htmlspecialchars($edit_customer['surname'] ?? ''); ?>">
                    <textarea name="address" rows="3"><?php echo htmlspecialchars($edit_customer['address'] ?? ''); ?></textarea>
                    <button type="submit" name="update_customer" style="margin-top: 15px;">Save Changes</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- All Customers Table -->
        <div class="section">
            <h2>All Customers</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($customer = $customers_result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $customer['user_id']; ?></td>
                            <td><?php echo htmlspecialchars($customer['username']); ?></td>
                            <td><?php echo htmlspecialchars($customer['email']); ?></td>
                            <td><?php echo htmlspecialchars($customer['name'] . ' ' . $customer['surname']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $customer['verification_status']; ?>">
                                    <?php echo ucfirst($customer['verification_status']); ?>
                                </span>
                            </td>
                            <td class="action-buttons">
                                <a href="?edit_id=<?php echo $customer['user_id']; ?>" class="btn-warning" style="padding: 5px 10px; border-radius: 5px; text-decoration: none; color: white; background: #f39c12; display: inline-block;">✏️ Edit</a>
                                <?php if ($customer['user_id'] != $_SESSION['admin_id']): ?>
                                <a href="?delete_id=<?php echo $customer['user_id']; ?>" class="btn-danger" style="padding: 5px 10px; border-radius: 5px; text-decoration: none; color: white; background: #e74c3c; display: inline-block;" onclick="return confirm('Delete this customer permanently?')">🗑️ Delete</a>
                                <?php endif; ?>
                                <?php if ($customer['verification_status'] == 'pending'): ?>
                                <a href="?verify_id=<?php echo $customer['user_id']; ?>" class="btn-success" style="padding: 5px 10px; border-radius: 5px; text-decoration: none; color: white; background: #27ae60; display: inline-block;">✓ Verify</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>