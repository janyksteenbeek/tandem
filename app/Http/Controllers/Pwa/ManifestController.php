<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use App\Services\Branding;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest, built from config so a rebranded deployment installs
 * under its own name, icon and colours.
 */
class ManifestController extends Controller
{
    public function __invoke(Branding $branding): JsonResponse
    {
        return response()
            ->json($branding->manifest(), 200, [
                'Content-Type' => 'application/manifest+json',
                'Cache-Control' => 'public, max-age=3600',
            ], JSON_UNESCAPED_SLASHES);
    }
}
