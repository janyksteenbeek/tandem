<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One counter per identifier scope (the organization itself, or a product). Locked with
        // SELECT ... FOR UPDATE inside the issue-creating transaction, see IssueIdentifiers.
        Schema::create('issue_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('scope_key', 24);
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('prefix', 10);
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'scope_key']);
        });

        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->string('prefix', 10);
            $table->unsignedInteger('number');
            $table->string('identifier', 24);
            $table->string('title');
            $table->longText('description_html')->nullable();
            $table->text('description_text')->nullable();
            $table->foreignId('status_id')->constrained('workflow_statuses')->restrictOnDelete();
            $table->string('status_category', 16);
            $table->foreignId('suggested_status_id')->nullable()->constrained('workflow_statuses')->nullOnDelete();
            $table->boolean('is_ready_to_complete')->default(false);
            $table->timestamp('status_overridden_at')->nullable();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lead_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('estimate', 8, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->unsignedSmallInteger('workstreams_count')->default(0);
            $table->unsignedSmallInteger('workstreams_required_count')->default(0);
            $table->unsignedSmallInteger('workstreams_completed_count')->default(0);
            $table->unsignedSmallInteger('blocked_workstreams_count')->default(0);
            $table->unsignedSmallInteger('blocked_by_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->unsignedInteger('attachment_count')->default(0);
            $table->unsignedInteger('link_count')->default(0);
            $table->unsignedInteger('sub_issues_count')->default(0);
            $table->unsignedInteger('completed_sub_issues_count')->default(0);
            $table->decimal('sort_order', 20, 10)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'identifier']);
            $table->index(['organization_id', 'status_category', 'updated_at']);
            $table->index(['product_id', 'status_category']);
            $table->index(['project_id', 'status_category']);
            $table->index('milestone_id');
            $table->index(['owner_id', 'status_category']);
            $table->index(['lead_team_id', 'status_category']);
            $table->index('parent_id');
            $table->index(['organization_id', 'due_date']);
            $table->index(['organization_id', 'completed_at']);
            $table->index(['organization_id', 'last_activity_at']);
            $table->index('status_id');
        });

        Schema::create('issue_workstreams', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->text('responsibility')->nullable();
            $table->foreignId('status_id')->constrained('workflow_statuses')->restrictOnDelete();
            $table->string('status_category', 16);
            $table->decimal('estimate', 8, 2)->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('is_required')->default(true);
            $table->foreignId('cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('blocked_by_count')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->decimal('sort_order', 20, 10)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['issue_id', 'team_id']);
            $table->index(['team_id', 'status_category', 'updated_at']);
            $table->index(['team_id', 'cycle_id', 'status_category']);
            $table->index('cycle_id');
            $table->index('status_id');
            $table->index(['team_id', 'due_date']);
        });

        Schema::create('issue_workstream_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstream_id')->constrained('issue_workstreams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workstream_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('issue_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_issue_id')->constrained('issues')->cascadeOnDelete();
            $table->string('type', 16);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['issue_id', 'related_issue_id', 'type']);
            $table->index(['related_issue_id', 'type']);
        });

        Schema::create('workstream_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstream_id')->constrained('issue_workstreams')->cascadeOnDelete();
            $table->foreignId('depends_on_workstream_id')->nullable()->constrained('issue_workstreams')->cascadeOnDelete();
            $table->foreignId('depends_on_issue_id')->nullable()->constrained('issues')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['workstream_id', 'depends_on_workstream_id'], 'workstream_dependencies_ws_unique');
            $table->unique(['workstream_id', 'depends_on_issue_id'], 'workstream_dependencies_issue_unique');
            $table->index('depends_on_workstream_id');
            $table->index('depends_on_issue_id');
        });

        Schema::create('issue_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['issue_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('issue_module', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['issue_id', 'module_id']);
            $table->index('module_id');
        });

        Schema::create('issue_label', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('issue_labels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['issue_id', 'label_id']);
            $table->index('label_id');
        });

        Schema::create('issue_release', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['issue_id', 'release_id']);
            $table->index('release_id');
        });

        Schema::create('issue_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('issue_comments')->nullOnDelete();
            $table->text('body_html');
            $table->text('body_text')->nullable();
            $table->json('mentions')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['issue_id', 'created_at']);
        });

        Schema::create('issue_comment_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('issue_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 32);
            $table->timestamp('created_at')->nullable();

            $table->unique(['comment_id', 'user_id', 'emoji']);
        });

        Schema::create('issue_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained('issue_comments')->nullOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filename');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disk', 32);
            $table->string('path', 1024);
            $table->string('checksum', 64)->nullable();
            $table->foreignId('source_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->foreignId('source_conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->timestamps();

            $table->index('issue_id');
        });

        Schema::create('issue_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workstream_id')->nullable()->constrained('issue_workstreams')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 48);
            $table->json('payload')->nullable();
            $table->timestamp('created_at');

            $table->index(['issue_id', 'created_at']);
            $table->index('workstream_id');
        });

        Schema::create('issue_conversation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->default('linked');
            $table->foreignId('linked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('automation_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['issue_id', 'conversation_id']);
            $table->index('conversation_id');
        });

        // The only table through which email content ever becomes part of an issue: every row is
        // the result of an explicit, authorised "share into issue" action.
        Schema::create('issue_shared_content', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shared_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16);
            $table->longText('body_html')->nullable();
            $table->foreignId('issue_attachment_id')->nullable()->constrained('issue_attachments')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['issue_id', 'created_at']);
        });

        Schema::create('issue_views', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 16)->default('personal');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('icon', 32)->nullable();
            $table->string('color', 32)->nullable();
            $table->json('filters')->nullable();
            $table->json('display')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'scope']);
            $table->index('user_id');
            $table->index('team_id');
        });

        Schema::create('recent_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->morphs('viewable');
            $table->timestamp('viewed_at');

            $table->unique(['user_id', 'viewable_type', 'viewable_id']);
            $table->index(['user_id', 'organization_id', 'viewed_at']);
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->morphs('favoritable');
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('created_at');

            $table->unique(['user_id', 'favoritable_type', 'favoritable_id']);
            $table->index(['user_id', 'organization_id', 'position']);
        });
    }

    public function down(): void
    {
        foreach ([
            'favorites', 'recent_views', 'issue_views', 'issue_shared_content', 'issue_conversation_links',
            'issue_activities', 'issue_attachments', 'issue_comment_reactions', 'issue_comments', 'issue_release',
            'issue_label', 'issue_module', 'issue_followers', 'workstream_dependencies', 'issue_relations',
            'issue_workstream_assignees', 'issue_workstreams', 'issues', 'issue_sequences',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
