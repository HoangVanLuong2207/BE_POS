<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StaffSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('users')->insert([
            'name' => 'Staff User',
            'email' => 'staff@staff.com',
            'password' => Hash::make('123456789'),
            'role' => 'staff',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
