<?php
/**
 * API: Webhook Notify
 * Called by n8n (or another automation tool) to trigger notifications.
 * When a reservation is approved or rejected, n8n can call POST to this endpoint
 * which will confirm the action and optionally record the webhook delivery.
 *
 * This is a RECEIVING endpoint — n8n sends a webhook here to confirm events.
 * The actual outbound notification to Facebook Messenger is handled by n8n.
 *
 * POST body (JSON):
 *   event          — "APPROVED" | "REJECTED" | "CANCELLED"
 *   reservation_id — integer
 *   fb_user_id     — string (for Messenger targeting)
 *   message        — string (message text for Messenger)
 *
 * Response JSON:
 *   success, received_at
 */
require_once("../config/db.php");
require_once("../config/api_auth.php");

requireAPIAuth();

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "POST method required."]);
    exit;
}

$data           = getRequestData();
$event          = strtoupper($data["event"] ?? '');
$reservation_id = isset($data["reservation_id"]) ? intval($data["reservation_id"]) : 0;
$fb_user_id     = $data["fb_user_id"] ?? '';
$message        = $data["message"] ?? '';

$valid_events = ["APPROVED", "REJECTED", "CANCELLED", "PENDING", "EXPIRED"];
if (!in_array($event, $valid_events) || $reservation_id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Provide a valid 'event' and 'reservation_id'."]);
    exit;
}

// Verify reservation exists
$check = $conn->prepare("SELECT id, status, fb_name FROM reservations WHERE id = ? LIMIT 1");
$check->bind_param("i", $reservation_id);
$check->execute();
$res = $check->get_result()->fetch_assoc();
$check->close();

if (!$res) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Reservation #$reservation_id not found."]);
    exit;
}

// Log the webhook delivery to audit_logs
require_once("../config/audit_helper.php");
$details = "Webhook received: event=$event, fb_user_id=$fb_user_id, message=" . substr($message, 0, 100);
logActivity($conn, 'WEBHOOK', 'RESERVATION', $reservation_id, $details, null, [
    'event'      => $event,
    'fb_user_id' => $fb_user_id,
    'message'    => $message
]);

echo json_encode([
    "success"        => true,
    "received_at"    => date("c"),
    "reservation_id" => $reservation_id,
    "event"          => $event,
    "fb_name"        => $res["fb_name"],
    "note"           => "Webhook logged. n8n should now send the Messenger notification."
]);

$conn->close();
