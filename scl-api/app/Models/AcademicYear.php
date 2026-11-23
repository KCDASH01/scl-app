<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public static function isValidName(string $name): bool
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $name)) {
            return false;
        }

        [$start, $end] = explode('-', $name);
        $expected = str_pad((string) (((int) $start + 1) % 100), 2, '0', STR_PAD_LEFT);

        return $end === $expected;
    }
}
