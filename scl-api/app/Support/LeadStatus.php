<?php

namespace App\Support;

class LeadStatus
{
    public static function label(string $status): string
    {
        return match ($status) {
            'new' => 'New Call',
            'contacted' => 'Contacted',
            'ringing' => 'Call Again',
            'important' => 'Important',
            'meeting' => 'To Visit',
            'visited' => 'Visited',
            'not_interested' => 'Not Interested',
            'lost' => 'Lost',
            'converted' => 'Admitted',
            'followup' => 'Follow-up',
            default => $status,
        };
    }
}
