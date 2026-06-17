<?php

include "DBConn.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try{
    $connect = new mysqli($server_name, $username, $password, $database);

    //drop the tables if they exist
    $query = mysqli_query($connect, "drop table if exists orders");
    $query = mysqli_query($connect, "drop table if exists clothes");
    $query = mysqli_query($connect, "drop table if exists admin");
    $query = mysqli_query($connect, "drop table if exists users");

    //create the tables 
    $users = "create table users (user_id int auto_increment primary key, username varchar(100) unique, email varchar(100), password_hash varchar(255), name varchar(100), surname varchar(100), address text, registration_date timestamp default current_timestamp, user_role varchar(250),  verification_status ENUM('pending', 'verified', 'rejected'));";
    mysqli_query($connect, $users);

    $admin = "create table admin (admin_id int auto_increment primary key, username varchar(50)  UNIQUE, email varchar(100) not null unique, password_hash varchar(255) not null, name varchar(100), surname varchar(100), role ENUM('super_admin', 'admin') default 'admin', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_login TIMESTAMP null)";
    mysqli_query($connect, $admin);

    $clothes_table = "create table clothes (product_id int auto_increment primary key, product_name varchar(200) not null, category varchar(50), subcategory varchar(50), price decimal(10, 2), on_sale BOOLEAN DEFAULT FALSE, sale_price DECIMAL(10, 2), description text, stock_quantity INT DEFAULT 0, image_url varchar(500), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)";
    mysqli_query($connect, $clothes_table);

    $orders_table = "create table orders (order_id int auto_increment primary key, user_id int not null, order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, total_amount decimal(10, 2), shipping_address text, tracking_number varchar(100), order_status ENUM('pending', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending', payment_status ENUM('pending', 'paid', 'failed') DEFAULT 'pending', FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE)";
    mysqli_query($connect, $orders_table);


    $check_admin = mysqli_query($connect, "select admin_id from admin where username = 'admin'");
    if (mysqli_num_rows($check_admin) == 0) {
        $insert_admin = "insert into admin (username, email, password_hash, name, surname, role) 
                         VALUES ('admin', 'admin@store.com', MD5('admin123'), 'Store', 'Admin', 'super_admin')";
        mysqli_query($connect, $insert_admin);
    } 

    $lines = file("userData.txt");
    $users = [];
    $current_user = [];
    $in_user_section = false;

    foreach($lines as $line){
        if(strpos($line, 'user') !==false && strpos($line, "---") !== false){
            if(!empty($current_user) && isset($current_user['email'])){
                $user[] = $current_user;
            }
            $current_user = [];
            $in_user_section = true;
            continue;
        }
        if($in_user_section && (strpos($line, 'admin') !==false || strpos($line, 'order') !==false || strpos($line, 'clothes') !==false)){
            $in_user_section = false;
            if(!empty($current_user) && isset($current_user['email'])){
                $users[] = $current_user;
            }
            $current_user = [];
            continue;
        }

        if($in_user_section && strpos($line, ':') !== false){
            $parts = explode(':', $line, 2);
            if(count($parts) == 2){
                $key = trim($parts[0]);
                $value = trim($parts[1]);

                if($key == 'Username') $current_user['username'] = $value;
                elseif($key == 'Email') $current_user['email'] = $value;
                elseif($key == 'Password') $current_user['password_hash'] = $value;
                elseif($key == 'First Name') $current_user['name'] = $value;
                elseif($key == 'Last Name') $current_user['surname'] = $value;
                elseif($key == 'Address') $current_user['address'] = $value;
            }
        }
    }
   foreach ($users as $user) {
    $username = $user['username'] ?? '';
    $email = $user['email'];
    $password_hash = $user['password_hash'] ?? '';
    $name = $user['name'] ?? '';
    $surname = $user['surname'] ?? '';
    $address = $user['address'] ?? '';
    
    $sql = "INSERT INTO Users (username, email, password_hash, name, surname, address) 
            VALUES ('$username', '$email', '$password_hash', '$name', '$surname', '$address')";
    
    if (mysqli_query($connect, $sql)) {
        echo "✓ Inserted: $name $surname ($email)<br>";
    } else {
        echo "✗ Failed: " . mysqli_error($connect) . "<br>";
    }
}

   
    $result = mysqli_query($connect, "select * from Users");
    
    echo "<table border='1' cellpadding='5'>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['user_id']}</td>";
        echo "<td>{$row['username']}</td>";
        echo "<td>{$row['email']}</td>";
        echo "<td>{$row['first_name']} {$row['last_name']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    mysqli_close($connect);
}
catch(Exception $e){
    die("Error: " . $e->getMessage());
}

?>