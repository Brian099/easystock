<?php
// Start session for auth tracking
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Global response headers
header('Content-Type: application/json; charset=utf-8');

/**
 * Utility to send JSON response and terminate script execution.
 */
function send_json($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Check if database configuration exists
$config_file = __DIR__ . '/db_config.php';
$is_installing = (isset($_GET['action']) && $_GET['action'] === 'install') || basename($_SERVER['PHP_SELF']) === 'install.php';

if (!file_exists($config_file)) {
    if (!$is_installing) {
        send_json(['error' => 'not_installed'], 503);
    }
} else {
    require_once $config_file;
}

// Establish database connection if configuration is available
$pdo = null;
if (defined('DB_HOST')) {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $e) {
        if (!$is_installing) {
            send_json(['error' => 'Database connection failed: ' . $e->getMessage()], 500);
        }
    }
}

/**
 * Validates session authentication. Returns session user info or sends 401.
 */
function require_login() {
    if (!isset($_SESSION['user'])) {
        send_json(['error' => 'Unauthorized. Please login.'], 401);
    }
    return $_SESSION['user'];
}

/**
 * Validates admin role authorization.
 */
function require_admin() {
    $user = require_login();
    if (($user['role'] ?? 'user') !== 'admin') {
        send_json(['error' => 'Forbidden. Admin role required.'], 403);
    }
    return $user;
}

/**
 * Parse input body as JSON
 */
function get_json_input() {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if ($raw && json_last_error() !== JSON_ERROR_NONE) {
        send_json(['error' => 'Invalid JSON input format.'], 400);
    }
    return $decoded ?: [];
}
