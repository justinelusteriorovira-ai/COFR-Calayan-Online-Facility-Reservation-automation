<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once("../config/db.php");

// Pagination
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filter
$where_clauses = [];
$params = [];
$types = "";

if (isset($_GET['status']) && !empty($_GET['status'])) {
    $where_clauses[] = "r.status = ?";
    $params[] = $_GET['status'];
    $types .= "s";
}

if (isset($_GET['date']) && !empty($_GET['date'])) {
    $where_clauses[] = "r.reservation_date = ?";
    $params[] = $_GET['date'];
    $types .= "s";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get total count
$count_query = "SELECT COUNT(*) FROM reservations r $where_sql";
$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_rows = $count_stmt->get_result()->fetch_row()[0];
$total_pages = ceil($total_rows / $limit);

// Get reservations with facility names
$query = "
    SELECT r.*, f.name as facility_name 
    FROM reservations r 
    JOIN facilities f ON r.facility_id = f.id 
    $where_sql 
    ORDER BY r.reservation_date DESC, r.start_time DESC 
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($query);
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
    <title>Reservation History - CEFI Reservation</title>
    <link rel="stylesheet" href="../style/reservations.css">
    <link rel="stylesheet" href="../style/navbar.css?v=2">
    <style>
        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }
        .history-table th, .history-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .history-table th {
            background-color: #f8f9fa;
            color: #333;
            font-weight: 600;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
            color: white;
        }
        .status-APPROVED { background-color: #27ae60; }
        .status-PENDING { background-color: #f39c12; }
        .status-REJECTED { background-color: #c0392b; }
        
        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            background: #fdfdfd;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #eee;
            align-items: flex-end;
        }
        .filters .input-group {
            margin-bottom: 0;
            flex: 1;
        }
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }
        .pagination a {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-decoration: none;
            color: #333;
            border-radius: 4px;
        }
        .pagination a.active {
            background: #e67e22;
            color: white;
            border-color: #e67e22;
        }
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1>Reservation History</h1>
        <div class="header-user"><?= ucfirst($_SESSION['user_role'] ?? 'staff') ?></div>
    </div>

    <div class="container">
    <h1><i class="fas fa-scroll"></i> Reservation History</h1>
    
    <form method="GET" class="filters">
        <div class="input-group">
            <label>Status</label>
            <select name="status">
                <option value="">All Statuses</option>
                <option value="PENDING" <?= isset($_GET['status']) && $_GET['status'] == 'PENDING' ? 'selected' : '' ?>>PENDING</option>
                <option value="APPROVED" <?= isset($_GET['status']) && $_GET['status'] == 'APPROVED' ? 'selected' : '' ?>>APPROVED</option>
                <option value="REJECTED" <?= isset($_GET['status']) && $_GET['status'] == 'REJECTED' ? 'selected' : '' ?>>REJECTED</option>
            </select>
        </div>
        <div class="input-group">
            <label>Date</label>
            <input type="date" name="date" value="<?= $_GET['date'] ?? '' ?>">
        </div>
        <button type="submit" class="btn">Filter</button>
        <a href="history.php" class="btn btn-secondary">Reset</a>
    </form>

    <div class="table-responsive">
        <table class="history-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Facility</th>
                    <th>User / FB Name</th>
                    <th>Time</th>
                    <th>Purpose</th>
                    <th>Status</th>
                    <th>Date Created</th>
                    <?php if (($_SESSION['user_role'] ?? 'staff') !== 'admin'): ?>
                        <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= date('M d, Y', strtotime($row['reservation_date'])) ?></td>
                        <td><?= htmlspecialchars($row['facility_name']) ?></td>
                        <td>
                            <?= htmlspecialchars($row['fb_name']) ?>
                            <?php if ($row['reservation_type'] == 'WALK_IN'): ?>
                                <br><small style="background:#dcfce7; color:#166534; padding:2px 4px; border-radius:4px; font-weight:600; font-size:0.65rem;"><i class="fas fa-person-walking"></i> WALK-IN</small>
                            <?php endif; ?>
                        </td>
                        <td><?= date('h:i A', strtotime($row['start_time'])) ?> - <?= date('h:i A', strtotime($row['end_time'])) ?></td>
                        <td><?= htmlspecialchars($row['purpose']) ?></td>
                        <td>
                            <span class="status-badge status-<?= $row['status'] ?>">
                                <?= $row['status'] ?>
                            </span>
                        </td>
                        <td><?= date('M d, Y h:i A', strtotime($row['created_at'])) ?></td>
                        <?php if (($_SESSION['user_role'] ?? 'staff') !== 'admin'): ?>
                            <td>
                                <a href="edit.php?id=<?= $row['id'] ?>&from=history" class="btn-edit">Edit</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endwhile; ?>
                <?php if ($result->num_rows == 0): ?>
                    <tr>
                        <td colspan="8" class="no-data">No reservations found in history.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?= $i ?>&status=<?= $_GET['status'] ?? '' ?>&date=<?= $_GET['date'] ?? '' ?>" class="<?= $page == $i ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<div class="footer">
    © 2026 CEFI ONLINE FACILITY RESERVATION. All rights reserved. | Calayan Educational Foundation Inc., Philippines
</div>

</body>
</html>
