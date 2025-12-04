<?php
session_start();
include "../config.php"; // Include database connection

// ------------------------------
// Step 0: Only admin can access
// ------------------------------
if (!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

// ------------------------------
// Step 1: Load PHPSpreadsheet
// ------------------------------
require '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$message = ''; // Message to display after upload

// ------------------------------
// Step 2: Handle form submission
// ------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['budget_file'])) {

    // Get the uploaded file
    $file = $_FILES['budget_file']['tmp_name'];

    // Load the Excel file
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();

    // Convert sheet to array
    $rows = $sheet->toArray();

    $countProjects = 0;
    $countActivities = 0;
    $countSubActivities = 0;

    // Loop through each row
    foreach ($rows as $index => $row) {

        // Skip header row
        if ($index == 0) continue;

        // Get values from Excel columns
        $projectName = trim($row[0]);         // Column A: Project Name
        $activityName = trim($row[1]);        // Column B: Activity Name
        $subActivityName = trim($row[2]);     // Column C: Sub-Activity Name
        $subActivityBudget = floatval($row[3]); // Column D: Sub-Activity Budget

        // Skip invalid rows
        if (!$projectName || !$activityName || !$subActivityName || $subActivityBudget <= 0) continue;

        // ------------------------------
        // Step 3: Check or create project
        // ------------------------------
        $stmt = $conn->prepare("SELECT id FROM projects WHERE name=?");
        $stmt->bind_param("s", $projectName);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $project = $res->fetch_assoc();
            $projectId = $project['id'];
        } else {
            $stmt2 = $conn->prepare("INSERT INTO projects (name, total_budget) VALUES (?, 0)");
            $stmt2->bind_param("s", $projectName);
            $stmt2->execute();
            $projectId = $stmt2->insert_id;
            $countProjects++;
        }

        // ------------------------------
        // Step 4: Check or create activity
        // ------------------------------
        $stmt3 = $conn->prepare("SELECT id FROM activities WHERE name=? AND project_id=?");
        $stmt3->bind_param("si", $activityName, $projectId);
        $stmt3->execute();
        $res3 = $stmt3->get_result();

        if ($res3->num_rows > 0) {
            $activity = $res3->fetch_assoc();
            $activityId = $activity['id'];
        } else {
            $stmt4 = $conn->prepare("INSERT INTO activities (project_id, name, budget) VALUES (?, ?, 0)");
            $stmt4->bind_param("is", $projectId, $activityName);
            $stmt4->execute();
            $activityId = $stmt4->insert_id;
            $countActivities++;
        }

        // ------------------------------
        // Step 5: Insert sub-activity
        // ------------------------------
        $stmt5 = $conn->prepare("INSERT INTO sub_activities (activity_id, name, budget) VALUES (?, ?, ?)");
        $stmt5->bind_param("isd", $activityId, $subActivityName, $subActivityBudget);
        $stmt5->execute();
        $countSubActivities++;

        // ------------------------------
        // Step 6: Update totals
        // ------------------------------
        // Update activity total budget
        $stmt6 = $conn->prepare("
            UPDATE activities 
            SET budget = (SELECT SUM(budget) FROM sub_activities WHERE activity_id = ?) 
            WHERE id = ?
        ");
        $stmt6->bind_param("ii", $activityId, $activityId);
        $stmt6->execute();

        // Update project total budget
        $stmt7 = $conn->prepare("
            UPDATE projects 
            SET total_budget = (SELECT SUM(budget) FROM activities WHERE project_id = ?) 
            WHERE id = ?
        ");
        $stmt7->bind_param("ii", $projectId, $projectId);
        $stmt7->execute();
    }

    $message = "✅ Uploaded $countProjects new projects, $countActivities new activities, and $countSubActivities sub-activities successfully.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload Project Budgets - Admin</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="card shadow">
        <div class="card-body">
            <h4 class="mb-3">Upload Project, Activity & Sub-Activity Budgets</h4>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label>Choose Excel File</label>
                    <input type="file" name="budget_file" class="form-control" required>
                    <small class="text-muted">Excel format: Project Name | Activity Name | Sub-Activity Name | Sub-Activity Budget</small>
                </div>
                <button class="btn btn-primary">Upload Budgets</button>
            </form>

            <a href="dashboard.php" class="btn btn-secondary mt-3">Back to Dashboard</a>
        </div>
    </div>
</div>

<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
