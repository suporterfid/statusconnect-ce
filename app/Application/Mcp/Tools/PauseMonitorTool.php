<?php

namespace App\Application\Mcp\Tools;

final class PauseMonitorTool extends SetMonitorEnabledTool
{
    public function name(): string
    {
        return 'pause_monitor';
    }

    public function description(): string
    {
        return 'Pause a monitor: it stops being checked and opens no incidents until resumed. Pausing a paused monitor changes nothing.';
    }

    protected function enabled(): bool
    {
        return false;
    }
}
