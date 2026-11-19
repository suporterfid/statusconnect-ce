<?php

namespace App\Application\Mcp\Tools;

final class ResumeMonitorTool extends SetMonitorEnabledTool
{
    public function name(): string
    {
        return 'resume_monitor';
    }

    public function description(): string
    {
        return 'Resume a paused monitor. Fails when the plan already has as many enabled monitors as it allows.';
    }

    protected function enabled(): bool
    {
        return true;
    }
}
