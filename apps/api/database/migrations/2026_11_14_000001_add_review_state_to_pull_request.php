<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC06 (BR-20/21, FR-UC06-12/13): the application-side review decision is
 * stored on the PR as review_state, separate from GitHub's lifecycle_state.
 *
 * Transitions happen only after a review decision is successfully synced to
 * GitHub (see contracts/uc06-api.md §3). "Ready for Merge" is a display label
 * for review_state=approved, not a new enum value (D-W14-01-b).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pull_request', function (Blueprint $table) {
            $table->enum('review_state', [
                'pending',
                'approved',
                'changes_requested',
            ])->default('pending')->after('lifecycle_state');

            $table->index(['lifecycle_state', 'review_state']);
        });
    }

    public function down(): void
    {
        Schema::table('pull_request', function (Blueprint $table) {
            $table->dropIndex(['lifecycle_state', 'review_state']);
            $table->dropColumn('review_state');
        });
    }
};
