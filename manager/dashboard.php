<?php
session_start();
include "../config.php";

// Ensure manager is logged in
if (!isset($_SESSION['email']) || $_SESSION['role'] != 'manager') {
    header("Location: ../login.php");
    exit();
}

$manager_email = $_SESSION['email'];
$success = $error = "";

// Handle approve/reject with comment
if (isset($_POST['action'], $_POST['request_id'])) {
    $request_id = (int) $_POST['request_id'];
    $action = $_POST['action'];
    $comment = trim($_POST['comment']);

    $newStatus = ($action === 'approve') ? 'Approved' : 'Rejected';

    $stmt = $conn->prepare("UPDATE requests SET status = ?, manager_comment = ? WHERE id = ?");
    $stmt->bind_param("ssi", $newStatus, $comment, $request_id);

    if ($stmt->execute()) {
        $success = "Request has been $newStatus successfully.";
    } else {
        $error = "Failed to update request status: " . $conn->error;
    }
}

// Fetch requests by status
function getRequestsByStatus($conn, $manager_email, $status) {
    $stmt = $conn->prepare("
        SELECT r.*, p.name AS project_name, a.name AS activity_name 
        FROM requests r
        JOIN projects p ON r.project_id = p.id
        JOIN activities a ON r.activity_id = a.id
        WHERE r.approver_email = ? AND r.status = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->bind_param("ss", $manager_email, $status);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$pendingRequests = getRequestsByStatus($conn, $manager_email, 'Pending');
$approvedRequests = getRequestsByStatus($conn, $manager_email, 'Approved');
$rejectedRequests = getRequestsByStatus($conn, $manager_email, 'Rejected');

// Count requests
$pendingCount = count($pendingRequests);
$approvedCount = count($approvedRequests);
$rejectedCount = count($rejectedRequests);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manager Dashboard - Fund Request System</title>
<link href="../assets/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background-color: #f8f9fa; }
.table td, .table th { vertical-align: middle; }
textarea.form-control { resize: none; height: 60px; }
.card-section { margin-bottom: 40px; }
.card-section h5 { margin-bottom: 15px; }
.dashboard-cards { display: flex; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; }
.card-summary { flex: 1; margin: 5px; border-radius: 12px; text-align: center; color: #fff; padding: 20px; cursor:pointer; transition: transform 0.2s; }
.card-summary:hover { transform: scale(1.05); }
.card-summary h3 { font-size: 1.8rem; margin: 0; }
.pending { background-color: #ffc107; color: #212529; }
.approved { background-color: #28a745; }
.rejected { background-color: #dc3545; }
</style>
</head>
<body>
<div class="container mt-4">
<h2 class="text-center mb-4">Manager Dashboard</h2>

<!-- Alerts -->
<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php elseif ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="dashboard-cards">
<div class="card-summary pending" onclick="scrollToTable('pendingTable')">
<h3><?= $pendingCount ?></h3>
<p>Pending Requests</p>
</div>
<div class="card-summary approved" onclick="scrollToTable('approvedTable')">
<h3><?= $approvedCount ?></h3>
<p>Approved Requests</p>
</div>
<div class="card-summary rejected" onclick="scrollToTable('rejectedTable')">
<h3><?= $rejectedCount ?></h3>
<p>Rejected Requests</p>
</div>
</div>

<!-- Pending Requests Table -->
<div class="card-section" id="pendingTable">
<h5 class="text-warning">Pending Requests</h5>
<div class="card shadow-sm">
<div class="card-body">
<table class="table table-hover align-middle">
<thead class="table-dark">
<tr>
<th>#</th>
<th>Project</th>
<th>Activity</th>
<th>Requester</th>
<th>Amount (MWK)</th>
<th>Attachments</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php if (!empty($pendingRequests)): ?>
<?php $i = 1; foreach ($pendingRequests as $row): ?>
<tr>
<td><?= $i++ ?></td>
<td><?= htmlspecialchars($row['project_name']) ?></td>
<td><?= htmlspecialchars($row['activity_name']) ?></td>
<td><?= htmlspecialchars($row['requester_email']) ?></td>
<td><?= number_format($row['amount'], 2) ?></td>
<td>
<?php
$files = [];
if (!empty($row['attachment'])) {
    $decoded = json_decode($row['attachment'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $files = $decoded;
}
if (!empty($files)) {
    foreach ($files as $file) {
        echo "<a href='../uploads/requests/" . htmlspecialchars($file) . "' target='_blank'>$file</a><br>";
    }
} else { echo "<small>No file</small>"; }
?>
</td>
<td>
<form method="POST">
<input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
<textarea name="comment" class="form-control mb-1" placeholder="Add comment..." required></textarea>
<div class="d-flex gap-1">
<button type="submit" name="action" value="approve" class="btn btn-success btn-sm w-50">Approve</button>
<button type="submit" name="action" value="reject" class="btn btn-danger btn-sm w-50">Reject</button>
</div>
</form>
</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="7" class="text-center text-muted">No pending requests.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>

<!-- Approved Requests Table -->
<div class="card-section" id="approvedTable">
<h5 class="text-success">Approved Requests</h5>
<div class="card shadow-sm">
<div class="card-body">
<table class="table table-hover align-middle">
<thead class="table-dark">
<tr>
<th>#</th>
<th>Project</th>
<th>Activity</th>
<th>Requester</th>
<th>Amount (MWK)</th>
<th>Attachments</th>
<th>Manager Comment</th>
</tr>
</thead>
<tbody>
<?php if (!empty($approvedRequests)): ?>
<?php $i = 1; foreach ($approvedRequests as $row): ?>
<tr>
<td><?= $i++ ?></td>
<td><?= htmlspecialchars($row['project_name']) ?></td>
<td><?= htmlspecialchars($row['activity_name']) ?></td>
<td><?= htmlspecialchars($row['requester_email']) ?></td>
<td><?= number_format($row['amount'], 2) ?></td>
<td>
<?php
$files = [];
if (!empty($row['attachment'])) {
    $decoded = json_decode($row['attachment'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $files = $decoded;
}
if (!empty($files)) {
    foreach ($files as $file) {
        echo "<a href='../uploads/requests/" . htmlspecialchars($file) . "' target='_blank'>$file</a><br>";
    }
} else { echo "<small>No file</small>"; }
?>
</td>
<td><?= htmlspecialchars($row['manager_comment'] ?? '-') ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="7" class="text-center text-muted">No approved requests.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>

<!-- Rejected Requests Table -->
<div class="card-section" id="rejectedTable">
<h5 class="text-danger">Rejected Requests</h5>
<div class="card shadow-sm">
<div class="card-body">
<table class="table table-hover align-middle">
<thead class="table-dark">
<tr>
<th>#</th>
<th>Project</th>
<th>Activity</th>
<th>Requester</th>
<th>Amount (MWK)</th>
<th>Attachments</th>
<th>Manager Comment</th>
</tr>
</thead>
<tbody>
<?php if (!empty($rejectedRequests)): ?>
<?php $i = 1; foreach ($rejectedRequests as $row): ?>
<tr>
<td><?= $i++ ?></td>
<td><?= htmlspecialchars($row['project_name']) ?></td>
<td><?= htmlspecialchars($row['activity_name']) ?></td>
<td><?= htmlspecialchars($row['requester_email']) ?></td>
<td><?= number_format($row['amount'], 2) ?></td>
<td>
<?php
$files = [];
if (!empty($row['attachment'])) {
    $decoded = json_decode($row['attachment'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $files = $decoded;
}
if (!empty($files)) {
    foreach ($files as $file) {
        echo "<a href='../uploads/requests/" . htmlspecialchars($file) . "' target='_blank'>$file</a><br>";
    }
} else { echo "<small>No file</small>"; }
?>
</td>
<td><?= htmlspecialchars($row['manager_comment'] ?? '-') ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="7" class="text-center text-muted">No rejected requests.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>

</div> <!-- End container -->

<script src="../assets/js/bootstrap.bundle.min.js"></script>
<script>
// Scroll to table on card click
function scrollToTable(tableId) {
    const element = document.getElementById(tableId);
    if(element){
        element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
</script>
</body>
</html>
