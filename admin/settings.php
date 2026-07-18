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

// ─── Ensure system_settings table exists ───────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        setting_label VARCHAR(255) DEFAULT '',
        setting_group VARCHAR(50) DEFAULT 'general',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// ─── Default Settings ──────────────────────────────────
$defaults = [
    // Reservation Rules
    ['max_reservations_per_day', '5', 'Max reservations per user per day', 'reservations'],
    ['advance_booking_days', '30', 'Max days in advance for booking', 'reservations'],
    ['min_advance_days', '2', 'Min days before reservation date', 'reservations'],
    ['max_duration_hours', '8', 'Max reservation duration (hours)', 'reservations'],
    ['min_duration_hours', '1', 'Min reservation duration (hours)', 'reservations'],
    ['allow_weekend_bookings', '1', 'Allow weekend bookings', 'reservations'],
    ['auto_expire_hours', '48', 'Auto-expire pending after (hours)', 'reservations'],
    // System
    ['system_name', 'CEFI Online Facility Reservation', 'System name', 'system'],
    ['maintenance_mode', '0', 'Maintenance mode', 'system'],
    ['session_timeout_minutes', '30', 'Session timeout (minutes)', 'system'],
    ['default_open_time', '07:00', 'Default facility open time', 'system'],
    ['default_close_time', '20:00', 'Default facility close time', 'system'],
    // Notifications
    ['enable_email_notifications', '0', 'Enable email notifications', 'notifications'],
    ['admin_email', '', 'Admin notification email', 'notifications'],
];

foreach ($defaults as $d) {
    $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_label, setting_group) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $d[0], $d[1], $d[2], $d[3]);
    $stmt->execute();
}

// ─── Handle Save ───────────────────────────────────────
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    
    $settings_to_update = $_POST['settings'] ?? [];
    $old_values = [];
    $new_values = [];
    $changes = 0;

    foreach ($settings_to_update as $key => $value) {
        $key = $conn->real_escape_string($key);
        $value = trim($value);

        // Get old value
        $old = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = '$key'")->fetch_assoc();
        if ($old && $old['setting_value'] !== $value) {
            $old_values[$key] = $old['setting_value'];
            $new_values[$key] = $value;

            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->bind_param("ss", $value, $key);
            $stmt->execute();
            $changes++;
        }
    }

    // Handle checkbox settings (unchecked = not submitted)
    $checkbox_keys = ['allow_weekend_bookings', 'maintenance_mode', 'enable_email_notifications'];
    foreach ($checkbox_keys as $ck) {
        if (!isset($settings_to_update[$ck])) {
            $old = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = '$ck'")->fetch_assoc();
            if ($old && $old['setting_value'] !== '0') {
                $old_values[$ck] = $old['setting_value'];
                $new_values[$ck] = '0';
                $conn->query("UPDATE system_settings SET setting_value = '0' WHERE setting_key = '$ck'");
                $changes++;
            }
        }
    }

    if ($changes > 0) {
        logActivity($conn, 'UPDATE', 'SETTINGS', null, "Updated $changes system setting(s)", $old_values, $new_values);
        $success_msg = "$changes setting(s) updated successfully.";
    } else {
        $success_msg = "No changes detected.";
    }
}

// ─── Fetch Current Settings ────────────────────────────
$all_settings = [];
$result = $conn->query("SELECT * FROM system_settings ORDER BY setting_group, setting_key");
while ($row = $result->fetch_assoc()) {
    $all_settings[$row['setting_key']] = $row;
}

