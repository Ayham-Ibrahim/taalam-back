<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    protected $fillable = [
        'student_id',
        'favoritable_type',
        'favoritable_id',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function favoritable()
    {
        return $this->morphTo();
    }
}
