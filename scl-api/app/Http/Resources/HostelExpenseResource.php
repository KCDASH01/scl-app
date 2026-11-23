<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HostelExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'branch_id'           => $this->branch_id,
            'hostel_id'           => $this->hostel_id,
            'expense_category_id' => $this->expense_category_id,
            'amount'              => $this->amount !== null ? (float) $this->amount : null,
            'expense_date'        => $this->expense_date?->toDateString(),
            'payment_mode'        => $this->payment_mode,
            'notes'               => $this->notes,
            'hostel'              => $this->whenLoaded('hostel', fn () => $this->hostel ? [
                'id'        => $this->hostel->id,
                'name'      => $this->hostel->name,
                'code'      => $this->hostel->code,
                'is_active' => $this->hostel->is_active,
                'branch_id' => $this->hostel->branch_id,
            ] : null),
            'category'            => $this->whenLoaded('category', fn () => $this->category ? [
                'id'        => $this->category->id,
                'name'      => $this->category->name,
                'is_active' => $this->category->is_active,
                'branch_id' => $this->category->branch_id,
            ] : null),
            'branch'              => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id'        => $this->branch->id,
                'name'      => $this->branch->name,
                'code'      => $this->branch->code,
                'is_active' => $this->branch->is_active,
            ] : null),
            'created_by'          => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id'   => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'created_at'          => $this->created_at?->toIso8601String(),
            'updated_at'          => $this->updated_at?->toIso8601String(),
        ];
    }
}
