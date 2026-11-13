<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE issues ADD FULLTEXT issues_search (title, description_text)');
        DB::statement('ALTER TABLE issue_comments ADD FULLTEXT issue_comments_search (body_text)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE issue_comments DROP INDEX issue_comments_search');
        DB::statement('ALTER TABLE issues DROP INDEX issues_search');
    }
};
