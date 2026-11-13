<?php

namespace App\Console\Commands\Calls;

use App\Services\Calls\CallRecordings;
use Illuminate\Console\Command;

class PruneRecordingsCommand extends Command
{
    protected $signature = 'calls:prune-recordings';

    protected $description = 'Delete call recordings that are past their retention period';

    public function handle(CallRecordings $recordings): int
    {
        $this->info('Deleted '.$recordings->prune().' expired recordings.');

        return self::SUCCESS;
    }
}
