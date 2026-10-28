<?php

use App\Enums\OrganizationRole;
use App\Http\Controllers\Automations\AutomationRuleController;
use App\Http\Controllers\Automations\MessageTemplateController;
use App\Http\Middleware\EnsureOrganizationMembership;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Automation & template routes
|--------------------------------------------------------------------------
|
| Organization-scoped settings pages for automation rules and message templates.
| Only owners and admins of the organization may manage them.
|
*/

Route::prefix('{current_organization}')
    ->middleware(['auth', 'verified', EnsureOrganizationMembership::class.':'.OrganizationRole::Admin->value])
    ->group(function () {
        Route::get('settings/automations', [AutomationRuleController::class, 'index'])->name('automations.index');
        Route::get('settings/automations/create', [AutomationRuleController::class, 'create'])->name('automations.create');
        Route::post('settings/automations', [AutomationRuleController::class, 'store'])->name('automations.store');
        Route::post('settings/automations/reorder', [AutomationRuleController::class, 'reorder'])->name('automations.reorder');
        Route::get('settings/automations/{rule}/edit', [AutomationRuleController::class, 'edit'])->name('automations.edit');
        Route::put('settings/automations/{rule}', [AutomationRuleController::class, 'update'])->name('automations.update');
        Route::delete('settings/automations/{rule}', [AutomationRuleController::class, 'destroy'])->name('automations.destroy');
        Route::patch('settings/automations/{rule}/toggle', [AutomationRuleController::class, 'toggle'])->name('automations.toggle');
        Route::get('settings/automations/{rule}/runs', [AutomationRuleController::class, 'runs'])->name('automations.runs');
        Route::post('settings/automations/{rule}/test', [AutomationRuleController::class, 'test'])->name('automations.test');

        Route::get('settings/templates', [MessageTemplateController::class, 'index'])->name('templates.index');
        Route::post('settings/templates', [MessageTemplateController::class, 'store'])->name('templates.store');
        Route::post('settings/templates/preview', [MessageTemplateController::class, 'preview'])->name('templates.preview');
        Route::put('settings/templates/{template}', [MessageTemplateController::class, 'update'])->name('templates.update');
        Route::delete('settings/templates/{template}', [MessageTemplateController::class, 'destroy'])->name('templates.destroy');
    });
