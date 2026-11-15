<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UC06 review and merge synchronization, additive to the UC05 schema.
 *
 * - pull_request keeps the GitHub PR snapshot. Review/merge fields are
 *   nullable so existing rows remain valid until the next GitHub sync.
 * - code_review stores every review observed from GitHub, including reviews
 *   submitted from MaintainGov. reviewer_id is nullable because a GitHub
 *   reviewer may not have a MaintainGov account; reviewer_login is the
 *   authoritative GitHub identity.
 * - maintenance_backlog gains the SRS status "Ready for Merge". Existing
 *   values are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pull_request', function (Blueprint $table) {
            $table->string('author_login', 100)->nullable()->after('repository_full_name');
            $table->boolean('is_draft')->default(false)->after('lifecycle_state');
            $table
                ->enum('review_state', ['pending', 'approved', 'changes_requested'])
                ->nullable()
                ->after('is_draft');
            $table->timestamp('merged_at')->nullable()->after('review_state');
            $table->timestamp('closed_at')->nullable()->after('merged_at');
            $table->timestamp('github_created_at')->nullable()->after('closed_at');
            $table->timestamp('github_updated_at')->nullable()->after('github_created_at');
            $table->timestamp('last_synced_at')->nullable()->after('github_updated_at');

            $table->index('review_state');
            $table->index('last_synced_at');
        });

        Schema::create('code_review', function (Blueprint $table) {
            $table->id('code_review_id');
            $table->unsignedBigInteger('pull_request_id');
            $table->unsignedBigInteger('backlog_id');
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->string('reviewer_login', 100);
            $table->unsignedBigInteger('github_review_id')->nullable()->unique();
            $table->enum('github_state', [
                'APPROVED',
                'CHANGES_REQUESTED',
                'COMMENTED',
                'DISMISSED',
                'PENDING',
            ]);
            $table->text('body')->nullable();
            $table->char('commit_id', 40)->nullable();
            $table->string('html_url', 500)->nullable();
            $table->enum('source', ['maintaingov', 'github_webhook', 'github_sync'])
                ->default('github_sync');
            $table->char('request_fingerprint', 64)->nullable()->unique();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['pull_request_id', 'reviewer_id']);
            $table->index(['pull_request_id', 'github_state']);
            $table->index(['backlog_id', 'submitted_at']);

            $table->foreign('pull_request_id')
                ->references('pull_request_id')->on('pull_request')
                ->onDelete('cascade');
            $table->foreign('backlog_id')
                ->references('backlog_id')->on('maintenance_backlog')
                ->onDelete('restrict');
            $table->foreign('reviewer_id')
                ->references('user_id')->on('pengguna_sistem')
                ->nullOnDelete();
        });

        Schema::table('maintenance_backlog', function (Blueprint $table) {
            $table->enum('status', [
                'Ready for Development',
                'Sedang Dikerjakan',
                'Ready for Pull Request',
                'Ready for Merge',
                'Selesai',
            ])->default('Ready for Development')->change();
        });
    }

    public function down(): void
    {
        // Revert the enum before removing it so rows using the new value do not
        // make the rollback fail on MySQL.
        DB::table('maintenance_backlog')
            ->where('status', 'Ready for Merge')
            ->update(['status' => 'Ready for Pull Request']);

        Schema::table('maintenance_backlog', function (Blueprint $table) {
            $table->enum('status', [
                'Ready for Development',
                'Sedang Dikerjakan',
                'Ready for Pull Request',
                'Selesai',
            ])->default('Ready for Development')->change();
        });

        Schema::dropIfExists('code_review');

        Schema::table('pull_request', function (Blueprint $table) {
            $table->dropIndex(['review_state']);
            $table->dropIndex(['last_synced_at']);
            $table->dropColumn([
                'author_login',
                'is_draft',
                'review_state',
                'merged_at',
                'closed_at',
                'github_created_at',
                'github_updated_at',
                'last_synced_at',
            ]);
        });
    }
};
