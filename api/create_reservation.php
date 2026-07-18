<?php
require_once("../config/db.php");
require_once("../config/api_auth.php");

requireAPIAuth();

header("Content-Type: application/json");

// Accept JSON body or POST form data
$data = getRequestData();

$fb_name          = $data["fb_name"] ?? null;
$fb_user_id       = $data["fb_user_id"] ?? null;
$facility_id      = isset($data["facility_id"]) ? intval($data["facility_id"]) : null;
$reservation_date = $data["reservation_date"] ?? null;
$start_time       = $data["start_time"] ?? null;
$end_time         = $data["end_time"] ?? null;
$purpose          = $data["purpose"] ?? '';
$user_email       = $data["user_email"] ?? '';
$user_phone       = $data["user_phone"] ?? '';
$user_type        = $data["user_type"] ?? 'Outside';
$num_attendees    = isset($data["num_attendees"]) ? intval($data["num_attendees"]) : 0;
$reservation_needs = isset($data["reservation_needs"]) ? (is_array($data["reservation_needs"]) ? json_encode($data["reservation_needs"]) : $data["reservation_needs"]) : null;
$remarks          = $data["remarks"] ?? null;

if (!$fb_name || !$fb_user_id || !$facility_id || !$reservation_date || !$start_time || !$end_time) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error"   => "Missing required fields: fb_name, fb_user_id, facility_id, reservation_date, start_time, end_time."
    ]);
    exit;
}

// Insert as PENDING with all extended fields
$stmt = $conn->prepare("
    INSERT INTO reservations
    (fb_user_id, fb_name, facility_id, reservation_date, start_time, end_time, purpose,
     user_email, user_phone, user_type, num_attendees, reservation_type, reservation_needs, remarks)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ONLINE', ?, ?)
");
$stmt->bind_param(
    "ssisssssssiss",
    $fb_user_id, $fb_name, $facility_id, $reservation_date,
    $start_time, $end_time, $purpose,
    $user_email, $user_phone, $user_type, $num_attendees,
    $reservation_needs, $remarks
);

if ($stmt->execute()) {
    $reservation_id = $conn->insert_id;

    // Save addon selections with quantities
    $addon_ids = isset($data["addon_ids"]) && is_array($data["addon_ids"]) ? $data["addon_ids"] : [];
    $addon_quantities = isset($data["addon_quantities"]) && is_array($data["addon_quantities"]) ? $data["addon_quantities"] : [];
    if (!empty($addon_ids)) {
        $addonStmt = $conn->prepare("INSERT INTO reservation_addon_selections (reservation_id, addon_id, quantity) VALUES (?, ?, ?)");
        foreach ($addon_ids as $addon_id) {
            $quantity = isset($addon_quantities[$addon_id]) ? intval($addon_quantities[$addon_id]) : 1;
            if ($quantity < 1) $quantity = 1;
            $addonStmt->bind_param("iii", $reservation_id, $addon_id, $quantity);
            $addonStmt->execute();
        }
        $addonStmt->close();
    }

    echo json_encode([
        "success"        => true,
        "reservation_id" => $reservation_id,
        "message"        => "Reservation submitted. Pending admin approval.",
        "status"         => "PENDING"
    ]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Database error: " . $conn->error]);
}

$stmt->close();
$conn->close();
