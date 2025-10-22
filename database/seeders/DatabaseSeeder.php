<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $superAdmin = User::updateOrCreate(
            ['email' => 'asapanganbangsa@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('1234567890'),
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

        $tenant->users()->syncWithoutDetaching([
            $superAdmin->id => [
                'role' => 'owner',
                'assigned_by' => $superAdmin->id,
            ],
        ]);

        if (! $superAdmin->hasRole('super-admin')) {
            $superAdmin->assignRole('super-admin');
        }

        $this->call(ProductSeeder::class);
    }
}
