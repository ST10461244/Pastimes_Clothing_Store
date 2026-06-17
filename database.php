<?php

$server_name = "localhost";
$username = "root";
$password = "";


$connect = new mysqli($server_name, $username, $password);

$query = mysqli_query($connect, "create database if not exists clothing_store");
$sql1 = "alter table users add column verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending'";    
$sql2 = "alter yable users add column user_role ENUM('user', 'admin') DEFAULT 'user'";

?>