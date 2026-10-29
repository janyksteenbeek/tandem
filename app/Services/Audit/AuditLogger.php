<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Records who changed what in organization settings and account connections.
 */
class AuditLogger
{
    public function __construct(protected Request $request) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $action, ?Organization $organization, ?User $actor, ?Model $subject = null, array $payload = []): AuditLog
    {
        return AuditLog::create([
            'organization_id' => $organization?->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $subject ? $subject->getMorphClass() : null,
            'auditable_id' => $subject?->getKey(),
            'payload' => $payload,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 500),
        ]);
    }
}
