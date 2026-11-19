<?php

namespace App\Domain\Monitoring;

enum AssertionType: string
{
    case STATUS_CODE = 'status_code';
    case LATENCY_MS = 'latency_ms';
    case BODY_CONTAINS = 'body_contains';
    case BODY_MATCHES = 'body_matches';
    case HEADER = 'header';
    case JSON_PATH = 'json_path';
    case TLS_EXPIRES_IN_DAYS = 'tls_expires_in_days';

    /**
     * Operators AssertionEvaluator actually honors for this type. Submitting
     * anything outside this set is rejected at creation time rather than
     * being silently ignored by the evaluator (see AssertionEvaluator's
     * per-type match arms, which fall through to `false`/a hardcoded
     * comparison for unlisted operators).
     *
     * @return list<AssertionOperator>
     */
    public function allowedOperators(): array
    {
        return match ($this) {
            self::STATUS_CODE => [AssertionOperator::EQUALS, AssertionOperator::IN, AssertionOperator::BETWEEN, AssertionOperator::LT, AssertionOperator::GTE],
            self::LATENCY_MS => [AssertionOperator::LT, AssertionOperator::LTE],
            self::BODY_CONTAINS => [AssertionOperator::CONTAINS, AssertionOperator::NOT_CONTAINS],
            self::BODY_MATCHES => [AssertionOperator::REGEX],
            self::HEADER => [AssertionOperator::EXISTS, AssertionOperator::EQUALS, AssertionOperator::CONTAINS],
            self::JSON_PATH => [
                AssertionOperator::EXISTS,
                AssertionOperator::EQUALS,
                AssertionOperator::CONTAINS,
                // Numeric thresholds — agent-ingestion addendum §3 (#70).
                AssertionOperator::LT,
                AssertionOperator::LTE,
                AssertionOperator::GTE,
                AssertionOperator::BETWEEN,
            ],
            self::TLS_EXPIRES_IN_DAYS => [AssertionOperator::GTE],
        };
    }
}
