<?php
/**
 * API: List Available Facilities
 * Returns all AVAILABLE facilities with pricing, hours, capacity.
 * Designed for chatbot use — lets users browse facilities before booking.
 * Requires API key (X-API-Key header) or admin session.
 *
 * GET params:
 *   status   — optional filter: AVAILABLE (default), MAINTENANCE, CLOSED, ALL
 *
 * Response JSON:
 *   success, count, facilities [...]
 */
require_once("../config/db.php");
require_once("../config/api_auth.php");

requireAPIAuth();

header("Content-Type: application/json");

$filter_status = strtoupper($_GET["status"] ?? "AVAILABLE");
$valid_statuses = ["AVAILABLE", "MAINTENANCE", "CLOSED", "ALL"];
if (!in_array($filter_status, $valid_statuses)) {
    $filter_status = "AVAILABLE";
}

if ($filter_status === "ALL") {
    $result = $conn->query("
        SELECT id, name, description, capacity, status,
               price_per_hour, price_per_day,
               open_time, close_time,
               advance_days_required, min_duration_hours, max_duration_hours,
               allowed_days, image
        FROM facilities
        ORDER BY name ASC
    ");
} else {
    $stmt = $conn->prepare("
        SELECT id, name, description, capacity, status,
               price_per_hour, price_per_day,
               open_time, close_time,
               advance_days_required, min_duration_hours, max_duration_hours,
               allowed_days, image
        FROM facilities
        WHERE status = ?
        ORDER BY name ASC
    ");
    $stmt->bind_param("s", $filter_status);
    $stmt->execute();
    $result = $stmt->get_result();
}

$facilities = [];
$day_names = ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];

while ($row = $result->fetch_assoc()) {
    // Convert allowed_days CSV to human-readable
    $allowed = [];
    if (!empty($row["allowed_days"])) {
        foreach (explode(",", $row["allowed_days"]) as $d) {
            $allowed[] = $day_names[intval(trim($d))] ?? $d;
        }
    }

    $facilities[] = [
        "id"                    => (int)$row["id"],
        "name"                  => $row["name"],
        "description"           => $row["description"],
        "capacity"              => (int)$row["capacity"],
        "status"                => $row["status"],
        "price_per_hour"        => (float)$row["price_per_hour"],
        "price_per_day"         => (float)$row["price_per_day"],
        "open_time"             => substr($row["open_time"], 0, 5),
        "close_time"            => substr($row["close_time"], 0, 5),
        "advance_days_required" => (int)$row["advance_days_required"],
        "min_duration_hours"    => (int)$row["min_duration_hours"],
        "max_duration_hours"    => (int)$row["max_duration_hours"],
        "allowed_days"          => $allowed,
        "image"                 => $row["image"],
    ];
}

echo json_encode([
    "success"    => true,
    "count"      => count($facilities),
    "facilities" => $facilities
]);

$conn->close();
