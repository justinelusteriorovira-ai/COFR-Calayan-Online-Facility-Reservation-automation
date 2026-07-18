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

// Fetch facility details for logging BEFORE deletion
$fac_stmt = $conn->prepare("SELECT * FROM facilities WHERE id = ?");
$fac_stmt->bind_param("i", $id);
$fac_stmt->execute();
$facility = $fac_stmt->get_result()->fetch_assoc();

// Check if there are active reservations for this facility
$check = $conn->prepare("SELECT id FROM reservations WHERE facility_id = ? LIMIT 1");
$check->bind_param("i", $id);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    header("Location: index.php?error=Cannot delete facility. It has existing reservations.");
    exit;
}

$stmt = $conn->prepare("DELETE FROM facilities WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    if ($facility) {
        require_once("../config/audit_helper.php");
        logActivity($conn, 'DELETE', 'FACILITY', $id, "Deleted facility: " . $facility['name'], $facility, null);
    }
    header("Location: index.php?msg=Facility deleted successfully.");
} else {
    header("Location: index.php?error=Delete failed: " . $conn->error);
}
exit;
?>
