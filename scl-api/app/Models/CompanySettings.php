<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySettings extends Model
{
    protected $table = 'company_settings';

    protected $fillable = [
        'name', 'email', 'phone', 'website', 'logo',
        'address', 'city', 'country', 'timezone',
        'settings', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'settings'  => 'array',
            'is_active' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'name'      => 'Company',
            'timezone'  => 'Asia/Kolkata',
            'is_active' => true,
        ]);
    }
}
