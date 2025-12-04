<?php
session_start();
include "../config.php";

if (!isset($_SESSION['email']) || $_SESSION['role'] != 'requester') {
    exit("Unauthorized access");
}

if (!isset($_GET['project_id'])) {
    exit("Project ID missing");
}

$project_id = intval($_GET['project_id']);
$requester = $_SESSION['email'];

// Check if the requester is assigned to this project
$stmt = $conn->prepare("
    SELECT COUNT(*) AS cnt 
    FROM project_assignments 
    WHERE project_id = ? AND requester_email = ?
");
$stmt->bind_param("is", $project_id, $requester);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
if ($result['cnt'] == 0) {
    exit("You are not assigned to this project");
}

// Get all activities for this project
$stmt = $conn->prepare("
    SELECT id, name, budget 
    FROM activities 
    WHERE project_id = ?
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$activities = $stmt->get_result();

if ($activities->num_rows > 0) {
    foreach ($activities as $act) {
        $activity_id = $act['id'];

        // Total spent on sub-activities of this activity
        $spent_stmt = $conn->prepare("
            SELECT COALESCE(SUM(r.amount),0) AS total
            FROM requests r
            JOIN sub_activities sa ON r.sub_activity_id = sa.id
            WHERE sa.activity_id = ? AND r.status = 'Approved'
        ");
        $spent_stmt->bind_param("i", $activity_id);
        $spent_stmt->execute();
        $spent = $spent_stmt->get_result()->fetch_assoc()['total'];

        $remaining = $act['budget'] - $spent;
        $usage = ($act['budget'] > 0) ? ($spent / $act['budget']) * 100 : 0;

        $bar = "bg-success";
        if ($usage >= 70 && $usage < 90) $bar = "bg-warning";
        if ($usage >= 90) $bar = "bg-danger";

        echo "
        <tr>
            <td>{$act['name']}</td>
            <td class='text-end'>".number_format($act['budget'],2)."</td>
            <td class='text-end'>".number_format($spent,2)."</td>
            <td class='text-end'>".number_format($remaining,2)."</td>
            <td>
                <div class='progress'>
                    <div class='progress-bar $bar' style='width: ".round($usage,1)."%;'>".round($usage,1)."%</div>
                </div>
            </td>
        </tr>";
    }
} else {
    echo "<tr><td colspan='5' class='text-muted text-center'>No activities found for this project</td></tr>";
}
?>
