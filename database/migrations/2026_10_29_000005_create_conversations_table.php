<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color', 32)->default('slate');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->unique(['user_id', 'slug']);
        });

        Schema::table('mail_folders', function (Blueprint $table) {
            $table->foreign('tag_id')->references('id')->on('tags')->nullOnDelete();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_inbox_id')->nullable()->constrained('inboxes')->nullOnDelete();
            $table->foreignId('mail_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();
            $table->string('normalized_subject')->nullable();
            $table->string('snippet', 512)->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->boolean('is_starred')->default(false);
            $table->boolean('is_read')->default(false);
            $table->unsignedInteger('unread_count')->default(0);
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->boolean('has_attachments')->default(false);
            $table->boolean('has_draft')->default(false);
            $table->boolean('in_inbox')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_trashed')->default(false);
            $table->boolean('is_spam')->default(false);
            $table->timestamp('snoozed_until')->nullable();
            $table->foreignId('snoozed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('participants')->nullable();
            $table->string('provider_thread_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['mail_account_id', 'provider_thread_id']);
            $table->index(['inbox_id', 'status', 'last_message_at']);
            $table->index(['inbox_id', 'in_inbox', 'last_message_at']);
            $table->index(['inbox_id', 'assignee_id', 'status']);
            $table->index(['inbox_id', 'normalized_subject']);
            $table->index(['status', 'snoozed_until']);
            $table->index('last_message_at');
        });

        DB::statement('ALTER TABLE conversations ADD FULLTEXT conversations_search (subject, snippet)');

        Schema::create('conversation_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 1000)->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'inbox_id']);
        });

        Schema::create('conversation_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('conversation_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_tag');
        Schema::dropIfExists('conversation_followers');
        Schema::dropIfExists('conversation_shares');
        Schema::dropIfExists('conversations');
        Schema::table('mail_folders', fn (Blueprint $table) => $table->dropForeign(['tag_id']));
        Schema::dropIfExists('tags');
    }
};
