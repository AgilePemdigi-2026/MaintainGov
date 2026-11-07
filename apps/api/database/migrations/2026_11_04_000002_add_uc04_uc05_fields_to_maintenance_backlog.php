<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive delta for UC04/UC05. Existing columns created by W12-01
 * (assigned_to, assigned_at, source_branch) are preserved.
 *
 * - repository_id links a backlog to the trusted GitHub repository.
 * - last_push_sha / last_push_at are written only after GitHub verification.
 * - source_branch is widened to 255 to match the API contract and GitHub's
 *   branch-name limit. No existing value is truncated or rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_backlog', function (Blueprint $table) {
            $table->unsignedBigInteger('repository_id')->nullable()->after('case_id');
            $table->char('last_push_sha', 40)->nullable()->after('source_branch');
            $table->timestamp('last_push_at')->nullable()->after('last_push_sha');

            $table->index('repository_id');
            $table->index('assigned_to');
            $table->index('source_branch');
        });

        Schema::table('maintenance_backlog', function (Blueprint $table) {
            $table->string('source_branch', 255)->nullable()->change();
            $table->foreign('repository_id')
                ->references('repository_id')
                ->on('github_repository')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_backlog', function (Blueprint $table) {
            $table->dropForeign(['repository_id']);
            $table->dropIndex(['repository_id']);
            $table->dropIndex(['assigned_to']);
            $table->dropIndex(['source_branch']);
            $table->dropColumn(['repository_id', 'last_push_sha', 'last_push_at']);
            $table->string('source_branch', 100)->nullable()->change();
        });
    }
};
