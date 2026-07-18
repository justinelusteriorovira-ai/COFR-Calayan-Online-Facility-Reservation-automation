<?php
require_once("../config/db.php");
require_once("../config/api_auth.php");

requireAPIAuth();

header("Content-Type: application/json");

// Accept JSON body or POST form data
$data = getRequestData();

$facility_id = isset($data["facility_id"]) ? intval($data["facility_id"]) : null;

if (!$facility_id) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => "Missing required field: facility_id"
    ]);
    exit;
}

// Fetch add-ons for the facility
$stmt = $conn->prepare("
    SELECT id, facility_id, addon_name, price 
    FROM facility_addons 
    WHERE facility_id = ?
    ORDER BY addon_name ASC
");
$stmt->bind_param("i", $facility_id);
$stmt->execute();
$result = $stmt->get_result();

$addons = [];
while ($row = $result->fetch_assoc()) {
    $addons[] = [
        "id" => (int)$row["id"],
        "facility_id" => (int)$row["facility_id"],
        "addon_name" => $row["addon_name"],
        "price" => (float)$row["price"]
    ];
}

echo json_encode([
    "success" => true,
    "addons" => $addons,
    "count" => count($addons)
]);

$stmt->close();
$conn->close();