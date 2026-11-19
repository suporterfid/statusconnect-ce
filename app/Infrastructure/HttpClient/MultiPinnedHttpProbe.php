<?php

namespace App\Infrastructure\HttpClient;

interface MultiPinnedHttpProbe
{
    /**
     * @param  list<MultiPinnedHttpRequest>  $requests
     * @return list<MultiPinnedHttpResult>
     */
    public function probe(array $requests): array;

    /**
     * Rolling concurrency (#221): keeps up to $concurrency requests in flight.
     * $next is called whenever a slot is free and returns the next request,
     * or null when there is none for now. $onResult is called as each request
     * finishes. Returns once nothing is in flight and $next has returned null.
     *
     * @param  \Closure(): ?MultiPinnedHttpRequest  $next
     * @param  \Closure(MultiPinnedHttpResult): void  $onResult
     */
    public function stream(\Closure $next, \Closure $onResult, int $concurrency): void;
}
