<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::where('name', 'super-admin')->where('guard_name', 'api')->first();
        if (! $role) {
            $role = Role::create([
                'id' => Str::orderedUuid(),
                'name' => 'super-admin',
                'guard_name' => 'api',
            ]);
        }

        $email = env('SUPER_ADMIN_EMAIL', 'asapanganbangsa@gmail.com');
        $name = env('SUPER_ADMIN_NAME', 'Super Admin');
        $password = env('SUPER_ADMIN_PASSWORD', '1234567890');

        $superAdmin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'tenant_id' => null,
                'manager_id' => null,
            ]
        );

        $tenant = Tenant::updateOrCreate(
            ['name' => 'Headquarters'],
            [
                'owner_id' => $superAdmin->id,
            ]
        );

        $superAdmin->tenant_id = $tenant->id;
        $superAdmin->manager_id = null;
        $superAdmin->save();

        if (! $superAdmin->hasRole('super-admin')) {
            $superAdmin->assignRole('super-admin');
        }

        $tenant->users()->syncWithoutDetaching([
            $superAdmin->id => [
                'role' => 'owner',
                'assigned_by' => $superAdmin->id,
            ],
        ]);
    }
}