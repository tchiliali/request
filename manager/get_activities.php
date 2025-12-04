<?php
include "../config.php";

// Check if project_id is provided
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    echo '<option value="">-- Select Activity --</option>';
    exit();
}

$projectId = (int)$_GET['project_id'];

// Fetch activities for the given project
$stmt = $conn->prepare("SELECT id, name FROM activities WHERE project_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $projectId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo '<option value="">-- Select Activity --</option>';
    while ($row = $result->fetch_assoc()) {
        echo '<option value="' . $row['id'] . '">' . htmlspecialchars($row['name']) . '</option>';
    }
} else {
    echo '<option value="">No activities available</option>';
}
?>
