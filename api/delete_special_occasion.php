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

    if (empty($id)) {
        echo json_encode(["success" => false, "message" => "ID is required."]);
        exit;
    }

    // Fetch record for logging before deletion
    $old_stmt = $conn->prepare("SELECT * FROM special_occasions WHERE id = ?");
    $old_stmt->bind_param("i", $id);
    $old_stmt->execute();
    $old_values = $old_stmt->get_result()->fetch_assoc();

    $stmt = $conn->prepare("DELETE FROM special_occasions WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        if ($old_values) {
            require_once("../config/audit_helper.php");
            logActivity($conn, 'DELETE', 'OCCASION', $id, "Deleted special occasion: " . $old_values['title'], $old_values, null);
        }
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => $conn->error]);
    }
}
