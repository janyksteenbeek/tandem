<?php

use App\Enums\Automations\AutomationTrigger;
use App\Enums\Projects\IssueLinkKind;
use App\Enums\Projects\IssuePriority;
use App\Enums\Projects\StatusCategory;
use App\Models\Automations\AutomationRule;
use App\Models\Automations\AutomationRun;
use App\Models\Mail\Conversation;
use App\Models\Mail\Inbox;
use App\Models\Mail\Message;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueConversationLink;
use App\Models\Projects\Product;
use App\Services\Automations\AutomationEngine;
use App\Services\Projects\IssueActions;
use App\Services\Projects\WorkflowActions;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ProjectsTestHelpers;

beforeEach(function () {
    Notification::fake();

    foreach (ProjectsTestHelpers::workspace() as $key => $value) {
        $this->{$key} = $value;
    }

    $this->product = Product::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Frontier', 'key' => 'FRONT']);
    $this->actions = app(IssueActions::class);
    $this->engine = app(AutomationEngine::class);
    $this->workflow = app(WorkflowActions::class)->defaultIssueWorkflow($this->organization);

    $this->rule = fn (array $attributes = []) => AutomationRule::factory()->issue()->create(array_merge([
        'organization_id' => $this->organization->id,
        'created_by_id' => $this->admin->id,
        'conditions' => [],
        'actions' => [['type' => 'set_issue_priority', 'params' => ['priority' => 'high']]],
    ], $attributes));

    $this->issue = fn (array $attributes = [], array $teams = []) => $this->actions->create($this->organization, array_merge([
        'title' => 'Export breaks on large reports',
        'product' => $this->product,
    ], $attributes), $this->admin, $teams);
});

test('an issue rule runs its actions and writes a run and an activity line', function () {
    $rule = ($this->rule)([
        'conditions' => [['field' => 'issue_title', 'operator' => 'contains', 'value' => 'export']],
        'actions' => [
            ['type' => 'set_issue_priority', 'params' => ['priority' => 'urgent']],
            ['type' => 'add_issue_label', 'params' => ['label_name' => 'Reports']],
            ['type' => 'add_team_to_issue', 'params' => ['team_id' => $this->design->id]],
        ],
    ]);

    $issue = ($this->issue)();

    $fresh = $issue->fresh();
    $run = AutomationRun::query()->where('automation_rule_id', $rule->id)->first();

    expect($fresh->priority)->toBe(IssuePriority::Urgent)
        ->and($fresh->labels()->pluck('name')->all())->toBe(['Reports'])
        ->and($fresh->workstreams()->where('team_id', $this->design->id)->exists())->toBeTrue()
        ->and($run->status)->toBe('matched')
        ->and($run->subject_type)->toBe('issue')
        ->and($run->subject_id)->toBe($issue->id)
        ->and(collect($run->details['actions'])->pluck('status')->all())->toBe(['done', 'done', 'done'])
        ->and($issue->activities()->where('type', 'automation_ran')->exists())->toBeTrue();
});

test('issue rules are scoped by product and participating team', function () {
    $otherProduct = Product::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Tandem', 'key' => 'TDM']);

    $forProduct = ($this->rule)(['product_id' => $otherProduct->id]);
    $forTeam = ($this->rule)(['team_id' => $this->design->id]);
    $global = ($this->rule)();

    $issue = ($this->issue)([], [['team' => $this->engineering]]);

    $ids = $this->engine->rulesFor(AutomationTrigger::IssueCreated, $issue)->pluck('id');

    expect($ids->all())->toBe([$global->id])
        ->and($ids)->not->toContain($forProduct->id)
        ->and($ids)->not->toContain($forTeam->id);
});

test('conversation triggers never run issue rules and vice versa', function () {
    $issue = ($this->issue)();
    $rule = ($this->rule)(['trigger' => AutomationTrigger::IssueCreated]);

    $this->engine->run(AutomationTrigger::ConversationReceived, $issue);

    expect(AutomationRun::query()->where('automation_rule_id', $rule->id)->exists())->toBeFalse();
});

test('an automation cannot complete an issue while required work is open', function () {
    $done = $this->workflow->statuses()->where('category', StatusCategory::Completed->value)->first();

    $rule = ($this->rule)(['actions' => [['type' => 'set_issue_status', 'params' => ['status_id' => $done->id]]]]);
    $issue = ($this->issue)([], [['team' => $this->engineering]]);

    $run = AutomationRun::query()->where('automation_rule_id', $rule->id)->first();

    expect($issue->fresh()->status_category)->not->toBe(StatusCategory::Completed)
        ->and($run->details['actions'][0]['reason'])->toBe('workstreams_incomplete');
});

test('actions performed by the engine do not retrigger it', function () {
    $rule = ($this->rule)([
        'trigger' => AutomationTrigger::TeamAdded,
        'actions' => [['type' => 'add_team_to_issue', 'params' => ['team_id' => $this->design->id]]],
    ]);

    $issue = ($this->issue)();
    $this->actions->addTeam($issue, $this->engineering, $this->admin);

    expect(AutomationRun::query()->where('automation_rule_id', $rule->id)->where('status', 'matched')->count())->toBe(1)
        ->and($issue->fresh()->workstreams()->count())->toBe(2);
});

