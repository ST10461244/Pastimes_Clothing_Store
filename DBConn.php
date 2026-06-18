<?php
//declare variables for connection

$server_name = (string) "localhost";
$username = (string) "root";
$password = (string) "";
$database = (string) "clothing_store";
$port = (int) 3306;


$connect = mysqli_connect($server_name, $username, $password, $database, $port)
?>