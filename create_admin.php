<?php
include "config.php";

$email = "admin@habitatt.mw";
$password = "12345";
$role = "admin";

$hashed = password_hash($password, PASSWORD_DEFAULT);

$sql = "INSERT INTO users (email, password, role) VALUES (?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $email, $hashed, $role);

if ($stmt->execute()) {
    echo "✅ Admin user created successfully!<br>";
    echo "Email: $email<br>Password: $password";
} else {
    echo "❌ Error: " . $stmt->error;
}
