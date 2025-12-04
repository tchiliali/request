<?php
session_start();
include "../config.php";

if (!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

// ==========================
// DELETE USER
// ==========================
if (isset($_GET['delete'])) {
    $deleteId = intval($_GET['delete']);

    // Fetch the user's email first
    $stmtEmail = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmtEmail->bind_param("i", $deleteId);
    $stmtEmail->execute();
    $resEmail = $stmtEmail->get_result();
    if ($resEmail->num_rows > 0) {
        $emailToDelete = $resEmail->fetch_assoc()['email'];

        // Delete project assignments
        $stmt1 = $conn->prepare("DELETE FROM project_assignments WHERE requester_email = ?");
        $stmt1->bind_param("s", $emailToDelete);
        $stmt1->execute();

        // Delete user
        $stmt2 = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt2->bind_param("i", $deleteId);
        $stmt2->execute();
    }

    header("Location: users_list.php");
    exit();
}

// ==========================
// FETCH USERS AND ASSIGNED PROJECTS
// ==========================
$users = [];
$result = $conn->query("SELECT * FROM users ORDER BY id ASC");

while ($user = $result->fetch_assoc()) {
    $user['projects'] = [];

    // Fetch assigned projects
    $stmt = $conn->prepare("
        SELECT p.name 
        FROM projects p 
        JOIN project_assignments pa ON pa.project_id = p.id
        WHERE pa.requester_email = ?
    ");
    $stmt->bind_param("s", $user['email']);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($proj = $res->fetch_assoc()) {
        $user['projects'][] = $proj['name'];
    }

    $users[] = $user;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Users List - Admin</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">

    <h2 class="mb-4 text-center">Users List</h2>

    <div class="mb-3 text-center">
        <a href="dashboard.php" class="btn btn-secondary me-2">Back to Dashboard</a>
        <a href="dashboard.php" class="btn btn-primary">Create New User</a>
    </div>

    <?php if (empty($users)): ?>
        <div class="alert alert-warning">No users found.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Assigned Projects</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $index => $user): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars($user['name']) ?></td>
                            <td><?= htmlspecialchars($user['email']) ?></td>
                            <td><?= ucfirst($user['role']) ?></td>
                            <td>
                                <?php
                                if (!empty($user['projects'])) {
                                    echo implode(", ", $user['projects']);
                                } else {
                                    echo "<span class='text-muted'>No projects assigned</span>";
                                }
                                ?>
                            </td>
                            <td>
                                <a href="edit_user.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-warning mb-1">Edit</a>
                                <a href="users_list.php?delete=<?= $user['id'] ?>" class="btn btn-sm btn-danger mb-1"
                                   onclick="return confirm('Are you sure you want to delete this user? This will also remove assigned projects.')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
