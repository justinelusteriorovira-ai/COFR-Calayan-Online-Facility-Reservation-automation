<?php
date_default_timezone_set('Asia/Manila');
$host = "localhost";
$db = "cefi_reservation";
$user = "root";
$pass = "admin";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>