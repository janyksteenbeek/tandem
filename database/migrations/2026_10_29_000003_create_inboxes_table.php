<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inboxes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('shared');
            $table->string('name');
            $table->string('slug');
            $table->string('color', 32)->default('violet');
            $table->string('icon', 32)->nullable();
            $table->string('description')->nullable();
            $table->string('email_address')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('inbox_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('agent');
            $table->timestamps();

            $table->unique(['inbox_id', 'team_id']);
        });

        Schema::create('inbox_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('agent');
            $table->timestamps();

            $table->unique(['inbox_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_users');
        Schema::dropIfExists('inbox_teams');
        Schema::dropIfExists('inboxes');
    }
};
