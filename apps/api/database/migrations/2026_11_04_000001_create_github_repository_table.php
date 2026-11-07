<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repository metadata required by SRS UC04/UC05 data mapping.
 *
 * The token is never stored here. Owner/name/full_name are trusted values from
 * server-side configuration; GitHub metadata is synced only after a successful
 * connection check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_repository', function (Blueprint $table) {
            $table->id('repository_id');
            $table->unsignedBigInteger('github_repository_id')->nullable()->unique();
            $table->string('owner', 100);
            $table->string('name', 100);
            $table->string('full_name', 201)->unique();
            $table->string('default_branch', 255)->nullable();
            $table->string('html_url', 500)->nullable();
            $table->boolean('is_private')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_repository');
    }
};
