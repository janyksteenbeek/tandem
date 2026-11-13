<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('call_settings')->nullable()->after('project_settings');
        });

        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('host_id')->nullable()->constrained('users')->nullOnDelete();
            // The shareable meeting code (abc-defg-hij); also the LiveKit room name suffix.
            $table->string('code', 16)->unique();
            $table->string('title')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('password')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedSmallInteger('peak_participants')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('transcript_emailed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['organization_id', 'ended_at']);
            $table->index('host_id');
        });

        // Everyone who was invited to, waited for, or sat in a call. Guests have no user_id and
        // are recognised by the participant id kept in their session.
        Schema::create('call_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->string('email')->nullable();
            $table->string('role', 16)->default('guest');
            $table->string('status', 16)->default('waiting');
            $table->string('invite_token', 64)->nullable()->unique();
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('admitted_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('recording_consent_at')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamps();

            $table->unique(['call_id', 'user_id']);
            $table->index(['call_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index(['call_id', 'email']);
        });

        Schema::create('call_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('call_participants')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('call_messages')->nullOnDelete();
            // Sent by the browser so a retried optimistic message is stored once.
            $table->string('client_id', 40)->nullable();
            $table->text('body')->nullable();
            $table->string('type', 16)->default('text');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['call_id', 'id']);
            $table->unique(['call_id', 'participant_id', 'client_id']);
        });

        Schema::create('call_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('call_messages')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('call_participants')->cascadeOnDelete();
            $table->string('emoji', 32);
            $table->timestamps();

            $table->unique(['message_id', 'participant_id', 'emoji']);
        });

        Schema::create('call_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('call_messages')->nullOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('call_participants')->nullOnDelete();
            $table->string('filename');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disk', 32);
            $table->string('path', 500);
            $table->string('checksum', 64)->nullable();
            $table->timestamps();

            $table->index(['call_id', 'created_at']);
            $table->index('message_id');
        });

        Schema::create('call_recordings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('started_by_id')->nullable()->constrained('call_participants')->nullOnDelete();
            $table->foreignId('stopped_by_id')->nullable()->constrained('call_participants')->nullOnDelete();
            $table->string('mode', 16)->default('full');
            $table->string('status', 16)->default('starting');
            $table->string('disk', 32)->default('local');
            // Directory relative to the recordings root, shared with the recorder sidecar.
            $table->string('path', 255);
            $table->json('manifest')->nullable();
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('failure_reason', 500)->nullable();
            $table->string('last_warning', 500)->nullable();
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('share_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['call_id', 'status']);
            $table->index(['status', 'heartbeat_at']);
            $table->index('expires_at');
        });

        // Files derived from a recording on request: a mixed audio track, a screen recording
        // with sound, or a combined video. Produced asynchronously, never during the call.
        Schema::create('call_recording_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('recording_id')->constrained('call_recordings')->cascadeOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16)->default('queued');
            $table->string('disk', 32)->default('local');
            $table->string('path', 500)->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('error', 1000)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['recording_id', 'kind']);
        });

        Schema::create('call_transcript_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->boolean('is_automatic')->default(false);
            $table->boolean('include_files')->default(true);
            $table->boolean('include_recording')->default(true);
            $table->string('status', 16)->default('queued');
            $table->string('error', 1000)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['call_id', 'created_at']);
        });

        Schema::create('call_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->string('linkable_type', 40);
            $table->unsignedBigInteger('linkable_id');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['call_id', 'linkable_type', 'linkable_id']);
            $table->index(['linkable_type', 'linkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_links');
        Schema::dropIfExists('call_transcript_deliveries');
        Schema::dropIfExists('call_recording_exports');
        Schema::dropIfExists('call_recordings');
        Schema::dropIfExists('call_files');
        Schema::dropIfExists('call_message_reactions');
        Schema::dropIfExists('call_messages');
        Schema::dropIfExists('call_participants');
        Schema::dropIfExists('calls');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('call_settings');
        });
    }
};
