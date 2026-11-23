<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\LeadTimeline;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\PhoneNormalizer;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Lead extends Model
{
    use SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'branch_id', 'course_id', 'created_by', 'assigned_to', 'lead_date',
        'pipeline_id', 'stage_id',
        'first_name', 'last_name', 'email', 'phone', 'phone_normalized',
        'status', 'source',
        'city', 'state', 'country', 'pin',
        'custom_fields', 'external_lead_id', 'lost_reason',
    ];

    protected function casts(): array
    {
        return [
            'lead_date'     => 'date',
            'custom_fields' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Lead $lead) {
            if (! Schema::hasColumn('leads', 'organization_id') || $lead->getAttribute('organization_id')) {
                return;
            }

            $orgId = DB::table('organizations')->orderBy('id')->value('id');
            if (! $orgId && Schema::hasTable('organizations')) {
                $orgId = DB::table('organizations')->insertGetId([
                    'name'       => 'Acme Ltd',
                    'slug'       => 'acme-ltd',
                    'email'      => 'ops@acme.test',
                    'timezone'   => 'Asia/Kolkata',
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($orgId) {
                $lead->setAttribute('organization_id', $orgId);
            }
        });
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function createdBy(): BelongsTo    { return $this->belongsTo(User::class, 'created_by'); }
    public function assignedTo(): BelongsTo   { return $this->belongsTo(User::class, 'assigned_to'); }
    public function pipeline(): BelongsTo     { return $this->belongsTo(Pipeline::class); }
    public function stage(): BelongsTo        { return $this->belongsTo(Stage::class); }
    public function course(): BelongsTo       { return $this->belongsTo(Course::class); }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function admission(): HasOne
    {
        return $this->hasOne(Admission::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(LeadTimeline::class)->latest();
    }

    // ── Computed ───────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function defaultDealValue(): ?float
    {
        $price = $this->course?->price ?? $this->course()->value('price');
        if ($price === null || (float) $price <= 0) {
            return null;
        }

        return (float) $price;
    }

    public function setPhoneAttribute(?string $value): void
    {
        $phone = is_string($value) ? trim($value) : $value;
        $phone = $phone === '' ? null : $phone;

        $this->attributes['phone'] = $phone;
        $this->attributes['phone_normalized'] = PhoneNormalizer::normalize($phone);
    }
}
