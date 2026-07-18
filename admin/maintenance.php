<?php
session_start();
require_once("../config/session.php");
require_once("../config/db.php");
require_once("../config/audit_helper.php");
require_once("../config/csrf.php");

// Admin only
if (!isset($_SESSION["user_id"]) || $_SESSION["user_role"] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

// ─── Gather System Info ────────────────────────────────
$db_name = 'cefi_reservation';

// Table sizes (information_schema for sizes, actual COUNT(*) for accurate row counts)
$table_info = $conn->query("
    SELECT table_name AS table_name,
           ROUND(data_length / 1024, 1) as data_kb,
           ROUND(index_length / 1024, 1) as index_kb,
           ROUND((data_length + index_length) / 1024, 1) as total_kb
    FROM information_schema.tables 
    WHERE table_schema = '$db_name'
    ORDER BY (data_length + index_length) DESC
");

$total_db_size = 0;
$table_data = [];
while ($t = $table_info->fetch_assoc()) {
    // Get accurate row count via COUNT(*)
    $tbl = $conn->real_escape_string($t['table_name']);
    $cnt = $conn->query("SELECT COUNT(*) as c FROM `$tbl`");
    $t['table_rows'] = $cnt ? $cnt->fetch_assoc()['c'] : 0;
    $table_data[] = $t;
    $total_db_size += $t['total_kb'];
}

// Counts
$log_count = $conn->query("SELECT COUNT(*) as c FROM audit_logs")->fetch_assoc()['c'];
$reservation_count = $conn->query("SELECT COUNT(*) as c FROM reservations")->fetch_assoc()['c'];
$facility_count = $conn->query("SELECT COUNT(*) as c FROM facilities")->fetch_assoc()['c'];
$user_count = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];

// Oldest log
$oldest_log_result = $conn->query("SELECT MIN(created_at) as oldest FROM audit_logs");
$oldest_log = $oldest_log_result->fetch_assoc()['oldest'] ?? 'N/A';

$success_msg = '';
$error_msg = '';

// ─── Handle POST Actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    // BACKUP DATABASE
    if ($action === 'backup') {
        $backup_dir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }

        $filename = 'cofr_backup_' . date('Y-m-d_His') . '.sql';
        $filepath = $backup_dir . DIRECTORY_SEPARATOR . $filename;

        // Use mysqldump
        $mysqldump_path = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        if (!file_exists($mysqldump_path)) {
            $mysqldump_path = 'mysqldump'; // fallback to PATH
        }

        $command = "\"$mysqldump_path\" --user=root --password=admin --host=localhost $db_name > \"$filepath\" 2>&1";
        exec($command, $output, $return_code);

        if ($return_code === 0 && file_exists($filepath) && filesize($filepath) > 0) {
            $size = round(filesize($filepath) / 1024, 1);
            logActivity($conn, 'CREATE', 'BACKUP', null, "Database backup created: $filename ({$size}KB)");
            $success_msg = "Backup created successfully: <strong>$filename</strong> ({$size} KB)";
        } else {
            // Fallback: Generate SQL via PHP
            $backup_content = "-- COFR Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n-- Database: $db_name\n\n";
            $backup_content .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            $tables_result = $conn->query("SHOW TABLES");
            while ($table_row = $tables_result->fetch_row()) {
                $table = $table_row[0];
                
                // Get CREATE TABLE
                $create = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row();
                $backup_content .= "DROP TABLE IF EXISTS `$table`;\n";
                $backup_content .= $create[1] . ";\n\n";

                // Get data
                $rows = $conn->query("SELECT * FROM `$table`");
                while ($row = $rows->fetch_assoc()) {
                    $values = array_map(function($v) use ($conn) {
                        if ($v === null) return 'NULL';
                        return "'" . $conn->real_escape_string($v) . "'";
                    }, array_values($row));
                    $backup_content .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
                }
                $backup_content .= "\n";
            }

            $backup_content .= "SET FOREIGN_KEY_CHECKS=1;\n";
            
            if (file_put_contents($filepath, $backup_content)) {
                $size = round(filesize($filepath) / 1024, 1);
                logActivity($conn, 'CREATE', 'BACKUP', null, "Database backup created (PHP): $filename ({$size}KB)");
                $success_msg = "Backup created successfully: <strong>$filename</strong> ({$size} KB)";
            } else {
                $error_msg = "Failed to create backup file.";
            }
        }
    }

    // CLEAR AUDIT LOGS
    if ($action === 'clear_logs') {
        $days = intval($_POST['days'] ?? 30);
        
        if ($days > 0) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-$days days"));
            $count_result = $conn->query("SELECT COUNT(*) as c FROM audit_logs WHERE created_at < '$cutoff'");
            $del_count = $count_result->fetch_assoc()['c'];

            $conn->query("DELETE FROM audit_logs WHERE created_at < '$cutoff'");
            logActivity($conn, 'DELETE', 'AUDIT_LOG', null, "Cleared $del_count audit log entries older than $days days");
            $success_msg = "Cleared <strong>$del_count</strong> audit log entries older than $days days.";
        } else {
            // Clear ALL
            $count_result = $conn->query("SELECT COUNT(*) as c FROM audit_logs");
            $del_count = $count_result->fetch_assoc()['c'];

            $conn->query("TRUNCATE TABLE audit_logs");
            logActivity($conn, 'DELETE', 'AUDIT_LOG', null, "Cleared ALL $del_count audit log entries");
            $success_msg = "All <strong>$del_count</strong> audit log entries cleared.";
        }
    }

    // CLEAR EXPIRED/CANCELLED RESERVATIONS
    if ($action === 'clear_expired') {
        $count_result = $conn->query("SELECT COUNT(*) as c FROM reservations WHERE status IN ('EXPIRED','CANCELLED')");
        $del_count = $count_result->fetch_assoc()['c'];

        if ($del_count > 0) {
            // Delete addon selections first (FK)
            $conn->query("DELETE ras FROM reservation_addon_selections ras 
                          INNER JOIN reservations r ON ras.reservation_id = r.id 
                          WHERE r.status IN ('EXPIRED','CANCELLED')");
            $conn->query("DELETE FROM reservations WHERE status IN ('EXPIRED','CANCELLED')");
            logActivity($conn, 'DELETE', 'RESERVATION', null, "Purged $del_count expired/cancelled reservations");
            $success_msg = "Purged <strong>$del_count</strong> expired/cancelled reservations.";
        } else {
            $success_msg = "No expired or cancelled reservations to clear.";
        }
    }

    // OPTIMIZE TABLES
    if ($action === 'optimize') {
        $tables_result = $conn->query("SHOW TABLES");
        $optimized = 0;
        while ($table_row = $tables_result->fetch_row()) {
            $conn->query("OPTIMIZE TABLE `{$table_row[0]}`");
            $optimized++;
        }
        logActivity($conn, 'UPDATE', 'DATABASE', null, "Optimized $optimized database tables");
        $success_msg = "Optimized <strong>$optimized</strong> database tables.";
    }

    // Refresh counts after actions
    $log_count = $conn->query("SELECT COUNT(*) as c FROM audit_logs")->fetch_assoc()['c'];
}

