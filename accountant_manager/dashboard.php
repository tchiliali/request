<?php
session_start();
require_once "../config.php";

// Ensure only accountant_manager can access
if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'accountant_manager') {
    header("Location: ../login.php");
    exit();
}

// Exact statuses you gave
$STATUSES = ['pending','approved','approve_for_payment','rejected','paid'];

/* ------------------ REQUEST STATISTICS ------------------ */
// initialize counts
$stats = array_fill_keys($STATUSES, 0);

// fetch counts grouped by status
$res = $conn->query("SELECT status, COUNT(*) AS total FROM requests GROUP BY status");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $st = strtolower($r['status']);
        if (array_key_exists($st, $stats)) $stats[$st] = (int)$r['total'];
    }
}

/* ------------------ PROJECT / ACTIVITY / SUB-ACT DETAILS ------------------
   We'll fetch all projects, their activities and sub-activities and the sum of spent
   for each sub-activity (considering approved/approve_for_payment/paid as committed).
-------------------------------------------------------------------------- */

$query = "
    SELECT
        p.id AS project_id,
        p.name AS project_name,
        a.id AS activity_id,
        a.name AS activity_name,
        IFNULL(a.budget,0) AS activity_budget,
        sa.id AS sub_id,
        sa.name AS sub_name,
        IFNULL(sa.budget,0) AS sub_budget,
        COALESCE(SUM(CASE WHEN r.status IN ('approved','approve_for_payment','paid') THEN r.amount ELSE 0 END),0) AS spent,
        -- pick a representative requester/approver/paid_at/accountant_comment for display (may be NULL)
        MAX(r.requester_email) AS requester_email,
        MAX(r.approver_email) AS approver_email,
        MAX(r.paid_at) AS paid_at,
        MAX(r.accountant_comment) AS accountant_comment
    FROM projects p
    LEFT JOIN activities a ON a.project_id = p.id
    LEFT JOIN sub_activities sa ON sa.activity_id = a.id
    LEFT JOIN requests r ON r.sub_activity_id = sa.id
    GROUP BY p.id, a.id, sa.id
    ORDER BY p.name ASC, a.name ASC, sa.name ASC
";
$details = $conn->query($query);
if (!$details) {
    die("Query error: " . $conn->error);
}

/* Build nested array structure for display and aggregate totals */
$projects = [];
while ($row = $details->fetch_assoc()) {
    $pid = $row['project_id'] ?? 0;
    $aid = $row['activity_id'] ?? 0;
    $sid = $row['sub_id'] ?? 0;

    // ensure numeric floats to avoid null warnings
    $activity_budget = floatval($row['activity_budget'] ?? 0);
    $sub_budget = floatval($row['sub_budget'] ?? 0);
    $spent = floatval($row['spent'] ?? 0);

    if (!isset($projects[$pid])) {
        $projects[$pid] = [
            'name' => $row['project_name'] ?? 'Unnamed Project',
            'total_budget' => 0.0,
            'total_spent' => 0.0,
            'activities' => []
        ];
    }

    // Add activity container
    if ($aid && !isset($projects[$pid]['activities'][$aid])) {
        $projects[$pid]['activities'][$aid] = [
            'name' => $row['activity_name'] ?? 'Unnamed Activity',
            'budget' => $activity_budget,
            'spent' => 0.0,
            'sub_activities' => []
        ];
        // Accumulate project total budget by activity budget (avoid double count if multiple sub rows)
        $projects[$pid]['total_budget'] += $activity_budget;
    }

    // Add sub-activity row
    if ($sid) {
        $projects[$pid]['activities'][$aid]['sub_activities'][$sid] = [
            'name' => $row['sub_name'] ?? 'Unnamed Sub-Activity',
            'budget' => $sub_budget,
            'spent' => $spent,
            'requester_email' => $row['requester_email'],
            'approver_email' => $row['approver_email'],
            'paid_at' => $row['paid_at'],
            'accountant_comment' => $row['accountant_comment']
        ];

        // Add spent to activity and project totals
        $projects[$pid]['activities'][$aid]['spent'] += $spent;
        $projects[$pid]['total_spent'] += $spent;
    }
}

