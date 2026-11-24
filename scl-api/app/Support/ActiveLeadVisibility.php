<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;

/** HTTP visibility only. Stored history and console reconciliation remain unfiltered. */
class ActiveLeadVisibility
{
    public const REQUEST_ATTRIBUTE = 'filter_deleted_lead_records';

    public static function apply(Builder $query): void
    {
        if (! app()->bound('request') || ! request()->attributes->get(self::REQUEST_ATTRIBUTE, false)) {
            return;
        }
        $table = $query->getModel()->getTable();
        match ($table) {
            'admissions', 'proposals', 'lead_timeline' => self::lead($query, "$table.lead_id"),
            'deals' => self::optionalLead($query, 'deals.lead_id'),
            'deal_payments' => self::deal($query, 'deal_payments.deal_id'),
            'attendance_records', 'admission_documents' => self::admission($query, "$table.admission_id"),
            'activities' => self::subject($query, 'activities.subject_type', 'activities.subject_id'),
            'notes' => self::subject($query, 'notes.notable_type', 'notes.notable_id'),
            'notifications' => self::notification($query),
            default => null,
        };
    }

    private static function lead(Builder|Query $query, string $column): void
    {
        $query->whereExists(fn (Query $q) => $q->selectRaw('1')->from('leads as visible_leads')
            ->whereColumn('visible_leads.id', $column)->whereNull('visible_leads.deleted_at'));
    }

    private static function optionalLead(Builder|Query $query, string $column): void
    {
        // Standalone records are unrelated to any lead and remain available.
        $query->where(function ($q) use ($column) {
            $q->whereNull($column)->orWhere(function ($q) use ($column) {
                self::lead($q, $column);
            });
        });
    }

    private static function deal(Builder|Query $query, string $column): void
    {
        $query->whereExists(function (Query $q) use ($column) {
            $q->selectRaw('1')->from('deals as visible_deals')->whereColumn('visible_deals.id', $column)->whereNull('visible_deals.deleted_at');
            self::optionalLead($q, 'visible_deals.lead_id');
        });
    }

    private static function admission(Builder|Query $query, string $column): void
    {
        $query->whereExists(function (Query $q) use ($column) {
            $q->selectRaw('1')->from('admissions as visible_admissions')->whereColumn('visible_admissions.id', $column)->whereNull('visible_admissions.deleted_at');
            self::lead($q, 'visible_admissions.lead_id');
        });
    }

    private static function subject(Builder|Query $query, string $typeColumn, string $idColumn): void
    {
        $types = ['lead', 'App\\Models\\Lead', 'deal', 'App\\Models\\Deal', 'admission', 'App\\Models\\Admission'];
        $query->where(function ($q) use ($typeColumn, $idColumn, $types) {
            $q->whereNull($typeColumn)->orWhereNotIn($typeColumn, $types);
            foreach (['lead', 'deal', 'admission'] as $kind) {
                $q->orWhere(function ($q) use ($typeColumn, $idColumn, $kind) {
                    $q->whereIn($typeColumn, [$kind, 'App\\Models\\'.ucfirst($kind)]);
                    self::$kind($q, $idColumn);
                });
            }
        });
    }

    private static function notification(Builder $query): void
    {
        foreach (['lead', 'deal', 'admission', 'activity'] as $kind) {
            $column = 'notifications.data->'.$kind.'_id';
            $query->where(function ($q) use ($kind, $column) {
                $q->whereNull($column)->orWhere(function ($q) use ($kind, $column) {
                    if ($kind !== 'activity') {
                        self::$kind($q, $column);
                    } else {
                        $q->whereExists(function (Query $q) use ($column) {
                            $q->selectRaw('1')->from('activities as visible_activities')
                                ->whereColumn('visible_activities.id', $column)->whereNull('visible_activities.deleted_at');
                            self::subject($q, 'visible_activities.subject_type', 'visible_activities.subject_id');
                        });
                    }
                });
            });
        }
    }
}
