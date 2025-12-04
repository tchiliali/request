<?php
session_start();
include "../config.php";

if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'requester') {
    header("Location: ../login.php");
    exit();
}

$requester = $_SESSION['email'];

/* ==========================================================
   FETCH PROJECTS, ACTIVITIES & SUB-ACTIVITIES (existing)
   - This query returns sub-activity rows aggregated by sub-activity
   - Spent is sum of requests.amount where requests.status in ('Approved','approve_for_payment')
========================================================== */
$stmt = $conn->prepare("
    SELECT 
        p.id AS project_id,
        p.name AS project_name,
        a.id AS activity_id,
        a.name AS activity_name,
        a.budget AS activity_budget,
        sa.id AS sub_id,
        sa.name AS sub_name,
        sa.budget AS sub_budget,
        COALESCE(SUM(r.amount),0) AS spent
    FROM projects p
    INNER JOIN project_assignments pa ON pa.project_id = p.id AND pa.requester_email = ?
    LEFT JOIN activities a ON a.project_id = p.id
    LEFT JOIN sub_activities sa ON sa.activity_id = a.id
    LEFT JOIN requests r ON r.sub_activity_id = sa.id AND r.status IN ('Approved','approve_for_payment')
    GROUP BY sa.id
    ORDER BY p.name, a.name, sa.name
");
$stmt->bind_param("s", $requester);
$stmt->execute();
$res = $stmt->get_result();

$projects = [];
while ($row = $res->fetch_assoc()) {
    $pid = $row['project_id'];
    $aid = $row['activity_id'];
    $sid = $row['sub_id'];

    if (!isset($projects[$pid])) {
        $projects[$pid] = [
            'name' => $row['project_name'],
            'total_budget' => 0,
            'total_spent' => 0,
            'activities' => []
        ];
    }

    if ($aid && !isset($projects[$pid]['activities'][$aid])) {
        $projects[$pid]['activities'][$aid] = [
            'name' => $row['activity_name'],
            'budget' => $row['activity_budget'],
            'spent' => 0,
            'sub_activities' => []
        ];
        // accumulate activity budgets to project total
        $projects[$pid]['total_budget'] += (float)$row['activity_budget'];
    }

    if ($sid) {
        $projects[$pid]['activities'][$aid]['sub_activities'][$sid] = [
            'name' => $row['sub_name'],
            'budget' => (float)$row['sub_budget'],
            'spent' => (float)$row['spent']
        ];
        $projects[$pid]['activities'][$aid]['spent'] += (float)$row['spent'];
        $projects[$pid]['total_spent'] += (float)$row['spent'];
    }
}

/* ==========================================================
   CALCULATE TOTALS (FOR GRAPHS)
========================================================== */
$total_budget = 0;
$total_spent = 0;
$project_ids = [];
foreach ($projects as $pid => $proj) {
    $total_budget += $proj['total_budget'];
    $total_spent += $proj['total_spent'];
    $project_ids[] = (int)$pid;
}
$total_remaining = $total_budget - $total_spent;
$percent_used = ($total_budget>0)?($total_spent/$total_budget)*100:0;

/* ==========================================================
   REQUEST STATUS TOTALS
   We'll keep sum of amounts per status (for requester)
========================================================== */
function getStatusTotal($conn, $email, $statuses) {
    // build placeholders (?, ?, ...)
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    // types string: one s for email + s per status
    $types = str_repeat('s', count($statuses) + 1);
    $sql = "SELECT COALESCE(SUM(amount),0) AS total FROM requests WHERE requester_email = ? AND status IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    // bind params dynamically
    $params = array_merge([$email], $statuses);
    // mysqli_stmt::bind_param requires references
    $bind_names[] = $types;
    for ($i=0;$i<count($params);$i++) {
        $bind_names[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_names);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return (float)($res['total'] ?? 0);
}

$total_pending  = getStatusTotal($conn, $requester, ['Pending']);
$total_approved = getStatusTotal($conn, $requester, ['Approved','approve_for_payment']);
$total_paid_req = getStatusTotal($conn, $requester, ['Paid']);
$total_rejected = getStatusTotal($conn, $requester, ['Rejected']);

$status_labels = ['Pending', 'Approved', 'Paid', 'Rejected'];
$status_values = [$total_pending, $total_approved, $total_paid_req, $total_rejected];

/* ==========================================================
   ADDITIONAL AGGREGATES FOR NEW CHARTS
   1) Activity Budget vs Spent (for activities assigned to requester)
   2) Sub-Activity Budget Distribution (for sub-activities under assigned projects)
   3) Monthly Spending Trend (last 12 months)
========================================================== */

// prepare list of assigned projects (if none, use 0 to avoid SQL error)
if (empty($project_ids)) {
    $project_ids = [0];
}
$proj_placeholders = implode(',', array_fill(0, count($project_ids), '?'));
$proj_types = str_repeat('i', count($project_ids));

// 1) Activity aggregates
$activity_labels = [];
$activity_budget_vals = [];
$activity_spent_vals = [];

$act_sql = "
    SELECT a.id, a.name, COALESCE(a.budget,0) AS budget,
           COALESCE(SUM(CASE WHEN r.status IN ('Approved','approve_for_payment') THEN r.amount ELSE 0 END),0) AS spent
    FROM activities a
    INNER JOIN project_assignments pa ON a.project_id = pa.project_id
    LEFT JOIN requests r ON r.activity_id = a.id
    WHERE pa.requester_email = ?
    GROUP BY a.id
    ORDER BY a.name
    LIMIT 50
";
$act_stmt = $conn->prepare($act_sql);
$act_stmt->bind_param("s", $requester);
$act_stmt->execute();
$act_res = $act_stmt->get_result();
while ($row = $act_res->fetch_assoc()) {
    $activity_labels[] = $row['name'];
    $activity_budget_vals[] = (float)$row['budget'];
    $activity_spent_vals[]  = (float)$row['spent'];
}

// 2) Sub-activity distribution (by budget) - for assigned projects
$sub_labels = [];
$sub_budget_vals = [];
$sub_sql = "
    SELECT sa.id, sa.name, COALESCE(sa.budget,0) AS budget
    FROM sub_activities sa
    INNER JOIN activities a ON sa.activity_id = a.id
    INNER JOIN project_assignments pa ON a.project_id = pa.project_id
    WHERE pa.requester_email = ?
    ORDER BY sa.name
    LIMIT 100
";
$sub_stmt = $conn->prepare($sub_sql);
$sub_stmt->bind_param("s", $requester);
$sub_stmt->execute();
$sub_res = $sub_stmt->get_result();
while ($row = $sub_res->fetch_assoc()) {
    $sub_labels[] = $row['name'];
    $sub_budget_vals[] = (float)$row['budget'];
}

// 3) Monthly spending trend (last 12 months)
$months = [];
$month_vals = [];
$month_sql = "
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total
    FROM requests
    WHERE requester_email = ? AND status IN ('Approved','approve_for_payment','Paid')
    GROUP BY ym
    ORDER BY ym DESC
    LIMIT 12
";
$month_stmt = $conn->prepare($month_sql);
$month_stmt->bind_param("s", $requester);
$month_stmt->execute();
$month_res = $month_stmt->get_result();
$tmp_months = [];
$tmp_vals = [];
while ($row = $month_res->fetch_assoc()) {
    $tmp_months[] = $row['ym'];
    $tmp_vals[] = (float)$row['total'];
}
// we want chronological order oldest->newest
$tmp_months = array_reverse($tmp_months);
$tmp_vals   = array_reverse($tmp_vals);
$months = $tmp_months;
$month_vals = $tmp_vals;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Requester Dashboard</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        .summary-card {
            text-align: center;
            background: linear-gradient(135deg, #0d6efd, #20c997);
            color: white;
            border-radius: 15px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .small-card {
            border-radius: 15px;
            padding: 20px;
            color: white;
            text-align: center;
            margin-bottom:10px;
        }
        .card-pending { background-color: #ffc107; }
        .card-approved { background-color: #28a745; }
        .card-paid { background-color: #0d6efd; }
        .card-rejected { background-color: #dc3545; }
        .progress-bar-custom { height: 18px; font-size: 0.8rem; }
        /* ensure chart containers are not huge on desktop */
        .chart-box { min-height: 180px; max-height: 320px; position: relative; }
        @media (max-width:767px) {
            .chart-box { max-height: 260px; }
        }
    </style>
</head>
<body class="bg-light">
<div class="container mt-4 mb-5">
    <h2 class="text-center mb-4">Requester Dashboard</h2>

    <!-- TOTAL BUDGET -->
    <div class="card summary-card mb-3 p-3">
        <div class="row align-items-center">
            <div class="col-md-8 text-center">
                <h5>Total Remaining Budget</h5>
                <h1 class="display-6 fw-bold">MWK <?= number_format($total_remaining, 2) ?></h1>
                <p>Out of MWK <?= number_format($total_budget, 2) ?> total budget</p>
            </div>
            <div class="col-md-4 text-center">
                <div style="width:120px;height:120px;margin:0 auto;">
                    <!-- small donut-like visual using CSS + text (keeps simple) -->
                    <svg viewBox="0 0 36 36" style="width:120px;height:120px">
                        <path d="M18 2.0845
                        a 15.9155 15.9155 0 0 1 0 31.831
                        a 15.9155 15.9155 0 0 1 0 -31.831"
                        fill="none" stroke="#e9ecef" stroke-width="3.5"></path>
                        <path d="M18 2.0845
                        a 15.9155 15.9155 0 0 1 0 31.831"
                        fill="none" stroke="#dc3545" stroke-width="3.5" stroke-dasharray="<?= round($percent_used,1) ?>,100"></path>
                        <text x="18" y="20" font-size="6" text-anchor="middle" fill="#fff"><?= round($percent_used,1) ?>%</text>
                    </svg>
                </div>
            </div>
        </div>

        <!-- NEW TOTAL BUDGET PROGRESS BAR -->
        <div class="progress mt-3" style="height:25px;">
            <div class="progress-bar bg-danger" role="progressbar" style="width: <?= round($percent_used,1) ?>%;">
                <?= round($percent_used,1) ?>% Used
            </div>
        </div>

        <div class="mt-2 text-center">
            <a href="fund_request.php" class="btn btn-light btn-lg fw-bold mt-2">Submit New Fund Request</a>
        </div>
    </div>

    <!-- STATUS CARDS -->
    <div class="row mb-3">
        <div class="col-sm-6 col-md-3"><div class="small-card card-pending"><h6>Pending</h6><h4>MWK <?= number_format($total_pending, 2) ?></h4></div></div>
        <div class="col-sm-6 col-md-3"><div class="small-card card-approved"><h6>Approved</h6><h4>MWK <?= number_format($total_approved, 2) ?></h4></div></div>
        <div class="col-sm-6 col-md-3"><div class="small-card card-paid"><h6>Paid</h6><h4>MWK <?= number_format($total_paid_req, 2) ?></h4></div></div>
        <div class="col-sm-6 col-md-3"><div class="small-card card-rejected"><h6>Rejected</h6><h4>MWK <?= number_format($total_rejected, 2) ?></h4></div></div>
    </div>

    <!-- CHARTS ROW: PIE (status) + BAR (budget vs spent) side-by-side -->
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card p-3 chart-box">
                <h6 class="mb-2">Request Status Overview</h6>
                <canvas id="statusPieChart" style="max-height:100%;"></canvas>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card p-3 chart-box">
                <h6 class="mb-2">Overall Budget vs Spent</h6>
                <canvas id="budgetBarChart" style="max-height:100%;"></canvas>
            </div>
        </div>
    </div>

    <!-- SECOND CHART ROW: Activity horizontal + Monthly trend -->
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card p-3 chart-box">
                <h6 class="mb-2">Activity Budget vs Spent</h6>
                <canvas id="activityChart" style="max-height:100%;"></canvas>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card p-3 chart-box">
                <h6 class="mb-2">Monthly Spending Trend</h6>
                <canvas id="monthlyLineChart" style="max-height:100%;"></canvas>
            </div>
        </div>
    </div>

    <!-- THIRD CHART ROW: Sub-activity distribution -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card p-3 chart-box">
                <h6 class="mb-2">Sub-Activity Budget Distribution</h6>
                <canvas id="subDoughnutChart" style="max-height:280px;"></canvas>
            </div>
        </div>
    </div>

    <!-- PROJECT ACTIVITIES (expandable rows) -->
    <?php foreach($projects as $pid => $proj): ?>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="mb-2"><?= htmlspecialchars($proj['name']) ?> <small class="text-muted">Total Budget: MWK <?= number_format($proj['total_budget'],2) ?> — Spent: MWK <?= number_format($proj['total_spent'],2) ?></small></h5>

                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Activity / Sub-Activity</th>
                            <th class="text-end">Budget</th>
                            <th class="text-end">Spent</th>
                            <th class="text-end">Remaining</th>
                            <th>Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($proj['activities'] as $aid => $act): 
                        $act_remaining = $act['budget'] - $act['spent'];
                        $act_usage = ($act['budget']>0)?($act['spent']/$act['budget'])*100:0;
                    ?>
                        <tr class="activity-row" data-activity="<?= htmlspecialchars($aid) ?>">
                            <td><strong><?= htmlspecialchars($act['name']) ?></strong></td>
                            <td class="text-end"><?= number_format($act['budget'],2) ?></td>
                            <td class="text-end"><?= number_format($act['spent'],2) ?></td>
                            <td class="text-end"><?= number_format($act_remaining,2) ?></td>
                            <td style="width:220px;">
                                <div class="progress">
                                    <div class="progress-bar bg-success progress-bar-custom" role="progressbar"
                                         style="width: <?= round($act_usage,1) ?>%;">
                                        <?= round($act_usage,1) ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>

                        <?php foreach($act['sub_activities'] as $sid => $sub): 
                            $sub_remaining = $sub['budget'] - $sub['spent'];
                            $sub_usage = ($sub['budget']>0)?($sub['spent']/$sub['budget'])*100:0;
                        ?>
                            <tr class="sub-activity-row" data-parent="<?= htmlspecialchars($aid) ?>" style="display:none;">
                                <td style="padding-left:28px;"><?= htmlspecialchars($sub['name']) ?></td>
                                <td class="text-end"><?= number_format($sub['budget'],2) ?></td>
                                <td class="text-end"><?= number_format($sub['spent'],2) ?></td>
                                <td class="text-end"><?= number_format($sub_remaining,2) ?></td>
                                <td>
                                    <div class="progress">
                                        <div class="progress-bar bg-info progress-bar-custom" role="progressbar"
                                             style="width: <?= round($sub_usage,1) ?>%;">
                                            <?= round($sub_usage,1) ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

</div>

<script>
/* Expand/Collapse Sub-Activities */
$(document).on("click", ".activity-row", function () {
    let aid = $(this).data("activity");
    $("tr.sub-activity-row[data-parent='"+aid+"']").toggle();
});

/* ============================
   Chart: Request Status Pie
   ============================ */
const statusCtx = document.getElementById('statusPieChart').getContext('2d');
new Chart(statusCtx, {
    type: 'pie',
    data: {
        labels: <?= json_encode($status_labels) ?>,
        datasets: [{
            data: <?= json_encode($status_values) ?>,
            backgroundColor: ['#ffc107','#28a745','#0d6efd','#dc3545']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
    }
});

/* ============================
   Chart: Overall Budget vs Spent
   ============================ */
const budgetCtx = document.getElementById('budgetBarChart').getContext('2d');
new Chart(budgetCtx, {
    type: 'bar',
    data: {
        labels: ['Total Budget','Total Spent'],
        datasets: [{
            label: 'Amount (MWK)',
            data: [<?= $total_budget ?>, <?= $total_spent ?>],
            backgroundColor: ['#0d6efd','#dc3545']
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true } }
    }
});

/* ============================
   Chart: Activity Budget vs Spent (horizontal)
   ============================ */
const activityLabels = <?= json_encode($activity_labels) ?>;
const activityBudgets = <?= json_encode($activity_budget_vals) ?>;
const activitySpents  = <?= json_encode($activity_spent_vals) ?>;

if (activityLabels.length > 0) {
    const activityCtx = document.getElementById('activityChart').getContext('2d');
    new Chart(activityCtx, {
        type: 'bar',
        data: {
            labels: activityLabels,
            datasets: [
                { label: 'Budget', data: activityBudgets, backgroundColor: '#0d6efd' },
                { label: 'Spent',  data: activitySpents,  backgroundColor: '#28a745' }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { x: { beginAtZero: true } }
        }
    });
} else {
    // hide chart or leave empty
    $('#activityChart').replaceWith('<div class="text-muted p-3">No activity data to show.</div>');
}

/* ============================
   Chart: Sub-Activity Doughnut (budget distribution)
   ============================ */
const subLabels = <?= json_encode($sub_labels) ?>;
const subBudgets = <?= json_encode($sub_budget_vals) ?>;
if (subLabels.length > 0) {
    const subCtx = document.getElementById('subDoughnutChart').getContext('2d');
    new Chart(subCtx, {
        type: 'doughnut',
        data: {
            labels: subLabels,
            datasets: [{ data: subBudgets }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', maxHeight: 80, labels: { boxWidth:12 } } }
        }
    });
} else {
    $('#subDoughnutChart').replaceWith('<div class="text-muted p-3">No sub-activity budget data to show.</div>');
}

/* ============================
   Chart: Monthly Spending Trend (line)
   ============================ */
const months = <?= json_encode($months) ?>;
const monthVals = <?= json_encode($month_vals) ?>;
if (months.length > 0) {
    const monthCtx = document.getElementById('monthlyLineChart').getContext('2d');
    new Chart(monthCtx, {
        type: 'line',
        data: {
            labels: months,
            datasets: [{
                label: 'Total Spent (MWK)',
                data: monthVals,
                tension: 0.3,
                fill: true,
                backgroundColor: 'rgba(13,110,253,0.08)',
                borderColor: '#0d6efd',
                pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true } }
        }
    });
} else {
    $('#monthlyLineChart').replaceWith('<div class="text-muted p-3">No monthly spending data to show.</div>');
}
</script>
</body>
</html>
