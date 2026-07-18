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
    $title = $_POST['title'] ?? '';
    $date = $_POST['occasion_date'] ?? '';
    $end_date = $_POST['end_date'] ?: null;
    $type = $_POST['type'] ?? 'SCHOOL_EVENT';
    $desc = $_POST['description'] ?? '';
    $color = $_POST['color'] ?? '#8e44ad';
    $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;

    if (empty($title) || empty($date)) {
        echo json_encode(["success" => false, "message" => "Title and Date are required."]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO special_occasions (title, occasion_date, end_date, type, description, color, is_recurring) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssi", $title, $date, $end_date, $type, $desc, $color, $is_recurring);

    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        require_once("../config/audit_helper.php");
        logActivity($conn, 'CREATE', 'OCCASION', $new_id, "Added special occasion: $title", null, [
            'title' => $title,
            'occasion_date' => $date,
            'end_date' => $end_date,
            'type' => $type,
            'description' => $desc,
            'color' => $color,
            'is_recurring' => $is_recurring
        ]);
        echo json_encode(["success" => true, "id" => $new_id]);
    } else {
        echo json_encode(["success" => false, "message" => $conn->error]);
    }
}
