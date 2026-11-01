<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Hardening index W12-02 (pdm-delta-sprint2.md §Hardening index).
// Ketiga index sudah ada pada migration pembuat tabel; migration ini
// idempoten: hanya menambah index yang belum ada di database target.
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->indexExists('maintenance_case', 'maintenance_case_status_validasi_index')) {
            Schema::table('maintenance_case', fn (Blueprint $t) => $t->index('status_validasi'));
        }
        if (! $this->indexExists('maintenance_backlog', 'maintenance_backlog_status_index')) {
            Schema::table('maintenance_backlog', fn (Blueprint $t) => $t->index('status'));
        }
        if (! $this->indexExists('ulasan', 'ulasan_case_id_index')) {
            Schema::table('ulasan', fn (Blueprint $t) => $t->index('case_id'));
        }
    }

    public function down(): void
    {
        foreach ([
            ['maintenance_case', 'maintenance_case_status_validasi_index'],
            ['maintenance_backlog', 'maintenance_backlog_status_index'],
            ['ulasan', 'ulasan_case_id_index'],
        ] as [$table, $index]) {
            if ($this->indexExists($table, $index)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
            }
        }
    }

    // Batas driver yang didukung: sqlite, mysql, mariadb.
    // Driver lain (mis. postgres) TIDAK terverifikasi — menambah index tanpa
    // deteksi dobel bisa error; cab postgres hanya opsional bila diperlukan.
    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("pragma index_list('{$table}')");

            return collect($rows)->contains(fn ($row) => strtolower((string) $row->name) === strtolower($index));
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return ! empty(DB::select("show index from `{$table}` where key_name = ?", [$index]));
        }

        return false;
    }
};
