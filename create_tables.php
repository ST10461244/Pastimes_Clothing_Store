<?php
include "DBConn.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $connect = new mysqli($server_name, $username, $password, $database);

    // 1. Users table
    $users = "CREATE TABLE IF NOT EXISTS users (
        user_id INT AUTO_INCREMENT PRIMARY KEY, 
        username VARCHAR(100) UNIQUE, 
        email VARCHAR(100), 
        password_hash VARCHAR(255), 
        name VARCHAR(100), 
        surname VARCHAR(100), 
        address TEXT, 
        registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
        user_role VARCHAR(250) DEFAULT 'user',  
        verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending'
    );";
    mysqli_query($connect, $users);

    // 2. Admin table
    $admin = "CREATE TABLE IF NOT EXISTS admin (
        admin_id INT AUTO_INCREMENT PRIMARY KEY, 
        username VARCHAR(50) UNIQUE, 
        email VARCHAR(100) NOT NULL UNIQUE, 
        password_hash VARCHAR(255) NOT NULL, 
        name VARCHAR(100), 
        surname VARCHAR(100), 
        role ENUM('super_admin', 'admin') DEFAULT 'admin', 
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
        last_login TIMESTAMP NULL
    );";
    mysqli_query($connect, $admin);

    // 3. Clothes table
    $clothes_table = "CREATE TABLE IF NOT EXISTS clothes (
        product_id INT AUTO_INCREMENT PRIMARY KEY, 
        product_name VARCHAR(200) NOT NULL, 
        category VARCHAR(50), 
        subcategory VARCHAR(50), 
        price DECIMAL(10, 2), 
        on_sale BOOLEAN DEFAULT FALSE, 
        sale_price DECIMAL(10, 2) DEFAULT NULL, 
        stock_quantity INT DEFAULT 0, 
        description TEXT,
        image_url VARCHAR(500) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        seller_id INT DEFAULT NULL,
        FOREIGN KEY (seller_id) REFERENCES users(user_id) ON DELETE SET NULL
    );";
    mysqli_query($connect, $clothes_table);

    // 4. Base Cart Table
    $cart_table = "CREATE TABLE IF NOT EXISTS cart (
        cart_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES clothes(product_id) ON DELETE CASCADE,
        UNIQUE KEY unique_cart_item (user_id, product_id)
    );";
    mysqli_query($connect, $cart_table);

    // 5. Orders master table
    $orders_table = "CREATE TABLE IF NOT EXISTS orders (
        order_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        order_num VARCHAR(100) NOT NULL,
        session_id VARCHAR(255) NOT NULL,
        total_amount DECIMAL(10, 2) NOT NULL,
        order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    );";
    mysqli_query($connect, $orders_table);

    // 6. Order Line item details table
    $order_line_table = "CREATE TABLE IF NOT EXISTS orderline (
        line_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        price_at_purchase DECIMAL(10, 2) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES clothes(product_id) ON DELETE CASCADE
    );";
    mysqli_query($connect, $order_line_table);

    

} catch (Exception $e) {
    echo "Database setup notice: " . $e->getMessage() . "<br>";
}
?>