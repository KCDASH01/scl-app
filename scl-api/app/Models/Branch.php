<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'code', 'address', 'phone', 'email', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function managers(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'manager');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'employee');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function pipelines(): HasMany
    {
        return $this->hasMany(Pipeline::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function hasBusinessRecords(): bool
    {
        return $this->users()->exists()
            || $this->departments()->exists()
            || $this->leads()->exists()
            || $this->deals()->exists()
            || $this->courses()->exists()
            || $this->batches()->exists()
            || $this->hasMany(Activity::class)->exists()
            || $this->hasMany(Proposal::class)->exists()
            || $this->hasMany(SalesTarget::class)->exists()
            || $this->hasMany(DealPayment::class)->exists();
    }
}
