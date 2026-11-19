<?php

namespace App\Infrastructure\Persistence\Eloquent;

final readonly class StoredMetrics
{
    public function __construct(
        public int $stored,
        public int $droppedOverCardinality,
    ) {
    }
}
