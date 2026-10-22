<?php
/** Synthetic MariaDB integration only. No production DSNs or non-test databases. */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../libs/services/AccountingEngine.php';
$dsn = getenv('COOP_LEDGER_TEST_DSN');
if (!$dsn || !preg_match('/\Amysql:host=127\.0\.0\.1;port=13307;dbname=(coop_test_[a-z0-9_]+);charset=utf8mb4\z/', $dsn, $match)) {
    fwrite(STDERR, "Set COOP_LEDGER_TEST_DSN to the isolated loopback:13307 coop_test_* database.\n");
    exit(2);
}
function ledgerDb($dsn) {
    return new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}
if (($argv[1] ?? '') === 'worker') {
    $engine = new AccountingEngine(ledgerDb($dsn));
    if ($argv[2] === 'create-key') {
        $result = ledgerKeyedCreate($engine, 'concurrent-' . $argv[3]);
    } elseif ($argv[2] === 'create') {
        $result = ledgerCreate($engine, '0.01', (int) $argv[3]);
    } else {
        $result = ($argv[2] === 'post') ? $engine->postEntry((int) $argv[3]) : $engine->reverseEntry((int) $argv[3], 1, 'concurrent retry');
    }
    echo json_encode($result);
    exit($result['success'] ? 0 : 1);
}
$database = $match[1];
$server = ledgerDb('mysql:host=127.0.0.1;port=13307;charset=utf8mb4');
$server->exec("CREATE DATABASE IF NOT EXISTS `$database`");
$db = ledgerDb($dsn);
$tables = ['coop_journal_sequences', 'coop_journal_entry_lines', 'coop_period_balances', 'coop_audit_trail', 'coop_journal_entries', 'coop_accounts', 'tbpayrollperiods'];
foreach ($tables as $table) { $db->exec("DROP TABLE IF EXISTS `$table`"); }
$db->exec('CREATE TABLE tbpayrollperiods (id INT PRIMARY KEY, PayrollPeriod VARCHAR(100)) ENGINE=InnoDB');
$db->exec("INSERT INTO tbpayrollperiods VALUES (1, 'October 2026'), (2, 'November 2026')");
$schema = file_get_contents(__DIR__ . '/../SETUP_FULL_ACCOUNTING_SYSTEM.sql');
foreach (['coop_accounts', 'coop_journal_entries', 'coop_journal_entry_lines', 'coop_period_balances', 'coop_audit_trail'] as $table) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . '\s*\(.*?ENGINE=InnoDB[^;]*;/s', $schema, $sql)) { throw new RuntimeException('Missing schema ' . $table); }
    $db->exec($sql[0]);
}
$db->exec(file_get_contents(__DIR__ . '/../database/migrations/20261021_ledger_sequences.sql'));
$idempotencyMigration = file_get_contents(__DIR__ . '/../database/migrations/20261021_journal_idempotency.sql');
$db->exec($idempotencyMigration);
$db->exec($idempotencyMigration);
$db->exec("INSERT INTO coop_accounts (id, account_code, account_name, account_type, normal_balance) VALUES (1,'1000','Bank','asset','debit'), (2,'3201','Savings','equity','credit')");
$engine = new AccountingEngine($db);
function ledgerCreate($engine, $amount = '0.30', $period = 1) {
    $entry = $engine->createJournalEntry($period, '2026-10-21', 'manual', 'Synthetic ledger test', [
        ['account_id' => 1, 'debit_amount' => $amount], ['account_id' => 2, 'credit_amount' => $amount]
    ], 1);
    if (!$entry['success']) { throw new RuntimeException($entry['error']); }
    return $entry;
}
function ledgerSuccess($result) {
    if (!$result['success']) { throw new RuntimeException($result['error']); }
}
function ledgerWorkers($action, $entryId, $barrierDb = null) {
    if ($barrierDb) {
        $barrierDb->beginTransaction();
        $barrierDb->query('SELECT id FROM tbpayrollperiods WHERE id=1 FOR UPDATE')->fetchColumn();
    }
    $workers = [];
    for ($index = 0; $index < 3; $index++) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $action, (string) $entryId], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start concurrent PHP worker'); }
        $workers[] = [$process, $pipes];
    }
    $barrierFailed = false;
    if ($barrierDb) {
        $waiting = 0;
        try {
            for ($attempt = 0; $attempt < 300; $attempt++) {
                $waiting = (int) $barrierDb->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND INFO LIKE 'SELECT id FROM tbpayrollperiods WHERE id = %FOR UPDATE'")->fetchColumn();
                if ($waiting >= 3) { break; }
                usleep(10000);
            }
        } finally { $barrierDb->commit(); }
        $barrierFailed = $waiting < 3;
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) { throw new RuntimeException('Concurrent worker failed: ' . $output . $errors); }
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        ledgerSuccess($result);
        $results[] = $result;
    }
    if ($barrierFailed) { throw new RuntimeException('Concurrent workers did not reach the locked period barrier'); }
    return $results;
}

