<?php
require_once("../config/db.php");

header("Content-Type: application/json");

// Define API Key (In production, move this to config/db.php or env)
$API_KEY = "CEFI_CHATBOT_2026";

$received_key = $_GET['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';

if ($received_key !== $API_KEY) {
    http_response_code(403);
    echo json_encode(["error" => "Invalid API Key"]);
    exit;
}

$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
$today = date('Y-m-d');
$end_date = date('Y-m-d', strtotime("+$days days"));

$response = [
    "generated_at" => date('Y-m-d H:i:s'),
    "range_start" => $today,
    "range_end" => $end_date,
    "reservations" => [],
    "special_occasions" => []
];

// Fetch Approved Reservations
$stmt = $conn->prepare("
    SELECT r.*, f.name AS facility_name 
    FROM reservations r 
    JOIN facilities f ON r.facility_id = f.id 
    WHERE r.status = 'APPROVED' AND r.reservation_date BETWEEN ? AND ?
    ORDER BY r.reservation_date ASC, r.start_time ASC
");
$stmt->bind_param("ss", $today, $end_date);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $response["reservations"][] = $row;
}

// Fetch Special Occasions
$o_stmt = $conn->prepare("
    SELECT * FROM special_occasions 
    WHERE (occasion_date BETWEEN ? AND ?) 
       OR (end_date BETWEEN ? AND ?)
       OR (? BETWEEN occasion_date AND end_date)
    ORDER BY occasion_date ASC
");
$o_stmt->bind_param("sssss", $today, $end_date, $today, $end_date, $today);
$o_stmt->execute();
$o_result = $o_stmt->get_result();
while ($row = $o_result->fetch_assoc()) {
    $response["special_occasions"][] = $row;
}

echo json_encode($response);
