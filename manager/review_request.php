<?php
session_start();
include "../config.php";

// ------------------------------
// Manager access check
// ------------------------------
if (!isset($_SESSION['email']) || $_SESSION['role'] != 'manager') {
    header("Location: ../login.php");
    exit();
}

// Handle Approve/Reject actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $requestId = (int)$_POST['request_id'];
    $action = $_POST['action'];

    if ($action == 'approve' || $action == 'reject') {
        $stmt = $conn->prepare("UPDATE fund_requests SET status=? WHERE id=? AND approver_email=?");
        $stmt->bind_param("sis", $action, $requestId, $_SESSION['email']);
        if($stmt->execute()) {
            $_SESSION['flash_success'] = "Request #$requestId has been $action.";
        } else {
            $_SESSION['flash_error'] = "Failed to update request #$requestId.";
        }
        header("Location: review_request.php");
        exit();
    }
}

// Fetch requests assigned to this manager
$stmt = $conn->prepare("SELECT fr.*, p.name AS project_name, a.name AS activity_name 
                        FROM fund_requests fr
                        LEFT JOIN projects p ON fr.project_id=p.id
                        LEFT JOIN activities a ON fr.activity_id=a.id
                        WHERE fr.approver_email=? 
                        ORDER BY fr.created_at DESC");
$stmt->bind_param("s", $_SESSION['email']);
$stmt->execute();
$result = $stmt->get_result();
$requests = $result->fetch_all(MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Review Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <h2 class="text-center mb-4">Review Fund Requests</h2>

    <!-- Flash Messages -->
    <?php if(isset($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?></div>
    <?php endif; ?>
    <?php if(isset($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger"><?php echo $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>

    <?php if(!empty($requests)): ?>
        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Project</th>
                        <th>Activity</th>
                        <th>Requester</th>
                        <th>Amount (MWK)</th>
                        <th>Status</th>
                        <th>Attachment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($requests as $idx => $req): ?>
                        <tr>
                            <td><?php echo $idx+1; ?></td>
                            <td><?php echo htmlspecialchars($req['project_name']); ?></td>
                            <td><?php echo htmlspecialchars($req['activity_name']); ?></td>
                            <td><?php echo htmlspecialchars($req['requester_email']); ?></td>
                            <td><?php echo number_format($req['amount'],2); ?></td>
                            <td>
                                <span class="badge <?php 
                                    echo $req['status']=='approved' ? 'bg-success' : 
                                         ($req['status']=='rejected' ? 'bg-danger':'bg-warning'); ?>">
                                    <?php echo ucfirst($req['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if($req['attachment']): ?>
                                    <a href="../uploads/requests/<?php echo $req['attachment']; ?>" target="_blank">View</a>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($req['status']=='pending'): ?>
                                    <form method="POST" class="d-flex gap-1">
                                        <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                        <button name="action" value="approve" class="btn btn-sm btn-success">Approve</button>
                                        <button name="action" value="reject" class="btn btn-sm btn-danger">Reject</button>
                                    </form>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="text-center">No requests assigned to you yet.</p>
    <?php endif; ?>

    <div class="text-center mt-3">
        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
