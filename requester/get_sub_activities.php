<?php
include "../config.php";
session_start();
$requester_email = $_SESSION['email'] ?? '';

if (!isset($_GET['activity_id']) || !$requester_email) {
    echo '<option value="">-- Select Sub-Activity --</option>';
    exit();
}

$activity_id = intval($_GET['activity_id']);

// Fetch sub-activities and remaining budget
$stmt = $conn->prepare("
    SELECT sa.id, sa.name, sa.budget,
        COALESCE(SUM(r.amount),0) AS spent
    FROM sub_activities sa
    INNER JOIN activities a ON sa.activity_id = a.id
    INNER JOIN project_assignments pa ON a.project_id = pa.project_id
    LEFT JOIN requests r ON r.sub_activity_id = sa.id AND r.status = 'Approved'
    WHERE sa.activity_id = ? AND pa.requester_email = ?
    GROUP BY sa.id
    ORDER BY sa.name ASC
");
$stmt->bind_param("is", $activity_id, $requester_email);
$stmt->execute();
$result = $stmt->get_result();

echo '<option value="">-- Select Sub-Activity --</option>';
while ($row = $result->fetch_assoc()) {
    $remaining = $row['budget'] - $row['spent'];
    echo '<option value="' . $row['id'] . '">' . htmlspecialchars($row['name']) . 
         ' (Remaining: ' . number_format($remaining, 2) . ' MWK)</option>';
}
?>
