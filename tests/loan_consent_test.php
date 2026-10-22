<?php
/**
 * Consent and ownership invariants, without database or network access.
 * Run: php tests/loan_consent_test.php
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/loan_consent_helper.php';

function expectLoanException(callable $body, $class, $code = null)
{
    try {
        $body();
    } catch (Throwable $error) {
        if (!($error instanceof $class)) {
            throw new RuntimeException('Expected ' . $class . ', got ' . get_class($error));
        }
        if ($code !== null) {
            assertSameValue($code, $error->getCode());
        }
        return;
    }
    throw new RuntimeException('Expected ' . $class . ' to be thrown');
}

function consentFixture()
{
    $loan = [
        'id' => 10,
        'requester_coop_id' => '00123',
        'requested_amount' => '150000.50',
        'monthly_repayment' => '12500.04',
        'status' => 'submitted',
    ];
    $guarantors = [];
    foreach (['00456', '00789'] as $index => $id) {
        $guarantors[] = [
            'id' => $index + 1,
            'guarantor_coop_id' => $id,
            'evidence_id' => $index + 101,
            'evidence_loan_request_id' => 10,
            'evidence_guarantor_request_id' => $index + 1,
            'evidence_guarantor_coop_id' => $id,
            'evidence_kind' => 'approval',
            'evidence_terms_hash' => loanConsentTermsHash($loan),
            'evidence_scan_status' => 'clean',
            'evidence_review_status' => 'accepted',
            'evidence_reviewed_by_type' => 'staff',
            'evidence_reviewed_by_id' => '7',
            'evidence_reviewed_at' => '2026-10-21 12:00:00',
            'evidence_content_sha256' => str_repeat('a', 64),
            'evidence_uploaded_by_type' => 'member',
            'evidence_uploaded_by_id' => '00123',
            'status' => 'approved',
            'response_date' => '2026-10-21 12:00:00',
            'requested_amount' => '150000.50',
            'monthly_repayment' => '12500.04',
            'member_status' => 'Active',
            'responded_by_coop_id' => $id,
            'consent_terms_hash' => loanConsentTermsHash($loan),
        ];
    }
    return [$loan, $guarantors];
}

function assertConsentRejected($loan, $guarantors, $amount = '150000.50')
{
    $error = findLoanConsentError($loan, $guarantors, $amount);
    if (!is_string($error) || trim($error) === '') {
        throw new RuntimeException('Expected an understandable consent error');
    }
}

test('converts decimal values exactly to minor units', function () {
    assertSameValue(1, loanAmountMinorUnits('0.01'));
    assertSameValue(10, loanAmountMinorUnits('0.10'));
    assertSameValue(1230, loanAmountMinorUnits('12.30'));
    assertSameValue(1200, loanAmountMinorUnits(12));
    assertSameValue(1230, loanAmountMinorUnits(12.3));
    assertSameValue(0, loanAmountMinorUnits('0.00'));
    assertSameValue(999999999999999, loanAmountMinorUnits('9999999999999.99'));
});

foreach ([-1, '-0.01', '1.001', 1.001, '10000000000000', [], null, true, '', 'NaN', NAN, INF, -INF] as $index => $invalidAmount) {
    test('rejects invalid monetary input ' . $index, function () use ($invalidAmount) {
        expectLoanException(function () use ($invalidAmount) {
            loanAmountMinorUnits($invalidAmount);
        }, InvalidArgumentException::class);
    });
}

test('retains authenticated member identifiers as strings', function () {
    assertSameValue('00123', authenticatedLoanMember(['user_id' => '00123']));
    assertSameValue('123', authenticatedLoanMember(['user_id' => 123]));
});

foreach ([null, [], (object) ['user_id' => '00123'], ['user_id' => null], ['user_id' => ''], ['user_id' => '   '], ['user_id' => []], ['user_id' => (object) []], ['user_id' => true], ['user_id' => 1.5]] as $index => $claims) {
    test('rejects unauthenticated claims ' . $index, function () use ($claims) {
        expectLoanException(function () use ($claims) {
            authenticatedLoanMember($claims);
        }, Exception::class, 401);
    });
}

test('requires the exact authenticated member identifier', function () {
    requireLoanMember('00123', '00123');
    expectLoanException(function () { requireLoanMember('123', '00123'); }, Exception::class, 403);
    expectLoanException(function () { requireLoanMember('00456', '00123'); }, Exception::class, 403);
});

foreach (['submitted', 'fully_guaranteed'] as $status) {
    test('accepts current consent for ' . $status, function () use ($status) {
        [$loan, $guarantors] = consentFixture();
        $loan['status'] = $status;
        assertSameValue(null, findLoanConsentError($loan, $guarantors, '150000.50'));
    });
}

test('compares numerically equivalent decimal terms exactly', function () {
    [$loan, $guarantors] = consentFixture();
    $loan['requested_amount'] = '150000.5';
    $loan['monthly_repayment'] = '12500.4';
    foreach ($guarantors as &$guarantor) {
        $guarantor['monthly_repayment'] = '12500.40';
        $guarantor['evidence_terms_hash'] = loanConsentTermsHash($loan);
    }
    unset($guarantor);
    assertSameValue(null, findLoanConsentError($loan, $guarantors, '150000.50'));
});

foreach ([0, 1, 3] as $count) {
    test('requires exactly two guarantors, rejects count ' . $count, function () use ($count) {
        [$loan, $guarantors] = consentFixture();
        if ($count === 3) {
            $third = $guarantors[0];
            $third['guarantor_coop_id'] = '00999';
            $guarantors[] = $third;
        } else {
            $guarantors = array_slice($guarantors, 0, $count);
        }
        assertConsentRejected($loan, $guarantors);
    });
}

foreach ([
    'rejected' => ['status', 'rejected'],
    'duplicate' => ['guarantor_coop_id', '00789'],
    'self' => ['guarantor_coop_id', '00123'],
    'inactive' => ['member_status', 'inactive'],
    'missing signed proof' => ['evidence_id', null],
    'acknowledgement only' => ['evidence_kind', 'acknowledgement'],
    'quarantined proof' => ['evidence_scan_status', 'quarantined'],
    'infected proof' => ['evidence_scan_status', 'infected'],
    'pending review' => ['evidence_review_status', 'pending'],
    'rejected review' => ['evidence_review_status', 'rejected'],
    'member review' => ['evidence_reviewed_by_type', 'member'],
    'missing reviewer' => ['evidence_reviewed_by_id', null],
    'invalid reviewer' => ['evidence_reviewed_by_id', 'not-staff-id'],
    'missing review date' => ['evidence_reviewed_at', null],
    'missing content hash' => ['evidence_content_sha256', null],
    'missing uploader' => ['evidence_uploaded_by_id', null],
    'other loan proof' => ['evidence_loan_request_id', 11],
    'other invitation proof' => ['evidence_guarantor_request_id', 999],
    'other guarantor proof' => ['evidence_guarantor_coop_id', 'different'],
    'stale signed terms' => ['evidence_terms_hash', str_repeat('0', 64)],
    'stale amount' => ['requested_amount', '150000.49'],
    'stale repayment' => ['monthly_repayment', '12500.03'],
] as $name => $change) {
    test('rejects ' . $name . ' guarantor consent', function () use ($change) {
        [$loan, $guarantors] = consentFixture();
        $guarantors[0][$change[0]] = $change[1];
        assertConsentRejected($loan, $guarantors);
    });
}

foreach (['draft', 'approved', 'rejected', 'disbursed', 'cancelled', 'closed'] as $status) {
    test('rejects loan status ' . $status, function () use ($status) {
        [$loan, $guarantors] = consentFixture();
        $loan['status'] = $status;
        assertConsentRejected($loan, $guarantors);
    });
}

test('requires fresh consent for a partial approval', function () {
    [$loan, $guarantors] = consentFixture();
    assertConsentRejected($loan, $guarantors, '100000.00');
});

test('rejects approval above requested amount', function () {
    [$loan, $guarantors] = consentFixture();
    assertConsentRejected($loan, $guarantors, '150000.51');
});

test('binds authenticated consent to the borrower as well as monetary terms', function () {
    [$loan, $guarantors] = consentFixture();
    $loan['requester_coop_id'] = '00999';
    assertConsentRejected($loan, $guarantors);
});

test('offline reviewed signed approval needs no fabricated direct member response', function () {
    [$loan, $guarantors] = consentFixture();
    foreach ($guarantors as &$guarantor) {
        $guarantor['status'] = 'pending'; $guarantor['response_date'] = null;
        $guarantor['responded_by_coop_id'] = null; $guarantor['consent_terms_hash'] = null;
    }
    unset($guarantor);
    assertSameValue(null, findLoanConsentError($loan, $guarantors, '150000.50'));
});
test('staff upload requires a different staff reviewer; member and staff namespaces remain separate', function () {
    [$loan, $guarantors] = consentFixture();
    $guarantors[0]['evidence_uploaded_by_type'] = 'staff'; $guarantors[0]['evidence_uploaded_by_id'] = '7';
    assertConsentRejected($loan, $guarantors);
    $guarantors[0]['evidence_uploaded_by_id'] = '8';
    assertSameValue(null, findLoanConsentError($loan, $guarantors, '150000.50'));
    $guarantors[0]['evidence_uploaded_by_type'] = 'member'; $guarantors[0]['evidence_uploaded_by_id'] = '7';
    assertSameValue(null, findLoanConsentError($loan, $guarantors, '150000.50'));
});
test('authenticated direct clicks without reviewed signed proof never approve', function () {
    [$loan, $guarantors] = consentFixture();
    foreach ($guarantors as &$guarantor) $guarantor['evidence_id'] = null;
    unset($guarantor); assertConsentRejected($loan, $guarantors);
});
finishTests();
