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
        User::create([
            'name' => 'ITSM Admin',
            'email' => 'admin@mfdb.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'admin',
        ]);

        User::create([
            'name' => 'ITSM Engineer',
            'email' => 'eng@mfdb.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'engineer',
        ]);
    }
}
