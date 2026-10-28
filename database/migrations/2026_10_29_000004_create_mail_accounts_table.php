<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('email_address');
            $table->string('display_name')->nullable();
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->json('sync_state')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_sync_started_at')->nullable();
            $table->timestamp('initial_sync_completed_at')->nullable();
            $table->timestamp('watch_expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('error_count')->default(0);
            $table->string('inbound_token', 64)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['inbox_id', 'email_address']);
            $table->index(['provider', 'status']);
            $table->index('watch_expires_at');
        });

        Schema::create('sender_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('reply_to')->nullable();
            $table->text('signature_html')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['mail_account_id', 'email']);
        });

        Schema::create('mail_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->nullable();
            $table->string('provider_id');
            $table->string('name');
            $table->string('type', 32)->default('custom');
            $table->json('attributes')->nullable();
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('uid_next')->nullable();
            $table->unsignedBigInteger('last_synced_uid')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->boolean('is_selectable')->default(true);
            $table->boolean('sync_enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'provider_id']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 32)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['mail_account_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('mail_folders');
        Schema::dropIfExists('sender_identities');
        Schema::dropIfExists('mail_accounts');
    }
};
