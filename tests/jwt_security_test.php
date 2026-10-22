<?php
if (PHP_SAPI !== 'cli') exit(2);
$fixture = sys_get_temp_dir() . '/coop-jwt-' . bin2hex(random_bytes(8));
mkdir($fixture . '/auth_api/config', 0777, true);
mkdir($fixture . '/auth_api/utils', 0777, true);
mkdir($fixture . '/vendor', 0777, true);
// Empty autoloader only in this isolated fixture; no .env or production configuration is read.
file_put_contents($fixture . '/vendor/autoload.php', '<?php');
copy(__DIR__ . '/../auth_api/config/config.php', $fixture . '/auth_api/config/config.php');
copy(__DIR__ . '/../auth_api/utils/JWTHandler.php', $fixture . '/auth_api/utils/JWTHandler.php');
require $fixture . '/auth_api/utils/JWTHandler.php';
$previousEnv = $_ENV;
$previousSecret = getenv('JWT_SECRET');
$previousExpiry = getenv('JWT_EXPIRY');
function jwtCheck($condition, $label) {
    if (!$condition) throw new RuntimeException($label);
    echo "PASS $label\n";
}
function testJwtToken($header, $payload, $secret) {
    $encode = fn($part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');
    $body = $encode($header) . '.' . $encode($payload);
    return $body . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');
}
try {
    $_ENV = [];
    putenv('JWT_SECRET'); putenv('JWT_EXPIRY');
    foreach ([null, 'short', str_repeat(' ', 40)] as $secret) {
        if ($secret === null) unset($_ENV['JWT_SECRET']); else $_ENV['JWT_SECRET'] = $secret;
        try { new JWTHandler(); throw new LogicException('Invalid secret accepted'); }
        catch (RuntimeException $e) { jwtCheck(strpos($e->getMessage(), 'JWT_SECRET') !== false, 'missing/weak secret fails closed'); }
    }
    $secret = str_repeat('isolated-test-key-', 3);
    unset($_ENV['JWT_SECRET']); putenv('JWT_SECRET=' . $secret);
    foreach ([0, -1, 'invalid'] as $expiry) {
        $_ENV['JWT_EXPIRY'] = $expiry;
        try { new JWTHandler(); throw new LogicException('Invalid expiry accepted'); }
        catch (RuntimeException $e) { jwtCheck(strpos($e->getMessage(), 'JWT_EXPIRY') !== false, 'invalid expiry configuration rejected'); }
    }
    unset($_ENV['JWT_EXPIRY']);
    $jwt = new JWTHandler();
    foreach (['member-42', 42] as $id) {
        $token = $jwt->generateToken($id);
        jwtCheck($jwt->validateToken($token)['user_id'] === $id, 'generated token round trips');
        jwtCheck($jwt->getPayload($token)['user_id'] === $id, 'payload accessor validates');
        $parts = explode('.', $token); $parts[2][0] = $parts[2][0] === 'A' ? 'B' : 'A';
        jwtCheck($jwt->validateToken(implode('.', $parts)) === false, 'tampered signature rejected');
        jwtCheck($jwt->getPayload(implode('.', $parts)) === null, 'payload accessor rejects forgery');
    }
    $valid = ['user_id' => 'member-42', 'iat' => time(), 'exp' => time() + 3600];
    foreach ([['alg' => 'none'], ['alg' => 'HS512'], ['alg' => 'HS256', 'crit' => ['custom']], ['alg' => 'HS256', 'b64' => false]] as $header) {
        jwtCheck($jwt->validateToken(testJwtToken($header, $valid, $secret)) === false, 'unsupported signed header rejected');
    }
    foreach ([['exp' => time()], ['exp' => time() - 1], ['exp' => '9999999999'], ['user_id' => []], ['user_id' => true], ['user_id' => ' '], ['user_id' => null], ['nbf' => time() + 60], ['iat' => time() + 60], ['iat' => 'invalid']] as $claims) {
        jwtCheck($jwt->validateToken(testJwtToken(['alg' => 'HS256'], array_replace($valid, $claims), $secret)) === false, 'invalid identity/time claim rejected');
    }
    foreach ([null, [], '', 'a.b', 'a.b.c.d', '!.a.b'] as $token) jwtCheck($jwt->validateToken($token) === false, 'malformed token rejected');
    foreach ([[], true, null, ' '] as $id) {
        try { $jwt->generateToken($id); throw new RuntimeException('Invalid identity accepted'); }
        catch (InvalidArgumentException $e) { jwtCheck(true, 'invalid generated identity rejected'); }
    }
} finally {
    $_ENV = $previousEnv;
    putenv($previousSecret === false ? 'JWT_SECRET' : 'JWT_SECRET=' . $previousSecret);
    putenv($previousExpiry === false ? 'JWT_EXPIRY' : 'JWT_EXPIRY=' . $previousExpiry);
    unlink($fixture . '/vendor/autoload.php');
    unlink($fixture . '/auth_api/config/config.php');
    unlink($fixture . '/auth_api/utils/JWTHandler.php');
    rmdir($fixture . '/auth_api/config'); rmdir($fixture . '/auth_api/utils'); rmdir($fixture . '/auth_api'); rmdir($fixture . '/vendor'); rmdir($fixture);
}