test('existing balance totals add once and repeated post is idempotent', function () use ($engine, $db) {
    $first = ledgerCreate($engine, '0.10'); ledgerSuccess($engine->postEntry($first['entry_id']));
    $next = ledgerCreate($engine, '0.20'); ledgerSuccess($engine->postEntry($next['entry_id']));
    ledgerSuccess($engine->postEntry($next['entry_id']));
    $balance = $db->query('SELECT period_debit, closing_debit FROM coop_period_balances WHERE account_id=1 AND periodid=1')->fetch(PDO::FETCH_ASSOC);
    assertSameValue(['period_debit' => '0.30', 'closing_debit' => '0.30'], $balance);
    assertSameValue('2', (string) $db->query("SELECT COUNT(*) FROM coop_audit_trail WHERE action_type='post'")->fetchColumn());
});
test('owned savepoints preserve caller transaction and rollback', function () use ($engine, $db) {
    $before = $db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn();
    $db->beginTransaction();
    $entry = ledgerCreate($engine, '2.00'); ledgerSuccess($engine->postEntry($entry['entry_id']));
    assertSameValue(true, $db->inTransaction());
    $db->rollBack();
    assertSameValue((string) $before, (string) $db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn());
});
test('one kobo imbalance is rejected without a header', function () use ($engine, $db) {
    $before = $db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn();
    $result = $engine->createJournalEntry(1, '2026-10-21', 'manual', 'Bad', [['account_id'=>1, 'debit_amount'=>'1.00'], ['account_id'=>2, 'credit_amount'=>'0.99']], 1);
    assertSameValue(false, $result['success']);
    assertSameValue((string) $before, (string) $db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn());
});
test('persisted malformed lines and header mismatch cannot post', function () use ($engine, $db) {
    $entry = ledgerCreate($engine, '4.00');
    $db->exec('UPDATE coop_journal_entry_lines SET credit_amount=3.99 WHERE credit_amount>0 AND journal_entry_id=' . (int) $entry['entry_id']);
    assertSameValue(false, $engine->postEntry($entry['entry_id'])['success']);
    assertSameValue('draft', $engine->getJournalEntry($entry['entry_id'])['status']);
    $db->exec('UPDATE coop_journal_entry_lines SET credit_amount=4 WHERE credit_amount>0 AND journal_entry_id=' . (int) $entry['entry_id']);
    $db->exec('UPDATE coop_journal_entries SET total_amount=5 WHERE id=' . (int) $entry['entry_id']);
    assertSameValue(false, $engine->postEntry($entry['entry_id'])['success']);
});
test('audit failures roll back status and balances but preserve outer work', function () use ($engine, $db) {
    $entry = ledgerCreate($engine, '1.00');
    $before = $db->query('SELECT period_debit FROM coop_period_balances WHERE account_id=1 AND periodid=1')->fetchColumn();
    $db->exec("CREATE TRIGGER ledger_fail_audit BEFORE INSERT ON coop_audit_trail FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    try {
        $db->beginTransaction();
        $db->exec("UPDATE coop_accounts SET account_name='Outer work survives' WHERE id=1");
        assertSameValue(false, $engine->postEntry($entry['entry_id'])['success']);
        assertSameValue(true, $db->inTransaction());
        assertSameValue('Outer work survives', $db->query('SELECT account_name FROM coop_accounts WHERE id=1')->fetchColumn());
        assertSameValue('draft', $engine->getJournalEntry($entry['entry_id'])['status']);
        assertSameValue($before, $db->query('SELECT period_debit FROM coop_period_balances WHERE account_id=1 AND periodid=1')->fetchColumn());
        $db->rollBack();
    } finally { if ($db->inTransaction()) {$db->rollBack();} $db->exec('DROP TRIGGER ledger_fail_audit'); }
});
test('reversal link failure rolls back newly posted reversal and balance changes', function () use ($engine, $db) {
    $entry = ledgerCreate($engine, '1.00'); ledgerSuccess($engine->postEntry($entry['entry_id']));
    $before = $db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn();
    $totals = $db->query('SELECT SUM(period_debit), SUM(period_credit) FROM coop_period_balances')->fetch(PDO::FETCH_NUM);
    $db->exec("CREATE TRIGGER ledger_fail_reversal BEFORE UPDATE ON coop_journal_entries FOR EACH ROW BEGIN IF NEW.is_reversed=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic reversal link failure'; END IF; END");
    try { assertSameValue(false, $engine->reverseEntry($entry['entry_id'], 1, 'fail atomically')['success']); }
    finally { $db->exec('DROP TRIGGER ledger_fail_reversal'); }
    assertSameValue((string) $before, (string) $db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn());
    assertSameValue($totals, $db->query('SELECT SUM(period_debit), SUM(period_credit) FROM coop_period_balances')->fetch(PDO::FETCH_NUM));
    assertSameValue('0', (string) $engine->getJournalEntry($entry['entry_id'])['is_reversed']);
});
test('concurrent posts apply one journal exactly once', function () use ($engine, $db) {
    $entry = ledgerCreate($engine, '1.23');
    ledgerWorkers('post', $entry['entry_id']);
    assertSameValue('1', (string) $db->query("SELECT COUNT(*) FROM coop_audit_trail WHERE action_type='post' AND record_id=" . (int) $entry['entry_id'])->fetchColumn());
});
test('concurrent reversals return one existing reversal and one audit', function () use ($engine, $db) {
    $entry = ledgerCreate($engine, '1.25'); ledgerSuccess($engine->postEntry($entry['entry_id']));
    $results = ledgerWorkers('reverse', $entry['entry_id']);
    assertSameValue(1, count(array_unique(array_column($results, 'reversing_entry_id'))));
    assertSameValue('1', (string) $db->query("SELECT COUNT(*) FROM coop_audit_trail WHERE action_type='reverse' AND record_id=" . (int) $entry['entry_id'])->fetchColumn());
});
test('journal sequences preserve complete suffix above 9999', function () use ($engine, $db) {
    $db->exec("INSERT INTO coop_journal_entries (entry_number, entry_date, periodid, entry_type, description, total_amount, created_by) VALUES ('JE-2026-10000','2026-10-21',1,'manual','legacy',0,1)");
    assertSameValue('JE-2026-10001', ledgerCreate($engine)['entry_number']);
});
test('inactive accounts and closed periods fail without posting', function () use ($engine, $db) {
    $entry = ledgerCreate($engine, '1.00', 2);
    $db->exec('UPDATE coop_accounts SET is_active=0 WHERE id=2');
    assertSameValue(false, $engine->postEntry($entry['entry_id'])['success']);
    $db->exec('UPDATE coop_accounts SET is_active=1 WHERE id=2');
    $db->exec('INSERT INTO coop_period_balances (periodid,account_id,is_closed) VALUES (2,1,1)');
    assertSameValue(false, $engine->postEntry($entry['entry_id'])['success']);
    assertSameValue('draft', $engine->getJournalEntry($entry['entry_id'])['status']);
});
test('concurrent creates issue distinct entry numbers', function () {
    $results = ledgerWorkers('create', 1);
    assertSameValue(3, count(array_unique(array_column($results, 'entry_number'))));
});
test('caller rollback also undoes a complete reversal', function () use ($engine, $db) {
    $entry = ledgerCreate($engine, '3.25'); ledgerSuccess($engine->postEntry($entry['entry_id']));
    $before = $db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn();
    $totals = $db->query('SELECT SUM(period_debit), SUM(period_credit) FROM coop_period_balances')->fetch(PDO::FETCH_NUM);
    $db->beginTransaction();
    ledgerSuccess($engine->reverseEntry($entry['entry_id'], 1, 'outer rollback'));
    assertSameValue(true, $db->inTransaction());
    $db->rollBack();
    assertSameValue((string) $before, (string) $db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn());
    assertSameValue($totals, $db->query('SELECT SUM(period_debit), SUM(period_credit) FROM coop_period_balances')->fetch(PDO::FETCH_NUM));
    assertSameValue('0', (string) $engine->getJournalEntry($entry['entry_id'])['is_reversed']);
});
test('reversal audit failure undoes the reversal and original marker', function () use ($engine, $db) {
    $entry = ledgerCreate($engine, '3.25'); ledgerSuccess($engine->postEntry($entry['entry_id']));
    $before = $db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn();
    $totals = $db->query('SELECT SUM(period_debit), SUM(period_credit) FROM coop_period_balances')->fetch(PDO::FETCH_NUM);
    $db->exec("CREATE TRIGGER ledger_fail_reverse_audit BEFORE INSERT ON coop_audit_trail FOR EACH ROW BEGIN IF NEW.action_type='reverse' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic reversal audit failure'; END IF; END");
    try { assertSameValue(false, $engine->reverseEntry($entry['entry_id'], 1, 'audit failure')['success']); }
    finally { $db->exec('DROP TRIGGER ledger_fail_reverse_audit'); }
    assertSameValue((string) $before, (string) $db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn());
    assertSameValue($totals, $db->query('SELECT SUM(period_debit), SUM(period_credit) FROM coop_period_balances')->fetch(PDO::FETCH_NUM));
    assertSameValue('0', (string) $engine->getJournalEntry($entry['entry_id'])['is_reversed']);
});
function ledgerKeyedCreate($engine, $key, $amount='0.75', $actor=1, $period=1, $description='Idempotent synthetic command', $source='REPEATABLE-SOURCE') {
    return $engine->createJournalEntry($period,'2026-10-21','manual',$description,[
        ['account_id'=>1,'debit_amount'=>$amount],['account_id'=>2,'credit_amount'=>$amount]
    ],$actor,$source,$key);
}
test('same business key replays one journal with canonical decimal amounts', function () use ($engine,$db) {
    $before=(int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn();
    $entry=ledgerKeyedCreate($engine,'retry-money','1.00');ledgerSuccess($entry);
    $again=ledgerKeyedCreate($engine,'retry-money',1);ledgerSuccess($again);
    assertSameValue((string)$entry['entry_id'],(string)$again['entry_id']);assertSameValue(true,$again['replayed']);
    assertSameValue($before+1,(int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn());
});
test('same key different payload is rejected without writing and actors have separate scope', function () use ($engine,$db) {
    $entry=ledgerKeyedCreate($engine,'scoped-key','1.00');ledgerSuccess($entry);
    foreach ([['1.01',1,1,'Idempotent synthetic command','REPEATABLE-SOURCE'],['1.00',1,2,'Idempotent synthetic command','REPEATABLE-SOURCE'],
        ['1.00',1,1,'Changed description','REPEATABLE-SOURCE'],['1.00',1,1,'Idempotent synthetic command','DIFFERENT-SOURCE']] as $payload) {
        $before=(int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn();
        $result=ledgerKeyedCreate($engine,'scoped-key',...$payload);assertSameValue(false,$result['success']);assertContains('different journal request',$result['error']);
        assertSameValue($before,(int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn());
    }
    $other=ledgerKeyedCreate($engine,'scoped-key','1.00',2);ledgerSuccess($other);
    assertSameValue(false,(string)$entry['entry_id']===(string)$other['entry_id']);
});
test('concurrent same-key creates return exactly one persisted journal',function () use ($engine,$db) {
    $before=(int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn();
    $results=ledgerWorkers('create-key',1,$db);
    assertSameValue(1,count(array_unique(array_map('strval',array_column($results,'entry_id')))));
    assertSameValue($before+1,(int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn());
});
test('request identity rolls back with caller and is reusable after rollback',function () use ($engine,$db) {
    $before=(int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn();$db->beginTransaction();
    $entry=ledgerKeyedCreate($engine,'outer-rollback-key');ledgerSuccess($entry);ledgerSuccess($engine->postEntry($entry['entry_id']));
    assertSameValue(true,$db->inTransaction());$db->rollBack();
    assertSameValue($before,(int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn());
    $again=ledgerKeyedCreate($engine,'outer-rollback-key');ledgerSuccess($again);assertSameValue(false,isset($again['replayed']));
});
test('conflicting keyed retry preserves unrelated caller work',function () use ($engine,$db) {
    ledgerSuccess(ledgerKeyedCreate($engine,'savepoint-key'));$db->beginTransaction();
    $db->exec("UPDATE coop_accounts SET account_name='Caller idempotency work' WHERE id=1");
    $result=ledgerKeyedCreate($engine,'savepoint-key','1.00');assertSameValue(false,$result['success']);
    assertSameValue(true,$db->inTransaction());assertSameValue('Caller idempotency work',$db->query('SELECT account_name FROM coop_accounts WHERE id=1')->fetchColumn());$db->rollBack();
});
test('different keys and null keys permit legitimate repeated source documents',function () use ($engine) {
    $one=ledgerKeyedCreate($engine,'legitimate-source-a');$two=ledgerKeyedCreate($engine,'legitimate-source-b');ledgerSuccess($one);ledgerSuccess($two);
    assertSameValue(false,(string)$one['entry_id']===(string)$two['entry_id']);
    $three=ledgerKeyedCreate($engine,null);$four=ledgerKeyedCreate($engine,null);ledgerSuccess($three);ledgerSuccess($four);
    assertSameValue(false,(string)$three['entry_id']===(string)$four['entry_id']);
});
test('invalid request keys are rejected before a journal is written',function () use ($engine,$db) {
    $before=(int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn();
    foreach (['',' spaces ',str_repeat('a',129),['bad'],"bad\nkey"] as $key) {
        assertSameValue(false,ledgerKeyedCreate($engine,$key)['success']);
    }
    assertSameValue($before,(int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn());
});
test('carried later openings block old-period create post reversal but allow existing retries',function () use ($engine,$db) {
    $posted=ledgerKeyedCreate($engine,'before-carry');ledgerSuccess($posted);ledgerSuccess($engine->postEntry($posted['entry_id']));
    $draft=ledgerCreate($engine,'1.00');
    $db->exec('UPDATE coop_period_balances SET is_closed=0');
    $db->exec('UPDATE coop_period_balances SET opening_debit=1,closing_debit=period_debit+1 WHERE periodid=2 AND account_id=1');
    $count=(int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn();
    $result=ledgerKeyedCreate($engine,'after-carry');assertSameValue(false,$result['success']);assertContains('later period opening balances',$result['error']);
    assertSameValue(false,$engine->postEntry($draft['entry_id'])['success']);assertSameValue(false,$engine->reverseEntry($posted['entry_id'],1,'old period')['success']);
    assertSameValue($count,(int)$db->query('SELECT COUNT(*) FROM coop_journal_entries')->fetchColumn());
    ledgerSuccess($engine->postEntry($posted['entry_id']));$again=ledgerKeyedCreate($engine,'before-carry');ledgerSuccess($again);assertSameValue(true,$again['replayed']);
});
finishTests();




