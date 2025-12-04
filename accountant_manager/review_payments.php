<?php
session_start();
require_once "../config.php";

if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'accountant_manager') {
    header("Location: ../login.php");
    exit();
}

// Approve for payment
if (isset($_GET['approve_id'])) {
    $id = intval($_GET['approve_id']);
    $stmt = $conn->prepare("UPDATE requests SET status='approve_for_payment' WHERE id=? AND status='approved'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: review_payments.php"); // Redirect to avoid resubmission
    exit();
}

// Fetch only requests that are approved by manager
$result = $conn->query("SELECT * FROM requests WHERE status='approved' ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Review Payments</title>
<link href="../assets/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <h3>Review Requests & Payments</h3>
    <table class="table table-striped mt-3">
        <thead>
            <tr>
                <th>ID</th>
                <th>Requester</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Payment Method</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($result->num_rows > 0): ?>
            <?php while($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= $row['id'] ?></td>
                    <td><?= htmlspecialchars($row['requester_email']) ?></td>
                    <td><?= number_format($row['amount'], 2) ?></td>
                    <td><?= ucfirst($row['status']) ?></td>
                    <td><?= $row['payment_method'] ?? '-' ?></td>
                    <td>
                        <a href="?approve_id=<?= $row['id'] ?>" class="btn btn-sm btn-warning">Approve for Payment</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="6" class="text-center text-muted">No requests pending your approval.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
    <a href="dashboard.php" class="btn btn-secondary mt-2">Back to Dashboard</a>
</div>
<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
