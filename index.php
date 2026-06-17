<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}
include "create_tables.php";
include "DBConn.php";
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clothing Store - Welcome</title>
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

        .welcome-container {
            background: white;
            padding: 50px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 500px;
            width: 100%;
        }

        h1 {
            color: #333;
            margin-bottom: 20px;
            font-size: 32px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 16px;
        }

        .button-group {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 20px;
        }

        .btn {
            padding: 12px 30px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: transform 0.2s ease;
        }

        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-register {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn:hover {
            transform: scale(1.05);
        }

        .btn-admin {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .store-icon {
            font-size: 60px;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <div class="welcome-container">
        <h1>Welcome to Pastimes</h1>
        <p class="subtitle">Your one-stop shop for fashion and style</p>

        <div class="button-group">
            <a href="login.php" class="btn btn-login">Login</a>
             <a href="admin_login.php" class="btn btn-admin">Admin Login</a>
            <a href="register.php" class="btn btn-register">Register</a>
        </div>
    </div>
</body>

</html>