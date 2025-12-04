<?php
session_start();
include "../config.php";

// Ensure only accountant can access
if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'accountant') {
    header("Location: ../login.php");
    exit();
}

// Validate request ID
$request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;
if ($request_id <= 0) {
    die("Invalid request ID.");
}

// Fetch request
$stmt = $conn->prepare("SELECT * FROM requests WHERE id = ?");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("Request not found.");
}
$request = $result->fetch_assoc();

// Handle Payment Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method   = trim($_POST['payment_method'] ?? '');
    $payment_provider = trim($_POST['payment_provider'] ?? '');
    $transaction_ref  = trim($_POST['transaction_ref'] ?? '');
    $phone_number     = trim($_POST['phone_number'] ?? '');
    $account_number   = trim($_POST['account_number'] ?? '');

    // Validation
    if (empty($payment_method) || empty($transaction_ref)) {
        $_SESSION['flash_message'] = ["type" => "warning", "text" => "Please fill in all required fields."];
    } elseif ($payment_method === "Mobile Money" && empty($phone_number)) {
        $_SESSION['flash_message'] = ["type" => "warning", "text" => "Phone number is required for Mobile Money payments."];
    } elseif ($payment_method === "Bank Transfer" && empty($account_number)) {
        $_SESSION['flash_message'] = ["type" => "warning", "text" => "Account number is required for Bank Transfer payments."];
    } elseif ($request['status'] !== 'approve_for_payment') {  // ✅ Updated check
        $_SESSION['flash_message'] = ["type" => "danger", "text" => "This request is not ready for payment. Payment cannot be processed."];
    } else {
        $provider_text = $payment_provider ? " - Provider: $payment_provider" : "";
        $phone_text = $phone_number ? " - Phone: $phone_number" : "";
        $account_text = $account_number ? " - Account: $account_number" : "";
        $accountant_comment = "Paid via $payment_method$provider_text$phone_text$account_text. Ref: $transaction_ref";

        $stmt_update = $conn->prepare("
            UPDATE requests 
            SET status = 'paid',
                payment_method = ?,
                payment_provider = ?,
                phone_number = ?,
                account_number = ?,
                accountant_comment = ?,
                paid_at = NOW()
            WHERE id = ?
        ");

        $stmt_update->bind_param(
            "sssssi",
            $payment_method,
            $payment_provider,
            $phone_number,
            $account_number,
            $accountant_comment,
            $request_id
        );

        if ($stmt_update->execute()) {
            $_SESSION['last_payment'] = [
                "amount" => $request['amount'],
                "method" => $payment_method,
                "provider" => $payment_provider,
                "phone" => $phone_number,
                "account" => $account_number,
                "reference" => $transaction_ref,
                "request_id" => $request_id
            ];
            header("Location: payment_success.php");
            exit();
        } else {
            $_SESSION['flash_message'] = ["type" => "danger", "text" => "Failed to record payment: " . $stmt_update->error];
        }
    }
}

function safe($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Make Payment</title>
<link href="../assets/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background: #f3f6f9; }
.card { border-radius: 15px; border: none; }
.section-title { font-size: 18px; font-weight: bold; margin-bottom: 10px; }
</style>
</head>
<body>

<div class="container mt-4" style="max-width: 700px;">

    <h3 class="text-center mb-4 fw-bold">Process Payment</h3>

    <?php if (!empty($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?= $_SESSION['flash_message']['type'] ?>">
            <?= $_SESSION['flash_message']['text'] ?>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <div class="card shadow-sm p-4">

        <div class="mb-4 bg-light p-3 rounded">
            <h6 class="text-muted mb-1">Requester</h6>
            <div class="fw-bold"><?= safe($request['requester_email']) ?></div>

            <h6 class="text-muted mt-3 mb-1">Amount</h6>
            <div class="fw-bold text-success">MWK <?= number_format($request['amount'], 2) ?></div>
        </div>

        <form method="POST">

            <!-- Payment Method -->
            <div class="mb-3">
                <label class="form-label fw-bold">Payment Method</label>
                <select name="payment_method" id="payment_method" class="form-select" required>
                    <option value="">-- Select Method --</option>
                    <option value="Bank Transfer" <?= ($request['payment_method'] ?? '') === "Bank Transfer" ? 'selected' : '' ?>>Bank Transfer</option>
                    <option value="Mobile Money" <?= ($request['payment_method'] ?? '') === "Mobile Money" ? 'selected' : '' ?>>Mobile Money</option>
                    <option value="Cash" <?= ($request['payment_method'] ?? '') === "Cash" ? 'selected' : '' ?>>Cash</option>
                </select>
            </div>

            <!-- Provider -->
            <div class="mb-3" id="provider_div" style="display:none;">
                <label class="form-label fw-bold">Provider</label>
                <select name="payment_provider" id="payment_provider" class="form-select"></select>
            </div>

            <!-- Account Number (Bank Transfer only) -->
            <div class="mb-3" id="account_div" style="display:none;">
                <label class="form-label fw-bold">Account Number</label>
                <input type="text" name="account_number" id="account_number" class="form-control" placeholder="e.g. 100267" value="<?= safe($request['account_number'] ?? '') ?>">
            </div>

            <!-- Phone Number (Mobile Money only) -->
            <div class="mb-3" id="phone_div" style="display:none;">
                <label class="form-label fw-bold">Receiver Phone Number</label>
                <div class="input-group">
                    <span class="input-group-text">+265</span>
                    <input type="text" name="phone_number" id="phone_number" class="form-control" placeholder="991234567" value="<?= safe($request['phone_number'] ?? '') ?>">
                </div>
            </div>

            <!-- Transaction Reference -->
            <div class="mb-3">
                <label class="form-label fw-bold">Transaction Reference</label>
                <input type="text" name="transaction_ref" class="form-control" required value="<?= safe($request['transaction_ref'] ?? '') ?>">
            </div>

            <button class="btn btn-success w-100 py-2 fw-bold">Confirm Payment</button>
            <a href="view_requests.php" class="btn btn-secondary w-100 mt-2">Back to Requests</a>

        </form>
    </div>
</div>

<script>
const paymentMethod = document.getElementById("payment_method");
const providerDiv = document.getElementById("provider_div");
const phoneDiv = document.getElementById("phone_div");
const accountDiv = document.getElementById("account_div");
const providerSelect = document.getElementById("payment_provider");

// Pre-fill provider select options and visibility
function loadProviders(method, selectedProvider = '') {
    providerSelect.innerHTML = "";

    if (method === "Bank Transfer") {
        providerDiv.style.display = "block";
        phoneDiv.style.display = "none";
        accountDiv.style.display = "block";
        const options = ["National Bank","Standard Bank","FDH Bank"];
        providerSelect.innerHTML = options.map(p => `<option value="${p}" ${p === selectedProvider ? 'selected' : ''}>${p}</option>`).join('');
    } else if (method === "Mobile Money") {
        providerDiv.style.display = "block";
        phoneDiv.style.display = "block";
        accountDiv.style.display = "none";
        const options = ["Airtel Money","Mpamba"];
        providerSelect.innerHTML = options.map(p => `<option value="${p}" ${p === selectedProvider ? 'selected' : ''}>${p}</option>`).join('');
    } else {
        providerDiv.style.display = "none";
        phoneDiv.style.display = "none";
        accountDiv.style.display = "none";
    }
}

// Initial load based on existing request data
loadProviders("<?= safe($request['payment_method'] ?? '') ?>", "<?= safe($request['payment_provider'] ?? '') ?>");

// Listen for changes
paymentMethod.addEventListener("change", () => loadProviders(paymentMethod.value));
</script>

<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
