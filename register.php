<?php

include "DBConn.php";

$error_message = '';
$success_message = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $form_data = $_POST;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $username = trim($_POST['reg_username']);
    $email = trim($_POST['reg_email']);
    $password = trim($_POST['reg_password']);
    $confirm_password = trim($_POST['confirm_password']);
    $name = trim($_POST['name']);
    $surname = trim($_POST['surname']);
    $address = trim($_POST['address']);

    $errors = [];

    
    if (empty($username)) $errors[] = "Username is required";
    if (empty($email)) $errors[] = "Email is required";
    if (empty($password)) $errors[] = "Password is required";
    if ($password != $confirm_password) $errors[] = "Passwords do not match";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters";
    if (strlen($username) < 3) $errors[] = "Username must be at least 3 characters";

    if (empty($errors)) {
        $check_stmt = $connect->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $check_stmt->bind_param("ss", $username, $email);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $errors[] = "Username or email already exists. Please choose different ones.";
        } else {
            $password_hash = md5($password);
            
            $insert_stmt = $connect->prepare("INSERT INTO users (username, email, password_hash, name, surname, address, verification_status, user_role) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'user')");
            $insert_stmt->bind_param("ssssss", $username, $email, $password_hash, $name, $surname, $address);
            
            if ($insert_stmt->execute()) {
                $success_message = "✓ Registration successful! Your account is pending administrator verification. You will be able to login once verified.";
               
                $form_data = [];
            } else {
                $errors[] = "Registration failed: " . $connect->error;
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
    }
    
    if (!empty($errors)) {
        $error_message = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Clothing Store</title>
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

        .register-container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 450px;
            transition: transform 0.3s ease;
        }

        .register-container:hover {
            transform: translateY(-5px);
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 28px;
        }

        input, textarea {
            width: 100%;
            padding: 12px 15px;
            margin: 10px 0;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            box-sizing: border-box;
            font-family: inherit;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        input:focus, textarea:focus {
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
            padding: 12px;
            background: #fee;
            border-radius: 10px;
            font-size: 14px;
        }

        .success {
            color: #2e7d32;
            text-align: center;
            margin-bottom: 15px;
            padding: 12px;
            background: #e8f5e9;
            border-radius: 10px;
            font-size: 14px;
        }

        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }

        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        hr {
            margin: 20px 0;
            border: none;
            border-top: 2px solid #f0f0f0;
        }

        .info-text {
            margin-top: 20px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 10px;
            font-size: 12px;
            color: #666;
            text-align: center;
        }

        .required {
            color: #ff6b6b;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <h2> Create New Account</h2>
        
        <?php if ($error_message): ?>
            <div class="error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="text" name="reg_username" placeholder=" Username *" value="<?php echo isset($form_data['reg_username']) ? htmlspecialchars($form_data['reg_username']) : ''; ?>" required>
            <input type="email" name="reg_email" placeholder=" Email *" value="<?php echo isset($form_data['reg_email']) ? htmlspecialchars($form_data['reg_email']) : ''; ?>" required>
            <input type="text" name="name" placeholder=" First Name" value="<?php echo isset($form_data['name']) ? htmlspecialchars($form_data['name']) : ''; ?>">
            <input type="text" name="surname" placeholder=" Last Name" value="<?php echo isset($form_data['surname']) ? htmlspecialchars($form_data['surname']) : ''; ?>">
            <input type="password" name="reg_password" placeholder=" Password (min 6 chars) *" required>
            <input type="password" name="confirm_password" placeholder=" Confirm Password *" required>
            <textarea name="address" placeholder=" Address"><?php echo isset($form_data['address']) ? htmlspecialchars($form_data['address']) : ''; ?></textarea>
            <button type="submit" name="register">Register Account</button>
        </form>
        
        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
        
        <hr>
        
        <div class="info-text">
            <strong> Note:</strong> After registration, an administrator must verify your account before you can log in.
        </div>
    </div>
</body>
</html>