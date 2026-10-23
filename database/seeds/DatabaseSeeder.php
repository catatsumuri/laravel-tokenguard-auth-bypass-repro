<?php

use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        User::firstOrCreate(['email' => 'demo@example.com'], [
            'name' => 'Demo User',
            'password' => Hash::make('password'),
        ]);
    }
}
