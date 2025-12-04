<?php
session_start();

if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'accountant') {
    header("Location: ../login.php");
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payment Successful</title>
<link href="../assets/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container text-center mt-5">

    <div class="alert alert-success shadow p-4">
        <h2>✔ Payment Recorded Successfully</h2>
        <p>The transaction has been updated in the system.</p>
        <a href="view_requests.php" class="btn btn-primary">Back to Requests</a>
    </div>

</div>

</body>
</html>
