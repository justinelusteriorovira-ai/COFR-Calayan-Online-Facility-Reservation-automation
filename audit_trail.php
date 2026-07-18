<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit;
}

require_once("config/db.php");

// Pagination
$limit = 20;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filter
$where_clauses = [];
$params = [];
$types = "";

if (isset($_GET['action']) && !empty($_GET['action'])) {
    $where_clauses[] = "a.action = ?";
    $params[] = $_GET['action'];
    $types .= "s";
}

if (isset($_GET['entity']) && !empty($_GET['entity'])) {
    $where_clauses[] = "a.entity_type = ?";
    $params[] = $_GET['entity'];
    $types .= "s";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get total count for pagination
$count_query = "SELECT COUNT(*) FROM audit_logs a $where_sql";
$count_stmt = $conn->prepare($count_query);
if (!$count_stmt) {
    die("Count query preparation failed: " . $conn->error);
}
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_rows = $count_stmt->get_result()->fetch_row()[0];
$total_pages = ceil($total_rows / $limit);

// Get logs with admin names
$query = "
    SELECT a.*, adm.username as admin_name 
    FROM audit_logs a 
    LEFT JOIN users adm ON a.admin_id = adm.id 
    $where_sql  
    ORDER BY a.created_at DESC 
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($query);

if (!$stmt) {
    die("Query preparation failed: " . $conn->error);
}

$stmt_params = array_merge($params, [$limit, $offset]);
$stmt_types = $types . "ii";
$stmt->bind_param($stmt_types, ...$stmt_params);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trail - CEFI Reservation</title>
    <link rel="stylesheet" href="style/navbar.css?v=2">
    <link rel="stylesheet" href="style/audit_trail.css">
</head>

<body>

    <?php include 'includes/navbar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1>Audit Trail</h1>
            <div class="header-user">Staff</div>
        </div>

        <div class="container">

            <form method="GET" class="filters">
                <div class="input-group">
                    <label>Action Type</label>
                    <select name="action">
                        <option value="">All Actions</option>
                        <option value="CREATE" <?= isset($_GET['action']) && $_GET['action'] == 'CREATE' ? 'selected' : '' ?>>CREATE</option>
                        <option value="UPDATE" <?= isset($_GET['action']) && $_GET['action'] == 'UPDATE' ? 'selected' : '' ?>>UPDATE</option>
                        <option value="DELETE" <?= isset($_GET['action']) && $_GET['action'] == 'DELETE' ? 'selected' : '' ?>>DELETE</option>
                        <option value="LOGIN" <?= isset($_GET['action']) && $_GET['action'] == 'LOGIN' ? 'selected' : '' ?>>LOGIN</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>Entity Category</label>
                    <select name="entity">
                        <option value="">All Entities</option>
                        <option value="RESERVATION" <?= isset($_GET['entity']) && $_GET['entity'] == 'RESERVATION' ? 'selected' : '' ?>>RESERVATION</option>
                        <option value="FACILITY" <?= isset($_GET['entity']) && $_GET['entity'] == 'FACILITY' ? 'selected' : '' ?>>FACILITY</option>
                        <option value="OCCASION" <?= isset($_GET['entity']) && $_GET['entity'] == 'OCCASION' ? 'selected' : '' ?>>OCCASION</option>
                    </select>
                </div>
                <button type="submit" class="btn-filter">Apply Filters</button>
                <a href="audit_trail.php" class="btn-reset">Reset</a>
            </form>

            <div class="audit-table-container">
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Timestamp</th>
                            <th>Staff Member</th>
                            <th>Action</th>
                            <th>Target Entity</th>
                            <th>Activity Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $row_num = $offset + 1; while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td style="font-weight:600;color:#94a3b8;"><?= $row_num++ ?></td>
                                <td class="timestamp"><?= date('M d, Y H:i:s', strtotime($row['created_at'])) ?></td>
                                <td class="admin-name"><?= htmlspecialchars($row['admin_name'] ?? 'System') ?></td>
                                <td>
                                    <span class="action-badge bg-<?= strtolower($row['action']) ?>">
                                        <?= $row['action'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="entity-tag"><?= $row['entity_type'] ?></span>
                                    <small class="entity-id">#<?= $row['entity_id'] ?></small>
                                </td>
                                <td>
                                    <div class="details-wrapper">
                                        <?= htmlspecialchars($row['details']) ?>
                                    </div>
                                </td>

                            </tr>
                        <?php endwhile; ?>
                        <?php if ($result->num_rows == 0): ?>
                            <tr>
                                <td colspan="6" class="no-data">
                                    No activity logs found matching your filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&action=<?= urlencode($_GET['action'] ?? '') ?>&entity=<?= urlencode($_GET['entity'] ?? '') ?>"
                            class="<?= $page == $i ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            © 2026 CEFI ONLINE FACILITY RESERVATION. All rights reserved. | Calayan Educational Foundation Inc.,
            Philippines
        </div>

</body>

</html>