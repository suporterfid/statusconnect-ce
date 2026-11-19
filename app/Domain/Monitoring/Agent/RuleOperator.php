<?php

namespace App\Domain\Monitoring\Agent;

enum RuleOperator: string
{
    case LT = 'lt';
    case LTE = 'lte';
    case GT = 'gt';
    case GTE = 'gte';
    case BETWEEN = 'between';
    case EQUALS = 'equals';
}
