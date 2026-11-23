<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LeadTimeline extends Model
{
    protected $table = 'lead_timeline';

    protected $fillable = [
        'lead_id',
        'user_id',
        'action',
        'description',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (LeadTimeline $entry) {
            if (! Schema::hasColumn('lead_timeline', 'organization_id') || $entry->getAttribute('organization_id')) {
                return;
            }

            $orgId = DB::table('organizations')->orderBy('id')->value('id');
            if ($orgId) {
                $entry->setAttribute('organization_id', $orgId);
            }
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Static helper — log any lead action ───────────────────────────────────

    public static function log(
        Lead   $lead,
        string $action,
        string $description,
        ?int   $userId = null,
        array  $meta   = []
    ): self {
        return self::create([
            'lead_id'         => $lead->id,
            'user_id'         => $userId,
            'action'          => $action,
            'description'     => $description,
            'meta'            => empty($meta) ? null : $meta,
        ]);
    }
}
