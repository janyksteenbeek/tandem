<?php

namespace App\Exceptions\Projects;

use App\Services\Projects\Data\CompletionReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when someone tries to complete an issue while required workstreams are still open.
 * Carries the readiness so the UI can explain and offer an override.
 */
class CompletionBlockedException extends RuntimeException
{
    public function __construct(public readonly CompletionReadiness $readiness)
    {
        parent::__construct('Required workstreams are not finished yet.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'workstreams_incomplete',
            'readiness' => $this->readiness->toArray(),
        ], 422);
    }
}
