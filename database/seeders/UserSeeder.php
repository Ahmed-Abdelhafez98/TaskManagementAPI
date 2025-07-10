<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create managers
        User::create([
            'name' => 'John Manager',
            'email' => 'manager@taskapp.com',
            'password' => Hash::make('password123'),
            'role' => 'manager',
        ]);

        User::create([
            'name' => 'Sarah Admin',
            'email' => 'admin@taskapp.com',
            'password' => Hash::make('password123'),
            'role' => 'manager',
        ]);

        // Create regular users
        User::create([
            'name' => 'Alice Developer',
            'email' => 'alice@taskapp.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
        ]);

        User::create([
            'name' => 'Bob Developer',
            'email' => 'bob@taskapp.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
        ]);

        User::create([
            'name' => 'Charlie Tester',
            'email' => 'charlie@taskapp.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
        ]);

        User::create([
            'name' => 'Diana Designer',
            'email' => 'diana@taskapp.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
        ]);
    }
}
