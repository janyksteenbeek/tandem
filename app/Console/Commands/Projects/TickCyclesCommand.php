<?php

namespace App\Console\Commands\Projects;

use App\Services\Projects\CycleScheduler;
use Illuminate\Console\Command;

class TickCyclesCommand extends Command
{
    protected $signature = 'projects:cycles';

    protected $description = 'Start, complete and schedule team cycles based on their dates';

    public function handle(CycleScheduler $scheduler): int
    {
        $stats = $scheduler->tick();

        $this->info(sprintf('Cycles: %d started, %d completed, %d upcoming scheduled.', $stats['started'], $stats['completed'], $stats['scheduled']));

        return self::SUCCESS;
    }
}
