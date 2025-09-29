<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define the default roles
        $roles = [
            [
                'name' => 'user',
                'display_name' => 'User',
                'description' => 'Regular user with basic privileges',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'admin',
                'display_name' => 'Administrator',
                'description' => 'Administrator with elevated privileges',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'super_admin',
                'display_name' => 'Super Administrator',
                'description' => 'Super administrator with full system access',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ];
        
        // Insert the roles into the database
        DB::table('roles')->insert($roles);
    }
}
