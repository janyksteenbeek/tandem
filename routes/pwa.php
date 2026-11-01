<?php

use App\Http\Controllers\Pwa\ManifestController;
use App\Http\Controllers\Pwa\OfflineController;
use App\Http\Controllers\Pwa\ServiceWorkerController;
use Illuminate\Support\Facades\Route;

Route::get('manifest.webmanifest', ManifestController::class)->name('pwa.manifest');
Route::get('sw.js', ServiceWorkerController::class)->name('pwa.service-worker');
Route::get('offline', OfflineController::class)->name('pwa.offline');
