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
        // Create admin user - pass plain password, Laravel's 'hashed' cast will hash it
        User::create([
            'username' => 'admin',
            'name' => 'Admin User',
            'email' => 'admin@mdukuzi.ai',
            'password' => 'password', // Will be automatically hashed by the 'hashed' cast
            'role' => 'admin',
        ]);

        // Create test user - pass plain password, Laravel's 'hashed' cast will hash it
        User::create([
            'username' => 'testuser',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password', // Will be automatically hashed by the 'hashed' cast
            'role' => 'user',
        ]);
    }
}
