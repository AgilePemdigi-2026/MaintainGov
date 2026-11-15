<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC06 Code Review (SRS data mapping §4.8.4; BR-19..21).
 *
 * Table name follows the SRS entity name `code_review`, not
 * `pull_request_reviews` (D-W14-01-f). Every decision records the reviewer,
 * decision, review time, and the note when required (BR-19).
 *
 * UNIQUE(pull_request_id, reviewer_id) keeps one active review per reviewer:
 * a new review by the same reviewer replaces the previous one (D-W14-01-c).
 * sync_* columns record the GitHub synchronization result; a failed sync is
 * never reported as success (D-W14-01-e).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_review', function (Blueprint $table) {
            $table->id('review_id');
            $table->unsignedBigInteger('pull_request_id');
            $table->unsignedBigInteger('reviewer_id');
            $table->enum('decision', ['Approved', 'Changes Requested']);
            $table->text('comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->unsignedBigInteger('github_review_id')->nullable()->unique();
            $table->enum('sync_status', ['pending', 'synced', 'gagal'])->default('pending');
            $table->timestamp('synced_at')->nullable();
            $table->string('last_sync_error_code', 100)->nullable();
            $table->timestamps();

            $table->unique(
                ['pull_request_id', 'reviewer_id'],
                'code_review_pull_request_reviewer_unique',
            );
            $table->index(['pull_request_id', 'reviewed_at']);

            $table->foreign('pull_request_id')
                ->references('pull_request_id')->on('pull_request')
                ->onDelete('restrict');
            $table->foreign('reviewer_id')
                ->references('user_id')->on('pengguna_sistem')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_review');
    }
};
