<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('issue_key', 10)->nullable()->after('color');
            $table->json('project_settings')->nullable()->after('outbound_mail_settings');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->string('icon', 32)->nullable()->after('color');
            $table->json('cycle_settings')->nullable()->after('description');
            $table->string('estimate_scale', 16)->default('fibonacci')->after('cycle_settings');
        });

        // One status set, used either as the overall issue workflow of an organization/product
        // or as the workstream workflow of a team. scope_key is NOT NULL because MySQL treats
        // NULLs in a unique index as distinct.
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope_key', 40);
            $table->string('name', 100);
            $table->boolean('auto_start')->default(true);
            $table->boolean('auto_complete')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'scope_key']);
        });

        Schema::create('workflow_statuses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 32)->default('slate');
            $table->string('icon', 32)->nullable();
            $table->string('category', 16);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['workflow_id', 'position']);
            $table->index(['workflow_id', 'category']);
        });

        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_status_id')->constrained('workflow_statuses')->cascadeOnDelete();
            $table->foreignId('to_status_id')->constrained('workflow_statuses')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['from_status_id', 'to_status_id']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->string('key', 10);
            $table->text('description')->nullable();
            $table->string('icon', 32)->nullable();
            $table->string('color', 32)->default('violet');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->foreignId('issue_workflow_id')->nullable()->constrained('workflows')->nullOnDelete();
            $table->unsignedInteger('issues_count')->default(0);
            $table->unsignedInteger('open_issues_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'key']);
            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        Schema::create('product_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('member');
            $table->timestamps();

            $table->unique(['product_id', 'user_id']);
        });

        Schema::create('product_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'team_id']);
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('modules')->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->string('icon', 32)->nullable();
            $table->string('color', 32)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('issues_count')->default(0);
            $table->unsignedInteger('open_issues_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['product_id', 'slug']);
            $table->index(['product_id', 'parent_id', 'position']);
        });

        Schema::create('module_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['module_id', 'team_id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 140);
            $table->string('slug', 140);
            $table->longText('description_html')->nullable();
            $table->string('icon', 32)->nullable();
            $table->string('color', 32)->default('violet');
            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('planned');
            $table->string('health', 16)->default('unknown');
            $table->unsignedTinyInteger('priority')->default(0);
            $table->string('visibility', 16)->default('organization');
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('issues_count')->default(0);
            $table->unsignedInteger('completed_issues_count')->default(0);
            $table->unsignedInteger('canceled_issues_count')->default(0);
            $table->unsignedInteger('started_issues_count')->default(0);
            $table->json('progress')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
            $table->index(['product_id', 'status']);
            $table->index('lead_id');
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('member');
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('project_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'team_id']);
        });

        Schema::create('module_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['module_id', 'project_id']);
        });

        Schema::create('initiatives', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 140);
            $table->string('slug', 140);
            $table->longText('description_html')->nullable();
            $table->string('color', 32)->default('violet');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('planned');
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('initiative_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('initiative_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['initiative_id', 'project_id']);
        });

        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('initiative_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 140);
            $table->text('description')->nullable();
            $table->date('target_date')->nullable();
            $table->string('status', 16)->default('planned');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('issues_count')->default(0);
            $table->unsignedInteger('completed_issues_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'position']);
            $table->index(['initiative_id', 'position']);
        });

        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 140);
            $table->string('version', 40)->nullable();
            $table->string('status', 16)->default('planned');
            $table->date('planned_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->longText('notes_html')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('issues_count')->default(0);
            $table->unsignedInteger('completed_issues_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'status', 'planned_at']);
        });

        Schema::create('cycles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('name', 100)->nullable();
            $table->text('goals')->nullable();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->string('status', 16)->default('upcoming');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('workstreams_count')->default(0);
            $table->unsignedInteger('completed_workstreams_count')->default(0);
            $table->unsignedInteger('carried_over_count')->default(0);
            $table->json('burndown')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'number']);
            $table->index(['team_id', 'status', 'starts_at']);
        });

        Schema::create('issue_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope_key', 24);
            $table->string('name', 60);
            $table->string('slug', 60);
            $table->string('color', 32)->default('slate');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'scope_key', 'slug']);
        });

        Schema::create('entity_links', function (Blueprint $table) {
            $table->id();
            $table->morphs('linkable');
            $table->string('title', 120);
            $table->string('url', 2048);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_links');
        Schema::dropIfExists('issue_labels');
        Schema::dropIfExists('cycles');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('milestones');
        Schema::dropIfExists('initiative_project');
        Schema::dropIfExists('initiatives');
        Schema::dropIfExists('module_project');
        Schema::dropIfExists('project_team');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('module_team');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('product_team');
        Schema::dropIfExists('product_members');
        Schema::table('workflows', fn (Blueprint $table) => $table->dropForeign(['product_id']));
        Schema::dropIfExists('products');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_statuses');
        Schema::dropIfExists('workflows');
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn(['icon', 'cycle_settings', 'estimate_scale']));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['issue_key', 'project_settings']));
    }
};
