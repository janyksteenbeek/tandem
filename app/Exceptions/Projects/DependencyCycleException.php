<?php

namespace App\Exceptions\Projects;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DependencyCycleException extends RuntimeException
{
    /**
     * @param  array<int, string>  $path  Human readable nodes forming the cycle.
     */
    public function __construct(public readonly array $path)
    {
        parent::__construct('That dependency would create a cycle: '.implode(' → ', $path));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'dependency_cycle', 'path' => $this->path], 422);
    }
}
