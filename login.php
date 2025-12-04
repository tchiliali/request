<?php
session_start();
include "config.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email && $password) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {

                // Store session info
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['user_id'] = $user['id'];

                // Redirect based on role
                switch ($user['role']) {
                    case 'admin':
                        header("Location: admin/dashboard.php");
                        break;

                    case 'manager':
                        header("Location: manager/dashboard.php");
                        break;

                    case 'accountant':
                        header("Location: accountant/dashboard.php");
                        break;

                    case 'accountant_manager':  // NEW ROLE
                        header("Location: accountant_manager/dashboard.php");
                        break;

                    default: // requester
                        header("Location: requester/dashboard.php");
                        break;
                }
                exit();

            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "User not found.";
        }
    } else {
        $error = "Please enter both email and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Fund Request System</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f3f6f9; }
        .card { border-radius: 12px; }
    </style>
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow">
                <div class="card-body p-4">
                    <h3 class="text-center mb-4">Fund Request System</h3>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Login</button>
                    </form>

                </div>
            </div>
            <p class="text-center mt-3 text-muted">&copy; <?php echo date("Y"); ?> Habitat Malawi</p>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
