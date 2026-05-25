<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class TestUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create roles if they don't exist
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'api')->first();
        if (!$adminRole) {
            $adminRole = Role::create([
                'id' => Str::orderedUuid(),
                'name' => 'admin',
                'guard_name' => 'api',
            ]);
        }
        
        $superAdminRole = Role::where('name', 'super-admin')->where('guard_name', 'api')->first();
        if (!$superAdminRole) {
            $superAdminRole = Role::create([
                'id' => Str::orderedUuid(),
                'name' => 'super-admin',
                'guard_name' => 'api',
            ]);
        }

        // Create a test tenant
        $tenant = Tenant::firstOrCreate([
            'name' => 'Test Tenant',
        ], [
            'name' => 'Test Tenant',
        ]);

        // Create a test user
        $user = User::firstOrCreate([
            'email' => 'admin@example.com',
        ], [
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'tenant_id' => $tenant->id,
        ]);

        // Assign admin role to the user
        if (!$user->hasRole('admin')) {
            $user->assignRole('admin');
        }

        // Link user to tenant
        if (!$tenant->users()->where('user_id', $user->id)->exists()) {
            $tenant->users()->attach($user->id, [
                'role' => 'owner',
                'assigned_by' => $user->id,
            ]);
        }

        echo "Test user created:\n";
        echo "Email: admin@example.com\n";
        echo "Password: password123\n";
        echo "Tenant: {$tenant->name}\n";
    }
}