<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional binding between an internal Developer account and a GitHub login.
 * It is used by the push webhook to verify that the actor is the assignee.
 * Accounts without a mapping are still supported for read-only/demo flows,
 * but production deployments should fill this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengguna_sistem', function (Blueprint $table) {
            $table->string('github_login', 100)->nullable()->unique()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('pengguna_sistem', function (Blueprint $table) {
            $table->dropUnique(['github_login']);
            $table->dropColumn('github_login');
        });
    }
};
