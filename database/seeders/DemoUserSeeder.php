<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    public function run()
    {
        User::firstOrCreate(['email' => 'demo@example.com'], [
            'name' => 'Demo User',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }
}
