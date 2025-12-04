<?php
session_start();
include "../config.php";

if (!isset($_SESSION['email']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

// ==========================
// HANDLE NEW USER CREATION
// ==========================
$message = "";
$valid_roles = ['requester', 'manager', 'admin', 'accountant', 'accountant_manager'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $role = $_POST['role'];
    $project_ids = $_POST['project_ids'] ?? [];

    if (!in_array($role, $valid_roles)) {
        $message = "<div class='alert alert-danger'>Invalid role selected.</div>";
    } else {
        // Check if user already exists
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $message = "<div class='alert alert-danger'>User with this email already exists.</div>";
        } else {
            // Insert user without project_id
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $name, $email, $password, $role);

            if ($stmt->execute()) {
                $userId = $stmt->insert_id;

                // Assign selected projects to user in project_assignments
                if (!empty($project_ids)) {
                    $assignStmt = $conn->prepare("INSERT INTO project_assignments (project_id, requester_email) VALUES (?, ?)");
                    foreach ($project_ids as $pid) {
                        $pid = intval($pid);
                        $assignStmt->bind_param("is", $pid, $email);
                        $assignStmt->execute();
                    }
                }

                $message = "<div class='alert alert-success'>User created successfully!</div>";
            } else {
                $message = "<div class='alert alert-danger'>Failed to create user. Please try again.</div>";
            }
        }
    }
}

// ==========================
// DASHBOARD SUMMARY STATS
// ==========================
$projectCount = $conn->query("SELECT COUNT(*) AS total FROM projects")->fetch_assoc()['total'];
$activityCount = $conn->query("SELECT COUNT(*) AS total FROM activities")->fetch_assoc()['total'];
$totalBudgetRow = $conn->query("SELECT SUM(total_budget) AS total FROM projects")->fetch_assoc();
$totalBudget = $totalBudgetRow['total'] ?? 0;

// ==========================
// FETCH PROJECTS & ACTIVITIES
// ==========================
$projects = [];
$projectNames = [];
$projectBudgets = [];
$projectQuery = $conn->query("SELECT * FROM projects ORDER BY id ASC");

