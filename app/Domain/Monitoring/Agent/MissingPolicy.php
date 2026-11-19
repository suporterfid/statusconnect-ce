<?php

namespace App\Domain\Monitoring\Agent;

/**
 * What a rule does when its metric did not arrive in the latest ingestion.
 */
enum MissingPolicy: string
{
    case IGNORE = 'ignore';
    case FAIL = 'fail';
}
