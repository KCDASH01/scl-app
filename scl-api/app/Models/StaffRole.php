<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffRole extends Model
{
    use SoftDeletes;

    public const ACCESSES = ['employee', 'manager', 'none'];

    public function isSystem(): bool
    {
        return in_array($this->name, ['Employee', 'Manager'], true)
            && in_array($this->access, ['employee', 'manager'], true);
    }

    public function permissionRole(): string
    {
        return $this->access === 'manager' ? 'manager' : 'employee';
    }

    protected $fillable = [
        'name',
        'access',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
