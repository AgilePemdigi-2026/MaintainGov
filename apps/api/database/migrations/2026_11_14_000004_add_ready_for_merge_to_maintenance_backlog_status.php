<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE maintenance_backlog MODIFY COLUMN status ENUM('Ready for Development', 'Sedang Dikerjakan', 'Ready for Pull Request', 'Ready for Merge', 'Selesai') NOT NULL DEFAULT 'Ready for Development'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE maintenance_backlog SET status = 'Ready for Pull Request' WHERE status = 'Ready for Merge'");
            DB::statement("ALTER TABLE maintenance_backlog MODIFY COLUMN status ENUM('Ready for Development', 'Sedang Dikerjakan', 'Ready for Pull Request', 'Selesai') NOT NULL DEFAULT 'Ready for Development'");
        }
    }
};
