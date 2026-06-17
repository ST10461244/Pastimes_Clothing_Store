<?php
session_start();
include "DBConn.php";

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin_login.php');
    exit();
}

$error_message = '';
$sticky_username = '';

// Check if admin is already logged in
if(isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] !== true){
    header('Location: admin_dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['admin_login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    $sticky_username = htmlspecialchars($username);
    
    if (empty($username) || empty($password)) {
        $error_message = "Both username and password are required";
    } else {
 
        $stmt = mysqli_prepare($connect, "select * from admin WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $admin = $result->fetch_assoc();
            
            $password_hash = null;
            if (isset($admin['password_hash'])) {
                $password_hash = $admin['password_hash'];
            } elseif (isset($admin['password'])) {
                $password_hash = $admin['password'];
            }
            
            // Verify password
            $password_valid = false;
            
            if (!empty($password_hash)) {
                if (strlen($password_hash) == 32 && ctype_xdigit($password_hash)) {
                    $password_valid = (md5($password) == $password_hash);
                } elseif (function_exists('password_get_info') && !empty($password_hash) && password_get_info($password_hash)['algo']) {
                    $password_valid = password_verify($password, $password_hash);
                } else {
                    $password_valid = ($password == $password_hash);
                }
            }
            
            if ($password_valid) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['admin_id'];
                $_SESSION['admin_name'] = $admin['name'] ?? $admin['username'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_role'] = $admin['role'] ?? 'admin';
                
                // Update last login time if column exists
                $check_column = mysqli_query($connect, "SHOW COLUMNS FROM admin LIKE 'last_login'");
                if (mysqli_num_rows($check_column) > 0) {
                    $update_stmt = mysqli_prepare($connect, "UPDATE admin SET last_login = NOW() WHERE admin_id = ?");
                    mysqli_stmt_bind_param($update_stmt, "i", $admin['admin_id']);
                    mysqli_stmt_execute($update_stmt);
                    mysqli_stmt_close($update_stmt);
                }
                
                header('Location: admin_dashboard.php');
                exit();
            } else {
                $error_message = "Invalid password";
            }
        } else {
            $error_message = "Admin user not found. Please check your credentials.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Clothing Store</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .admin-login-container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 400px;
            transition: transform 0.3s ease;
        }

        .admin-login-container:hover {
            transform: translateY(-5px);
        }

        h2 {
            text-align: center;
            color: #1a1a2e;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .admin-badge {
            text-align: center;
            color: #e74c3c;
            font-size: 14px;
            margin-bottom: 30px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 12px 15px;
            margin: 10px 0;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        input:focus {
            outline: none;
            border-color: #e74c3c;
            box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.1);
        }

        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            margin-top: 10px;
            transition: transform 0.2s ease;
        }

        button:hover {
            transform: scale(1.02);
        }

        .error {
            color: #e74c3c;
            text-align: center;
            margin-bottom: 15px;
            padding: 10px;
            background: #fee;
            border-radius: 10px;
            font-size: 14px;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: #666;
            text-decoration: none;
            font-size: 14px;
        }

        .back-link a:hover {
            color: #e74c3c;
        }

        hr {
            margin: 20px 0;
            border: none;
            border-top: 2px solid #f0f0f0;
        }
    </style>
</head>
<body>
    <div class="admin-login-container">
        <h2>Admin Access</h2>
        <div class="admin-badge">Administrator Login</div>
        
        <?php if ($error_message): ?>
            <div class="error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="text" name="username" placeholder="Admin Username" value="<?php echo $sticky_username; ?>" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" name="admin_login">Login as Admin</button>
        </form>
        
        <hr>
        
        <div class="back-link">
            <a href="index.php">← Back to Customer Login</a>
        </div>
    </div>
</body>
</html>