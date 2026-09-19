<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // firstOrCreate: safe to re-run against a database that already has these users
        // (e.g. after a fresh clone) instead of failing on the unique email constraint.
        User::firstOrCreate(
            ['email' => 'admin@mfdb.com'],
            [
                'name' => 'ITSM Admin',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'admin',
            ]
        );

        User::firstOrCreate(
            ['email' => 'eng@mfdb.com'],
            [
                'name' => 'ITSM Engineer',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'engineer',
            ]
        );

        User::firstOrCreate(
            ['email' => 'kyawkyaw@mfdb.com'],
            [
                'name' => 'Kyaw Kyaw',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'engineer',
            ]
        );

        User::firstOrCreate(
            ['email' => 'tuntun@mfdb.com'],
            [
                'name' => 'Tun Tun',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'manager',
            ]
        );
    }
}
