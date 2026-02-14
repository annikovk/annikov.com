<?php
/**
 * Public endpoint for reporting plugin installations
 *
 * Usage: POST /apps/yandex-music-controller/report-installation.php
 * Body: JSON with installation data
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
require_once __DIR__ . '/lib/InstallationTracker.php';

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
 * @param string|null $error Error message
 * @param int $httpCode HTTP status code
 * @return void
 */
function sendResponse(
    bool $success,
    ?string $error = null,
    int $httpCode = 200
): void {
    http_response_code($httpCode);

    $response = ['success' => $success];

    if ($error !== null) {
        $response['error'] = $error;
    }

    echo json_encode($response);
    exit;
}

try {
    // Validate HTTP method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Method not allowed', 405);
    }

    // Parse JSON body
    $rawBody = file_get_contents('php://input');
    $installationData = json_decode($rawBody, true);

    if ($installationData === null) {
        sendResponse(false, 'Invalid JSON', 400);
    }

    // Initialize database
    $db = Database::getInstance($config['database']);
    $db->initializeTables();

    // Check rate limit (installation endpoint has higher limit to allow plugin restarts)
    if ($config['rate_limit']['enabled']) {
        $rateLimiter = new RateLimiter(
            $db,
            $config['rate_limit']['installation_max_requests'],
            $config['rate_limit']['window_seconds'],
            $config['rate_limit']['cleanup_probability']
        );

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (!$rateLimiter->check($ipAddress, 'installation')) {
            sendResponse(
                false,
                'Rate limit exceeded. Please try again later.',
                429
            );
        }

        // Increment rate limit counter
        $rateLimiter->increment($ipAddress, 'installation');
    }

    // Track installation
    $installationTracker = new InstallationTracker($db);

    try {
        $installationTracker->track($installationData);
        sendResponse(true, null, 201);
    } catch (InvalidArgumentException $e) {
        sendResponse(false, 'Invalid installation data', 400);
    }

} catch (Exception $e) {
    sendResponse(false, 'Unable to process request', 500);
}
