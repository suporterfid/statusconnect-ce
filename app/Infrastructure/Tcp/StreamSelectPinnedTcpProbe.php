<?php

namespace App\Infrastructure\Tcp;

final class StreamSelectPinnedTcpProbe implements PinnedTcpProbe
{
    /**
     * @param  list<PinnedTcpRequest>  $requests
     * @return list<PinnedTcpResult>
     */
    public function probe(array $requests): array
    {
        return $this->finish($this->start($requests));
    }

    /** @param list<PinnedTcpRequest> $requests */
    public function start(array $requests): PendingTcpBatch
    {
        $batch = new PendingTcpBatch();

        foreach ($requests as $request) {
            $startedAt = hrtime(true);
            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', str_contains($request->endpoint->pinnedIp, ':') ? "[{$request->endpoint->pinnedIp}]" : $request->endpoint->pinnedIp, $request->endpoint->port),
                $errorCode,
                $errorMessage,
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );

            if ($socket === false) {
                $batch->results[] = new PinnedTcpResult($request->monitorId, false, 0, $errorMessage ?: "tcp_connect_error_{$errorCode}");
                continue;
            }

            stream_set_blocking($socket, false);
            $batch->pending[(int) $socket] = [
                'socket' => $socket,
                'request' => $request,
                'startedAt' => $startedAt,
                'deadline' => $startedAt + ($request->timeoutMs * 1_000_000),
            ];
        }

        return $batch;
    }

    /**
     * Waits for the sockets opened by start().
     *
     * A socket whose connect already completed counts as connected even when
     * finish() is called after its deadline: the caller may have been busy with
     * other work in between, and that delay is not the target's. Only a socket
     * still connecting when its deadline passes is a timeout. Latency is taken
     * when the connect is first seen complete, so it is exact when finish() runs
     * right after start() and an upper bound otherwise.
     */
    public function finish(PendingTcpBatch $batch, ?int $deadline = null): array
    {
        // Settle every connect that completed while the caller was away, before
        // looking at any deadline.
        $this->settleReady($batch, 0);

        while ($batch->pending !== []) {
            $now = hrtime(true);
            $nearestDeadline = min(array_column($batch->pending, 'deadline'));
            if ($deadline !== null) $nearestDeadline = min($nearestDeadline, $deadline);
            $this->settleReady($batch, max(0, $nearestDeadline - $now));

            $now = hrtime(true);
            foreach ($batch->pending as $key => $item) {
                if ($item['deadline'] > $now && ($deadline === null || $deadline > $now)) {
                    continue;
                }
                fclose($item['socket']);
                unset($batch->pending[$key]);
                $batch->results[] = new PinnedTcpResult(
                    $item['request']->monitorId,
                    false,
                    (int) round(($now - $item['startedAt']) / 1_000_000),
                    'tcp_timeout',
                );
            }
        }

        return $batch->results;
    }

    /**
     * Waits up to $timeoutNs for pending sockets to become writable and records
     * each one that did: connected, or refused/unreachable.
     */
    private function settleReady(PendingTcpBatch $batch, int $timeoutNs): void
    {
        if ($batch->pending === []) {
            return;
        }
        $write = array_column($batch->pending, 'socket');
        $read = [];
        $except = [];
        $selected = @stream_select($read, $write, $except, intdiv($timeoutNs, 1_000_000_000), intdiv($timeoutNs % 1_000_000_000, 1_000));
        if ($selected === false || $selected === 0) {
            return;
        }

        $now = hrtime(true);
        foreach ($write as $socket) {
            $key = (int) $socket;
            if (! isset($batch->pending[$key])) {
                continue;
            }
            $item = $batch->pending[$key];
            $connected = stream_socket_get_name($socket, true) !== false;
            fclose($socket);
            unset($batch->pending[$key]);
            $batch->results[] = new PinnedTcpResult(
                $item['request']->monitorId,
                $connected,
                (int) round(($now - $item['startedAt']) / 1_000_000),
                $connected ? null : 'tcp_connect_error',
            );
        }
    }
}
