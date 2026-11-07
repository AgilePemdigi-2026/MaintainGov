<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC05 Pull Request metadata. One backlog may have multiple PRs (BR-18),
 * for example after additional revisions on a new head SHA.
 *
 * submission_state protects the GitHub side-effect:
 * pending -> created | failed | reconciliation_required.
 * lifecycle_state is the real GitHub state once the PR exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pull_request', function (Blueprint $table) {
            $table->id('pull_request_id');
            $table->unsignedBigInteger('backlog_id');
            $table->unsignedBigInteger('developer_id');
            $table->unsignedBigInteger('repository_id')->nullable();
            $table->string('repository_full_name', 201)->nullable();
            $table->string('source_branch', 255);
            $table->string('target_branch', 255);
            $table->string('title', 255);
            $table->text('description');

            $table->unsignedBigInteger('github_pr_id')->nullable()->unique();
            $table->unsignedBigInteger('github_pr_number')->nullable()->unique();
            $table->string('github_pr_url', 500)->nullable();
            $table->char('github_head_sha', 40)->nullable();
            $table->char('github_base_sha', 40)->nullable();

            $table->enum('submission_state', [
                'pending',
                'created',
                'failed',
                'reconciliation_required',
            ])->default('pending');
            $table->enum('lifecycle_state', ['open', 'closed', 'merged'])->nullable();

            // Idempotency: a request fingerprint is immutable; the optional
            // client key is unique per backlog. The head/branch tuple prevents
            // two concurrent submissions from creating the same GitHub PR.
            $table->char('request_fingerprint', 64)->unique();
            $table->string('idempotency_key', 255)->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['backlog_id', 'idempotency_key'],
                'pull_request_backlog_idempotency_unique',
            );
            $table->unique(
                ['backlog_id', 'source_branch', 'target_branch', 'github_head_sha'],
                'pull_request_backlog_head_unique',
            );
            $table->index(['backlog_id', 'submission_state']);
            $table->index(['backlog_id', 'lifecycle_state']);

            $table->foreign('backlog_id')
                ->references('backlog_id')->on('maintenance_backlog')
                ->onDelete('restrict');
            $table->foreign('developer_id')
                ->references('user_id')->on('pengguna_sistem')
                ->onDelete('restrict');
            $table->foreign('repository_id')
                ->references('repository_id')->on('github_repository')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pull_request');
    }
};
