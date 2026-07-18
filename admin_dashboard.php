<?php
session_start();
require_once("config/session.php");
require_once("config/db.php");

// Admin restricted access
if (!isset($_SESSION["user_id"]) || $_SESSION["user_role"] !== 'admin') {
    header("Location: auth/login.php");
    exit;
}

// ─── Basic Statistics ──────────────────────────────────────
$total_facilities = $conn->query("SELECT COUNT(*) as count FROM facilities")->fetch_assoc()['count'];
$total_reservations = $conn->query("SELECT COUNT(*) as count FROM reservations")->fetch_assoc()['count'];
$total_staff = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'staff'")->fetch_assoc()['count'];
$revenue_total = $conn->query("SELECT COALESCE(SUM(total_cost), 0) as total FROM reservations WHERE status = 'APPROVED'")->fetch_assoc()['total'];

// ─── Recent Audit Logs (Control Center View) ───────────────
$recent_logs = $conn->query("
    SELECT a.*, u.username as admin_name 
    FROM audit_logs a 
    LEFT JOIN users u ON a.admin_id = u.id 
    ORDER BY a.created_at DESC 
    LIMIT 10
");

// ─── Recent Reservations ───────────────────────────────────
$recent_reservations = $conn->query("
    SELECT r.*, f.name AS facility_name 
    FROM reservations r 
    JOIN facilities f ON r.facility_id = f.id 
    ORDER BY r.created_at DESC 
    LIMIT 5
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - CEFI Reservation</title>
    <link rel="stylesheet" href="style/dashboard.css">
    <link rel="stylesheet" href="style/navbar.css?v=2">
    <link rel="stylesheet" href="style/audit_trail.css">
    <style>
        .admin-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        .audit-widget {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(1, 60, 16, 0.1);
        }
        .audit-widget h2 {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: #013c10;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .audit-mini-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .audit-mini-table th {
            text-align: left;
            padding: 0.75rem;
            background: #f8fafc;
            color: #64748b;
            font-weight: 600;
        }
        .audit-mini-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .action-badge-mini {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .bg-create { background: #dcfce7; color: #166534; }
        .bg-update { background: #e0f2fe; color: #0369a1; }
        .bg-delete { background: #fee2e2; color: #991b1b; }
        .bg-login { background: #fef3c7; color: #92400e; }
        
        .quick-mgmt {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .mgmt-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 4px solid #013c10;
        }
        .mgmt-card h3 { font-size: 0.9rem; margin-bottom: 0.5rem; }
        .btn-mgmt {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: #013c10;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.8rem;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>

<?php include 'includes/navbar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1>Admin Control Center</h1>
        <div class="header-user">Administrator</div>
    </div>

    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <h2 style="margin: 0;">System Overview</h2>
            <div id="realTimeClock" style="display: flex; align-items: center; gap: 0.6rem; background: #ffffff; padding: 0.6rem 1.2rem; border-radius: 12px; border: 1px solid rgba(1, 60, 16, 0.1); box-shadow: 0 4px 12px rgba(0,0,0,0.05); color: #013c10;">
                <i class="fas fa-clock" style="color: #fcb900;"></i>
                <span id="clockTime" style="font-weight: 700;">--:--:--</span>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon facilities-icon"><i class="fas fa-user-shield"></i></div>
                <div class="stat-info">
                    <h3><?= $total_staff ?></h3>
                    <p>Active Staff</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon reservations-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-info">
                    <h3><?= $total_reservations ?></h3>
                    <p>Total Bookings</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon today-icon"><i class="fas fa-coins"></i></div>
                <div class="stat-info">
                    <h3>₱<?= number_format($revenue_total, 2) ?></h3>
                    <p>Total Revenue</p>
                </div>
            </div>
        </div>

        <div class="admin-grid">
            <!-- Audit Trail Widget -->
            <div class="audit-widget">
                <h2><i class="fas fa-history"></i> Recent Activity Log</h2>
                <table class="audit-mini-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($log = $recent_logs->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['admin_name'] ?? 'System') ?></td>
                            <td>
                                <span class="action-badge-mini bg-<?= strtolower($log['action']) ?>">
                                    <?= $log['action'] ?>
                                </span>
                            </td>
                            <td><?= $log['entity_type'] ?></td>
                            <td style="color: #64748b;"><?= date('H:i', strtotime($log['created_at'])) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <a href="audit_trail.php" style="display:block; margin-top:1rem; font-size:0.8rem; color:#013c10; font-weight:600; text-decoration:none; text-align:center;">View Full Audit Trail →</a>
            </div>

            <!-- Quick Management -->
            <div class="quick-mgmt">
                <div class="mgmt-card">
                    <h3>Staff Management</h3>
                    <p style="font-size: 0.75rem; color: #64748b;">Manage user accounts and permissions.</p>
                    <a href="admin/users.php" class="btn-mgmt">Manage Users</a>
                </div>
                <div class="mgmt-card" style="border-left-color: #f59e0b;">
                    <h3>System Settings</h3>
                    <p style="font-size: 0.75rem; color: #64748b;">Configure reservation limits and rules.</p>
                    <a href="admin/settings.php" class="btn-mgmt" style="background: #f59e0b;">Global Config</a>
                </div>
                <div class="mgmt-card" style="border-left-color: #ef4444;">
                    <h3>System Maintenance</h3>
                    <p style="font-size: 0.75rem; color: #64748b;">Backup database or clear logs.</p>
                    <a href="admin/maintenance.php" class="btn-mgmt" style="background: #ef4444;">Maintenance</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function updateClock() {
        const now = new Date();
        document.getElementById('clockTime').textContent = now.toLocaleTimeString();
    }
    setInterval(updateClock, 1000);
    updateClock();
</script>

</body>
</html>
