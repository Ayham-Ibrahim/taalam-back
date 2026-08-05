<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherBlackout extends Model
{
    protected $fillable = ['teacher_id', 'start_date', 'end_date', 'reason'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }
}
