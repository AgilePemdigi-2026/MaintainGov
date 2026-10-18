<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ulasan', function (Blueprint $table) {
            $table->id('review_id');                               
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('case_id')->nullable();     
            $table->string('source_review_id', 100);               
            $table->string('nama_reviewer', 100)->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('teks_ulasan');
            $table->string('sumber', 50)->default('csv');
            $table->string('versi_aplikasi', 50)->nullable();
            $table->timestamp('tanggal_ulasan')->nullable();       

            $table->unique(['batch_id', 'source_review_id'], 'ulasan_batch_source_unique'); // AC #3
            $table->index('case_id');

            $table->foreign('batch_id')
                  ->references('batch_id')->on('analysis_batch')
                  ->onDelete('restrict');
            $table->foreign('case_id')
                  ->references('case_id')->on('maintenance_case')
                  ->onDelete('restrict');
        });

        // FIX: Bungkus dengan kondisi SQLite check
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE ulasan ADD CONSTRAINT ulasan_rating_range CHECK (rating BETWEEN 1 AND 5)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ulasan');
    }
};