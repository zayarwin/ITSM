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
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'ITSM Admin',
            'email' => 'admin@mftb.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
        
        User::factory()->create([
            'name' => 'ITSM Engineer',
            'email' => 'eng@mftb.com',
            'password' => bcrypt('password'),
            'role' => 'engineer',
        ]);
    }
}
