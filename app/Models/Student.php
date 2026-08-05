<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'education_type',
        'curriculum_id',
        'stage_id',
        'grade',
        'university_id',
        'major_id',
        'academic_level',
        'course_field_id',
        'level',
        'birth_date',
        'guardian_name',
        'guardian_phone',
        'imported',
        'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'imported' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function stage()
    {
        return $this->belongsTo(Stage::class);
    }

    public function university()
    {
        return $this->belongsTo(University::class);
    }

    public function major()
    {
        return $this->belongsTo(Major::class);
    }

    public function courseField()
    {
        return $this->belongsTo(CourseField::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function complaints()
    {
        return $this->hasMany(Complaint::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }
}
