<?php
/**
 * CSRF Token Protection Helper
 * Include this file anywhere you need CSRF protection.
 */

/**
 * Generate a CSRF token and store it in the session.
 * Returns the token string for embedding in forms.
 */
function generateCSRFToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden input field containing the CSRF token.
 * Call this inside any <form> tag.
 */
function csrfField() {
    $token = generateCSRFToken();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Validate a submitted CSRF token against the session token.
 * Regenerates the token after validation for one-time use.
 *
 * @param string $token The submitted token from $_POST['csrf_token']
 * @return bool True if valid, false otherwise
 */
function validateCSRFToken($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    if ($valid) {
        // Regenerate token after successful validation
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $valid;
}

/**
 * Validate CSRF or die with 403.
 * Convenience wrapper for POST handlers.
 */
function requireCSRF() {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        // CSRF failure usually means an expired/invalid session — force re-login
        session_unset();
        session_destroy();

        // Determine correct login path based on calling script's directory depth
        $login_path = '../auth/login.php';
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/COFR-updated-version2.1/auth/login.php')) {
            // Build path relative to current script
            $script_dir = dirname($_SERVER['SCRIPT_NAME']);
            $base = '/COFR-updated-version2.1';
            if ($script_dir === $base || $script_dir === $base . '/') {
                $login_path = 'auth/login.php';
            } else {
                $login_path = '../auth/login.php';
            }
        }

        header("Location: $login_path?csrf_expired=1");
        exit;
    }
}
?>
