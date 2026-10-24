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
            // Fixed seed token so `{"api_token":[0]}` reproduces the bypass
            // deterministically without logging in first: sha256('fixed-demo-seed')
            // starts with a hex letter, so MySQL's string-to-number coercion
            // reads it as 0. Logging in overwrites this with a fresh random token.
            'api_token' => hash('sha256', 'fixed-demo-seed'),
        ]);
    }
}
