<?php
/**
 * API Authentication Helper
 * Supports dual auth: session-based (admin panel) + API key (chatbot/n8n).
 *
 * Usage:
 *   require_once("../config/api_auth.php");
 *   requireAPIAuth(); // dies with 401/403 if unauthorized
 */

// The API key — should be moved to environment variable in production
define('CEFI_API_KEY', 'cefi_api_2026_secure_key');

/**
 * Check if the request has a valid API key.
 */
function hasValidAPIKey() {
    // Check X-API-Key header
    $headers = getallheaders();
    $api_key = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? '';
    
    // Fallback: check query parameter
    if (empty($api_key)) {
        $api_key = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
    }

    return !empty($api_key) && $api_key === CEFI_API_KEY;
}

/**
 * Check if the request has a valid admin session.
 */
function hasValidSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['user_id']);
}

/**
 * Require either API key or session authentication.
 * Returns a JSON 401/403 and exits if unauthorized.
 */
function requireAPIAuth() {
    header('Content-Type: application/json');
    
    if (hasValidAPIKey() || hasValidSession()) {
        return true;
    }

    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized. Provide a valid X-API-Key header or staff session.'
    ]);
    exit;
}

/**
 * Get JSON request body.
 * Falls back to $_POST for form-encoded requests.
 */
function getRequestData() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (stripos($contentType, 'application/json') !== false) {
        $json = json_decode(file_get_contents('php://input'), true);
        return is_array($json) ? $json : [];
    }
    
    return $_POST;
}
?>
