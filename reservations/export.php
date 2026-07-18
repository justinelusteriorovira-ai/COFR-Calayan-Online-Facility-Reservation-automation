<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once("../config/db.php");

// Reuse the same filter parameters from index.php
$filter_search = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';
$filter_facility = $_GET['facility'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';

// Build WHERE clause
$where_clauses = [];
$bind_types = '';
$bind_values = [];

if (!empty($filter_search)) {
    $where_clauses[] = "r.fb_name LIKE ?";
    $bind_types .= 's';
    $bind_values[] = '%' . $filter_search . '%';
}
if (!empty($filter_status)) {
    $where_clauses[] = "r.status = ?";
    $bind_types .= 's';
    $bind_values[] = $filter_status;
}
if (!empty($filter_facility)) {
    $where_clauses[] = "r.facility_id = ?";
    $bind_types .= 'i';
    $bind_values[] = intval($filter_facility);
}
if (!empty($filter_date_from)) {
    $where_clauses[] = "r.reservation_date >= ?";
    $bind_types .= 's';
    $bind_values[] = $filter_date_from;
}
if (!empty($filter_date_to)) {
    $where_clauses[] = "r.reservation_date <= ?";
    $bind_types .= 's';
    $bind_values[] = $filter_date_to;
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$sql = "
    SELECT r.fb_name, f.name AS facility_name, r.reservation_date, 
           r.start_time, r.end_time, r.duration_hours, r.total_cost,
           r.status, r.reservation_type, r.user_type, r.user_email,
           r.user_phone, r.purpose, r.num_attendees, r.created_at
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    $where_sql
    ORDER BY r.created_at DESC
";

if (!empty($bind_types)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($bind_types, ...$bind_values);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

// Output CSV
$filename = 'reservations_export_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// BOM for Excel UTF-8 compatibility
fwrite($output, "\xEF\xBB\xBF");

// Header row
fputcsv($output, [
    'Name', 'Facility', 'Date', 'Start Time', 'End Time',
    'Duration (hrs)', 'Cost', 'Status', 'Type', 'User Type',
    'Email', 'Phone', 'Purpose', 'Attendees', 'Created At'
]);

// Data rows
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['fb_name'],
        $row['facility_name'],
        $row['reservation_date'],
        substr($row['start_time'], 0, 5),
        substr($row['end_time'], 0, 5),
        $row['duration_hours'],
        $row['total_cost'] ? number_format($row['total_cost'], 2) : '0.00',
        $row['status'],
        $row['reservation_type'],
        $row['user_type'],
        $row['user_email'],
        $row['user_phone'],
        $row['purpose'],
        $row['num_attendees'],
        $row['created_at']
    ]);
}

fclose($output);
exit;
?>
