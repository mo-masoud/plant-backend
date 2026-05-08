<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['id' => 1],
            [
                'name' => 'Default User',
                'username' => 'default',
                'email' => 'default@example.com',
                'role' => 'user',
                'password' => Hash::make('password'),
            ]
        );

        $this->call(PlantSeeder::class);
    }
}
