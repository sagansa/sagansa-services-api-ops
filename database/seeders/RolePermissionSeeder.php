<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Define simplified permissions
        $permissions = [
            'access-backoffice' => 'Access Backoffice (Admin Web)',
            'access-pos' => 'Access Point of Sale (POS)',
        ];

        // Create all permissions
        foreach ($permissions as $name => $description) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'api',
            ]);
        }

        // Create roles
        $superAdminRole = Role::firstOrCreate([
            'name' => 'super-admin',
            'guard_name' => 'api',
        ]);

        $ownerRole = Role::firstOrCreate([
            'name' => 'owner',
            'guard_name' => 'api',
        ]);

        $userRole = Role::firstOrCreate([
            'name' => 'user',
            'guard_name' => 'api',
        ]);

        // Super Admin: Gets ALL permissions
        $superAdminRole->syncPermissions(Permission::pluck('name')->all());

        // Owner: Gets ALL permissions (same as super-admin for now based on requirement)
        $ownerRole->syncPermissions([
            'access-backoffice',
            'access-pos',
        ]);

        // User: Gets POS access only by default (can be changed)
        $userRole->syncPermissions([
            'access-pos',
        ]);
    }
}
