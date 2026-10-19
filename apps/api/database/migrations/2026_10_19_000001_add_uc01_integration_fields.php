<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('pengguna_sistem', fn (Blueprint $t) => $t->rememberToken());
        Schema::table('analysis_batch', function (Blueprint $t) {
            $t->string('request_key', 100)->nullable();
            $t->string('source_sha256', 64)->nullable();
            $t->unique(['user_id', 'request_key']);
        });
        Schema::table('ulasan', fn (Blueprint $t) => $t->json('analysis_result')->nullable());
        Schema::table('maintenance_case', function (Blueprint $t) {
            $t->unsignedBigInteger('batch_id')->nullable();
            $t->string('group_key', 100)->nullable();
            $t->unique(['batch_id', 'group_key']);
            $t->foreign('batch_id')->references('batch_id')->on('analysis_batch')->restrictOnDelete();
        });
    }
    public function down(): void {
        Schema::table('maintenance_case', function (Blueprint $t) {
            $t->dropForeign(['batch_id']);
            $t->dropUnique(['batch_id', 'group_key']);
            $t->dropColumn(['batch_id', 'group_key']);
        });
        Schema::table('ulasan', fn (Blueprint $t) => $t->dropColumn('analysis_result'));
        Schema::table('analysis_batch', function (Blueprint $t) {
            $t->dropUnique(['user_id', 'request_key']);
            $t->dropColumn(['request_key', 'source_sha256']);
        });
        Schema::table('pengguna_sistem', fn (Blueprint $t) => $t->dropRememberToken());
    }
};
