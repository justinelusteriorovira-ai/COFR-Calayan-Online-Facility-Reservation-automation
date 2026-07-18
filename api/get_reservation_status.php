<?php
/**
 * API: Get Reservation Status
 * Returns status and details for a reservation by ID or fb_user_id.
 * Requires API key (X-API-Key header) or admin session.
 *
 * GET params:
 *   id          — reservation ID (optional)
 *   fb_user_id  — Facebook user ID (optional, returns latest reservation)
 *
 * Response JSON:
 *   success, reservation { id, status, facility_name, date, start_time, end_time, cost, reject_reason, cancel_reason }
 */
require_once("../config/db.php");
require_once("../config/api_auth.php");

requireAPIAuth();

header("Content-Type: application/json");

$reservation_id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;
$fb_user_id     = $_GET["fb_user_id"] ?? '';

if ($reservation_id <= 0 && empty($fb_user_id)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Provide either 'id' or 'fb_user_id' query parameter."]);
    exit;
}

if ($reservation_id > 0) {
    $stmt = $conn->prepare("
        SELECT r.id, r.status, r.reservation_date, r.start_time, r.end_time,
               r.total_cost, r.reject_reason, r.cancel_reason, r.purpose,
               r.fb_name, r.user_email, r.user_phone, r.reservation_type,
               r.approval_reason, f.name AS facility_name
        FROM reservations r
        JOIN facilities f ON r.facility_id = f.id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $reservation_id);
} else {
    $stmt = $conn->prepare("
        SELECT r.id, r.status, r.reservation_date, r.start_time, r.end_time,
               r.total_cost, r.reject_reason, r.cancel_reason, r.purpose,
               r.fb_name, r.user_email, r.user_phone, r.reservation_type,
               r.approval_reason, f.name AS facility_name
        FROM reservations r
        JOIN facilities f ON r.facility_id = f.id
        WHERE r.fb_user_id = ?
        ORDER BY r.created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("s", $fb_user_id);
}

$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Reservation not found."]);
    exit;
}

echo json_encode([
    "success"     => true,
    "reservation" => [
        "id"              => $row["id"],
        "status"          => $row["status"],
        "facility"        => $row["facility_name"],
        "date"            => $row["reservation_date"],
        "start_time"      => substr($row["start_time"], 0, 5),
        "end_time"        => substr($row["end_time"], 0, 5),
        "cost"            => (float)$row["total_cost"],
        "purpose"         => $row["purpose"],
        "type"            => $row["reservation_type"],
        "approval_reason" => $row["approval_reason"] ?? null,
        "reject_reason"   => $row["reject_reason"] ?? null,
        "cancel_reason"   => $row["cancel_reason"] ?? null,
        "contact_email"   => $row["user_email"] ?? null,
        "contact_phone"   => $row["user_phone"] ?? null,
    ]
]);

$conn->close();
