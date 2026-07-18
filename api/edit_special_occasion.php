<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

require_once("../config/db.php");

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = $_POST['id'] ?? 0;
    $title = $_POST['title'] ?? '';
    $date = $_POST['occasion_date'] ?? '';
    $end_date = $_POST['end_date'] ?: null;
    $type = $_POST['type'] ?? 'SCHOOL_EVENT';
    $desc = $_POST['description'] ?? '';
    $color = $_POST['color'] ?? '#8e44ad';
    $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;

    if (empty($id) || empty($title) || empty($date)) {
        echo json_encode(["success" => false, "message" => "ID, Title and Date are required."]);
        exit;
    }

    // Fetch old values for logging
    $old_stmt = $conn->prepare("SELECT * FROM special_occasions WHERE id = ?");
    $old_stmt->bind_param("i", $id);
    $old_stmt->execute();
    $old_values = $old_stmt->get_result()->fetch_assoc();

    $stmt = $conn->prepare("UPDATE special_occasions SET title=?, occasion_date=?, end_date=?, type=?, description=?, color=?, is_recurring=? WHERE id=?");
    $stmt->bind_param("ssssssii", $title, $date, $end_date, $type, $desc, $color, $is_recurring, $id);

    if ($stmt->execute()) {
        require_once("../config/audit_helper.php");
        logActivity($conn, 'UPDATE', 'OCCASION', $id, "Updated special occasion: $title", $old_values, [
            'title' => $title,
            'occasion_date' => $date,
            'end_date' => $end_date,
            'type' => $type,
            'description' => $desc,
            'color' => $color,
            'is_recurring' => $is_recurring
        ]);
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => $conn->error]);
    }
}
