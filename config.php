<?php
$host = "localhost";     // your MySQL host
$user = "root";          // your MySQL username
$pass = "";              // your MySQL password
$dbname = "fund_request_system";  // database name

$conn = new mysqli($host, $user, $pass, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
