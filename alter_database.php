<?php
include "DBConn.php";

// Add verification_status column
$sql1 = "ALTER TABLE users ADD COLUMN verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending'";
if (mysqli_query($connect, $sql1)) {
    echo "✅ verification_status column added successfully<br>";
} else {
    echo "Error adding verification_status: " . mysqli_error($connect) . "<br>";
}

// Add user_role column
$sql2 = "ALTER TABLE users ADD COLUMN user_role ENUM('user', 'admin') DEFAULT 'user'";
if (mysqli_query($connect, $sql2)) {
    echo "✅ user_role column added successfully<br>";
} else {
    echo "Error adding user_role: " . mysqli_error($connect) . "<br>";
}

// Check if admin already exists
$check_sql = "SELECT user_id FROM users WHERE username = 'admin'";
$check_result = mysqli_query($connect, $check_sql);

if (mysqli_num_rows($check_result) == 0) {
    // Create admin user (password: admin123)
    $sql = "INSERT INTO users (username, email, password_hash, name, surname, user_role, verification_status) 
            VALUES ('admin', 'admin@store.com', 'e2fc714c4727ee9395f324cd2e7f331f', 'Store', 'Admin', 'admin', 'verified')";
    
    if (mysqli_query($connect, $sql)) {
        echo "<br>✅ Admin user created successfully!<br>";
        echo "Username: admin<br>";
        echo "Password: admin123<br>";
    } else {
        echo "<br>Error creating admin: " . mysqli_error($connect);
    }
} else {
    echo "<br>⚠️ Admin user already exists. No action taken.";
}

mysqli_close($connect);
?>