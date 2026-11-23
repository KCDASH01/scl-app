<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdmissionDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'file_name'   => $this->file_name,
            'doc_type'    => $this->doc_type,
            'doc_label'   => $this->doc_label,
            'mime_type'   => $this->mime_type,
            'size'        => $this->size,
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy ? [
                'id'   => $this->uploadedBy->id,
                'name' => $this->uploadedBy->name,
            ] : null),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
