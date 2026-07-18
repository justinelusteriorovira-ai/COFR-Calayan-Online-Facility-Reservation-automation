<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

require_once("../config/db.php");

header("Content-Type: application/json");

// Expect ?month=YYYY-MM
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Validate format
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(["error" => "Invalid month format. Use YYYY-MM."]);
    exit;
}

$start = $month . '-01';
$end   = date('Y-m-t', strtotime($start)); // last day of month

$stmt = $conn->prepare("
    SELECT 
        r.id,
        r.fb_name,
        r.reservation_date,
        r.start_time,
        r.end_time,
        r.status,
        r.purpose,
        f.name AS facility_name
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    WHERE r.reservation_date BETWEEN ? AND ?
    ORDER BY r.reservation_date ASC, r.start_time ASC
");

$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$result = $stmt->get_result();

$events = [];
while ($row = $result->fetch_assoc()) {
    $events[] = [
        "id"               => (int) $row['id'],
        "type"             => "reservation",
        "fb_name"          => $row['fb_name'],
        "facility_name"    => $row['facility_name'],
        "reservation_date" => $row['reservation_date'],
        "start_time"       => $row['start_time'],
        "end_time"         => $row['end_time'],
        "status"           => $row['status'],
        "purpose"          => $row['purpose'],
    ];
}

// Fetch Special Occasions
$o_stmt = $conn->prepare("
    SELECT * FROM special_occasions 
    WHERE (occasion_date BETWEEN ? AND ?) 
       OR (end_date BETWEEN ? AND ?)
       OR (? BETWEEN occasion_date AND end_date)
");
$o_stmt->bind_param("sssss", $start, $end, $start, $end, $start);
$o_stmt->execute();
$o_result = $o_stmt->get_result();

while ($row = $o_result->fetch_assoc()) {
    $events[] = [
        "id"            => (int) $row['id'],
        "type"          => "occasion",
        "title"         => $row['title'],
        "occasion_date" => $row['occasion_date'],
        "end_date"      => $row['end_date'],
        "occ_type"      => $row['type'],
        "description"   => $row['description'],
        "color"         => $row['color'],
        "is_recurring"  => (bool)$row['is_recurring']
    ];
}

echo json_encode($events);
