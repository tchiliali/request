<?php
include "../config.php";
session_start();
$requester_email = $_SESSION['email'] ?? '';

if (!isset($_GET['project_id']) || !$requester_email) {
    echo '<option value="">-- Select Activity --</option>';
    exit();
}

$project_id = intval($_GET['project_id']);

// Fetch activities with remaining budget
$stmt = $conn->prepare("
    SELECT a.id, a.name, a.budget,
        COALESCE(SUM(r.amount),0) AS spent
    FROM activities a
    INNER JOIN project_assignments pa ON a.project_id = pa.project_id
    LEFT JOIN sub_activities sa ON sa.activity_id = a.id
    LEFT JOIN requests r ON r.sub_activity_id = sa.id AND r.status = 'Approved'
    WHERE a.project_id = ? AND pa.requester_email = ?
    GROUP BY a.id
    ORDER BY a.name ASC
");
$stmt->bind_param("is", $project_id, $requester_email);
$stmt->execute();
$result = $stmt->get_result();

echo '<option value="">-- Select Activity --</option>';
while ($row = $result->fetch_assoc()) {
    $remaining = $row['budget'] - $row['spent'];
    echo '<option value="' . $row['id'] . '">' . htmlspecialchars($row['name']) . 
         ' (Remaining: ' . number_format($remaining, 2) . ' MWK)</option>';
}
?>
