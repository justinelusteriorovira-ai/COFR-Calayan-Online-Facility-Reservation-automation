<?php
// API: Returns facility details including pricing, hours, and rules
require_once("../config/db.php");
require_once("../config/api_auth.php");

requireAPIAuth();

header("Content-Type: application/json");

$id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;

if ($id <= 0) {
    echo json_encode(["status" => "error", "message" => "Invalid facility ID."]);
    exit;
}

$stmt = $conn->prepare("SELECT id, name, description, capacity, status, price_per_hour, price_per_day, open_time, close_time, advance_days_required, min_duration_hours, max_duration_hours, allowed_days, image FROM facilities WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$facility = $result->fetch_assoc();

if (!$facility) {
    echo json_encode(["status" => "error", "message" => "Facility not found."]);
    exit;
}

// Format times for display
$facility['open_time'] = substr($facility['open_time'], 0, 5);
$facility['close_time'] = substr($facility['close_time'], 0, 5);

echo json_encode([
    "status" => "success",
    "facility" => $facility
]);

$stmt->close();
$conn->close();
