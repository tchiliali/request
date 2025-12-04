<?php
session_start();
include "../config.php";
include "../includes/auth_check.php";

require "../vendor/autoload.php";
use PhpOffice\PhpSpreadsheet\IOFactory;

// Only admin can access
if ($_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = "";

if (isset($_POST['upload'])) {
    $file = $_FILES['excel_file']['tmp_name'];

    if ($file) {
        try {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            $count = 0;
            foreach ($rows as $index => $row) {
                if ($index == 0) continue; // Skip header row

                $email = trim($row[0]);
                $password = trim($row[1]);
                $role = trim(strtolower($row[2]));

                if (!empty($email) && !empty($password) && !empty($role)) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);

                    $sql = "INSERT INTO users (email, password, role) VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sss", $email, $hashed, $role);

                    if ($stmt->execute()) {
                        $count++;
                    }
                }
            }

            $message = "<div class='alert alert-success'>Successfully uploaded $count users.</div>";
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
        }
    } else {
        $message = "<div class='alert alert-warning'>Please choose a file to upload.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload Users - Fund Request System</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <a href="dashboard.php" class="btn btn-secondary mb-3">← Back to Dashboard</a>
    <div class="card shadow-sm">
        <div class="card-body">
            <h4>Upload Users (Excel File)</h4>
            <?php echo $message; ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label>Select Excel File (.xlsx or .xls)</label>
                    <input type="file" name="excel_file" class="form-control" accept=".xlsx, .xls" required>
                </div>
                <button type="submit" name="upload" class="btn btn-primary">Upload</button>
            </form>
            <p class="mt-3 text-muted">
                <strong>Excel format:</strong><br>
                email | password | role
            </p>
        </div>
    </div>
</div>
<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
