<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_batch', function (Blueprint $table) {
            $table->id('batch_id');
            $table->unsignedBigInteger('user_id');               
            $table->string('nama_file', 255);
            $table->unsignedInteger('jumlah_data');
            $table->timestamp('tanggal_analisis')->nullable();     
            $table->enum('status_analisis', ['Diproses', 'Selesai', 'Gagal']);

            // Delta Sprint 1 (pdm-delta-sprint1.md)
            $table->timestamp('started_at')->nullable();           
            $table->timestamp('completed_at')->nullable();       
            $table->text('error_message')->nullable();             
            $table->unsignedTinyInteger('attempt')->default(1);    
            $table->string('analysis_version', 50)->nullable();

            $table->timestamp('created_at')->nullable();
            $table->index('status_analisis');
            $table->foreign('user_id')
                  ->references('user_id')->on('pengguna_sistem')
                  ->onDelete('restrict');                          
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_batch');
    }
};