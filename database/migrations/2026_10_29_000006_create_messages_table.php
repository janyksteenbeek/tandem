<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('mail_folders')->nullOnDelete();
            $table->string('direction', 16);
            $table->string('message_id_header', 998)->nullable();
            $table->char('message_id_hash', 40)->nullable();
            $table->string('in_reply_to', 998)->nullable();
            $table->json('references')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('provider_thread_id')->nullable();
            $table->unsignedBigInteger('imap_uid')->nullable();
            $table->json('from')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->json('reply_to')->nullable();
            $table->string('subject')->nullable();
            $table->string('snippet', 512)->nullable();
            $table->longText('text_body')->nullable();
            $table->longText('html_body')->nullable();
            $table->json('headers')->nullable();
            $table->json('provider_labels')->nullable();
            $table->json('mailbox_state')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->boolean('has_attachments')->default(false);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('delivery_status', 16)->default('none');
            $table->text('delivery_error')->nullable();
            $table->unsignedInteger('send_attempts')->default(0);
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->string('raw_path')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['mail_account_id', 'provider_message_id']);
            $table->unique(['mail_account_id', 'message_id_hash']);
            $table->index(['conversation_id', 'sent_at']);
            $table->index(['folder_id', 'imap_uid']);
            $table->index('message_id_hash');
        });

        DB::statement('ALTER TABLE messages ADD FULLTEXT messages_search (subject, snippet, text_body)');

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime_type')->default('application/octet-stream');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('content_id')->nullable();
            $table->boolean('is_inline')->default(false);
            $table->string('disk', 32)->default('local');
            $table->string('path')->nullable();
            $table->string('provider_attachment_id', 512)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestamps();

            $table->index(['message_id', 'content_id']);
        });

        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sender_identity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('in_reply_to_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('kind', 16)->default('new');
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('subject')->nullable();
            $table->longText('html_body')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->foreignId('locked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->string('provider_draft_id')->nullable();
            $table->timestamp('last_saved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversation_id', 'user_id']);
            $table->index(['inbox_id', 'user_id']);
        });

        Schema::create('draft_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('draft_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime_type')->default('application/octet-stream');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('content_id')->nullable();
            $table->boolean('is_inline')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_attachments');
        Schema::dropIfExists('drafts');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('messages');
    }
};
