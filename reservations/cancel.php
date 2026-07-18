<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once("../config/db.php");

// Check if ID is provided
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    header("Location: index.php?error=Invalid reservation ID.");
    exit;
}

$id = (int)$_GET["id"];

// Get the cancel reason from POST (modal form) or GET fallback
$cancel_reason = '';
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $cancel_reason = trim($_POST["cancel_reason"] ?? '');
} else {
    $cancel_reason = trim($_GET["reason"] ?? '');
}

if (empty($cancel_reason)) {
    header("Location: index.php?error=A cancellation reason is required.");
    exit;
}

// Fetch the reservation
$stmt = $conn->prepare("
    SELECT r.*, f.name AS facility_name, f.advance_days_required 
    FROM reservations r 
    JOIN facilities f ON r.facility_id = f.id 
    WHERE r.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$reservation = $result->fetch_assoc();

if (!$reservation) {
    header("Location: index.php?error=Reservation not found.");
    exit;
}

// Only PENDING or APPROVED reservations can be cancelled
if (!in_array($reservation['status'], ['PENDING', 'APPROVED', 'PENDING_VERIFICATION', 'ON_HOLD'])) {
    header("Location: index.php?error=Only active reservations can be cancelled. Current status: " . $reservation['status']);
    exit;
}

// Check cancellation deadline: must be at least 24 hours before the reservation start
$reservation_start = new DateTime($reservation['reservation_date'] . ' ' . $reservation['start_time']);
$now = new DateTime();
$hours_until = ($reservation_start->getTimestamp() - $now->getTimestamp()) / 3600;

if ($hours_until < 24 && $reservation['status'] === 'APPROVED') {
    header("Location: index.php?error=Cannot cancel within 24 hours of the reservation start time. Please contact the CEFI office.");
    exit;
}

// Perform the cancellation
$update = $conn->prepare("
    UPDATE reservations 
    SET status = 'CANCELLED', 
        cancel_reason = ?, 
        cancelled_at = NOW()
    WHERE id = ?
");
$update->bind_param("si", $cancel_reason, $id);

if ($update->execute()) {
    // Log to audit trail
    require_once("../config/audit_helper.php");
    $actionDetail = "Cancelled reservation for {$reservation['fb_name']} at {$reservation['facility_name']} — Reason: $cancel_reason";
    logActivity($conn, 'UPDATE', 'RESERVATION', $id, $actionDetail, $reservation, [
        'status' => 'CANCELLED',
        'cancel_reason' => $cancel_reason,
        'cancelled_at' => date('Y-m-d H:i:s')
    ]);
    
    header("Location: index.php?msg=Reservation for \"{$reservation['fb_name']}\" has been cancelled successfully.");
    exit;
} else {
    header("Location: index.php?error=Database error: " . $conn->error);
    exit;
}
?>
