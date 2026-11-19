<?php

namespace Tests\Unit;

use App\Domain\Monitoring\AssertionOperator;
use App\Domain\Monitoring\AssertionType;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for #37: AssertionEvaluator silently ignores the
 * operator field for body_matches (always treats expected_value as regex)
 * and tls_expires_in_days (always does a hardcoded GTE check), so a client
 * could previously submit a nonsensical type/operator combination that the
 * API accepted but the evaluator quietly reinterpreted. allowedOperators()
 * is the single source of truth both the API validation and this test
 * check against.
 */
class AssertionTypeAllowedOperatorsTest extends TestCase
{
    public function test_body_matches_only_allows_regex(): void
    {
        $this->assertSame([AssertionOperator::REGEX], AssertionType::BODY_MATCHES->allowedOperators());
    }

    public function test_tls_expires_in_days_only_allows_gte(): void
    {
        $this->assertSame([AssertionOperator::GTE], AssertionType::TLS_EXPIRES_IN_DAYS->allowedOperators());
    }

    public function test_status_code_allows_the_operators_the_evaluator_actually_matches(): void
    {
        $allowed = AssertionType::STATUS_CODE->allowedOperators();
        $this->assertContains(AssertionOperator::EQUALS, $allowed);
        $this->assertContains(AssertionOperator::BETWEEN, $allowed);
        $this->assertNotContains(AssertionOperator::CONTAINS, $allowed);
    }

    public function test_json_path_allows_numeric_thresholds(): void
    {
        $allowed = AssertionType::JSON_PATH->allowedOperators();

        foreach ([AssertionOperator::LT, AssertionOperator::LTE, AssertionOperator::GTE, AssertionOperator::BETWEEN] as $operator) {
            $this->assertContains($operator, $allowed);
        }

        $this->assertNotContains(AssertionOperator::IN, $allowed);
        $this->assertNotContains(AssertionOperator::REGEX, $allowed);
    }

    public function test_every_assertion_type_declares_a_non_empty_operator_set(): void
    {
        foreach (AssertionType::cases() as $type) {
            $this->assertNotEmpty($type->allowedOperators(), "{$type->value} has no allowed operators");
        }
    }
}
