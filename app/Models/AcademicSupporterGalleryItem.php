<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicSupporterGalleryItem extends Model
{
    protected $fillable = [
        'academic_supporter_id',
        'file_path',
        'thumbnail_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'file_type',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function academicSupporter(): BelongsTo
    {
        return $this->belongsTo(AcademicSupporter::class);
    }
}
