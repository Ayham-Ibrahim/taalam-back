<?php

namespace App\Http\Resources\Verification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VerificationDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'teacherId' => $this->teacher_id,
            'type' => $this->type,
            'fileName' => $this->original_name,
            'status' => $this->status,
            'uploadedAt' => optional($this->created_at)->toDateString(),
            'rejectionReason' => $this->rejection_reason,
        ];
    }
}
