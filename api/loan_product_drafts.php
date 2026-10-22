<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/admin_access_helper.php';
$actorClaim = $_SESSION['SESS_MEMBER_ID'] ?? null;
$validActor = (is_int($actorClaim) || is_string($actorClaim)) && preg_match('/\A[1-9][0-9]{0,9}\z/', (string) $actorClaim) && (int) $actorClaim <= 2147483647;
$accessError = $validActor ? findAdminAccessError($_SESSION) : 'A valid authenticated staff account is required';
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($accessError === null && $method === 'POST') $accessError = findAdminMutationOriginError($_SERVER);
if ($accessError !== null) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => $accessError]); exit;
}
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit;
}
try {
    require_once __DIR__ . '/../libs/services/LoanProductService.php';
    $openDatabase = static function (): PDO {
        require_once __DIR__ . '/../config/EnvConfig.php';
        $config = EnvConfig::getDatabaseConfig();
        return new PDO('mysql:host=' . $config['host'] . ';dbname=' . $config['name'] . ';charset=utf8mb4', $config['user'], $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    };
    if ($method === 'POST') {
        $body = file_get_contents('php://input', false, null, 0, 262145);
        if ($body === false || strlen($body) > 262144) throw new InvalidArgumentException('Draft request is too large', 413);
        try { $input = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { throw new InvalidArgumentException('Invalid JSON request', 400); }
        if (!is_array($input) || array_is_list($input) || !isset($input['action']) || !is_string($input['action'])) throw new InvalidArgumentException('An action and JSON object are required', 400);
        $action = $input['action']; unset($input['action']);
        if ($action === 'preview') {
            if (array_keys($input) !== ['calculation_inputs'] || !is_array($input['calculation_inputs'])) throw new InvalidArgumentException('Preview requires only calculation_inputs', 400);
            // Pure preview needs authenticated staff, but never opens a database connection.
            require_once __DIR__ . '/../libs/services/LoanScheduleCalculator.php';
            $result = (new LoanScheduleCalculator())->calculate($input['calculation_inputs']);
        } else {
            if (!in_array($action, ['create_draft', 'save_version'], true) || !isset($input['idempotency_key']) || !is_string($input['idempotency_key'])) throw new InvalidArgumentException('A draft action and submission reference are required', 400);
            $key = $input['idempotency_key']; unset($input['idempotency_key']);
            $conn = $openDatabase();
            $result = (new LoanProductService($conn))->runAdminCommand($action, $input, (int) $actorClaim, $key);
        }
    } else {
        $action = $_GET['action'] ?? 'list';
        $page = $_GET['page'] ?? '1'; $limit = $_GET['limit'] ?? '20';
        if (!is_string($action) || !is_scalar($page) || !is_scalar($limit) || !preg_match('/\A[1-9][0-9]{0,4}\z/', (string) $page) || !preg_match('/\A[1-9][0-9]?\z/', (string) $limit)) throw new InvalidArgumentException('Valid pagination is required', 400);
        if (!in_array($action, ['list', 'versions'], true)) throw new InvalidArgumentException('Unsupported catalog action', 400);
        $conn = $openDatabase();
        $service = new LoanProductService($conn);
        if ($action === 'versions') {
            $productId = $_GET['product_id'] ?? '';
            if (!is_string($productId) || !preg_match('/\A[1-9][0-9]{0,9}\z/', $productId)) throw new InvalidArgumentException('A valid product_id is required', 400);
            $result = $service->listVersions((int) $productId, (int) $page, (int) $limit);
        } else $result = $service->listDrafts((int) $page, (int) $limit);
    }
    echo json_encode(['success' => true, 'draft_only' => true, 'applications_enabled' => false, 'data' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $status = $error->getCode();
    if (($error instanceof InvalidArgumentException || $error instanceof OverflowException) && !in_array($status, [400, 413], true)) $status = 400;
    if (!in_array($status, [400, 403, 404, 409, 413], true) || $error instanceof PDOException) $status = 500;
    if ($status === 500) error_log('Loan product draft operation failed');
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $status === 500 ? 'Unable to complete the draft operation. Retry with the same submission reference or contact an administrator.' : $error->getMessage()]);
}
