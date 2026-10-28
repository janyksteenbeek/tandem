<?php

namespace App\Services\Mail\Providers\Forwarding;

use App\Enums\MailProvider as ProviderType;
use App\Models\MailAccount;
use App\Models\SyncRun;
use App\Services\Mail\Data\ConnectionTestResult;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\Data\SentMessageResult;
use App\Services\Mail\Data\SyncResult;
use App\Services\Mail\Exceptions\UnsupportedOperationException;
use App\Services\Mail\Providers\AbstractMailProvider;

class ForwardingProvider extends AbstractMailProvider
{
    public function type(): ProviderType
    {
        return ProviderType::Forwarding;
    }

    public function testConnection(MailAccount $account): ConnectionTestResult
    {
        throw new UnsupportedOperationException('Not implemented yet.');
    }

    public function initialSync(MailAccount $account, SyncRun $run): SyncResult
    {
        throw new UnsupportedOperationException('Not implemented yet.');
    }

    public function incrementalSync(MailAccount $account, SyncRun $run): SyncResult
    {
        throw new UnsupportedOperationException('Not implemented yet.');
    }

    public function send(MailAccount $account, OutgoingMessage $message): SentMessageResult
    {
        throw new UnsupportedOperationException('Not implemented yet.');
    }
}
