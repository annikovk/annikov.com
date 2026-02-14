.<?php
/**
 * Public endpoint for tracking actions
 *
 * Usage: GET /apps/yandex-music-controller/count-action?id=<action_name>
 */

// Set timezone and error reporting
date_default_timezone_set('UTC');
ini_set('display_errors', 0);
error_reporting(0);

// Load configuration
$config = require __DIR__ . '/config.php';

// Load required classes
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/RateLimiter.php';
require_once __DIR__ . '/lib/ActionTracker.php';

// Set CORS headers
if ($config['cors']['enabled']) {
    header('Access-Control-Allow-Origin: ' . $config['cors']['allow_origin']);
    header('Access-Control-Allow-Methods: ' . $config['cors']['allow_methods']);
    header('Access-Control-Allow-Headers: ' . $config['cors']['allow_headers']);
}

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Set JSON content type
header('Content-Type: application/json');

/**
 * Send JSON response and exit
 *
 * @param bool $success Success status
 * @param string|null $action Action name
 * @param int|null $totalCount Total count
 * @param string|null $error Error message
 * @param int $httpCode HTTP status code
 * @return void
 */
function sendResponse(
    bool $success,
    ?string $action = null,
    ?int $totalCount = null,
    ?string $error = null,
    int $httpCode = 200
): void {
    http_response_code($httpCode);

    $response = ['success' => $success];

    if ($action !== null) {
        $response['action'] = $action;
    }

    if ($totalCount !== null) {
        $response['total_count'] = $totalCount;
    }

    if ($error !== null) {
        $response['error'] = $error;
    }

    echo json_encode($response);
    exit;
}

try {
    // Get action ID and installation_id from query parameters
    $actionId = $_GET['id'] ?? null;
    $installationId = $_GET['installation_id'] ?? '0'; // Default to '0' for backward compatibility

    if ($actionId === null || $actionId === '') {
        sendResponse(false, null, null, 'Missing action ID', 400);
    }

    // Initialize database
    $db = Database::getInstance($config['database']);
    $db->initializeTables();

    // Check rate limit
    if ($config['rate_limit']['enabled']) {
        $rateLimiter = new RateLimiter(
            $db,
            $config['rate_limit']['max_requests'],
            $config['rate_limit']['window_seconds'],
            $config['rate_limit']['cleanup_probability']
        );

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (!$rateLimiter->check($ipAddress)) {
            sendResponse(
                false,
                null,
                null,
                'Rate limit exceeded. Please try again later.',
                429
            );
        }

        // Increment rate limit counter
        $rateLimiter->increment($ipAddress);
    }

    // Track action
    $actionTracker = new ActionTracker(
        $db,
        $config['validation']['action_name_pattern'],
        $config['validation']['max_action_length']
    );

    try {
        $totalCount = $actionTracker->track($actionId, $installationId);
        sendResponse(true, $actionId, $totalCount, null, 200);
    } catch (InvalidArgumentException $e) {
        sendResponse(false, null, null, 'Invalid action ID format', 400);
    }

} catch (Exception $e) {
    sendResponse(false, null, null, 'Unable to process request', 500);
}
