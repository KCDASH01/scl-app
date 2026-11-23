<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password',
        'avatar', 'phone', 'role', 'staff_role_id', 'is_active',
        'email_verified_at', 'branch_id', 'manager_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function staffRole(): BelongsTo
    {
        return $this->belongsTo(StaffRole::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function departments()
    {
        return $this->belongsToMany(Department::class, 'department_user')
                    ->withPivot('position')
                    ->withTimestamps();
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function assignedDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'assigned_to');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'assigned_to');
    }

    public function createdLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'created_by');
    }

    public function createdDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'created_by');
    }

    public const ROLES = ['superadmin', 'admin', 'manager', 'employee'];
    public const ASSIGNABLE_ROLES = ['manager', 'employee'];
    public const MANAGED_ROLES = ['superadmin', 'admin', 'manager'];

    public function isSuperAdmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function isOwner(): bool
    {
        return $this->isSuperAdmin();
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, self::MANAGED_ROLES, true);
    }

    public function isManager(): bool
    {
        return $this->role === 'manager' || $this->role === 'admin';
    }

    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    public function canManageTeam(): bool
    {
        return $this->isSuperAdmin() || $this->isManager();
    }

    public function getFullNameAttribute(): string
    {
        return $this->name;
    }
}
