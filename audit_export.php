<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit;
}

require_once("config/db.php");

$sql = "
    SELECT al.created_at, a.username AS admin_name, al.action, 
           al.entity_type, al.entity_id, al.details
    FROM audit_logs al
    LEFT JOIN users a ON al.admin_id = a.id
    ORDER BY al.created_at DESC
";

$result = $conn->query($sql);

$filename = 'audit_log_export_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, ['Timestamp', 'Staff', 'Action', 'Entity Type', 'Entity ID', 'Details']);

while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['created_at'],
        $row['admin_name'] ?? 'System',
        $row['action'],
        $row['entity_type'],
        $row['entity_id'],
        $row['details']
    ]);
}

fclose($output);
exit;
?>
