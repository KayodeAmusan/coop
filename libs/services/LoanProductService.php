<?php
/** Draft configuration only. Publication waits for approved, implemented calculation and guarantee rules. */
class LoanProductService
{
    public const SETUP_MESSAGE = 'Loan product configuration is required before new applications can be created. Ask an administrator to complete and approve the product rules.';
    private PDO $db;
    private static int $savepointNumber = 0;

    public function __construct(PDO $db) { $this->db = $db; }

    public function applicationAvailability(): array
    {
        return ['applications_enabled' => false, 'code' => 'LOAN_PRODUCT_SETUP_REQUIRED',
            'message' => self::SETUP_MESSAGE, 'products' => []];
    }

    public function requireApplicationTerms($productVersionId, string $borrowerType = 'member'): array
    {
        // No draft, supplied ID, or database status edit can enable unsupported financial rules.
        throw new RuntimeException(self::SETUP_MESSAGE, 409);
    }

    public function createDraft(string $code, string $name, string $description, int $createdBy): array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,40}\z/', $code) || trim($name) === '' || strlen($name) > 150 || strlen($description) > 8192 || $createdBy <= 0) {
            throw new InvalidArgumentException('A product code, name and authenticated staff actor are required; description must not exceed 8192 bytes', 400);
        }
        try {
            return $this->transaction(function () use ($code, $name, $description, $createdBy) {
            $this->execute("INSERT INTO coop_loan_products (product_code, name, description, status, created_by_user_id) VALUES (?, ?, ?, 'draft', ?)", [$code, trim($name), trim($description), $createdBy]);
            $result = ['id' => (int) $this->db->lastInsertId(), 'status' => 'draft'];
            $this->audit($createdBy, 'coop_loan_products', $result['id'], ['product_code' => $code, 'status' => 'draft']);
            return $result;
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) throw new RuntimeException('A loan product already uses this code. Choose another code or open the existing draft.', 409);
            throw $error;
        }
    }

    public function addDraftVersion(int $productId, array $candidateRules, ?string $terms, int $createdBy): array
    {
        if ($productId <= 0 || $createdBy <= 0) throw new InvalidArgumentException('A product and authenticated staff actor are required', 400);
        $json = json_encode($candidateRules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 65535 || ($terms !== null && strlen($terms) > 65535)) throw new InvalidArgumentException('Draft configuration is too large', 400);
        return $this->transaction(function () use ($productId, $json, $terms, $createdBy) {
            if (!$this->execute('SELECT id FROM coop_loan_products WHERE id = ? FOR UPDATE', [$productId])->fetchColumn()) throw new RuntimeException('Loan product not found', 404);
            $version = (int) $this->execute('SELECT version_number FROM coop_loan_product_versions WHERE product_id = ? ORDER BY version_number DESC LIMIT 1 FOR UPDATE', [$productId])->fetchColumn() + 1;
            $hash = self::draftHash($json, $terms);
            $this->execute('INSERT INTO coop_loan_product_versions (product_id, version_number, configuration_json, terms_text, content_hash, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)', [$productId, $version, $json, $terms, $hash, $createdBy]);
            $id = (int) $this->db->lastInsertId();
            $this->audit($createdBy, 'coop_loan_product_versions', $id, ['product_id' => $productId, 'version_number' => $version, 'content_hash' => $hash, 'status' => 'draft']);
            return ['id' => $id, 'product_id' => $productId, 'version_number' => $version, 'content_hash' => $hash, 'status' => 'draft'];
        });
    }

    public function runAdminCommand(string $action, array $payload, int $actor, string $key): array
    {
        if ($actor <= 0 || $actor > 2147483647 || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/', $key)) throw new InvalidArgumentException('A valid staff actor and submission reference are required', 400);
        $allowed = $action === 'create_draft' ? ['code', 'name', 'description'] : ($action === 'save_version' ? ['product_id', 'calculation_inputs', 'terms'] : []);
        if (!$allowed || array_diff(array_keys($payload), $allowed) || array_diff($allowed, array_keys($payload))) throw new InvalidArgumentException('Unsupported action or draft fields', 400);
        if ($action === 'create_draft') {
            foreach ($allowed as $field) if (!is_string($payload[$field])) throw new InvalidArgumentException('Product code, name and description must be text', 400);
        } else {
            if (!is_int($payload['product_id']) || $payload['product_id'] <= 0 || !is_array($payload['calculation_inputs']) || !is_string($payload['terms']) || trim($payload['terms']) === '') throw new InvalidArgumentException('A product, calculation inputs and nonempty draft terms are required', 400);
            $this->preview($payload['calculation_inputs']);
        }
        $hash = self::draftHash(json_encode(['action' => $action, 'payload' => $payload], JSON_THROW_ON_ERROR), null);
        return $this->transaction(function () use ($action, $payload, $actor, $key, $hash) {
            $this->execute('INSERT INTO coop_loan_product_commands (actor_user_id, action, idempotency_key, request_hash) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id', [$actor, $action, $key, $hash]);
            $command = $this->execute('SELECT id, request_hash, result_json FROM coop_loan_product_commands WHERE actor_user_id = ? AND action = ? AND idempotency_key = ? FOR UPDATE', [$actor, $action, $key])->fetch(PDO::FETCH_ASSOC);
            if (!$command || !hash_equals($command['request_hash'], $hash)) throw new RuntimeException('Submission reference was already used with different draft inputs. Start a new submission.', 409);
            if ($command['result_json'] !== null) {
                $result = json_decode($command['result_json'], true, 512, JSON_THROW_ON_ERROR);
                $result['replayed'] = true;
                return $result;
            }
            $result = $action === 'create_draft'
                ? $this->createDraft($payload['code'], $payload['name'], $payload['description'], $actor)
                : $this->addDraftVersion($payload['product_id'], ['calculation_inputs' => $payload['calculation_inputs'], 'calculation_version' => 'fixed_period_v1'], $payload['terms'], $actor);
            $this->execute('UPDATE coop_loan_product_commands SET result_json = ? WHERE id = ?', [json_encode($result, JSON_THROW_ON_ERROR), $command['id']]);
            $result['replayed'] = false;
            return $result;
        });
    }

    public function preview(array $inputs): array
    {
        require_once __DIR__ . '/LoanScheduleCalculator.php';
        return (new LoanScheduleCalculator())->calculate($inputs);
    }

    public function listDrafts(int $page, int $limit): array
    {
        $offset = $this->paginationOffset($page, $limit);
        $items = $this->execute("SELECT p.*, (SELECT COUNT(*) FROM coop_loan_product_versions v WHERE v.product_id = p.id) AS version_count FROM coop_loan_products p ORDER BY p.id DESC LIMIT $limit OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item['id'] = (int) $item['id']; $item['created_by_user_id'] = (int) $item['created_by_user_id']; $item['version_count'] = (int) $item['version_count'];
        }
        return ['items' => $items, 'page' => $page, 'limit' => $limit, 'total' => (int) $this->execute('SELECT COUNT(*) FROM coop_loan_products')->fetchColumn()];
    }

    public function listVersions(int $productId, int $page, int $limit): array
    {
        $offset = $this->paginationOffset($page, $limit);
        if ($productId <= 0 || !$this->execute('SELECT id FROM coop_loan_products WHERE id = ?', [$productId])->fetchColumn()) throw new RuntimeException('Loan product not found', 404);
        $items = $this->execute("SELECT * FROM coop_loan_product_versions WHERE product_id = ? ORDER BY version_number DESC LIMIT $limit OFFSET $offset", [$productId])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            if (!hash_equals($item['content_hash'], self::draftHash($item['configuration_json'], $item['terms_text']))) throw new RuntimeException('Draft version integrity check failed');
            $item['configuration'] = json_decode($item['configuration_json'], true, 512, JSON_THROW_ON_ERROR); unset($item['configuration_json']);
            foreach (['id', 'product_id', 'version_number', 'created_by_user_id'] as $field) $item[$field] = (int) $item[$field];
            $item['status'] = 'draft';
        }
        $total = (int) $this->execute('SELECT COUNT(*) FROM coop_loan_product_versions WHERE product_id = ?', [$productId])->fetchColumn();
        return ['items' => $items, 'page' => $page, 'limit' => $limit, 'total' => $total];
    }

    private function paginationOffset(int $page, int $limit): int
    {
        if ($page < 1 || $page > 10000 || $limit < 1 || $limit > 50) throw new InvalidArgumentException('Page must be 1–10000 and limit 1–50', 400);
        return ($page - 1) * $limit;
    }

    private function execute(string $sql, array $parameters = [])
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt || !$stmt->execute($parameters)) throw new RuntimeException('Loan product database operation failed');
        return $stmt;
    }

    private function audit(int $actor, string $table, int $record, array $values): void
    {
        $this->execute("INSERT INTO coop_audit_trail (user_id, action_type, table_name, record_id, new_values, ip_address, user_agent) VALUES (?, 'create', ?, ?, ?, ?, ?)",
            [$actor, $table, $record, json_encode($values, JSON_THROW_ON_ERROR), $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);
    }

    private function transaction(callable $operation): array
    {
        $owned = !$this->db->inTransaction(); $savepoint = 'loan_product_' . ++self::$savepointNumber; $started = false;
        try {
            if ($owned) $this->db->beginTransaction(); else $this->db->exec("SAVEPOINT $savepoint");
            $started = true; $result = $operation();
            if ($owned) $this->db->commit(); else $this->db->exec("RELEASE SAVEPOINT $savepoint");
            return $result;
        } catch (Throwable $error) {
            if ($started && $this->db->inTransaction()) {
                if ($owned) $this->db->rollBack();
                else { $this->db->exec("ROLLBACK TO SAVEPOINT $savepoint"); $this->db->exec("RELEASE SAVEPOINT $savepoint"); }
            }
            throw $error;
        }
    }

    public static function draftHash(string $configurationJson, ?string $terms): string
    {
        $rules = json_decode($configurationJson, true, 512, JSON_THROW_ON_ERROR);
        $canonicalize = function ($value) use (&$canonicalize) {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($canonicalize, $value);
        };
        // Native MySQL JSON may reorder object keys and whitespace when it is read back.
        return hash('sha256', json_encode([$canonicalize($rules), $terms], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