// ─── List backups ──────────────────────────────────────
$backup_dir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'backups';
$backups = [];
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir, SCANDIR_SORT_DESCENDING);
    foreach ($files as $f) {
        if (pathinfo($f, PATHINFO_EXTENSION) === 'sql') {
            $backups[] = [
                'name' => $f,
                'size' => round(filesize($backup_dir . DIRECTORY_SEPARATOR . $f) / 1024, 1),
                'date' => date('M d, Y H:i', filemtime($backup_dir . DIRECTORY_SEPARATOR . $f))
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Maintenance - CEFI Admin</title>
    <link rel="stylesheet" href="../style/dashboard.css">
    <link rel="stylesheet" href="../style/navbar.css?v=2">
    <link rel="stylesheet" href="../style/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
        integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-tools"></i> System Maintenance</h1>
        <div class="header-user">Administrator</div>
    </div>

    <div class="container">
        <?php if ($success_msg): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?= $success_msg ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <!-- System Metrics -->
        <div class="info-metrics">
            <div class="info-metric">
                <div class="info-metric-icon green"><i class="fas fa-database"></i></div>
                <div class="info-metric-data">
                    <h4><?= number_format($total_db_size, 1) ?> KB</h4>
                    <span>Database Size</span>
                </div>
            </div>
            <div class="info-metric">
                <div class="info-metric-icon amber"><i class="fas fa-history"></i></div>
                <div class="info-metric-data">
                    <h4><?= number_format($log_count) ?></h4>
                    <span>Audit Log Entries</span>
                </div>
            </div>
            <div class="info-metric">
                <div class="info-metric-icon blue"><i class="fas fa-calendar-check"></i></div>
                <div class="info-metric-data">
                    <h4><?= number_format($reservation_count) ?></h4>
                    <span>Total Reservations</span>
                </div>
            </div>
            <div class="info-metric">
                <div class="info-metric-icon red"><i class="fas fa-hdd"></i></div>
                <div class="info-metric-data">
                    <h4><?= count($backups) ?></h4>
                    <span>Backup Files</span>
                </div>
            </div>
        </div>

        <!-- Maintenance Actions -->
        <div class="maintenance-grid">
            <!-- Backup Database -->
            <div class="maint-card">
                <div class="maint-card-icon backup">
                    <i class="fas fa-download"></i>
                </div>
                <div class="maint-card-body">
                    <h3>Backup Database</h3>
                    <p>Create a full SQL backup of all tables and data. Backups are saved to the server's <code>backups/</code> folder.</p>
                </div>
                <div class="maint-card-footer">
                    <form method="POST" style="display:inline;">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="backup">
                        <button type="submit" class="btn btn-primary" onclick="this.innerHTML='<span class=spinner></span> Creating...'; this.disabled=true; this.form.submit();">
                            <i class="fas fa-download"></i> Create Backup
                        </button>
                    </form>
                </div>
            </div>

            <!-- Clear Audit Logs -->
            <div class="maint-card">
                <div class="maint-card-icon logs">
                    <i class="fas fa-broom"></i>
                </div>
                <div class="maint-card-body">
                    <h3>Clear Audit Logs</h3>
                    <p>Remove old audit log entries to free up database space. Currently <strong><?= number_format($log_count) ?></strong> entries<?= $oldest_log !== 'N/A' ? ' since ' . date('M d, Y', strtotime($oldest_log)) : '' ?>.</p>
                </div>
                <div class="maint-card-footer">
                    <button type="button" class="btn btn-warning" onclick="openModal('clearLogsModal')">
                        <i class="fas fa-broom"></i> Clear Logs
                    </button>
                </div>
            </div>

            <!-- Optimize Tables -->
            <div class="maint-card">
                <div class="maint-card-icon cache">
                    <i class="fas fa-bolt"></i>
                </div>
                <div class="maint-card-body">
                    <h3>Optimize Database</h3>
                    <p>Run table optimization to reclaim unused space and improve query performance across all tables.</p>
                </div>
                <div class="maint-card-footer">
                    <form method="POST" style="display:inline;">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="optimize">
                        <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);box-shadow:0 4px 14px rgba(124,58,237,0.25);"
                            onclick="this.innerHTML='<span class=spinner></span> Optimizing...'; this.disabled=true; this.form.submit();">
                            <i class="fas fa-bolt"></i> Optimize Tables
                        </button>
                    </form>
                </div>
            </div>

            <!-- Purge Expired Reservations -->
            <div class="maint-card">
                <div class="maint-card-icon danger-zone">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <div class="maint-card-body">
                    <h3>Purge Old Reservations</h3>
                    <p>Permanently delete all <strong>EXPIRED</strong> and <strong>CANCELLED</strong> reservations from the database.</p>
                </div>
                <div class="maint-card-footer">
                    <button type="button" class="btn btn-danger" onclick="openModal('purgeModal')">
                        <i class="fas fa-trash-alt"></i> Purge Records
                    </button>
                </div>
            </div>
        </div>

        <!-- Database Tables Info -->
        <div class="admin-card" style="margin-top:2rem;">
            <div class="admin-card-header">
                <h3><i class="fas fa-table"></i> Database Tables</h3>
                <span style="font-size:0.75rem;color:#94a3b8;"><?= count($table_data) ?> tables · <?= number_format($total_db_size, 1) ?> KB total</span>
            </div>
            <div style="overflow-x:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Table Name</th>
                            <th>Rows</th>
                            <th>Data Size</th>
                            <th>Index Size</th>
                            <th>Total Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($table_data as $t): ?>
                        <tr>
                            <td style="font-weight:600;">
                                <i class="fas fa-table" style="color:#94a3b8;margin-right:0.4rem;font-size:0.75rem;"></i>
                                <?= htmlspecialchars($t['table_name']) ?>
                            </td>
                            <td><?= number_format($t['table_rows']) ?></td>
                            <td><?= $t['data_kb'] ?> KB</td>
                            <td><?= $t['index_kb'] ?> KB</td>
                            <td>
                                <span style="font-weight:600;color:#013c10;"><?= $t['total_kb'] ?> KB</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Existing Backups -->
        <?php if (!empty($backups)): ?>
        <div class="admin-card" style="margin-top:1.5rem;">
            <div class="admin-card-header">
                <h3><i class="fas fa-archive"></i> Backup History</h3>
                <span style="font-size:0.75rem;color:#94a3b8;"><?= count($backups) ?> backup(s)</span>
            </div>
            <div style="overflow-x:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Filename</th>
                            <th>Size</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($backups, 0, 10) as $b): ?>
                        <tr>
                            <td>
                                <i class="fas fa-file-code" style="color:#059669;margin-right:0.4rem;"></i>
                                <?= htmlspecialchars($b['name']) ?>
                            </td>
                            <td><?= $b['size'] ?> KB</td>
                            <td style="color:#64748b;"><?= $b['date'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ═══ CLEAR LOGS MODAL ═══ -->
<div class="modal-overlay" id="clearLogsModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-broom" style="color:#f59e0b;"></i> Clear Audit Logs</h3>
            <button class="modal-close" onclick="closeModal('clearLogsModal')">&times;</button>
        </div>
        <form method="POST">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="clear_logs">
            <div class="modal-body">
                <div class="confirm-body">
                    <i class="fas fa-exclamation-triangle warning"></i>
                    <h4>Clear Audit Logs</h4>
                    <p>Select the age of logs to clear. This action cannot be undone.</p>
                </div>
                <div class="form-group" style="margin-top:1rem;">
                    <label>Delete logs older than:</label>
                    <select name="days" class="form-control">
                        <option value="90">90 days</option>
                        <option value="60">60 days</option>
                        <option value="30" selected>30 days</option>
                        <option value="14">14 days</option>
                        <option value="7">7 days</option>
                        <option value="0">All logs (clear everything)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('clearLogsModal')">Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="fas fa-broom"></i> Clear Logs</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ PURGE RESERVATIONS MODAL ═══ -->
<div class="modal-overlay" id="purgeModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Purge Reservations</h3>
            <button class="modal-close" onclick="closeModal('purgeModal')">&times;</button>
        </div>
        <form method="POST">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="clear_expired">
            <div class="modal-body">
                <div class="confirm-body">
                    <i class="fas fa-exclamation-triangle danger"></i>
                    <h4>Permanently Delete?</h4>
                    <p>This will permanently remove all <strong>EXPIRED</strong> and <strong>CANCELLED</strong> reservations and their associated add-on selections. This action cannot be undone.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('purgeModal')">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Purge Records</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('active');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('active');
    });
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
    }
});

// Auto-hide alerts
document.querySelectorAll('.success, .error').forEach(el => {
    setTimeout(() => { el.style.transition = 'opacity 0.5s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }, 5000);
});
</script>

</body>
</html>
