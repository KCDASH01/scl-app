<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranch;

class Department extends Model
{
    use SoftDeletes, BelongsToBranch;

    protected $fillable = ['name', 'description', 'branch_id'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user')
                    ->withPivot('position')
                    ->withTimestamps();
    }
}