while ($proj = $projectQuery->fetch_assoc()) {
    $projectId = $proj['id'];
    $projectNames[] = $proj['name'];
    $projectBudgets[] = $proj['total_budget'];
    $proj['activities'] = [];

    $stmt = $conn->prepare("SELECT * FROM activities WHERE project_id=? ORDER BY id ASC");
    $stmt->bind_param("i", $projectId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($act = $result->fetch_assoc()) {
        $actId = $act['id'];
        $act['sub_activities'] = [];

        $stmt2 = $conn->prepare("SELECT * FROM sub_activities WHERE activity_id=? ORDER BY id ASC");
        $stmt2->bind_param("i", $actId);
        $stmt2->execute();
        $result2 = $stmt2->get_result();

        while ($sub = $result2->fetch_assoc()) {
            $act['sub_activities'][] = $sub;
        }

        $proj['activities'][] = $act;
    }
    $projects[] = $proj;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-light">

<div class="container mt-5">
    <h2 class="mb-4 text-center">Admin Dashboard</h2>

    <?= $message ?>

    <!-- SUMMARY CARDS -->
    <div class="row mb-4 text-center">
        <div class="col-md-4 mb-3">
            <div class="card text-white bg-primary shadow">
                <div class="card-body">
                    <h3><?php echo $projectCount; ?></h3>
                    <p>Total Projects</p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card text-white bg-success shadow">
                <div class="card-body">
                    <h3><?php echo $activityCount; ?></h3>
                    <p>Total Activities</p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card text-white bg-warning shadow">
                <div class="card-body">
                    <h3>MWK <?php echo number_format($totalBudget, 2); ?></h3>
                    <p>Total Budget</p>
                </div>
            </div>
        </div>
    </div>

    <!-- CREATE USER FORM -->
    <div class="card shadow mb-5">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">Create New User</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" required>
                            <option value="">-- Select Role --</option>
                            <option value="requester">Requester</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                            <option value="accountant">Accountant</option>
                            <option value="accountant_manager">Accountant Manager</option> <!-- NEW ROLE -->
                        </select>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Assign to Projects</label>
                        <select name="project_ids[]" class="form-select" multiple size="4">
                            <?php foreach ($projects as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Hold CTRL (or CMD on Mac) to select multiple projects.</small>
                    </div>

                    <div class="col-md-12 mt-3 text-center">
                        <button type="submit" name="create_user" class="btn btn-primary px-4">Create User</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- BUTTONS -->
    <div class="mb-4 text-center">
        <a href="users_list.php" class="btn btn-secondary me-2 mb-2">View Users</a>
        <a href="upload_budgets.php" class="btn btn-success me-2 mb-2">Upload Project Budgets</a>
        <a href="../logout.php" class="btn btn-danger mb-2">Logout</a>
    </div>

    <!-- CHARTS -->
    <div class="row mb-5">
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">Project Budget Overview (Bar Chart)</div>
                <div class="card-body" style="height:300px;">
                    <canvas id="budgetChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white">Project Budget Share (%)</div>
                <div class="card-body" style="height:300px;">
                    <canvas id="pieChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- VIEW UPLOADED BUDGETS -->
    <div class="card shadow mb-5">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">View Uploaded Budgets</h5>
        </div>
        <div class="card-body">
            <?php if (empty($projects)): ?>
                <div class="alert alert-warning">No projects uploaded yet.</div>
            <?php else: ?>
                <div class="accordion" id="budgetAccordion">
                    <?php foreach ($projects as $proj): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="heading<?= $proj['id'] ?>">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $proj['id'] ?>">
                                    <strong><?= htmlspecialchars($proj['name']) ?></strong> — Total Budget: MWK <?= number_format($proj['total_budget'], 2) ?>
                                </button>
                            </h2>
                            <div id="collapse<?= $proj['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#budgetAccordion">
                                <div class="accordion-body">
                                    <?php if (empty($proj['activities'])): ?>
                                        <p class="text-muted">No activities found.</p>
                                    <?php else: ?>
                                        <?php foreach ($proj['activities'] as $act): ?>
                                            <div class="border p-3 mb-3 rounded">
                                                <strong><?= htmlspecialchars($act['name']) ?></strong> — Budget: MWK <?= number_format($act['budget'], 2) ?>
                                                <?php if (!empty($act['sub_activities'])): ?>
                                                    <ul class="mt-2">
                                                        <?php foreach ($act['sub_activities'] as $sub): ?>
                                                            <li><?= htmlspecialchars($sub['name']) ?> — MWK <?= number_format($sub['budget'], 2) ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="../assets/js/bootstrap.bundle.min.js"></script>

<script>
const projectNames = <?php echo json_encode($projectNames); ?>;
const projectBudgets = <?php echo json_encode($projectBudgets); ?>;

new Chart(document.getElementById('budgetChart'), {
    type: 'bar',
    data: {
        labels: projectNames,
        datasets: [{
            label: 'Project Budgets (MWK)',
            data: projectBudgets,
            backgroundColor: 'rgba(54,162,235,0.7)',
            borderColor: 'rgba(54,162,235,1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true } },
        plugins: { legend: { display: false } }
    }
});

new Chart(document.getElementById('pieChart'), {
    type: 'pie',
    data: {
        labels: projectNames,
        datasets: [{
            data: projectBudgets,
            backgroundColor: ['#FF6384','#36A2EB','#FFCE56','#4BC0C0','#9966FF','#FF9F40'],
            borderColor: '#fff',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let value = context.parsed;
                        let total = projectBudgets.reduce((a,b)=>a+b,0);
                        let percent = ((value/total)*100).toFixed(2);
                        return `${context.label}: MWK ${value.toLocaleString()} (${percent}%)`;
                    }
                }
            }
        }
    }
});
</script>

</body>
</html>
