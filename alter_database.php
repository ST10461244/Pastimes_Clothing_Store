<?php
include "DBConn.php";

// Add verification_status column
$sql1 = "ALTER TABLE users ADD COLUMN verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending'";
if (mysqli_query($connect, $sql1)) {
    echo "✅ verification_status column added successfully<br>";
} else {
    echo "Error adding verification_status: " . mysqli_error($connect) . "<br>";
}

// Add user_role column (legacy installs)
$sql2 = "ALTER TABLE users ADD COLUMN user_role ENUM('user', 'admin') DEFAULT 'user'";
if (mysqli_query($connect, $sql2)) {
    echo "✅ user_role column added successfully<br>";
} else {
    echo "Error adding user_role: " . mysqli_error($connect) . "<br>";
}

// Widen user_role to also support buyer/seller (existing installs may only have 'user'/'admin')
$role_col = mysqli_query($connect, "SHOW COLUMNS FROM users LIKE 'user_role'");
if ($role_col) {
    $role_col_info = mysqli_fetch_assoc($role_col);
    if (stripos($role_col_info['Type'], 'enum') !== false && stripos($role_col_info['Type'], 'buyer') === false) {
        if (mysqli_query($connect, "ALTER TABLE users MODIFY COLUMN user_role ENUM('user', 'admin', 'buyer', 'seller') DEFAULT 'buyer'")) {
            echo "✅ user_role enum widened to include buyer/seller<br>";
        } else {
            echo "Error widening user_role: " . mysqli_error($connect) . "<br>";
        }
    }
}

// Add seller_id column to clothes (if missing) so items can be linked to the seller who added them
$col_check3 = mysqli_query($connect, "SHOW COLUMNS FROM clothes LIKE 'seller_id'");
if ($col_check3 && mysqli_num_rows($col_check3) == 0) {
    if (mysqli_query($connect, "ALTER TABLE clothes ADD COLUMN seller_id INT DEFAULT NULL")) {
        echo "✅ seller_id column added to clothes<br>";
        mysqli_query($connect, "ALTER TABLE clothes ADD FOREIGN KEY (seller_id) REFERENCES users(user_id) ON DELETE SET NULL");
    } else {
        echo "Error adding seller_id: " . mysqli_error($connect) . "<br>";
    }
}

// Add image_url column to clothes (if missing)
$col_check = mysqli_query($connect, "SHOW COLUMNS FROM clothes LIKE 'image_url'");
if ($col_check && mysqli_num_rows($col_check) == 0) {
    if (mysqli_query($connect, "ALTER TABLE clothes ADD COLUMN image_url VARCHAR(500) DEFAULT NULL")) {
        echo "✅ image_url column added to clothes<br>";
    } else {
        echo "Error adding image_url: " . mysqli_error($connect) . "<br>";
    }
}

// Add created_at column to clothes (if missing)
$col_check2 = mysqli_query($connect, "SHOW COLUMNS FROM clothes LIKE 'created_at'");
if ($col_check2 && mysqli_num_rows($col_check2) == 0) {
    if (mysqli_query($connect, "ALTER TABLE clothes ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP")) {
        echo "✅ created_at column added to clothes<br>";
    } else {
        echo "Error adding created_at: " . mysqli_error($connect) . "<br>";
    }
}

// Ensure orders / order_lines tables exist (same schema cart.php creates on first checkout)
mysqli_query($connect, "CREATE TABLE IF NOT EXISTS orders (
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10,2),
    shipping_address TEXT,
    tracking_number VARCHAR(100),
    order_status ENUM('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    payment_status ENUM('pending','paid','failed') DEFAULT 'pending',
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE)");

mysqli_query($connect, "CREATE TABLE IF NOT EXISTS order_lines (
    line_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(200),
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE)");

// Add ratings table (if missing) — one rating per purchased line item
$ratings_check = mysqli_query($connect, "SHOW TABLES LIKE 'ratings'");
if ($ratings_check && mysqli_num_rows($ratings_check) == 0) {
    $ratings_sql = "CREATE TABLE ratings (
        rating_id INT AUTO_INCREMENT PRIMARY KEY,
        line_id INT NOT NULL,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        user_id INT NOT NULL,
        seller_id INT DEFAULT NULL,
        rating TINYINT NOT NULL,
        comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_line_rating (line_id),
        FOREIGN KEY (line_id) REFERENCES order_lines(line_id) ON DELETE CASCADE,
        FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES clothes(product_id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (seller_id) REFERENCES users(user_id) ON DELETE SET NULL
    )";
    if (mysqli_query($connect, $ratings_sql)) {
        echo "✅ ratings table created<br>";
    } else {
        echo "Error creating ratings table: " . mysqli_error($connect) . "<br>";
    }
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