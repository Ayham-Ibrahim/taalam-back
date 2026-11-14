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
        'city',
        'country',
        'country_id',
        'region_id',
        'guardian_name',
        'guardian_phone',
        'imported',
        'imported_by',
    ];

    /**
     * الدولة تتبع الولاية دائماً: ولاية جديدة (أو دولة فارغة مع ولاية) تضبط دولتها، وتغيير
     * الدولة يُسقط ولاية لا تتبعها — فلا يختلفان أبداً مهما كان مصدر التعديل (نموذج، استيراد، أمر).
     */
    protected static function booted(): void
    {
        static::saving(function (Student $student) {
            if ($student->region_id && ($student->isDirty('region_id') || $student->country_id === null)) {
                $student->country_id = Region::whereKey($student->region_id)->value('country_id');
            } elseif ($student->region_id && $student->isDirty('country_id')
                && Region::whereKey($student->region_id)->value('country_id') !== (int) $student->country_id) {
                $student->region_id = null;
            }
        });
    }

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

    /** الدولة من جدول countries — ليست country() لأن عمود students.country النصّي القديم يحمل الاسم نفسه */
    public function locationCountry()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
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
