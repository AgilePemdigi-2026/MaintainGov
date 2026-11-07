<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent webhook delivery ledger. A unique delivery id is the primary
 * duplicate/replay guard; retaining failed deliveries allows a safe retry of
 * the same GitHub delivery without processing it twice after success.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_webhook_delivery', function (Blueprint $table) {
            $table->id('webhook_delivery_id');
            $table->string('github_delivery_id', 100)->unique();
            $table->string('event', 50);
            $table->string('action', 50)->nullable();
            $table->string('repository_full_name', 201)->nullable();
            $table->string('ref', 255)->nullable();
            $table->char('after_sha', 40)->nullable();
            $table->enum('status', ['received', 'processed', 'ignored', 'failed'])
                ->default('received');
            $table->unsignedBigInteger('backlog_id')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['event', 'status']);
            $table->foreign('backlog_id')
                ->references('backlog_id')->on('maintenance_backlog')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_webhook_delivery');
    }
};