/* Overall totals for header / charts */
$total_budget = 0.0;
$total_spent = 0.0;
foreach ($projects as $p) {
    $total_budget += floatval($p['total_budget']);
    $total_spent += floatval($p['total_spent']);
}
$total_remaining = $total_budget - $total_spent;
$percent_used = $total_budget > 0 ? ($total_spent / $total_budget) * 100 : 0;

/* Prepare project-level arrays for Budget vs Spent chart */
$project_labels = [];
$project_total_budgets = [];
$project_total_spents = [];
foreach ($projects as $p) {
    $project_labels[] = $p['name'];
    $project_total_budgets[] = round(floatval($p['total_budget']),2);
    $project_total_spents[] = round(floatval($p['total_spent']),2);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Accountant Manager Dashboard</title>
<link href="../assets/css/bootstrap.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body { background: #f3f6f9; }
.sidebar { height: 100vh; background: #343a40; color: #fff; padding-top: 25px; min-width:200px; }
.sidebar a { color: #fff; display: block; padding: 12px; text-decoration: none; }
.sidebar a:hover { background: #495057; }
.card { border-radius: 12px; }
.table-wrapper { max-height: 420px; overflow-y: auto; }
.progress { height: 20px; }
.canvas-small { width:100%; height:220px; }
@media (min-width:992px){
    .chart-side { display:flex; gap:1rem; }
    .chart-side > .card { flex:1; }
}
</style>
</head>
<body>
<div class="d-flex">

    <!-- Sidebar -->
    <div class="sidebar p-3">
        <h4 class="text-center mb-4">Accountant Manager</h4>
        <a href="dashboard.php">Dashboard</a>
        <a href="review_payments.php">Review Payments</a>
        <a href="../logout.php">Logout</a>
    </div>

    <!-- Main -->
    <div class="container-fluid p-4">
        <h3>Welcome, <?= htmlspecialchars($_SESSION['email']) ?></h3>

        <!-- Stats -->
        <div class="row my-3">
            <?php foreach ($stats as $st => $count): 
                // friendly label (upper-case first)
                $label = ucfirst($st);
            ?>
            <div class="col-sm-6 col-md-3 mb-3">
                <div class="card shadow-sm p-3 text-center">
                    <h6 class="mb-2"><?= htmlspecialchars($label) ?></h6>
                    <h3><?= (int)$count ?></h3>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Charts side-by-side -->
        <div class="chart-side mb-4">
            <div class="card p-3 shadow-sm">
                <h6>Request Status (Counts)</h6>
                <canvas id="statusPie" class="canvas-small"></canvas>
            </div>

            <div class="card p-3 shadow-sm">
                <h6>Projects: Budget vs Spent</h6>
                <canvas id="projectsBar" class="canvas-small"></canvas>
            </div>
        </div>

        <!-- Overall budget progress -->
        <div class="card p-3 mb-4 shadow-sm">
            <div class="d-flex justify-content-between">
                <div>
                    <h5>Total Budget: MWK <?= number_format((float)$total_budget,2) ?></h5>
                    <h6>Total Spent: MWK <?= number_format((float)$total_spent,2) ?></h6>
                </div>
                <div style="min-width:220px; text-align:right;">
                    <h6>Total Remaining</h6>
                    <h4 class="fw-bold">MWK <?= number_format((float)$total_remaining,2) ?></h4>
                </div>
            </div>

            <div class="mt-3">
                <div class="progress" style="height:26px;">
                    <?php
                        $pct = round($percent_used,1);
                        $pct = ($pct>100)?100:$pct;
                        $barClass = ($pct < 70) ? 'bg-success' : (($pct < 90) ? 'bg-warning' : 'bg-danger');
                    ?>
                    <div class="progress-bar <?= $barClass ?>" role="progressbar" style="width: <?= $pct ?>%;">
                        <?= $pct ?>% Used
                    </div>
                </div>
            </div>
        </div>

        <!-- Project breakdown table -->
        <div class="card p-3 shadow-sm">
            <h5>Project Budget Breakdown</h5>
            <div class="table-wrapper mt-2">
                <table class="table table-bordered table-hover table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Project</th>
                            <th>Activity</th>
                            <th>Sub-Activity</th>
                            <th class="text-end">Budget (MWK)</th>
                            <th class="text-end">Spent (MWK)</th>
                            <th class="text-end">Remaining</th>
                            <th>Usage</th>
                            <th>Requester Email</th>
                            <th>Approver Email</th>
                            <th>Paid At</th>
                            <th>Payer / Note</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    // loop projects -> activities -> sub_activities
                    foreach ($projects as $proj) {
                        foreach ($proj['activities'] as $act) {
                            // if no sub_activities, print one row with activity totals
                            if (empty($act['sub_activities'])) {
                                $budget = floatval($act['budget']);
                                $spent = floatval($act['spent']);
                                $remaining = $budget - $spent;
                                $usage = $budget > 0 ? ($spent / $budget * 100) : 0;
                                $bar = ($usage >= 90) ? 'bg-danger' : (($usage >= 70) ? 'bg-warning' : 'bg-success');
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($proj['name']) . "</td>";
                                echo "<td>" . htmlspecialchars($act['name']) . "</td>";
                                echo "<td> - </td>";
                                echo "<td class='text-end'>" . number_format($budget,2) . "</td>";
                                echo "<td class='text-end'>" . number_format($spent,2) . "</td>";
                                echo "<td class='text-end'>" . number_format(max($remaining,0),2) . "</td>";
                                echo "<td><div class='progress'><div class='progress-bar {$bar}' style='width:" . round($usage) . "%'>" . round($usage) . "%</div></div></td>";
                                echo "<td>-</td><td>-</td><td>-</td><td>-</td>";
                                echo "</tr>";
                            } else {
                                // iterate sub activities
                                foreach ($act['sub_activities'] as $sub) {
                                    $budget = floatval($sub['budget']);
                                    $spent = floatval($sub['spent']);
                                    $remaining = $budget - $spent;
                                    $usage = $budget > 0 ? ($spent / $budget * 100) : 0;
                                    $bar = ($usage >= 90) ? 'bg-danger' : (($usage >= 70) ? 'bg-warning' : 'bg-success');
                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($proj['name']) . "</td>";
                                    echo "<td>" . htmlspecialchars($act['name']) . "</td>";
                                    echo "<td>" . htmlspecialchars($sub['name']) . "</td>";
                                    echo "<td class='text-end'>" . number_format($budget,2) . "</td>";
                                    echo "<td class='text-end'>" . number_format($spent,2) . "</td>";
                                    echo "<td class='text-end'>" . number_format(max($remaining,0),2) . "</td>";
                                    echo "<td><div class='progress'><div class='progress-bar {$bar}' style='width:" . round($usage) . "%'>" . round($usage) . "%</div></div></td>";
                                    echo "<td>" . htmlspecialchars($sub['requester_email'] ?? '-') . "</td>";
                                    echo "<td>" . htmlspecialchars($sub['approver_email'] ?? '-') . "</td>";
                                    echo "<td>" . htmlspecialchars($sub['paid_at'] ? date("d M Y H:i", strtotime($sub['paid_at'])) : '-') . "</td>";
                                    echo "<td>" . htmlspecialchars($sub['accountant_comment'] ?? '-') . "</td>";
                                    echo "</tr>";
                                }
                            }
                        }
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
// Chart: status pie
const statusCtx = document.getElementById('statusPie').getContext('2d');
new Chart(statusCtx, {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_map('ucfirst', array_keys($stats))) ?>,
        datasets: [{
            data: <?= json_encode(array_values($stats)) ?>,
            backgroundColor: ['#ffc107','#0d6efd','#198754','#dc3545','#6c757d'] // keep five colors (pending, approved, approve_for_payment, rejected, paid)
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
    }
});

// Chart: Projects Budget vs Spent (bar) - compact
const projectsCtx = document.getElementById('projectsBar').getContext('2d');
new Chart(projectsCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($project_labels) ?>,
        datasets: [
            { label: 'Budget', data: <?= json_encode($project_total_budgets) ?>, backgroundColor: '#0d6efd' },
            { label: 'Spent', data: <?= json_encode($project_total_spents) ?>, backgroundColor: '#dc3545' }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: {
            y: { beginAtZero: true }
        }
    }
});
</script>
</body>
</html>
