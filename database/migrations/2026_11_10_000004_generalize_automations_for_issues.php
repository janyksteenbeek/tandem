<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->string('subject_type', 16)->default('conversation')->after('inbox_id');
            $table->foreignId('product_id')->nullable()->after('subject_type')->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->after('product_id')->constrained()->nullOnDelete();

            $table->index(['organization_id', 'subject_type', 'trigger', 'is_active'], 'automation_rules_subject_trigger_index');
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->nullableMorphs('subject');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'automation_runs_subject_created_index');
        });

        DB::table('automation_runs')->whereNotNull('conversation_id')->update(['subject_type' => 'conversation', 'subject_id' => DB::raw('conversation_id')]);
    }

    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropIndex('automation_runs_subject_created_index');
            $table->dropMorphs('subject');
        });

        Schema::table('automation_rules', function (Blueprint $table) {
            $table->dropIndex('automation_rules_subject_trigger_index');
            $table->dropConstrainedForeignId('team_id');
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('subject_type');
        });
    }
};
