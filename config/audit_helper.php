<?php
/**
 * Log activity to the audit_logs table
 *
 * @param mysqli $conn The database connection
 * @param string $action The action performed (CREATE, UPDATE, DELETE, LOGIN)
 * @param string $entity_type The type of entity (RESERVATION, FACILITY, OCCASION)
 * @param int|null $entity_id The ID of the entity
 * @param string $details A human-readable description of the change
 * @param array|null $old_values The previous state of the entity
 * @param array|null $new_values The new state of the entity
 * @return bool True on success, false on failure
 */
function logActivity($conn, $action, $entity_type, $entity_id = null, $details = "", $old_values = null, $new_values = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    $old_json = $old_values ? json_encode($old_values) : null;
    $new_json = $new_values ? json_encode($new_values) : null;
    
    $stmt = $conn->prepare("
        INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, details, old_values, new_values, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param("ississss", $user_id, $action, $entity_type, $entity_id, $details, $old_json, $new_json, $ip_address);
    return $stmt->execute();
}
?>
