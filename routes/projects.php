<?php

use App\Http\Controllers\Projects\CycleController;
use App\Http\Controllers\Projects\FavoriteController;
use App\Http\Controllers\Projects\InitiativeController;
use App\Http\Controllers\Projects\IssueAttachmentController;
use App\Http\Controllers\Projects\IssueCommentController;
use App\Http\Controllers\Projects\IssueController;
use App\Http\Controllers\Projects\IssueConversationLinkController;
use App\Http\Controllers\Projects\IssueRelationController;
use App\Http\Controllers\Projects\IssueViewController;
use App\Http\Controllers\Projects\IssueWorkstreamController;
use App\Http\Controllers\Projects\ModuleController;
use App\Http\Controllers\Projects\MyIssuesController;
use App\Http\Controllers\Projects\ProductController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectSettingsController;
use App\Http\Controllers\Projects\ProjectsHomeController;
use App\Http\Controllers\Projects\ReleaseController;
use App\Http\Controllers\Projects\ReportController;
use App\Http\Controllers\Projects\TeamController;
use App\Http\Controllers\Projects\WorkflowController;
use App\Http\Middleware\EnsureOrganizationMembership;
use App\Models\Organization;
use App\Models\Projects\Issue;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tandem Projects
|--------------------------------------------------------------------------
|
| Products, modules, projects, teams and issues. Issues are addressed by their stable
| identifier (FRONT-124) within the organization of the URL.
*/

Route::bind('issue', function (string $value, RouteDefinition $route) {
    $organization = $route->parameter('current_organization');
    $organization = $organization instanceof Organization ? $organization : Organization::query()->where('slug', is_string($organization) ? $organization : '')->firstOrFail();

    return Issue::query()->where('organization_id', $organization->id)->where('identifier', strtoupper($value))->firstOrFail();
});

