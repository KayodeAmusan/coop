<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../libs/services/AccountingEngine.php';

function ledgerExpectException(callable $body, string $class = InvalidArgumentException::class) {
    try { $body(); } catch (Throwable $error) {
        if ($error instanceof $class) { return; }
        throw $error;
    }
    throw new RuntimeException('Expected ' . $class);
}

test('money keeps decimal tenths exact', function () {
    assertSameValue(30, moneyAdd(moneyMinorUnits('0.10'), moneyMinorUnits('0.20')));
    assertSameValue('0.30', moneyDecimal(30));
});
test('money preserves signed and maximum amounts', function () {
    assertSameValue(-1, moneyMinorUnits('-0.01'));
    assertSameValue('-0.01', moneyDecimal(-1));
    assertSameValue('9999999999999.99', moneyDecimal(moneyMinorUnits('9999999999999.99')));
    assertSameValue('0.00', moneyDecimal(moneyMinorUnits('-0.00')));
});
test('money rejects malformed and excessive precision', function () {
    foreach (['1.001', '1e2', '', 'NaN', '10000000000000', [], true, INF, NAN] as $value) {
        ledgerExpectException(function () use ($value) { moneyMinorUnits($value); });
    }
});
test('money rejects accumulation overflow', function () {
    ledgerExpectException(function () { moneyAdd(999999999999999, 1); }, OverflowException::class);
    ledgerExpectException(function () { moneyDecimal(PHP_INT_MIN); }, OverflowException::class);
});
$engine = new AccountingEngine(null);
$validate = new ReflectionMethod(AccountingEngine::class, 'validateJournalLines');
$validate->setAccessible(true);
test('journal validates 0.10 plus 0.20 against 0.30', function () use ($engine, $validate) {
    $result = $validate->invoke($engine, [
        ['account_id' => 1, 'debit_amount' => '0.10'], ['account_id' => 1, 'debit_amount' => '0.20'],
        ['account_id' => 2, 'credit_amount' => '0.30']
    ]);
    assertSameValue('0.30', $result['total_amount']);
});
test('journal rejects one kobo differences and invalid sides', function () use ($engine, $validate) {
    foreach ([['1.00', '0', '0', '0.99'], ['-1', '0', '0', '-1'], ['1', '1', '0', '1'], ['0', '0', '0', '1']] as $amounts) {
        ledgerExpectException(function () use ($engine, $validate, $amounts) {
            $validate->invoke($engine, [
                ['account_id' => 1, 'debit_amount' => $amounts[0], 'credit_amount' => $amounts[1]],
                ['account_id' => 2, 'debit_amount' => $amounts[2], 'credit_amount' => $amounts[3]]
            ]);
        });
    }
});
finishTests();
