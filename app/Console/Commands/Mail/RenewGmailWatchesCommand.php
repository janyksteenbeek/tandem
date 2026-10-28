<?php

namespace App\Console\Commands\Mail;

use App\Services\Mail\Providers\Gmail\GmailWatchManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mail:gmail:renew-watches')]
#[Description('Renew Gmail push watches that are missing or about to expire')]
class RenewGmailWatchesCommand extends Command
{
    public function handle(GmailWatchManager $watches): int
    {
        $renewed = $watches->renewExpiring();

        $this->info(sprintf('Renewed %d Gmail watch(es).', $renewed));

        return self::SUCCESS;
    }
}
