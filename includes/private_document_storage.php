<?php
/** Private opaque storage; the caller supplies deployment configuration, never request paths. */
class PrivateDocumentStorage {
    private string $root;
    private $scanner;
    public function __construct(string $configuredRoot, string $documentRoot, ?callable $scanner = null, ?string $applicationRoot = null) {
        $normalizedRoot=str_replace('\\','/',$configuredRoot);
        if (trim($configuredRoot) === '' || (!str_starts_with($normalizedRoot,'/') && !preg_match('~\A[A-Za-z]:/~',$normalizedRoot))) throw new RuntimeException('An absolute private storage directory must be configured');
        $root = realpath($configuredRoot); $public = realpath($documentRoot);
        if (!$root || !$public || !is_dir($root) || !is_dir($public) || !is_writable($root)) throw new RuntimeException('Private document storage is not configured');
        if (self::isWithin($root, $public) || self::isWithin($public, $root)) throw new RuntimeException('Document storage must be separate from the application document root');
        if ($applicationRoot !== null) { $app=realpath($applicationRoot); if(!$app || self::isWithin($root,$app) || self::isWithin($app,$root)) throw new RuntimeException('Document storage must be separate from the application root'); }
        $this->root = $root; $this->scanner = $scanner;
    }
    public static function isWithin(string $path, string $root): bool {
        $path = str_replace('\\', '/', $path); $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
        return $path === $root || str_starts_with($path, $root . '/');
    }
    private function path(string $key): string {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) throw new InvalidArgumentException('Invalid document storage key');
        if(realpath($this->root)!==$this->root)throw new RuntimeException('Private storage location changed');
        return $this->root . DIRECTORY_SEPARATOR . $key;
    }
    public function storeUploadedFile(array $file): array {
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('A successful file upload is required');
        $source = $file['tmp_name']; $size = filesize($source);
        if ($size === false || $size < 1 || $size > 5 * 1024 * 1024) throw new InvalidArgumentException('Document must contain between one byte and 5 MB');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
        $head = file_get_contents($source, false, null, 0, 16);
        $valid = false;
        if ($mime === 'application/pdf' && preg_match('/\A%PDF-[0-9]\.[0-9]/', $head)) {
            $handle = fopen($source, 'rb'); fseek($handle, max(0, $size - 1024)); $tail = stream_get_contents($handle); fclose($handle);
            $valid = preg_match('/%%EOF\s*\z/', $tail) === 1;
        } elseif (in_array($mime, ['image/jpeg', 'image/png'], true)) {
            $image = @getimagesize($source);
            $valid = $image && ($image['mime'] ?? '') === $mime &&
                (($mime === 'image/png' && str_starts_with($head, "\x89PNG\r\n\x1a\n")) || ($mime === 'image/jpeg' && str_starts_with($head, "\xff\xd8\xff")));
        }
        if (!$valid) throw new InvalidArgumentException('Only valid PDF, JPEG and PNG documents are accepted');
        $key = bin2hex(random_bytes(32)); $path = $this->path($key);
        if (file_exists($path) || !move_uploaded_file($source, $path)) throw new RuntimeException('Private document could not be stored');
        @chmod($path, 0600);
        try {
            $stored = ['storage_key' => $key, 'content_sha256' => hash_file('sha256', $path), 'byte_size' => $size, 'mime_type' => $mime];
            return array_merge($stored, $this->scan($stored));
        } catch (Throwable $error) { $this->remove($key); throw $error; }
    }
    public function scan(array $metadata): array {
        $handle = $this->verifiedHandle($metadata); fclose($handle);
        $result = null;
        if ($this->scanner !== null) {
            try { $result = ($this->scanner)($this->path($metadata['storage_key'])); }
            catch (Throwable $error) { $result = null; }
        }
        $status = 'quarantined'; $engine = null;
        if (is_array($result) && is_string($result['engine'] ?? null) && trim($result['engine']) !== '' && strlen($result['engine']) <= 100) {
            $engine = $result['engine'];
            if (($result['status'] ?? null) === 'clean') $status = 'clean';
            if (($result['status'] ?? null) === 'infected') $status = 'infected';
        }
        // A scanner must not alter the document it examined.
        $handle = $this->verifiedHandle($metadata); fclose($handle);
        return ['scan_status' => $status, 'scan_engine' => $engine];
    }
    private function verifiedHandle(array $metadata) {
        $path = $this->path($metadata['storage_key']); $resolved = realpath($path);
        if (!$resolved || !self::isWithin($resolved, $this->root) || !is_file($resolved)) throw new RuntimeException('Document content is unavailable');
        $handle = fopen($resolved, 'rb');
        if (!$handle) throw new RuntimeException('Document content is unavailable');
        $stat = fstat($handle); $hash = hash_init('sha256'); hash_update_stream($hash, $handle); $actual = hash_final($hash);
        if (!$stat || $stat['size'] !== (int)$metadata['byte_size'] || !hash_equals($metadata['content_sha256'], $actual)) { fclose($handle); throw new RuntimeException('Document integrity verification failed'); }
        rewind($handle); return $handle;
    }
    public function openCleanDocument(array $metadata) {
        if (($metadata['scan_status'] ?? null) !== 'clean') throw new RuntimeException('Document remains quarantined or blocked',409);
        return $this->verifiedHandle($metadata);
    }
    public function remove(string $key): void {
        $path = $this->path($key);
        // Only an opaque generated key under the configured private directory may be removed.
        if (is_file($path) && !is_link($path)) unlink($path);
    }
}
