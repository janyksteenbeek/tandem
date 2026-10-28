<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC')->after('is_personal');
            $table->string('color', 32)->default('violet')->after('timezone');
            $table->string('google_oauth_client_id')->nullable()->after('color');
            $table->text('google_oauth_client_secret')->nullable()->after('google_oauth_client_id');
            $table->string('google_pubsub_topic')->nullable()->after('google_oauth_client_secret');
            $table->json('business_hours')->nullable()->after('google_pubsub_topic');
            $table->text('outbound_mail_settings')->nullable()->after('business_hours');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('email');
            $table->string('timezone', 64)->default('UTC')->after('avatar_path');
            $table->text('signature_html')->nullable()->after('timezone');
            $table->json('preferences')->nullable()->after('signature_html');
            $table->timestamp('last_seen_at')->nullable()->after('preferences');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'timezone', 'color', 'google_oauth_client_id', 'google_oauth_client_secret',
                'google_pubsub_topic', 'business_hours', 'outbound_mail_settings',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'timezone', 'signature_html', 'preferences', 'last_seen_at']);
        });
    }
};
