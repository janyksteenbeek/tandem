<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use App\Services\Branding;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;

/**
 * Serves the service worker from the site root so it may control every page.
 *
 * The script is kept as plain JavaScript under resources/js/pwa and gets its
 * configuration spliced in here: the cache version follows the Vite build, so
 * every deploy produces a byte-different worker and browsers install the new
 * one on the next visit.
 */
class ServiceWorkerController extends Controller
{
    public function __invoke(Branding $branding): Response
    {
        $source = File::get(resource_path('js/pwa/service-worker.js'));

        $config = [
            'version' => $this->version($source),
            'offlineUrl' => route('pwa.offline', absolute: false),
            'precache' => array_values(array_unique([
                route('pwa.offline', absolute: false),
                route('pwa.manifest', absolute: false),
                $branding->favicon(),
                ...array_values($branding->icons()),
            ])),
        ];

        $script = str_replace(
            '__PWA_CONFIG__',
            json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $source,
        );

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    /**
     * Changes whenever the compiled assets or the worker itself change.
     */
    private function version(string $source): string
    {
        return substr(md5(($this->assetVersion() ?? 'dev').$source), 0, 12);
    }

    private function assetVersion(): ?string
    {
        $manifest = public_path('build/manifest.json');

        if (! File::exists($manifest)) {
            return Inertia::getVersion();
        }

        return md5_file($manifest) ?: null;
    }
}
