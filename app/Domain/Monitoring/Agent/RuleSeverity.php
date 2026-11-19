<?php

namespace App\Domain\Monitoring\Agent;

enum RuleSeverity: string
{
    case DOWN = 'down';
    case DEGRADED = 'degraded';
}
