<?php
session_start();
require_once "../config.php";

if (!isset($_SESSION['email']) || $_SESSION['role'] != 'accountant') {
    header("Location: ../login.php");
    exit();
}

$user_email = $_SESSION['email'];

// -------------------------------
// Fetch request stats
// -------------------------------
$stats = [
    'pending' => ['count' => 0, 'total' => 0],
    'approved' => ['count' => 0, 'total' => 0],
    'rejected' => ['count' => 0, 'total' => 0],
    'paid' => ['count' => 0, 'total' => 0]
];

$query = "SELECT status, COUNT(*) AS total_requests, SUM(amount) AS total_amount FROM requests GROUP BY status";
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $status = strtolower($row['status']);
        if (isset($stats[$status])) {
            $stats[$status]['count'] = $row['total_requests'];
            $stats[$status]['total'] = $row['total_amount'] ?? 0;
        }
    }
}

// Paid records
$paid_q = $conn->query("SELECT COUNT(*) AS paid_count, SUM(amount) AS paid_total FROM requests WHERE paid_at IS NOT NULL");
if ($paid_q) {
    $paid = $paid_q->fetch_assoc();
    $stats['paid']['count'] = $paid['paid_count'];
    $stats['paid']['total'] = $paid['paid_total'] ?? 0;
}

// Recent payments (latest 10)
$recent_payments = [];
$recent_q = $conn->query("SELECT r.id, r.requester_email, r.amount, r.payment_method, r.payment_provider, r.paid_at 
                          FROM requests r 
                          WHERE r.paid_at IS NOT NULL 
                          ORDER BY r.paid_at DESC LIMIT 10");
if ($recent_q) {
    while ($row = $recent_q->fetch_assoc()) {
        $recent_payments[] = $row;
    }
}

// Chart data
$status_labels = ['Pending', 'Approved', 'Rejected', 'Paid'];
$status_values = [
    $stats['pending']['count'], 
    $stats['approved']['count'], 
    $stats['rejected']['count'], 
    $stats['paid']['count']
];

$payments_labels = [];
$payments_values = [];
$last_months_q = $conn->query("
    SELECT DATE_FORMAT(paid_at, '%b %Y') AS month_year, SUM(amount) AS total_amount
    FROM requests
    WHERE paid_at IS NOT NULL
    GROUP BY month_year
    ORDER BY paid_at ASC
");
if ($last_months_q) {
    while ($row = $last_months_q->fetch_assoc()) {
        $payments_labels[] = $row['month_year'];
        $payments_values[] = $row['total_amount'];
    }
}

// Safe output
function safe($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Accountant Dashboard</title>
<link href="../assets/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body { font-family: Arial, sans-serif; }
.sidebar {
    height: 100vh;
    position: fixed;
    left: 0;
    top: 0;
    width: 220px;
    background-color: #343a40;
    padding-top: 60px;
    transition: all 0.3s;
}
.sidebar a {
    display: block;
    color: #fff;
    padding: 12px 20px;
    text-decoration: none;
}
.sidebar a:hover { background-color: #495057; }
.sidebar.collapsed { left: -220px; }
.content { margin-left: 230px; padding: 20px; transition: margin-left 0.3s; }
.content.expanded { margin-left: 10px; }

.toggle-btn {
    position: fixed;
    top: 10px;
    left: 10px;
    z-index: 1001;
    background-color: #343a40;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
}
.card { border-radius: 12px; }
.table thead th, .table tbody td { text-align: center; }
@media (max-width: 768px) {
    .sidebar { left: -220px; }
    .content { margin-left: 10px; }
}
</style>
</head>
<body class="bg-light">

<!-- Toggle Button -->
<button class="toggle-btn" id="toggleSidebar"><i class="bi bi-list"></i></button>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <h4 class="text-white text-center mb-4">Accountant Panel</h4>
    <a href="dashboard.php"><i class="bi bi-house"></i> Dashboard</a>
    <a href="view_requests.php"><i class="bi bi-list-task"></i> View Requests</a>
    <a href="make_payment.php"><i class="bi bi-cash-stack"></i> Make Payment</a>
    <a href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
</div>

<!-- Content -->
<div class="content" id="content">
    <h2 class="mb-4">Welcome, <?= safe($user_email) ?></h2>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <?php
        $colors = ['secondary','primary','danger','success'];
        $i = 0;
        foreach($stats as $key => $stat) {
            $title = ucfirst($key) . ($key === 'paid' ? ' Requests' : ' Requests');
            echo '<div class="col-md-3 mb-3">
                    <div class="card text-white bg-'.$colors[$i].' shadow">
                        <div class="card-body">
                            <h5 class="card-title">'.$title.'</h5>
                            <p class="card-text"><strong>'.$stat['count'].'</strong> requests</p>
                            <p>Total: MK '.number_format($stat['total'],2).'</p>
                        </div>
                    </div>
                  </div>';
            $i++;
        }
        ?>
    </div>

    <!-- Charts -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card p-3 shadow-sm">
                <h5 class="text-center">Request Status Breakdown</h5>
                <canvas id="statusChart"></canvas>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card p-3 shadow-sm">
                <h5 class="text-center">Payments Trend (Monthly)</h5>
                <canvas id="paymentsChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Recent Payments -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h4>Recent Payments</h4>
            <table class="table table-bordered table-hover text-center">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Requester</th>
                        <th>Amount (MK)</th>
                        <th>Method</th>
                        <th>Provider</th>
                        <th>Paid At</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if(count($recent_payments) > 0) {
                    foreach($recent_payments as $p) {
                        echo '<tr>
                                <td>'.$p['id'].'</td>
                                <td>'.safe($p['requester_email']).'</td>
                                <td>'.number_format($p['amount'],2).'</td>
                                <td>'.safe($p['payment_method']).'</td>
                                <td>'.safe($p['payment_provider']).'</td>
                                <td>'.date("d M Y", strtotime($p['paid_at'])).'</td>
                              </tr>';
                    }
                } else {
                    echo '<tr><td colspan="6" class="text-muted">No payments found</td></tr>';
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Charts Script -->
<script>
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'pie',
    data: {
        labels: <?= json_encode($status_labels) ?>,
        datasets: [{ data: <?= json_encode($status_values) ?>, backgroundColor: ['#6c757d','#0d6efd','#dc3545','#198754'] }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' }, title: { display: true, text: 'Request Status Overview' } } }
});

const paymentsCtx = document.getElementById('paymentsChart').getContext('2d');
new Chart(paymentsCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($payments_labels) ?>,
        datasets: [{ label: 'Total Payments (MK)', data: <?= json_encode($payments_values) ?>, backgroundColor: '#0d6efd' }]
    },
    options: { responsive: true, plugins: { legend: { display: false }, title: { display: true, text: 'Monthly Payments Trend' } }, scales: { y: { beginAtZero: true } } }
});

// Toggle Sidebar
const toggleBtn = document.getElementById('toggleSidebar');
const sidebar = document.getElementById('sidebar');
const content = document.getElementById('content');

toggleBtn.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    content.classList.toggle('expanded');
});
</script>

<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