test('a conversation rule can create and link an issue once', function () {
    $inbox = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $conversation = Conversation::factory()->create(['inbox_id' => $inbox->id, 'organization_id' => $this->organization->id, 'subject' => 'Bug: export is empty']);
    Message::factory()->create(['conversation_id' => $conversation->id]);

    $rule = AutomationRule::factory()->create([
        'organization_id' => $this->organization->id,
        'created_by_id' => $this->admin->id,
        'trigger' => AutomationTrigger::ConversationReceived,
        'conditions' => [],
        'actions' => [['type' => 'create_issue', 'params' => ['product_id' => $this->product->id, 'team_ids' => [$this->engineering->id], 'priority' => 'high', 'title_template' => 'Email: {{ conversation.subject }}']]],
    ]);

    $this->engine->run(AutomationTrigger::ConversationReceived, $conversation);
    $this->engine->run(AutomationTrigger::ConversationReceived, $conversation);

    $issue = Issue::query()->where('organization_id', $this->organization->id)->first();
    $runs = AutomationRun::query()->where('automation_rule_id', $rule->id)->orderBy('id')->get();

    expect(Issue::query()->count())->toBe(1)
        ->and($issue->title)->toBe('Email: Bug: export is empty')
        ->and($issue->identifier)->toBe('FRONT-1')
        ->and($issue->priority)->toBe(IssuePriority::High)
        ->and($issue->workstreams()->where('team_id', $this->engineering->id)->exists())->toBeTrue()
        ->and(IssueConversationLink::query()->where('issue_id', $issue->id)->where('conversation_id', $conversation->id)->first()->kind)->toBe(IssueLinkKind::CreatedFrom)
        ->and($runs[1]->details['actions'][0]['reason'])->toBe('already_created');
});

test('a conversation rule links the issue mentioned in the subject', function () {
    $issue = ($this->issue)();
    $inbox = Inbox::factory()->create(['organization_id' => $this->organization->id]);
    $conversation = Conversation::factory()->create(['inbox_id' => $inbox->id, 'organization_id' => $this->organization->id, 'subject' => 'Re: '.$issue->identifier.' still broken']);

    AutomationRule::factory()->create([
        'organization_id' => $this->organization->id,
        'trigger' => AutomationTrigger::ConversationReceived,
        'conditions' => [],
        'actions' => [['type' => 'link_issue', 'params' => ['mode' => 'subject_match']]],
    ]);

    $this->engine->run(AutomationTrigger::ConversationReceived, $conversation);

    expect($issue->conversationLinks()->where('conversation_id', $conversation->id)->exists())->toBeTrue();
});

test('the due-date command fires approaching and overdue triggers once per day', function () {
    $approaching = ($this->rule)(['trigger' => AutomationTrigger::DueDateApproaching, 'conditions' => [['field' => 'issue_days_until_due', 'operator' => 'at_most', 'value' => 3]]]);
    $overdue = ($this->rule)(['trigger' => AutomationTrigger::IssueOverdue]);

    $soon = ($this->issue)(['due_date' => now()->addDays(2)->toDateString()]);
    $late = ($this->issue)(['title' => 'Late one', 'due_date' => now()->subDays(2)->toDateString()]);
    $far = ($this->issue)(['title' => 'Far one', 'due_date' => now()->addDays(10)->toDateString()]);

    $this->artisan('projects:due-dates')->assertSuccessful();
    $this->artisan('projects:due-dates')->assertSuccessful();

    expect(AutomationRun::query()->where('automation_rule_id', $approaching->id)->where('subject_id', $soon->id)->where('status', 'matched')->count())->toBe(1)
        ->and(AutomationRun::query()->where('automation_rule_id', $approaching->id)->where('subject_id', $far->id)->where('status', 'skipped')->count())->toBe(1)
        ->and(AutomationRun::query()->where('automation_rule_id', $overdue->id)->where('subject_id', $late->id)->where('status', 'matched')->count())->toBe(1)
        ->and($soon->fresh()->priority)->toBe(IssuePriority::High)
        ->and($far->fresh()->priority)->toBe(IssuePriority::None);
});

test('saving a rule rejects fields and actions from the other subject', function () {
    $this->actingAs($this->admin)
        ->postJson(route('automations.store', $this->organization), [
            'name' => 'Mixed up',
            'subject_type' => 'issue',
            'trigger' => 'issue_created',
            'match' => 'all',
            'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'x']],
            'actions' => [['type' => 'add_tag', 'params' => ['tag_name' => 'auto']]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['conditions.0.field', 'actions.0.type']);

    $this->actingAs($this->admin)
        ->postJson(route('automations.store', $this->organization), [
            'name' => 'Escalate',
            'subject_type' => 'issue',
            'product_id' => $this->product->id,
            'trigger' => 'issue_created',
            'match' => 'all',
            'conditions' => [['field' => 'issue_priority', 'operator' => 'equals', 'value' => 'urgent']],
            'actions' => [['type' => 'notify_users', 'params' => ['user_ids' => [$this->member->id]]]],
        ])
        ->assertRedirect();

    expect(AutomationRule::query()->where('name', 'Escalate')->first())
        ->subject_type->value->toBe('issue')
        ->product_id->toBe($this->product->id);
});
