<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengguna_sistem', function (Blueprint $table) {
            $table->id('user_id');
            $table->string('nama', 100);
            $table->string('email', 100)->unique();          
            $table->string('password', 255);               
            $table->enum('role', ['Tim Maintenance', 'Developer']); 
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengguna_sistem');
    }
};