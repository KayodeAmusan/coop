<?php
class JWTHandler {
    private $secret;
    private $expiry;

    public function __construct() {
        $config = require __DIR__ . '/../config/config.php';
        $this->secret = $config['jwt']['secret'];
        $this->expiry = $config['jwt']['expiry'];
    }

    public function generateToken($userId) {
        if ((!is_string($userId) && !is_int($userId)) || trim((string)$userId) === '') {
            throw new InvalidArgumentException('A member identifier is required');
        }
        $payload = [
            'user_id' => $userId,
            'iat' => time(),
            'exp' => time() + $this->expiry
        ];

        return $this->encodeToken($payload);
    }

    public function validateToken($token) {
        try {
            if (!is_string($token)) return false;
            $parts = explode('.', $token);
            if (count($parts) !== 3) return false;
            list($header, $payload, $signature) = $parts;
            foreach ($parts as $part) {
                if ($part === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $part)) return false;
            }
            $decodedHeader = json_decode($this->base64UrlDecode($header), true);
            if (!is_array($decodedHeader) || ($decodedHeader['alg'] ?? null) !== 'HS256'
                || (isset($decodedHeader['typ']) && $decodedHeader['typ'] !== 'JWT')
                || isset($decodedHeader['crit']) || isset($decodedHeader['b64'])) return false;
            $expected = $this->base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $this->secret, true));
            if (!hash_equals($expected, $signature)) return false;
            $payload = json_decode($this->base64UrlDecode($payload), true);
            if (!is_array($payload) || !isset($payload['user_id'], $payload['exp'])
                || (!is_string($payload['user_id']) && !is_int($payload['user_id']))
                || trim((string)$payload['user_id']) === '' || !is_int($payload['exp'])) return false;
            $now = time();
            if ($payload['exp'] <= $now) return false;
            foreach (['iat', 'nbf'] as $claim) {
                if (array_key_exists($claim, $payload)
                    && (!is_int($payload[$claim]) || $payload[$claim] > $now)) return false;
            }
            return $payload;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getPayload($token) {
        $payload = $this->validateToken($token);
        return $payload === false ? null : $payload;
    }

    private function encodeToken($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);

        $base64Header = $this->base64UrlEncode($header);
        $base64Payload = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256',
            $base64Header . "." . $base64Payload,
            $this->secret,
            true
        );

        $base64Signature = $this->base64UrlEncode($signature);

        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }

    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }
}