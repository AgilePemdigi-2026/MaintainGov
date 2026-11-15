<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pull_request', function (Blueprint $table) {
            $table->string('author_login', 100)->nullable()->after('repository_full_name');
            $table->boolean('is_draft')->default(false)->after('lifecycle_state');
            $table->timestamp('merged_at')->nullable()->after('review_state');
            $table->timestamp('closed_at')->nullable()->after('merged_at');
            $table->timestamp('github_created_at')->nullable()->after('closed_at');
            $table->timestamp('github_updated_at')->nullable()->after('github_created_at');
            $table->timestamp('last_synced_at')->nullable()->after('github_updated_at');

            $table->index('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('pull_request', function (Blueprint $table) {
            $table->dropIndex(['last_synced_at']);
            $table->dropColumn([
                'author_login',
                'is_draft',
                'merged_at',
                'closed_at',
                'github_created_at',
                'github_updated_at',
                'last_synced_at',
            ]);
        });
    }
};
