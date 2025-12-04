<?php
session_start();
include "../config.php";
require '../vendor/autoload.php'; // PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check user role
if (!isset($_SESSION['email']) || $_SESSION['role'] != 'requester') {
    header("Location: ../login.php");
    exit();
}

$requester_email = $_SESSION['email'];

$error = $success = "";

// Fetch projects assigned to this requester
$projects = [];
$projectQuery = $conn->prepare("
    SELECT p.id, p.name 
    FROM projects p
    INNER JOIN project_assignments pa ON p.id = pa.project_id
    WHERE pa.requester_email = ?
    ORDER BY p.name ASC
");
$projectQuery->bind_param("s", $requester_email);
$projectQuery->execute();
$projectResult = $projectQuery->get_result();
while ($proj = $projectResult->fetch_assoc()) {
    $projects[] = $proj;
}

// Fetch managers
$managers = [];
$managerQuery = $conn->query("SELECT email FROM users WHERE role='manager' ORDER BY email ASC");
while ($mgr = $managerQuery->fetch_assoc()) {
    $managers[] = $mgr['email'];
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $project_id = intval($_POST['project_id'] ?? 0);
    $activity_id = intval($_POST['activity_id'] ?? 0);
    $sub_activity_id = intval($_POST['sub_activity_id'] ?? 0);
    $approver_email = $_POST['approver_email'] ?? '';
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $status = 'Pending';
    $created_at = date("Y-m-d H:i:s");

    // Validate project belongs to requester
    $stmtCheckProj = $conn->prepare("SELECT COUNT(*) as cnt FROM project_assignments WHERE project_id=? AND requester_email=?");
    $stmtCheckProj->bind_param("is", $project_id, $requester_email);
    $stmtCheckProj->execute();
    $countProj = $stmtCheckProj->get_result()->fetch_assoc()['cnt'] ?? 0;
    if ($countProj == 0) {
        $error = "Selected project is not assigned to you.";
    }

    // Validate activity belongs to project
    if (!$error) {
        $stmtCheckAct = $conn->prepare("SELECT COUNT(*) as cnt FROM activities WHERE id=? AND project_id=?");
        $stmtCheckAct->bind_param("ii", $activity_id, $project_id);
        $stmtCheckAct->execute();
        $countAct = $stmtCheckAct->get_result()->fetch_assoc()['cnt'] ?? 0;
        if ($countAct == 0) {
            $error = "Selected activity does not belong to the selected project.";
        }
    }

    // Validate sub-activity belongs to activity
    if (!$error) {
        $stmtCheckSub = $conn->prepare("SELECT COUNT(*) as cnt, budget FROM sub_activities WHERE id=? AND activity_id=?");
        $stmtCheckSub->bind_param("ii", $sub_activity_id, $activity_id);
        $stmtCheckSub->execute();
        $subActivity = $stmtCheckSub->get_result()->fetch_assoc();
        if (!$subActivity) {
            $error = "Selected sub-activity does not belong to the selected activity.";
        }
    }

    // Validate amount vs sub-activity budget
    if (!$error) {
        $stmtSpent = $conn->prepare("SELECT SUM(amount) as total_spent FROM requests WHERE sub_activity_id=? AND status='Approved'");
        $stmtSpent->bind_param("i", $sub_activity_id);
        $stmtSpent->execute();
        $spent = $stmtSpent->get_result()->fetch_assoc()['total_spent'] ?? 0;
        $remaining = $subActivity['budget'] - $spent;
        if ($amount > $remaining) {
            $error = "Insufficient funds. Remaining sub-activity budget: " . number_format($remaining, 2) . " MWK";
        }
    }

    // File uploads & insert
    if (!$error) {
        $uploadedFiles = [];
        if (!empty($_FILES['attachments']['name'][0])) {
            $files = $_FILES['attachments'];
            $uploadDir = "../uploads/requests/";
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0775, true);

            foreach ($files['name'] as $key => $name) {
                $tmpName = $files['tmp_name'][$key];
                $fileName = time() . "_" . basename($name);
                $targetPath = $uploadDir . $fileName;

                if (move_uploaded_file($tmpName, $targetPath)) {
                    $uploadedFiles[] = $fileName;
                }
            }
        }

        $attachmentJson = json_encode($uploadedFiles);

        $stmtInsert = $conn->prepare("INSERT INTO requests 
            (project_id, activity_id, sub_activity_id, requester_email, approver_email, amount, description, attachment, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtInsert->bind_param("iiissdssss", $project_id, $activity_id, $sub_activity_id, $requester_email, $approver_email, $amount, $description, $attachmentJson, $status, $created_at);

        if ($stmtInsert->execute()) {
            $success = "Request submitted successfully!";
            // Clear POST data so form is empty after submission
            $_POST = [];
        } else {
            $error = "Error submitting request: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Submit Fund Request</title>
<link href="../assets/css/bootstrap.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
</head>
<body class="bg-light">
<div class="container mt-5">
<h2 class="mb-4 text-center">Submit Fund Request</h2>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<form id="fundRequestForm" method="POST" enctype="multipart/form-data">
    <div class="mb-3">
        <label class="form-label">Project</label>
        <select name="project_id" id="project" class="form-select" required>
            <option value="">Select Project</option>
            <?php foreach ($projects as $proj): ?>
                <option value="<?= $proj['id'] ?>" <?= (isset($_POST['project_id']) && $_POST['project_id'] == $proj['id']) ? 'selected' : '' ?>><?= htmlspecialchars($proj['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">Activity</label>
        <select name="activity_id" id="activity" class="form-select" required>
            <option value="">Select Activity</option>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">Sub-Activity</label>
        <select name="sub_activity_id" id="sub_activity" class="form-select" required>
            <option value="">Select Sub-Activity</option>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">Approver (Manager)</label>
        <select name="approver_email" class="form-select" required>
            <option value="">Select Manager</option>
            <?php foreach ($managers as $mgr): ?>
                <option value="<?= $mgr ?>" <?= (isset($_POST['approver_email']) && $_POST['approver_email'] == $mgr) ? 'selected' : '' ?>><?= $mgr ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">Amount (MWK)</label>
        <input type="number" name="amount" class="form-control" step="0.01" value="<?= $_POST['amount'] ?? '' ?>" required>
    </div>

    <div class="mb-3">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3" placeholder="Reason for fund request..." required><?= $_POST['description'] ?? '' ?></textarea>
    </div>

    <div class="mb-3">
        <label class="form-label">Attachments</label>
        <input type="file" name="attachments[]" class="form-control" multiple>
        <small class="text-muted">You can select multiple files.</small>
    </div>

    <button type="submit" class="btn btn-primary w-100" id="submitBtn">Submit Request</button>
</form>
</div>

<script>
$(document).ready(function() {
    // Load activities dynamically
    $('#project').change(function() {
        var projectId = $(this).val();
        if (projectId) {
            $.get('get_activities.php', { project_id: projectId }, function(data) {
                $('#activity').html(data);
                $('#sub_activity').html('<option value="">Select Sub-Activity</option>');
            });
        } else {
            $('#activity').html('<option value="">Select Activity</option>');
            $('#sub_activity').html('<option value="">Select Sub-Activity</option>');
        }
    });

    // Load sub-activities dynamically
    $('#activity').change(function() {
        var activityId = $(this).val();
        if (activityId) {
            $.get('get_sub_activities.php', { activity_id: activityId }, function(data) {
                $('#sub_activity').html(data);
            });
        } else {
            $('#sub_activity').html('<option value="">Select Sub-Activity</option>');
        }
    });

    // Confirmation popup before submitting
    $('#fundRequestForm').on('submit', function(e) {
        e.preventDefault();
        let confirmed = confirm("Are you sure you want to submit this fund request?");
        if (confirmed) {
            $('#submitBtn').prop('disabled', true).text('Submitting...');
            this.submit();
        }
    });
});
</script>
<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
