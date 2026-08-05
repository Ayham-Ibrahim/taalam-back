<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageSchedule extends Model
{
    protected $fillable = ['package_id', 'date', 'day_of_week', 'start_time', 'end_time'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }
}
