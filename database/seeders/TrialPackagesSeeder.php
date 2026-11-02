<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\Teacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * جلسة تعريفية مجانية (is_trial) لكل معلم مدرسي موثّق — نشطة مباشرة بلا مراجعة أدمن.
 * يُشغَّل يدوياً فقط (php artisan db:seed --class=TrialPackagesSeeder)، وآمن التكرار:
 * المعلم الذي لديه باقة تجربة (بأي حالة) لا تُنشأ له أخرى. المعلم بلا أيام توفّر يُتخطّى —
 * الباقة الفردية لا تُحجز إلا ضمن أيامها — ويُضاف بإعادة التشغيل بعد أن يحدد أيامه.
 */
class TrialPackagesSeeder extends Seeder
{
    public function run(): void
    {
        $created = 0;
        $skippedNoDays = [];

        Teacher::query()
            ->where('teacher_type', 'school')
            ->where('status', 'verified')
            ->whereDoesntHave('packages', fn ($q) => $q->where('is_trial', true))
            ->with(['user:id,name,timezone', 'availabilitySlots:id,teacher_id,day_of_week'])
            ->orderBy('id')
            ->each(function (Teacher $teacher) use (&$created, &$skippedNoDays) {
                $days = $teacher->availabilitySlots->pluck('day_of_week')->unique()->sort()->values();

                if ($days->isEmpty()) {
                    $skippedNoDays[] = "#{$teacher->id} {$teacher->user?->name}";

                    return;
                }

                DB::transaction(function () use ($teacher, $days) {
                    $package = Package::create([
                        'teacher_id' => $teacher->id,
                        'title' => 'جلسة تعريفية مجانية',
                        'description' => 'جلسة فردية واحدة مجانية تتعرّف فيها على المعلم وأسلوبه في التدريس قبل الاشتراك في باقاته.',
                        'subject_id' => null,
                        'session_format' => 'individual',
                        'is_trial' => true,
                        'capacity' => 1,
                        'sessions_count' => 1,
                        'teacher_price' => 0,
                        'platform_margin_percent' => 0,
                        'student_price' => 0,
                        'platform_revenue' => 0,
                        'status' => 'active',
                        'approved_at' => now(),
                        'schedule_timezone' => $teacher->user?->timezone ?? 'UTC',
                    ]);

                    foreach ($days as $day) {
                        $package->schedules()->create(['day_of_week' => $day]);
                    }
                });

                $created++;
            });

        $this->command?->info("أُنشئت {$created} باقة تجربة مجانية.");

        if ($skippedNoDays !== []) {
            $this->command?->warn('تُخطّي '.count($skippedNoDays).' معلم بلا أيام توفّر: '.implode('، ', $skippedNoDays));
        }
    }
}
