<?php

namespace App\Http\Resources\AcademicSupporter;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** ملف داعم أكاديمي عام — يُستخدَم لتصفّح/بروفايل الطالب الجامعي داخل لوحته فقط (لا رابط عام). */
class PublicAcademicSupporterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user?->name,
            'avatar' => $this->user?->avatar_path,
            'bio' => $this->bio,
            'qualification' => $this->qualification,
            'experienceYears' => $this->experience_years,
            'university' => $this->whenLoaded('university', fn () => $this->university ? ['id' => $this->university->id, 'name' => $this->university->name_ar] : null),
            'major' => $this->whenLoaded('major', fn () => $this->major ? ['id' => $this->major->id, 'name' => $this->major->name_ar] : null),
            'subjects' => $this->whenLoaded('subjects', fn () => $this->subjects->map(fn ($s) => ['id' => $s->id, 'name' => $s->name_ar])),
            'languages' => $this->whenLoaded('languages', fn () => $this->languages->map(fn ($l) => ['code' => $l->code, 'name' => $l->name_ar])),
            'galleryItems' => $this->whenLoaded('galleryItems', fn () => $this->galleryItems->map(fn ($g) => [
                'id' => $g->id, 'filePath' => $g->file_path, 'thumbnailPath' => $g->thumbnail_path, 'originalName' => $g->original_name, 'fileType' => $g->file_type,
            ])),
            'supportPackages' => $this->whenLoaded('supportPackages', fn () => $this->supportPackages->map(fn ($sp) => [
                'id' => $sp->id,
                'title' => $sp->title,
                'tier' => $sp->tier,
                'description' => $sp->description,
                'subjects' => $sp->relationLoaded('subjects') ? $sp->subjects->map(fn ($s) => ['id' => $s->id, 'name' => $s->name_ar]) : [],
                'price' => (float) $sp->student_price,
                'currency' => $sp->currency,
            ])),
        ];
    }
}
