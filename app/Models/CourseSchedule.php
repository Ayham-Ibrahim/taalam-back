<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseSchedule extends Model
{
    protected $fillable = ['course_id', 'day_of_week', 'start_time', 'end_time'];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
