<?php

namespace App\Console\Commands\Calls;

use App\Enums\Calls\CallStatus;
use App\Enums\Calls\ParticipantStatus;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Services\Calls\CallActions;
use App\Services\Calls\CallRecordings;
use App\Services\Calls\Media\MediaServer;
use App\Services\Calls\Media\MediaServerException;
use Illuminate\Console\Command;

/**
 * The safety net behind the webhooks. If a room-closed or participant event never arrived,
 * this notices within a minute: calls whose room is gone are ended, the participant list is
 * corrected, and recordings that stopped reporting are flagged instead of looking healthy.
 */
class SyncCallsCommand extends Command
{
    protected $signature = 'calls:sync';

    protected $description = 'Reconcile live calls with the media server and check recordings that stopped reporting';

    public function handle(MediaServer $media, CallActions $actions, CallRecordings $recordings): int
    {
        $stalled = $recordings->checkStalled();
        $ended = 0;

        if (! $media->isConfigured()) {
            $this->info("Checked {$stalled} quiet recordings. Media server is not configured; calls were not reconciled.");

            return self::SUCCESS;
        }

        try {
            $rooms = collect($media->listRooms())->pluck('name')->flip();
        } catch (MediaServerException $exception) {
            $this->error('Media server unreachable: '.$exception->getMessage());

            return self::FAILURE;
        }

        // A room is only missed once it has been gone for longer than a reconnect could take.
        $grace = now()->subSeconds((int) config('calls.empty_timeout') + 60);

        Call::query()->where('status', CallStatus::Live->value)->with('organization')->each(function (Call $call) use ($rooms, $media, $actions, $grace, &$ended) {
            if (! $rooms->has($call->roomName())) {
                $lastSeen = $call->participants()->max('updated_at');

                if ($lastSeen === null || $grace->gt($lastSeen)) {
                    $actions->end($call, null, 'room_closed');
                    $ended++;
                }

                return;
            }

            try {
                $present = collect($media->listParticipants($call->roomName()))->pluck('identity')->flip();
            } catch (MediaServerException) {
                return;
            }

            $call->participants()->whereIn('status', [ParticipantStatus::Joined->value, ParticipantStatus::Admitted->value, ParticipantStatus::Left->value])->get()
                ->each(function (CallParticipant $participant) use ($present, $actions, $call) {
                    $participant->setRelation('call', $call);
                    $isPresent = $present->has($participant->identity());

                    if ($isPresent && $participant->status !== ParticipantStatus::Joined) {
                        $actions->markConnected($participant);
                    } elseif (! $isPresent && $participant->status === ParticipantStatus::Joined) {
                        $actions->markDisconnected($participant);
                    }
                });
        });

        $this->info("Checked {$stalled} quiet recordings, ended {$ended} calls whose room had closed.");

        return self::SUCCESS;
    }
}
