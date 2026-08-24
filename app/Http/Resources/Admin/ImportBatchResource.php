<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'file_name' => $this->file_name,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'imported_count' => $this->imported_count,
            'failed_count' => $this->failed_count,
            // نسبة تقدّم مبسّطة لعرض شريط تقدّم مباشرة بلا حساب إضافي في الواجهة.
            'progress_percent' => $this->total_rows
                ? (int) round(($this->processed_rows / $this->total_rows) * 100)
                : ($this->status === 'completed' ? 100 : 0),
            'errors' => $this->when($this->status !== 'queued' && $this->status !== 'processing', $this->errors),
            'failure_reason' => $this->failure_reason,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }
}
