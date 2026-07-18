<?php
require_once("../config/db.php");
require_once("../config/api_auth.php");

requireAPIAuth();

header("Content-Type: application/json");

// Accept JSON body or POST form data
$data = getRequestData();
$facility_id = isset($data["facility_id"]) ? intval($data["facility_id"]) : 0;
$reservation_date = $data["reservation_date"] ?? "";
$start_time = $data["start_time"] ?? "";
$end_time = $data["end_time"] ?? "";

if (
    $facility_id <= 0 ||
    empty($reservation_date) ||
    empty($start_time) ||
    empty($end_time)
) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing required fields."
    ]);
    exit;
}

$stmt = $conn->prepare("
    SELECT id FROM reservations
    WHERE facility_id = ?
    AND reservation_date = ?
    AND status = 'APPROVED'
    AND (
        (? < end_time) AND (? > start_time)
    )
");

$stmt->bind_param(
    "isss",
    $facility_id,
    $reservation_date,
    $start_time,
    $end_time
);

$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    echo json_encode([
        "status" => "unavailable",
        "message" => "Facility already booked for this time."
    ]);
} else {
    echo json_encode([
        "status" => "available",
        "message" => "Facility is available."
    ]);
}

$stmt->close();
$conn->close();
