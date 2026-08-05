<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChangeObjection extends Model
{
    protected $fillable = [
        'change_request_id',
        'student_id',
        'reason',
        'resolution',
    ];

    public function changeRequest()
    {
        return $this->belongsTo(ChangeRequest::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
