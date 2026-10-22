<?php
/** Run unit/UI checks: php tests/run.php; add --mysql for disposable loopback fixtures. */
if (PHP_SAPI !== 'cli') exit(2);
chdir(dirname(__DIR__));
$mysql = in_array('--mysql', $argv, true);
$scripts = glob(__DIR__ . '/*_test.php');
$failures = []; $checks = 0; $runs = 0; $skipped = [];
foreach ($scripts as $script) {
    $name = basename($script);
    $database = strpos($name, '_database_') !== false || strpos($name, '_mysql_') !== false || $name === 'payroll_holding_endpoint_test.php';
    if ($database && !$mysql) { $skipped[] = $name; continue; }
    putenv('COOP_CONSENT_MYSQL_TEST=1'); putenv('COOP_GUARANTOR_MYSQL_TEST=1');
    putenv('COOP_PLATFORM_MYSQL_TEST=1'); putenv('COOP_ACCESS_MYSQL_TEST=1'); putenv('COOP_EVIDENCE_MYSQL_TEST=1');
    putenv('COOP_HOLDING_SETUP_TEST=1'); putenv('COOP_LOAN_PRODUCT_ADMIN_MYSQL_TEST=1'); putenv('COOP_LOAN_PRODUCT_MYSQL_TEST=1'); putenv('COOP_BALANCE_MYSQL_TEST=1'); putenv('COOP_APPLICATION_MYSQL_TEST=1'); putenv('COOP_PAYROLL_MYSQL_TEST=1');
    putenv('COOP_LEDGER_TEST_DSN=mysql:host=127.0.0.1;port=13307;dbname=coop_test_ledger;charset=utf8mb4');
    putenv('COOP_WORKFLOWS_TEST_DSN=mysql:host=127.0.0.1;port=13307;dbname=coop_test_closing;charset=utf8mb4');
    runQaScript([PHP_BINARY, $script], $name);
}
foreach (glob(__DIR__ . '/*_test.cjs') as $script) runQaScript(['node', $script], basename($script));
echo "\nQA TOTAL: $runs scripts, $checks reported PASS checks, " . count($failures) . " failed scripts, " . count($skipped) . " skipped database scripts.\n";
if ($skipped) echo "Use --mysql to run fixed disposable loopback:13307 fixtures.\n";
exit($failures ? 1 : 0);
function runQaScript(array $command, string $name): void {
    global $checks, $runs, $failures;
    $process = proc_open($command, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start ' . $name);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
    if ($code === 0 && preg_match('/^SKIP\b/m', $stdout)) {
        $code = 2;
        $stderr .= "A selected test skipped itself; configure its test opt-in before claiming a complete gate.\n";
    }
    $passed = preg_match_all('/^PASS\b/m', $stdout);
    if (preg_match('/^(\d+) guarantor status checks passed$/m', $stdout, $legacy)) $passed += (int)$legacy[1];
    $checks += $passed; $runs++;
    echo ($code === 0 ? 'PASS ' : 'FAIL ') . "$name ($passed checks)\n";
    if ($code !== 0) { $failures[] = $name; echo $stdout . $stderr . "\n"; }
}
