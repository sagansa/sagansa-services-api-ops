<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable, HasRoles, HasApiTokens;

    protected $connection = 'mysql_auth';

    protected $with = ['detail'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'uuid',
        'tenant_id',
        'manager_id',
        'verification_token',
        'is_active',
        'invitation_token',
        'invitation_token_expires_at',
        'invited_at',
        'invited_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'last_active_at',
    ];

    /**
     * Get the user's last active time.
     */
    public function getLastActiveAtAttribute()
    {
        return $this->tokens()
            ->orderByDesc('last_used_at')
            ->value('last_used_at');
    }

    /**
     * Relationships
     */
    public function detail()
    {
        return $this->hasOne(UserDetail::class, 'id', 'uuid');
    }

    /**
     * Attendance entries associated with the user.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'user_id', 'uuid');
    }

    /**
     * Orders created by the user.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by', 'uuid');
    }

    /**
     * Tenant the user belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * Tenants this user participates in (as admin or member).
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user', 'user_id', 'tenant_id', 'uuid', 'id')
            ->withPivot(['role', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * Manager (tenant admin) responsible for the user.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id', 'uuid');
    }

    /**
     * User who sent the invitation.
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by', 'uuid');
    }

    /**
     * Users managed by this user.
     */
    public function managedUsers(): HasMany
    {
        return $this->hasManyThrough(self::class, UserDetail::class, 'manager_id', 'uuid', 'uuid', 'id');
    }

    /**
     * Tenant owned by the user.
     */
    public function ownedTenant(): HasOne
    {
        return $this->hasOne(Tenant::class, 'owner_id', 'uuid');
    }

    /**
     * Specify the default guard for this model
     */
    public function guardName()
    {
        return 'api';
    }

    // Accessors for backward compatibility
    public function getTenantIdAttribute()
    {
        return $this->detail?->tenant_id;
    }

    public function getRoleAttribute()
    {
        return $this->detail?->role ?? 'staff';
    }

    public function getIsActiveAttribute()
    {
        return (bool) ($this->detail?->is_active ?? true);
    }

    public function getManagerIdAttribute()
    {
        return $this->detail?->manager_id;
    }

    public function getInvitationTokenAttribute()
    {
        return $this->detail?->invitation_token;
    }

    public function getInvitationTokenExpiresAtAttribute()
    {
        return $this->detail?->invitation_token_expires_at;
    }

    public function getInvitationExpiresAtAttribute()
    {
        return $this->detail?->invitation_token_expires_at;
    }

    public function getInvitedAtAttribute()
    {
        return $this->detail?->invited_at;
    }

    public function getInvitedByAttribute()
    {
        return $this->detail?->invited_by;
    }

    public function getVerificationTokenAttribute()
    {
        return $this->detail?->verification_token;
    }

    // Mutators for backward compatibility
    public function setTenantIdAttribute($value)
    {
        $this->getOrCreateDetail()->tenant_id = $value;
    }

    public function setRoleAttribute($value)
    {
        $this->getOrCreateDetail()->role = $value;
    }

    public function setIsActiveAttribute($value)
    {
        $this->getOrCreateDetail()->is_active = $value;
    }

    public function setManagerIdAttribute($value)
    {
        $this->getOrCreateDetail()->manager_id = $value;
    }

    public function setInvitationTokenAttribute($value)
    {
        $this->getOrCreateDetail()->invitation_token = $value;
    }

    public function setInvitationTokenExpiresAtAttribute($value)
    {
        $this->getOrCreateDetail()->invitation_token_expires_at = $value;
    }

    public function setInvitationExpiresAtAttribute($value)
    {
        $this->getOrCreateDetail()->invitation_token_expires_at = $value;
    }

    public function setInvitedAtAttribute($value)
    {
        $this->getOrCreateDetail()->invited_at = $value;
    }

    public function setInvitedByAttribute($value)
    {
        $this->getOrCreateDetail()->invited_by = $value;
    }

    public function setVerificationTokenAttribute($value)
    {
        $this->getOrCreateDetail()->verification_token = $value;
    }

    protected function getOrCreateDetail()
    {
        if (!$this->relationLoaded('detail')) {
            $this->load('detail');
        }

        $detail = $this->detail;

        if (!$detail) {
            $detail = new UserDetail();
            $detail->id = $this->uuid;
            $this->setRelation('detail', $detail);
        }

        return $detail;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });

        static::saved(function ($user) {
            if ($user->relationLoaded('detail') && $user->detail) {
                $user->detail->id = $user->uuid;
                $user->detail->save();
            }
        });
    }

    /**
     * Set the current tenant context for permission checks.
     */
    public function setTenant(?Tenant $tenant): void
    {
        $this->setPermissionsTeamId($tenant?->id);
    }

    /**
     * Set tenant context by ID.
     */
    public function setTenantById($tenantId): void
    {
        $this->setPermissionsTeamId($tenantId);
    }

    /**
     * Check if user has permission in specific tenant.
     */
    public function hasPermissionInTenant(string $permission, $tenantId): bool
    {
        $this->setPermissionsTeamId($tenantId);
        return $this->hasPermissionTo($permission);
    }

    /**
     * Check if user has role in specific tenant.
     */
    public function hasRoleInTenant(string $roleName, $tenantId): bool
    {
        $this->setPermissionsTeamId($tenantId);
        return $this->hasRole($roleName);
    }

    /**
     * Assign permission to user in specific tenant.
     */
    public function givePermissionInTenant(string $permission, $tenantId): self
    {
        $this->setPermissionsTeamId($tenantId);
        $this->givePermissionTo($permission);
        return $this;
    }

    /**
     * Assign role to user in specific tenant.
     */
    public function assignRoleInTenant(string $roleName, $tenantId): self
    {
        $this->setPermissionsTeamId($tenantId);
        $this->assignRole($roleName);
        return $this;
    }

    /**
     * Remove permission from user in specific tenant.
     */
    public function revokePermissionInTenant(string $permission, $tenantId): self
    {
        $this->setPermissionsTeamId($tenantId);
        $this->revokePermissionTo($permission);
        return $this;
    }

    /**
     * Remove role from user in specific tenant.
     */
    public function removeRoleInTenant(string $roleName, $tenantId): self
    {
        $this->setPermissionsTeamId($tenantId);
        $this->removeRole($roleName);
        return $this;
    }

    /**
     * Get all permissions for user in specific tenant.
     */
    public function getPermissionsInTenant($tenantId)
    {
        $this->setTenantById($tenantId);
        return $this->getAllPermissions();
    }

    /**
     * The current team ID for permissions.
     *
     * @var int|string|null
     */
    protected $permissions_team_id;

    /**
     * Set the team ID for permissions.
     */
    public function setPermissionsTeamId($id)
    {
        $this->permissions_team_id = $id;
    }

    /**
     * Get the team ID for permissions.
     */
    public function getPermissionsTeamId()
    {
        return $this->permissions_team_id;
    }

    /**
     * Get all roles for user in specific tenant.
     */
    public function getRolesInTenant($tenantId)
    {
        $this->setPermissionsTeamId($tenantId);
        return $this->roles;
    }
}
