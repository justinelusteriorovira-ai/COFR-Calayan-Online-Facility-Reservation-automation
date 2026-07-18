<?php
/**
 * Centralized Session Management
 * Include this file at the top of every admin-facing page.
 * Handles: session start, timeout check, CSRF token init.
 */

// Session configuration
$SESSION_TIMEOUT = 30 * 60; // 30 minutes in seconds

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Skip timeout check for login page
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
if ($current_script !== 'login.php' && isset($_SESSION['user_id'])) {
    // Check for session timeout
    if (isset($_SESSION['last_activity'])) {
        $idle_time = time() - $_SESSION['last_activity'];
        if ($idle_time > $SESSION_TIMEOUT) {
            // Session expired
            session_unset();
            session_destroy();
            // Determine redirect path
            $login_path = '../auth/login.php';
            if (file_exists('auth/login.php')) {
                $login_path = 'auth/login.php';
            }
            header("Location: $login_path?timeout=1");
            exit;
        }
    }
    // Update last activity timestamp
    $_SESSION['last_activity'] = time();

    // Role-based Access Control (RBAC)
    $admin_only_pages = ['admin_dashboard.php', 'audit_export.php', 'audit_trail.php', 'users.php', 'settings.php', 'maintenance.php']; 
    if (in_array($current_script, $admin_only_pages) && $_SESSION['user_role'] !== 'admin') {
        header("Location: dashboard.php?error=" . urlencode("Access Denied: Administrators only."));
        exit;
    }

    // Admin Write-Action Protection (Read-Only Mode)
    $admin_blocked_actions = ['create.php', 'edit.php', 'delete.php', 'cancel.php', 'walkin_create.php', 'approve.php', 'reject.php'];
    if (in_array($current_script, $admin_blocked_actions) && $_SESSION['user_role'] === 'admin') {
        $referrer = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
        // Try to find the sensible index page to redirect to
        $redirect_to = 'index.php';
        if (strpos($_SERVER['REQUEST_URI'], 'reservations') !== false) $redirect_to = '../reservations/index.php';
        if (strpos($_SERVER['REQUEST_URI'], 'facilities') !== false) $redirect_to = '../facilities/index.php';
        
        header("Location: $redirect_to?error=" . urlencode("Read-Only Mode: Administrators cannot modify data."));
        exit;
    }
}

// Include CSRF helper (always available after session starts)
$csrf_path = __DIR__ . '/csrf.php';
if (file_exists($csrf_path)) {
    require_once($csrf_path);
}
?>
