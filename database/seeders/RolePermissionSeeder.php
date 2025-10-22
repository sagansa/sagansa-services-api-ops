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

        $permissions = [
            // User Management Permissions
            'view-users',
            'create-users',
            'update-users',
            'delete-users',

            // Role Management Permissions
            'view-roles',
            'create-roles',
            'update-roles',
            'delete-roles',

            // Permission Management Permissions
            'view-permissions',
            'assign-permissions',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }

        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'api',
        ]);

        $superAdminRole = Role::firstOrCreate([
            'name' => 'super-admin',
            'guard_name' => 'api',
        ]);

        $adminRole->syncPermissions([
            'view-users',
            'create-users',
            'update-users',
            'view-roles',
            'view-permissions',
        ]);

        $superAdminRole->syncPermissions(Permission::pluck('name')->all());
    }
}