function getSetting($key) {
    global $all_settings;
    return $all_settings[$key]['setting_value'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - CEFI Admin</title>
    <link rel="stylesheet" href="../style/dashboard.css">
    <link rel="stylesheet" href="../style/navbar.css?v=2">
    <link rel="stylesheet" href="../style/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
        integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .toggle-switch {
            position: relative;
            width: 44px;
            height: 24px;
            display: inline-block;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #cbd5e1;
            border-radius: 24px;
            transition: 0.3s;
        }
        .toggle-slider:before {
            content: "";
            position: absolute;
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.3s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .toggle-switch input:checked + .toggle-slider {
            background: linear-gradient(135deg, #013c10, #026b1c);
        }
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(20px);
        }
        .settings-save-bar {
            position: sticky;
            bottom: 0;
            background: #ffffff;
            padding: 1rem 1.5rem;
            border-top: 2px solid rgba(1, 60, 16, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 -4px 16px rgba(0,0,0,0.08);
            border-radius: 0 0 14px 14px;
            z-index: 10;
        }
        .unsaved-indicator {
            display: none;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: #f59e0b;
            font-weight: 600;
        }
        .unsaved-indicator.show {
            display: flex;
        }
        .unsaved-indicator .pulse {
            width: 8px;
            height: 8px;
            background: #f59e0b;
            border-radius: 50%;
            animation: pulse-dot 1.5s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.3); }
        }
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-cogs"></i> System Settings</h1>
        <div class="header-user">Administrator</div>
    </div>

    <div class="container">
        <?php if ($success_msg): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <form method="POST" id="settingsForm">
            <?php csrfField(); ?>

            <div class="settings-grid">

                <!-- ═══ Reservation Rules ═══ -->
                <div class="settings-section">
                    <div class="settings-section-header">
                        <i class="fas fa-calendar-alt"></i>
                        <h3>Reservation Rules</h3>
                    </div>
                    <div class="settings-section-body">
                        <div class="setting-row">
                            <div class="setting-label">
                                Max Reservations Per Day
                                <span class="desc">Per user, per day limit</span>
                            </div>
                            <div class="setting-input">
                                <input type="number" name="settings[max_reservations_per_day]"
                                    value="<?= htmlspecialchars(getSetting('max_reservations_per_day')) ?>"
                                    min="1" max="50" onchange="markUnsaved()">
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label">
                                Max Advance Booking
                                <span class="desc">Days ahead users can book</span>
                            </div>
                            <div class="setting-input">
                                <input type="number" name="settings[advance_booking_days]"
                                    value="<?= htmlspecialchars(getSetting('advance_booking_days')) ?>"
                                    min="1" max="365" onchange="markUnsaved()">
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label">
                                Min Advance Days
                                <span class="desc">Minimum days before reservation date</span>
                            </div>
                            <div class="setting-input">
                                <input type="number" name="settings[min_advance_days]"
                                    value="<?= htmlspecialchars(getSetting('min_advance_days')) ?>"
                                    min="0" max="30" onchange="markUnsaved()">
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label">
                                Min Duration
                                <span class="desc">Minimum hours per booking</span>
                            </div>
                            <div class="setting-input">
                                <input type="number" name="settings[min_duration_hours]"
                                    value="<?= htmlspecialchars(getSetting('min_duration_hours')) ?>"
                                    min="1" max="24" onchange="markUnsaved()">
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label">
                                Max Duration
                                <span class="desc">Maximum hours per booking</span>
                            </div>
                            <div class="setting-input">
                                <input type="number" name="settings[max_duration_hours]"
                                    value="<?= htmlspecialchars(getSetting('max_duration_hours')) ?>"
                                    min="1" max="24" onchange="markUnsaved()">
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label">
                                Auto-Expire Pending
                                <span class="desc">Hours before pending reservations expire</span>
                            </div>
                            <div class="setting-input">
                                <input type="number" name="settings[auto_expire_hours]"
                                    value="<?= htmlspecialchars(getSetting('auto_expire_hours')) ?>"
                                    min="1" max="168" onchange="markUnsaved()">
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label">
                                Allow Weekend Bookings
                                <span class="desc">Enable Saturday/Sunday reservations</span>
                            </div>
                            <div class="setting-input" style="text-align:right;">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="settings[allow_weekend_bookings]" value="1"
                                        <?= getSetting('allow_weekend_bookings') === '1' ? 'checked' : '' ?> onchange="markUnsaved()">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ System Configuration ═══ -->
                <div class="settings-section">
                    <div class="settings-section-header">
                        <i class="fas fa-server"></i>
                        <h3>System Configuration</h3>
                    </div>
                    <div class="settings-section-body">
                        <div class="setting-row">
                            <div class="setting-label">
                                System Name
                                <span class="desc">Displayed in headers and emails</span>
                            </div>
                            <div class="setting-input" style="max-width:200px;">
                                <input type="text" name="settings[system_name]"
                                    value="<?= htmlspecialchars(getSetting('system_name')) ?>"
                                    style="text-align:left;" onchange="markUnsaved()">
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label">
                                Session Timeout
                                <span class="desc">Minutes of inactivity before logout</span>
                            </div>
                            <div class="setting-input">
                                <input type="number" name="settings[session_timeout_minutes]"
                                    value="<?= htmlspecialchars(getSetting('session_timeout_minutes')) ?>"
                                    min="5" max="480" onchange="markUnsaved()">
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label">
                                Default Open Time
                                <span class="desc">Facility default opening hour</span>
                            </div>
                            <div class="setting-input">
                                <input type="time" name="settings[default_open_time]"
                                    value="<?= htmlspecialchars(getSetting('default_open_time')) ?>"
                                    onchange="markUnsaved()">
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label">
                                Default Close Time
                                <span class="desc">Facility default closing hour</span>
                            </div>
                            <div class="setting-input">
                                <input type="time" name="settings[default_close_time]"
                                    value="<?= htmlspecialchars(getSetting('default_close_time')) ?>"
                                    onchange="markUnsaved()">
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label">
                                Maintenance Mode
                                <span class="desc">Disable public reservations</span>
                            </div>
                            <div class="setting-input" style="text-align:right;">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="settings[maintenance_mode]" value="1"
                                        <?= getSetting('maintenance_mode') === '1' ? 'checked' : '' ?> onchange="markUnsaved()">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ Notifications ═══ -->
                <div class="settings-section" style="grid-column: 1 / -1;">
                    <div class="settings-section-header">
                        <i class="fas fa-bell"></i>
                        <h3>Notifications</h3>
                    </div>
                    <div class="settings-section-body">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
                            <div class="setting-row" style="border:none;">
                                <div class="setting-label">
                                    Email Notifications
                                    <span class="desc">Send email alerts for new reservations</span>
                                </div>
                                <div class="setting-input" style="text-align:right;">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="settings[enable_email_notifications]" value="1"
                                            <?= getSetting('enable_email_notifications') === '1' ? 'checked' : '' ?> onchange="markUnsaved()">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                            <div class="setting-row" style="border:none;">
                                <div class="setting-label">
                                    Admin Email
                                    <span class="desc">Receives notification emails</span>
                                </div>
                                <div class="setting-input" style="max-width:220px;">
                                    <input type="email" name="settings[admin_email]"
                                        value="<?= htmlspecialchars(getSetting('admin_email')) ?>"
                                        placeholder="admin@cefi.edu"
                                        style="text-align:left;" onchange="markUnsaved()">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Save Bar -->
            <div class="settings-save-bar" style="margin-top:1.5rem;border-radius:14px;border:1px solid rgba(1,60,16,0.08);">
                <div class="unsaved-indicator" id="unsavedIndicator">
                    <span class="pulse"></span>
                    Unsaved changes
                </div>
                <div style="display:flex;gap:0.75rem;margin-left:auto;">
                    <button type="button" class="btn btn-outline" onclick="window.location.reload()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
let hasChanges = false;

function markUnsaved() {
    hasChanges = true;
    document.getElementById('unsavedIndicator').classList.add('show');
    document.getElementById('saveBtn').style.animation = 'pulse-dot 1.5s infinite';
}

// Warn before leaving with unsaved changes
window.addEventListener('beforeunload', function(e) {
    if (hasChanges) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// Clear warning on submit
document.getElementById('settingsForm').addEventListener('submit', function() {
    hasChanges = false;
});

// Auto-hide alerts
document.querySelectorAll('.success, .error').forEach(el => {
    setTimeout(() => { el.style.transition = 'opacity 0.5s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }, 5000);
});
</script>

</body>
</html>
