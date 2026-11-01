<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_backlog', function (Blueprint $table) {
            $table->id('backlog_id');
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->string('source_branch', 100)->nullable();
            $table->enum('status', [
                'Ready for Development',
                'Sedang Dikerjakan',
                'Ready for Pull Request',
                'Selesai',
            ])->default('Ready for Development');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // BR-10: satu backlog utama per case valid
            $table->unique('case_id', 'maintenance_backlog_case_unique');
            $table->index('status');

            $table->foreign('case_id')
                  ->references('case_id')->on('maintenance_case')
                  ->onDelete('restrict');

            $table->foreign('assigned_to')
                  ->references('user_id')->on('pengguna_sistem')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_backlog');
    }
};
