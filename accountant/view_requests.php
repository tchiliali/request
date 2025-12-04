<?php
session_start();
include "../config.php";

// =======================
// ROLE CHECK
// =======================
if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'accountant') {
    header("Location: ../login.php");
    exit();
}

// =======================
// SAFE OUTPUT FUNCTION
// =======================
function safe($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// =======================
// DASHBOARD COUNTS
// =======================
$ready = $conn->query("SELECT COUNT(*) AS total FROM requests WHERE status='approve_for_payment'")->fetch_assoc()['total'];
$paid  = $conn->query("SELECT COUNT(*) AS total FROM requests WHERE status='paid'")->fetch_assoc()['total'];

// =======================
// FILTERS
// =======================
$search = trim($_GET['search'] ?? '');
$filter = $_GET['status'] ?? 'approve_for_payment';

$allowed_filters = ['approve_for_payment', 'paid', 'all'];
if (!in_array($filter, $allowed_filters)) {
    $filter = 'approve_for_payment';
}

// =======================
// PAGINATION
// =======================
$rows_per_page = 10;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $rows_per_page;

// =======================
// MAIN QUERY
// =======================
$sql = "
    SELECT r.*, 
           p.name AS project_name, 
           a.name AS activity_name, 
           sa.name AS sub_activity_name
    FROM requests r
    LEFT JOIN projects p ON r.project_id = p.id
    LEFT JOIN activities a ON r.activity_id = a.id
    LEFT JOIN sub_activities sa ON r.sub_activity_id = sa.id
    WHERE 1
";

$params = [];
$types = "";

// STATUS FILTER
if ($filter != "all") {
    $sql .= " AND r.status = ? ";
    $params[] = $filter;
    $types .= "s";
}

// SEARCH FILTER
if ($search !== '') {
    $sql .= " AND (
        r.id LIKE ? OR
        r.requester_email LIKE ? OR
        r.description LIKE ? OR
        r.amount LIKE ? OR
        p.name LIKE ? OR
        a.name LIKE ? OR
        sa.name LIKE ?
    )";

    for ($i = 0; $i < 7; $i++) {
        $params[] = "%$search%";
        $types .= "s";
    }
}

$sql .= " ORDER BY r.created_at DESC LIMIT ?, ?";
$params[] = $offset;
$params[] = $rows_per_page;
$types .= "ii";

// EXECUTE MAIN QUERY
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// =======================
// COUNT FOR PAGINATION
// =======================
$count_sql = "
    SELECT COUNT(*) AS total
    FROM requests r
    LEFT JOIN projects p ON r.project_id = p.id
    LEFT JOIN activities a ON r.activity_id = a.id
    LEFT JOIN sub_activities sa ON r.sub_activity_id = sa.id
    WHERE 1
";

if ($filter != "all") {
    $count_sql .= " AND r.status='$filter' ";
}

if ($search !== '') {
    $count_sql .= " AND (
        r.id LIKE '%$search%' OR
        r.requester_email LIKE '%$search%' OR
        r.description LIKE '%$search%' OR
        r.amount LIKE '%$search%' OR
        p.name LIKE '%$search%' OR
        a.name LIKE '%$search%' OR
        sa.name LIKE '%$search%'
    )";
}

$total_rows = $conn->query($count_sql)->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $rows_per_page);

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Accountant – Requests Ready for Payment</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-4">

    <h2 class="mb-3 fw-bold text-center">Accountant – Requests Ready for Payment</h2>

    <a href="dashboard.php" class="btn btn-secondary mb-3">← Back to Dashboard</a>

    <!-- SUMMARY CARDS -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm bg-primary text-white">
                <div class="card-body text-center">
                    <h3><?= $ready ?></h3>
                    <p>Requests Ready for Payment</p>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm bg-success text-white">
                <div class="card-body text-center">
                    <h3><?= $paid ?></h3>
                    <p>Paid Requests</p>
                </div>
            </div>
        </div>
    </div>

    <!-- FILTER BAR -->
    <form method="GET" class="row mb-3 g-3">
        <div class="col-md-3">
            <select name="status" class="form-select">
                <option value="approve_for_payment" <?= $filter=='approve_for_payment'?'selected':'' ?>>Ready for Payment</option>
                <option value="paid" <?= $filter=='paid'?'selected':'' ?>>Paid Requests</option>
                <option value="all" <?= $filter=='all'?'selected':'' ?>>All</option>
            </select>
        </div>

        <div class="col-md-6">
            <input type="text"
                   name="search"
                   value="<?= safe($search) ?>"
                   class="form-control"
                   placeholder="Search project, activity, requester, amount...">
        </div>

        <div class="col-md-3">
            <button class="btn btn-primary w-100">Apply Filters</button>
        </div>
    </form>

    <!-- TABLE -->
    <div class="card shadow-sm">
        <div class="card-body">
            <h4 class="mb-3">Requests List</h4>

            <div class="table-responsive">
                <table class="table table-bordered table-hover bg-white">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Project</th>
                            <th>Activity</th>
                            <th>Sub-Activity</th>
                            <th>Requester</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Paid At</th>
                            <th>Method</th>
                            <th>Provider</th>
                            <th>Manager Comment</th>
                            <th>Accountant Comment</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php 
                    $rownum = $offset + 1;
                    while ($req = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $rownum++ ?></td>
                            <td><?= safe($req['project_name']) ?></td>
                            <td><?= safe($req['activity_name']) ?></td>
                            <td><?= safe($req['sub_activity_name']) ?></td>
                            <td><?= safe($req['requester_email']) ?></td>
                            <td><strong>MWK <?= number_format($req['amount'],2) ?></strong></td>

                            <td>
                                <?php if ($req['status']=='approve_for_payment'): ?>
                                    <span class="badge bg-primary">Ready for Payment</span>
                                <?php elseif ($req['status']=='paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php endif; ?>
                            </td>

                            <td><?= $req['paid_at'] ? date("d-m-Y H:i", strtotime($req['paid_at'])) : '-' ?></td>
                            <td><?= safe($req['payment_method']) ?: '-' ?></td>
                            <td><?= safe($req['payment_provider']) ?: '-' ?></td>
                            <td><?= safe($req['manager_comment']) ?: '-' ?></td>
                            <td><?= safe($req['accountant_comment']) ?: '-' ?></td>

                            <td>
                                <?php if ($req['status']=='approve_for_payment'): ?>
                                    <a href="make_payment.php?request_id=<?= $req['id'] ?>" class="btn btn-sm btn-success">
                                        Make Payment
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-secondary" disabled>Paid</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <nav>
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page-1 ?>&status=<?= $filter ?>&search=<?= safe($search) ?>">Previous</a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= $i==$page?'active':'' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&status=<?= $filter ?>&search=<?= safe($search) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page+1 ?>&status=<?= $filter ?>&search=<?= safe($search) ?>">Next</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>

        </div>
    </div>

</div>
</body>
</html>
