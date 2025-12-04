<?php
session_start();
include "../config.php";

// Manager access check
if (!isset($_SESSION['email']) || $_SESSION['role'] != 'manager') {
    header("Location: ../login.php");
    exit();
}

$managerEmail = $_SESSION['email'];
$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $projectId = $_POST['project_id'];
    $activityId = $_POST['activity_id'];
    $amount = $_POST['amount'];
    
    // Handle attachment
    $attachmentName = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $uploadDir = '../uploads/requests/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        $attachmentName = time() . '_' . basename($_FILES['attachment']['name']);
        move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $attachmentName);
    }

    // Insert into database
    $stmt = $conn->prepare("INSERT INTO fund_requests (project_id, activity_id, requester_email, amount, attachment, status, created_at) VALUES (?,?,?,?,?, 'Pending', NOW())");
    $stmt->bind_param("iisd", $projectId, $activityId, $managerEmail, $amount, $attachmentName);

    if ($stmt->execute()) {
        $success = "Fund request submitted successfully!";
    } else {
        $error = "Error submitting request: " . $stmt->error;
    }
}

// Fetch projects for dropdown
$projects = $conn->query("SELECT * FROM projects ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Fund Request</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-light">

<div class="container mt-5">
    <h2 class="mb-4 text-center">Create Fund Request</h2>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label for="project" class="form-label">Project</label>
                    <select name="project_id" id="project" class="form-select" required>
                        <option value="">-- Select Project --</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?= $proj['id'] ?>"><?= htmlspecialchars($proj['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="activity" class="form-label">Activity</label>
                    <select name="activity_id" id="activity" class="form-select" required>
                        <option value="">-- Select Activity --</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="amount" class="form-label">Amount (MWK)</label>
                    <input type="number" name="amount" id="amount" class="form-control" step="0.01" required>
                </div>

                <div class="mb-3">
                    <label for="attachment" class="form-label">Attachment (Optional)</label>
                    <input type="file" name="attachment" id="attachment" class="form-control">
                </div>

                <div class="text-center">
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                    <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Fetch activities when project changes
$('#project').change(function() {
    let projectId = $(this).val();
    $('#activity').html('<option value="">Loading...</option>');
    if (projectId) {
        $.get('get_activities.php', {project_id: projectId}, function(data) {
            $('#activity').html(data);
        });
    } else {
        $('#activity').html('<option value="">-- Select Activity --</option>');
    }
});
</script>

</body>
</html>
