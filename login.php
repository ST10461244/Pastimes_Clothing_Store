<?php
session_start();

include "DBConn.php";

$error_message = '';
$success_message = '';
$sticky_username = '';
$sticky_email = '';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    $sticky_username = htmlspecialchars($username);
    $sticky_email = htmlspecialchars($email);

    if (empty($username) || empty($email) || empty($password)) {
        $error_message = "All fields are required";
    } else {
        $stmt = mysqli_prepare($connect, "SELECT * FROM users WHERE username = ? AND email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();

            $password_valid = false;

            if (strlen($user['password_hash']) == 32 && ctype_xdigit($user['password_hash'])) {
                $password_valid = (md5($password) == $user['password_hash']);
            } elseif (password_get_info($user['password_hash'])['algo']) {
                $password_valid = password_verify($password, $user['password_hash']);
            } else {
                $password_valid = ($password == $user['password_hash']);
            }

            if ($password_valid) {
                $verification_status = $user['verification_status'] ?? 'pending';

                if ($verification_status == 'verified') {
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['name'] = $user['name'] ?? '';
                    $_SESSION['surname'] = $user['surname'] ?? '';
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['user_role'] = $user['user_role'] ?? 'user';
                    $_SESSION['address'] = $user['address'] ?? '';

                    header('Location: dashboard.php');
                    exit();
                } elseif ($verification_status == 'pending') {
                    $error_message = "Your account is pending verification. Please wait for admin approval.";
                } else {
                    $error_message = "Your account has been rejected. Please contact support.";
                }
            } else {
                $error_message = "Invalid password. Please try again.";
            }
        } else {
            $error_message = "User not found with the provided username and email.";
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
    <title>Login - Clothing Store</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 400px;
            transition: transform 0.3s ease;
        }

        .container:hover {
            transform: translateY(-5px);
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 28px;
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
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            color: #c33;
            text-align: center;
            margin-bottom: 15px;
            padding: 10px;
            background: #fee;
            border-radius: 10px;
            font-size: 14px;
        }

        .register-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }

        .register-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        hr {
            margin: 25px 0 10px 0;
            border: none;
            border-top: 2px solid #f0f0f0;
        }

        .demo-info {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="container">
        <h2> Welcome Back</h2>

        <?php if ($error_message): ?>
            <div class="error"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="text" name="username" placeholder=" Username" value="<?php echo $sticky_username; ?>" required>
            <input type="email" name="email" placeholder=" Email" value="<?php echo $sticky_email; ?>" required>
            <input type="password" name="password" placeholder=" Password" required>
            <button type="submit" name="login">Login</button>
            <div class="register-link">
                Don't have an account? <a href="register.php">Register here</a>
            </div>
            <div class="register-link">
                Are you an Admin? <a href="admin_login.php">Login here</a>
            </div>
        </form>

        <hr>

        <div class="demo-info">
            <strong> Demo Accounts:</strong><br>
            Use existing users from your database or <a href="register.php">register a new account</a>
        </div>

    </div>
</body>

</html>