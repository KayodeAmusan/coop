<?php
require_once __DIR__ . '/../../includes/money_helper.php';

/** Pure fixed-period calculations. No publication, rate conversion, dates, fees or review authorization. */
class LoanScheduleCalculator
{
    private const SCALE = 40;
    private const MAX_INSTALLMENTS = 1200; // Technical resource bound; not a cooperative duration policy.
    private const METHODS = ['interest_free', 'flat', 'reducing_equal_installments', 'reducing_equal_principal', 'interest_only_balloon', 'manual_schedule'];

    public function calculate(array $input): array
    {
        if (!extension_loaded('bcmath')) throw new RuntimeException('BCMath is required for exact loan calculations');
        foreach (['principal', 'method', 'installment_count', 'rounding_mode', 'residual_treatment', 'fees'] as $key) {
            if (!array_key_exists($key, $input)) throw new InvalidArgumentException('Explicit ' . $key . ' is required');
        }
        $principal = $this->minor($input['principal']);
        if ($principal <= 0) throw new InvalidArgumentException('Principal must be positive');
        $method = $input['method'];
        if (!is_string($method) || !in_array($method, self::METHODS, true)) throw new InvalidArgumentException('Unsupported loan calculation method');
        $count = $input['installment_count'];
        if (!is_int($count) || $count < 1 || $count > self::MAX_INSTALLMENTS) throw new InvalidArgumentException('Installment count must be an integer from 1 to 1200 (technical limit)');
        $rounding = $input['rounding_mode'];
        if (!in_array($rounding, ['half_up', 'half_even', 'down', 'up'], true)) throw new InvalidArgumentException('Unsupported rounding mode');
        if ($input['residual_treatment'] !== 'adjust_final_installment') throw new InvalidArgumentException('Unsupported residual treatment');
        $fees = $input['fees'];
        if (!is_array($fees) || array_keys($fees) !== ['handling'] || $fees['handling'] !== 'none') throw new InvalidArgumentException('Only explicitly fee-free calculations are supported; configured fees need a supported handling method');
        if (isset($input['rate_basis']) && $input['rate_basis'] !== 'per_installment') throw new InvalidArgumentException('Only a per_installment rate basis is supported; annual/monthly/day-count conversion is not implicit');
        $rated = !in_array($method, ['interest_free', 'manual_schedule'], true);
        $rate = null;
        if ($rated) {
            if (($input['rate_basis'] ?? null) !== 'per_installment' || !isset($input['rate_per_installment']) || !is_string($input['rate_per_installment']) ||
                !preg_match('/\A(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,18})?\z/', $input['rate_per_installment'])) {
                throw new InvalidArgumentException('An explicit per_installment fractional decimal rate with at most 18 decimal places is required');
            }
            $rate = $input['rate_per_installment'];
        } elseif (array_key_exists('rate_per_installment', $input) || array_key_exists('rate_basis', $input)) {
            throw new InvalidArgumentException('This method does not consume an interest rate');
        }

        $allowed = ['principal', 'method', 'installment_count', 'rounding_mode', 'residual_treatment', 'fees'];
        if ($rated) $allowed = array_merge($allowed, ['rate_basis', 'rate_per_installment']);
        if ($method === 'manual_schedule') $allowed = array_merge($allowed, ['schedule', 'reviewed_total_interest']);
        if (array_diff(array_keys($input), $allowed)) throw new InvalidArgumentException('Unsupported calculation fields must not be silently ignored');

        $rows = [];
        $remaining = $principal;
        $totalInterest = 0;
        $targetInterest = null;
        $interestPart = null;
        $principalPart = null;
        $payment = null;
        if ($method === 'manual_schedule') {
            if (!isset($input['schedule']) || !is_array($input['schedule']) || !array_is_list($input['schedule']) || count($input['schedule']) !== $count || !array_key_exists('reviewed_total_interest', $input)) {
                throw new InvalidArgumentException('Manual schedule rows and reviewed_total_interest must be explicitly provided and match installment_count');
            }
            $targetInterest = $this->minor($input['reviewed_total_interest']);
        } elseif (in_array($method, ['interest_free', 'flat', 'reducing_equal_principal'], true)) {
            $principalPart = $this->roundedMinor(bcdiv(moneyDecimal($principal), (string) $count, self::SCALE), $rounding);
        }
        if (in_array($method, ['flat', 'interest_only_balloon'], true)) {
            $targetInterest = $this->roundedMinor(bcmul(bcmul(moneyDecimal($principal), $rate, self::SCALE), (string) $count, self::SCALE), $rounding);
            $interestPart = $this->roundedMinor(bcdiv(moneyDecimal($targetInterest), (string) $count, self::SCALE), $rounding);
        }
        if ($method === 'reducing_equal_installments') {
            if (bccomp($rate, '0', self::SCALE) === 0) {
                $rawPayment = bcdiv(moneyDecimal($principal), (string) $count, self::SCALE);
            } else {
                $factor = bcpow(bcadd('1', $rate, self::SCALE), (string) $count, self::SCALE);
                $rawPayment = bcdiv(bcmul(bcmul(moneyDecimal($principal), $rate, self::SCALE), $factor, self::SCALE), bcsub($factor, '1', self::SCALE), self::SCALE);
            }
            $payment = $this->roundedMinor($rawPayment, $rounding);
        }

        for ($number = 1; $number <= $count; $number++) {
            $final = $number === $count;
            if ($method === 'manual_schedule') {
                $row = $input['schedule'][$number - 1];
                if (!is_array($row) || !array_key_exists('principal', $row) || !array_key_exists('interest', $row) || array_diff(array_keys($row), ['principal', 'interest'])) throw new InvalidArgumentException('Each manual row requires explicit principal and interest');
                $part = $this->minor($row['principal']);
                $interest = $this->minor($row['interest']);
            } else {
                if ($method === 'interest_free') $interest = 0;
                elseif ($targetInterest !== null) $interest = $final ? $targetInterest - $totalInterest : $interestPart;
                else $interest = $this->roundedMinor(bcmul(moneyDecimal($remaining), $rate, self::SCALE), $rounding);
                if ($method === 'interest_only_balloon') $part = $final ? $remaining : 0;
                elseif ($final) $part = $remaining;
                elseif ($method === 'reducing_equal_installments') $part = $payment - $interest;
                else $part = $principalPart;
            }
            if ($part < 0 || $interest < 0 || $part > $remaining || ($method === 'reducing_equal_installments' && $part <= 0)) {
                throw new InvalidArgumentException('Configured schedule causes negative amortization or infeasible rounding');
            }
            $repayment = moneyAdd($part, $interest);
            if ($repayment <= 0) throw new InvalidArgumentException('Every installment must have a positive repayment; change the explicit count or rounding policy');
            if ($method !== 'manual_schedule' && !$final && $part === $remaining) throw new InvalidArgumentException('Configured rounding repays principal before the final installment');
            $closing = $remaining - $part;
            $rows[] = ['number' => $number, 'opening_principal' => moneyDecimal($remaining),
                'principal' => moneyDecimal($part), 'interest' => moneyDecimal($interest),
                'repayment' => moneyDecimal($repayment), 'closing_principal' => moneyDecimal($closing)];
            $remaining = $closing;
            $totalInterest = moneyAdd($totalInterest, $interest);
        }
        if ($remaining !== 0 || ($targetInterest !== null && $totalInterest !== $targetInterest)) throw new InvalidArgumentException('Schedule principal and reviewed interest totals must reconcile exactly');
        return ['calculation_version' => 'fixed_period_v1', 'method' => $method,
            'principal' => moneyDecimal($principal), 'installment_count' => $count,
            'rate_basis' => $rated ? 'per_installment' : null, 'rate_per_installment' => $rate,
            'rounding_mode' => $rounding, 'residual_treatment' => $input['residual_treatment'], 'fees' => $fees,
            'total_interest' => moneyDecimal($totalInterest), 'total_repayable' => moneyDecimal(moneyAdd($principal, $totalInterest)),
            'installments' => $rows];
    }

    private function minor($amount): int
    {
        if (!is_string($amount) || str_starts_with($amount, '-')) throw new InvalidArgumentException('Money inputs must be nonnegative decimal strings');
        return moneyMinorUnits($amount);
    }

    private function roundedMinor(string $amount, string $mode): int
    {
        if (bccomp($amount, '0', self::SCALE) < 0) throw new InvalidArgumentException('A nonnegative amount is required');
        $scaled = bcmul($amount, '100', self::SCALE);
        $whole = bcadd($scaled, '0', 0);
        if (bccomp($whole, '999999999999999', 0) > 0) throw new OverflowException('Calculated money exceeds DECIMAL(15,2) range');
        $minor = (int) $whole;
        $fraction = bcsub($scaled, $whole, self::SCALE);
        $comparison = bccomp($fraction, '0.5', self::SCALE);
        $increase = ($mode === 'up' && bccomp($fraction, '0', self::SCALE) > 0) ||
            ($mode === 'half_up' && $comparison >= 0) ||
            ($mode === 'half_even' && ($comparison > 0 || ($comparison === 0 && $minor % 2 !== 0)));
        return $increase ? moneyAdd($minor, 1) : $minor;
    }
}
