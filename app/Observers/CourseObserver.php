<?php

namespace App\Observers;

use App\Models\Course;

class CourseObserver
{
    public function saving(Course $course): void
    {
        if ($course->status === 'active' && $course->enrolled_count >= $course->max_seats) {
            $course->status = 'full';
        } elseif ($course->status === 'full' && $course->enrolled_count < $course->max_seats) {
            $course->status = 'active';
        }
    }
}
