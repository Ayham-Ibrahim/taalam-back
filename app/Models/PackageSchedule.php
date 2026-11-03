<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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

    /**
     * لحظة بداية هذا الموعد الفعلية بتوقيت النظام — date/start_time أُدخلا بمنطقة
     * المعلم وقت إنشاء الباقة (Package::schedule_timezone)، لا UTC ساذجة ولا منطقة
     * من يعرضها. null لمواعيد الباقات الفردية (يوم أسبوع فقط بلا تاريخ/وقت).
     */
    public function startsAt(?string $timezone): ?Carbon
    {
        if (! $this->date || ! $this->start_time) {
            return null;
        }

        // date كائن Carbon (date cast) — toDateString() ضروري، وإلا Carbon::parse يتجاهل $timezone
        return Carbon::parse($this->date->toDateString(), $timezone ?? 'UTC')
            ->setTimeFromTimeString((string) $this->start_time)
            ->setTimezone(config('app.timezone'));
    }
}
