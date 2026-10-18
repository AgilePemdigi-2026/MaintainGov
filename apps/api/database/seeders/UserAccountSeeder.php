<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserAccountSeeder extends Seeder
{
    public function run(): void
    {
        // Password uji lokal saja (env), BUKAN kredensial pribadi siapa pun.
        $hash = Hash::make(config('app.env') === 'testing'
            ? (env('SEED_TEST_PASSWORD') ?: 'password123')
            : (env('SEED_TEST_PASSWORD') ?: 'password123'));

        $now = now();

        DB::table('pengguna_sistem')->updateOrInsert(
            ['email' => 'maintenance@maintaingov.test'],
            [
                'nama'       => 'Akun Uji Tim Maintenance',
                'password'   => $hash,
                'role'       => 'Tim Maintenance',
                'created_at' => $now,
            ]
        );

        DB::table('pengguna_sistem')->updateOrInsert(
            ['email' => 'developer@maintaingov.test'],
            [
                'nama'       => 'Akun Uji Developer',
                'password'   => $hash,
                'role'       => 'Developer',
                'created_at' => $now,
            ]
        );
    }
}