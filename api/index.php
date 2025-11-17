<?php
/**
 * FinSight API Router
 * Central API endpoint with routing and documentation
 * 
 * Base URL: /api/
 * Version: 1.0
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// API Configuration
define('API_VERSION', '1.0');
define('API_BASE_PATH', dirname(__FILE__));

// Response helper
function apiResponse($success, $message, $data = null, $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'version' => API_VERSION,
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
    exit();
}

// Get requested endpoint
$request_uri = $_SERVER['REQUEST_URI'];
$script_name = dirname($_SERVER['SCRIPT_NAME']);
$path = str_replace($script_name, '', $request_uri);
$path = trim(parse_url($path, PHP_URL_PATH), '/');
$path_parts = explode('/', $path);

// Remove 'api' from path if present
if ($path_parts[0] === 'api') {
    array_shift($path_parts);
}

$endpoint = $path_parts[0] ?? 'index';

// API Documentation (when accessing /api/ or /api/index.php)
if (empty($endpoint) || $endpoint === 'index' || $endpoint === 'index.php') {
    apiResponse(true, 'FinSight API v' . API_VERSION, [
        'endpoints' => [
            'auth' => [
                'path' => '/api/auth.php',
                'methods' => ['POST'],
                'actions' => [
                    'login' => 'Authenticate user and get API token',
                    'register' => 'Register new user account',
                    'refresh' => 'Refresh authentication token',
                    'logout' => 'Invalidate authentication token'
                ]
            ],
            'expenses' => [
                'path' => '/api/expenses.php',
                'methods' => ['GET', 'POST', 'PUT', 'DELETE'],
                'actions' => [
                    'list' => 'GET - List user expenses with filters',
                    'create' => 'POST - Create new expense',
                    'update' => 'PUT - Update existing expense',
                    'delete' => 'DELETE - Delete expense',
                    'summary' => 'GET - Get expense summary and statistics'
                ]
            ],
            'categories' => [
                'path' => '/api/categories.php',
                'methods' => ['GET', 'POST', 'PUT', 'DELETE'],
                'actions' => [
                    'list' => 'GET - List all categories',
                    'create' => 'POST - Create new category',
                    'update' => 'PUT - Update category',
                    'delete' => 'DELETE - Delete category',
                    'stats' => 'GET - Category spending statistics'
                ]
            ],
            'budget' => [
                'path' => '/api/budget.php',
                'methods' => ['GET', 'POST', 'PUT'],
                'actions' => [
                    'get' => 'GET - Get budget information',
                    'set' => 'POST - Set monthly budget',
                    'update' => 'PUT - Update budget settings',
                    'analysis' => 'GET - Budget vs actual analysis'
                ]
            ],
            'sync' => [
                'path' => '/api/sync.php',
                'methods' => ['GET', 'POST'],
                'actions' => [
                    'sync_account' => 'POST - Sync single linked account',
                    'sync_all' => 'POST - Sync all linked accounts',
                    'status' => 'GET - Get sync status',
                    'history' => 'GET - Get sync history'
                ]
            ],
            'notifications' => [
                'path' => '/api/notifications.php',
                'methods' => ['GET', 'POST', 'PUT'],
                'actions' => [
                    'list' => 'GET - List user notifications',
                    'mark_read' => 'PUT - Mark notification as read',
                    'mark_all_read' => 'PUT - Mark all as read',
                    'delete' => 'DELETE - Delete notification'
                ]
            ],
            'reports' => [
                'path' => '/api/reports.php',
                'methods' => ['GET', 'POST'],
                'actions' => [
                    'summary' => 'GET - Financial summary report',
                    'trends' => 'GET - Spending trends analysis',
                    'insights' => 'GET - AI-powered insights',
                    'export' => 'POST - Export report data'
                ]
            ]
        ],
        'authentication' => [
            'type' => 'Bearer Token',
            'header' => 'Authorization: Bearer {token}',
            'alternative' => 'X-API-Key: {token}'
        ],
        'rate_limiting' => [
            'requests_per_minute' => 60,
            'requests_per_hour' => 1000
        ],
        'response_format' => [
            'success' => 'boolean',
            'message' => 'string',
            'data' => 'object|array|null',
            'version' => 'string',
            'timestamp' => 'ISO 8601 datetime'
        ],
        'status_codes' => [
            200 => 'OK - Request successful',
            201 => 'Created - Resource created',
            400 => 'Bad Request - Invalid parameters',
            401 => 'Unauthorized - Authentication required',
            403 => 'Forbidden - Access denied',
            404 => 'Not Found - Resource not found',
            405 => 'Method Not Allowed',
            429 => 'Too Many Requests - Rate limit exceeded',
            500 => 'Internal Server Error'
        ]
    ]);
}

// Route to appropriate endpoint
$endpoint_file = API_BASE_PATH . '/' . $endpoint . '.php';

if (file_exists($endpoint_file)) {
    require_once $endpoint_file;
} else {
    apiResponse(false, 'Endpoint not found', [
        'requested_endpoint' => $endpoint,
        'available_endpoints' => [
            'auth', 'expenses', 'categories', 'budget', 
            'sync', 'notifications', 'reports'
        ]
    ], 404);
}