Route::prefix('{current_organization}')
    ->middleware(['auth', 'verified', EnsureOrganizationMembership::class])
    ->name('projects.')
    ->group(function () {
        Route::get('projects', ProjectsHomeController::class)->name('home');
        Route::get('my-issues', [MyIssuesController::class, 'index'])->name('my-issues');

        // --- Issues --------------------------------------------------------------
        Route::get('issues', [IssueController::class, 'index'])->name('issues.index');
        Route::post('issues', [IssueController::class, 'store'])->name('issues.store');
        Route::post('issues/bulk', [IssueController::class, 'bulk'])->name('issues.bulk');
        Route::get('issues/search', [IssueController::class, 'search'])->name('issues.search');
        Route::get('issues/lookups', [IssueController::class, 'lookups'])->name('issues.lookups');
        Route::post('issues/{identifier}/restore', [IssueController::class, 'restore'])->name('issues.restore')->where('identifier', '[A-Za-z0-9]+-[0-9]+');

        Route::prefix('issues/{issue}')->name('issues.')->where(['issue' => '[A-Za-z0-9]+-[0-9]+'])->group(function () {
            Route::get('/', [IssueController::class, 'show'])->name('show');
            Route::patch('/', [IssueController::class, 'update'])->name('update');
            Route::delete('/', [IssueController::class, 'destroy'])->name('destroy');
            Route::post('status', [IssueController::class, 'status'])->name('status');
            Route::post('priority', [IssueController::class, 'priority'])->name('priority');
            Route::post('owner', [IssueController::class, 'owner'])->name('owner');
            Route::post('lead-team', [IssueController::class, 'leadTeam'])->name('lead-team');
            Route::post('parent', [IssueController::class, 'parent'])->name('parent');
            Route::post('milestone', [IssueController::class, 'milestone'])->name('milestone');
            Route::post('labels', [IssueController::class, 'labels'])->name('labels');
            Route::put('modules', [IssueController::class, 'modules'])->name('modules');
            Route::post('move', [IssueController::class, 'move'])->name('move');
            Route::post('follow', [IssueController::class, 'follow'])->name('follow');

            Route::post('teams', [IssueWorkstreamController::class, 'store'])->name('workstreams.store');
            Route::patch('workstreams/{workstream}', [IssueWorkstreamController::class, 'update'])->name('workstreams.update');
            Route::post('workstreams/{workstream}/status', [IssueWorkstreamController::class, 'status'])->name('workstreams.status');
            Route::put('workstreams/{workstream}/assignees', [IssueWorkstreamController::class, 'assignees'])->name('workstreams.assignees');
            Route::post('workstreams/{workstream}/cycle', [IssueWorkstreamController::class, 'cycle'])->name('workstreams.cycle');
            Route::delete('workstreams/{workstream}', [IssueWorkstreamController::class, 'destroy'])->name('workstreams.destroy');
            Route::post('workstreams/{workstream}/dependencies', [IssueRelationController::class, 'storeDependency'])->name('workstreams.dependencies.store');
            Route::delete('workstreams/{workstream}/dependencies/{dependency}', [IssueRelationController::class, 'destroyDependency'])->name('workstreams.dependencies.destroy');

            Route::post('relations', [IssueRelationController::class, 'store'])->name('relations.store');
            Route::delete('relations/{relation}', [IssueRelationController::class, 'destroy'])->name('relations.destroy');

            Route::post('comments', [IssueCommentController::class, 'store'])->name('comments.store');
            Route::get('conversations', [IssueConversationLinkController::class, 'index'])->name('conversations.index');
            Route::post('conversations', [IssueConversationLinkController::class, 'store'])->name('conversations.store');
            Route::delete('conversations/{conversation}', [IssueConversationLinkController::class, 'destroy'])->name('conversations.destroy');
            Route::post('conversations/{conversation}/share-excerpt', [IssueConversationLinkController::class, 'shareExcerpt'])->name('conversations.share-excerpt');
            Route::post('conversations/{conversation}/share-attachment', [IssueConversationLinkController::class, 'shareAttachment'])->name('conversations.share-attachment');
            Route::post('attachments', [IssueAttachmentController::class, 'store'])->name('attachments.store');
        });

        Route::patch('issue-comments/{comment}', [IssueCommentController::class, 'update'])->name('comments.update');
        Route::post('issue-comments/{comment}/react', [IssueCommentController::class, 'react'])->name('comments.react');
        Route::delete('issue-comments/{comment}', [IssueCommentController::class, 'destroy'])->name('comments.destroy');
        Route::delete('issue-attachments/{attachment}', [IssueAttachmentController::class, 'destroy'])->name('attachments.destroy');

        // --- Saved views, favorites ------------------------------------------------
        Route::get('views/{view}', [IssueViewController::class, 'show'])->name('views.show');
        Route::post('views', [IssueViewController::class, 'store'])->name('views.store');
        Route::patch('views/{view}', [IssueViewController::class, 'update'])->name('views.update');
        Route::delete('views/{view}', [IssueViewController::class, 'destroy'])->name('views.destroy');
        Route::post('favorites', [FavoriteController::class, 'toggle'])->name('favorites.toggle');

        // --- Products & modules -------------------------------------------------------
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::get('products/{product}/modules/{module}', [ModuleController::class, 'show'])->name('products.modules.show');
        Route::post('products/{product}/modules', [ModuleController::class, 'store'])->name('products.modules.store');
        Route::post('products/{product}/modules/reorder', [ModuleController::class, 'reorder'])->name('products.modules.reorder');
        Route::patch('products/{product}/modules/{module}', [ModuleController::class, 'update'])->name('products.modules.update');
        Route::delete('products/{product}/modules/{module}', [ModuleController::class, 'destroy'])->name('products.modules.destroy');
        Route::put('products/{product}/members', [ProductController::class, 'members'])->name('products.members');
        Route::post('products/{product}/workflow', [ProductController::class, 'workflow'])->name('products.workflow');
        Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

        // --- Projects -----------------------------------------------------------------
        Route::get('projects/all', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::put('projects/{project}/members', [ProjectController::class, 'members'])->name('projects.members');
        Route::post('projects/{project}/milestones', [ProjectController::class, 'storeMilestone'])->name('projects.milestones.store');
        Route::patch('projects/{project}/milestones/{milestone}', [ProjectController::class, 'updateMilestone'])->name('projects.milestones.update');
        Route::delete('projects/{project}/milestones/{milestone}', [ProjectController::class, 'destroyMilestone'])->name('projects.milestones.destroy');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        Route::get('projects/{project}/{tab?}', [ProjectController::class, 'show'])->name('projects.show')->where('tab', 'overview|issues|board|timeline|milestones|activity|settings');

        // --- Initiatives & releases ----------------------------------------------------
        Route::get('initiatives', [InitiativeController::class, 'index'])->name('initiatives.index');
        Route::post('initiatives', [InitiativeController::class, 'store'])->name('initiatives.store');
        Route::get('initiatives/{initiative}', [InitiativeController::class, 'show'])->name('initiatives.show');
        Route::patch('initiatives/{initiative}', [InitiativeController::class, 'update'])->name('initiatives.update');
        Route::delete('initiatives/{initiative}', [InitiativeController::class, 'destroy'])->name('initiatives.destroy');

        Route::post('products/{product}/releases', [ReleaseController::class, 'store'])->name('releases.store');
        Route::get('products/{product}/releases/{release}', [ReleaseController::class, 'show'])->name('releases.show');
        Route::patch('products/{product}/releases/{release}', [ReleaseController::class, 'update'])->name('releases.update');
        Route::post('products/{product}/releases/{release}/issues', [ReleaseController::class, 'issues'])->name('releases.issues');
        Route::delete('products/{product}/releases/{release}', [ReleaseController::class, 'destroy'])->name('releases.destroy');
        Route::get('products/{product}/{tab?}', [ProductController::class, 'show'])->name('products.show')->where('tab', 'overview|issues|modules|projects|roadmap|releases|activity|settings');

        // --- Teams ----------------------------------------------------------------------
        Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
        Route::post('teams/{team}/cycles', [CycleController::class, 'store'])->name('teams.cycles.store');
        Route::post('teams/{team}/cycles/schedule', [CycleController::class, 'schedule'])->name('teams.cycles.schedule');
        Route::get('teams/{team}/cycles/{cycle}', [CycleController::class, 'show'])->name('teams.cycles.show');
        Route::patch('teams/{team}/cycles/{cycle}', [CycleController::class, 'update'])->name('teams.cycles.update');
        Route::post('teams/{team}/cycles/{cycle}/start', [CycleController::class, 'start'])->name('teams.cycles.start');
        Route::post('teams/{team}/cycles/{cycle}/complete', [CycleController::class, 'complete'])->name('teams.cycles.complete');
        Route::delete('teams/{team}/cycles/{cycle}', [CycleController::class, 'destroy'])->name('teams.cycles.destroy');
        Route::patch('teams/{team}/project-settings', [TeamController::class, 'updateSettings'])->name('teams.settings');
        Route::get('teams/{team}/{tab?}', [TeamController::class, 'show'])->name('teams.show')->where('tab', 'overview|issues|backlog|board|cycles|workload|settings');

        // --- Workflows ----------------------------------------------------------------
        Route::prefix('workflows/{workflow}')->name('workflows.')->group(function () {
            Route::get('/', [WorkflowController::class, 'show'])->name('show');
            Route::patch('/', [WorkflowController::class, 'update'])->name('update');
            Route::post('statuses', [WorkflowController::class, 'storeStatus'])->name('statuses.store');
            Route::post('statuses/reorder', [WorkflowController::class, 'reorder'])->name('statuses.reorder');
            Route::patch('statuses/{status}', [WorkflowController::class, 'updateStatus'])->name('statuses.update');
            Route::post('statuses/{status}/default', [WorkflowController::class, 'setDefault'])->name('statuses.default');
            Route::delete('statuses/{status}', [WorkflowController::class, 'destroyStatus'])->name('statuses.destroy');
            Route::put('transitions', [WorkflowController::class, 'transitions'])->name('transitions');
        });

        // --- Reports --------------------------------------------------------------------
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/data', [ReportController::class, 'data'])->name('reports.data');

        // --- Organization settings ----------------------------------------------------
        Route::get('settings/projects', [ProjectSettingsController::class, 'edit'])->name('settings.edit');
        Route::patch('settings/projects', [ProjectSettingsController::class, 'update'])->name('settings.update');
    });

Route::get('issue-attachments/{attachment}', [IssueAttachmentController::class, 'show'])
    ->middleware(['auth', 'verified'])
    ->name('projects.attachments.show');
