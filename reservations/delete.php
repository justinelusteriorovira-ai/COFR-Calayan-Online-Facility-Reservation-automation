<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once("../config/db.php");
require_once("../config/csrf.php");

// Only accept POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

// Validate CSRF
requireCSRF();

if (!isset($_POST["id"]) || !is_numeric($_POST["id"])) {
    header("Location: index.php");
    exit;
}

$id = (int)$_POST["id"];

// Fetch reservation details for logging BEFORE deletion
$res_stmt = $conn->prepare("SELECT * FROM reservations WHERE id = ?");
$res_stmt->bind_param("i", $id);
$res_stmt->execute();
$reservation = $res_stmt->get_result()->fetch_assoc();

$stmt = $conn->prepare("DELETE FROM reservations WHERE id = ?");
$stmt->bind_param("i", $id);

$redirect_to = "index.php";
if (isset($_POST['redirect_to']) && !empty($_POST['redirect_to'])) {
    // Only allow relative redirects to prevent open redirect
    $redirect_to = basename($_POST['redirect_to']);
    if (!in_array($redirect_to, ['index.php'])) {
        // Check if it's a dashboard redirect
        if (strpos($_POST['redirect_to'], 'dashboard.php') !== false) {
            $redirect_to = '../dashboard.php';
        } else {
            $redirect_to = 'index.php';
        }
    }
}

if ($stmt->execute()) {
    if ($reservation) {
        require_once("../config/audit_helper.php");
        logActivity($conn, 'DELETE', 'RESERVATION', $id, "Deleted reservation for " . $reservation['fb_name'], $reservation, null);
    }
    header("Location: $redirect_to?msg=Reservation deleted successfully.");
} else {
    header("Location: $redirect_to?error=Delete failed: " . $conn->error);
}
exit;
?>
