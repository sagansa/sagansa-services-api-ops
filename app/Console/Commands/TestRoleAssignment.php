<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class TestRoleAssignment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:role-assignment';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test role assignment for debugging';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = User::where('email', 'superadmin@example.com')->first();
        if (!$user) {
            $this->error('User not found');
            return;
        }

        $this->info('User ID: ' . $user->id);

        // Check current roles
        $roles = $user->roles;
        $this->info('Current roles: ' . $roles->pluck('name')->implode(', '));

        // Get super-admin role
        $role = Role::where('name', 'super-admin')->first();
        if (!$role) {
            $this->error('Role not found');
            return;
        }

        $this->info('Role ID: ' . $role->id);
        $this->info('Role name: ' . $role->name);

        // Assign role
        try {
            $user->assignRole($role);
            $this->info('Role assigned successfully');
        } catch (\Exception $e) {
            $this->error('Error assigning role: ' . $e->getMessage());
            return;
        }

        // Check roles again
        $user->refresh();
        $roles = $user->roles;
        $this->info('Roles after assignment: ' . $roles->pluck('name')->implode(', '));
    }
}