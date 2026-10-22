<?php
// Load environment variables
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

try {
    // Load the .env file
    $envPath = __DIR__ . '/../../';
    $envFile = $envPath . '.env';
    
    if (file_exists($envFile)) {
        $dotenv = Dotenv::createImmutable($envPath);
        $dotenv->load();
    }
} catch (\Exception $e) {
    error_log("Error loading .env file: " . $e->getMessage());
}

// Get database configuration from environment variables
// Support both DB_AUTH_* and DB_* for compatibility
$dbHost = $_ENV['DB_AUTH_HOST'] ?? $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_AUTH_NAME'] ?? $_ENV['DB_NAME'] ?? '';
$dbUser = $_ENV['DB_AUTH_USER'] ?? $_ENV['DB_USERNAME'] ?? $_ENV['DB_USER'] ?? '';
$dbPassword = $_ENV['DB_AUTH_PASSWORD'] ?? $_ENV['DB_PASSWORD'] ?? '';

// Get JWT configuration from environment variables
$jwtSecret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET');
if (!is_string($jwtSecret) || strlen(trim($jwtSecret)) < 32) {
    throw new RuntimeException('JWT_SECRET must be configured with at least 32 bytes');
}
$jwtExpiry = $_ENV['JWT_EXPIRY'] ?? getenv('JWT_EXPIRY');
if ($jwtExpiry === false) $jwtExpiry = 3600;
if (filter_var($jwtExpiry, FILTER_VALIDATE_INT) === false || (int)$jwtExpiry <= 0) {
    throw new RuntimeException('JWT_EXPIRY must be a positive integer');
}

return [
    'database' => [
        'host' => $dbHost,
        'username' => $dbUser,
        'password' => $dbPassword,
        'dbname' => $dbName
    ],
    'jwt' => [
        'secret' => $jwtSecret,
        'expiry' => (int)$jwtExpiry // 1 hour in seconds
    ]
];
