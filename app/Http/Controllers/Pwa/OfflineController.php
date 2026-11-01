<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use App\Services\Branding;
use Illuminate\Contracts\View\View;

/**
 * The page the service worker shows for a navigation it cannot serve from
 * the network or its cache. Self-contained on purpose: no bundle to fetch.
 */
class OfflineController extends Controller
{
    public function __invoke(Branding $branding): View
    {
        return view('offline', ['branding' => $branding]);
    }
}
