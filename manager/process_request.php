<?php
include "../config.php";
session_start();

if (!isset($_SESSION['email']) || $_SESSION['role'] != 'manager') {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

$request_id = $_POST['request_id'] ?? null;
$action = $_POST['action'] ?? null;
$reason = $_POST['reason'] ?? '';

if (!$request_id || !$action) {
    echo json_encode(["success" => false, "message" => "Invalid data."]);
    exit();
}

$newStatus = ($action === 'approve') ? 'Approved' : 'Rejected';
$stmt = $conn->prepare("UPDATE requests SET status = ?, manager_comment = ? WHERE id = ?");
$stmt->bind_param("ssi", $newStatus, $reason, $request_id);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "id" => $request_id,
        "status" => $newStatus,
        "message" => "Request successfully $newStatus."
    ]);
} else {
    echo json_encode(["success" => false, "message" => "Database error."]);
}
?>
