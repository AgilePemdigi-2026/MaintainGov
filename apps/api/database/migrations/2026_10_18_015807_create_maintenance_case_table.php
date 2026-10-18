<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_case', function (Blueprint $table) {
            $table->id('case_id');
            $table->unsignedBigInteger('user_id')->nullable();    
            $table->string('judul_issue', 200);
            $table->string('kategori', 50);
            $table->string('modul', 100);
            $table->string('fitur', 100);
            $table->string('severity', 20);
            $table->decimal('priority_score', 5, 2);
            $table->text('ringkasan_masalah');
            $table->text('recommendation')->nullable();          
            $table->enum('status_validasi', ['Pending', 'Valid', 'Tidak Valid'])->default('Pending');
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('status_validasi');
            $table->foreign('user_id')
                  ->references('user_id')->on('pengguna_sistem')
                  ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_case');
    }
};