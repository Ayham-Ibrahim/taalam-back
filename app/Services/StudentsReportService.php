<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Curriculum;
use App\Models\Stage;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * التقارير والإحصاءات عن الطلاب (لوحة الأدمن):
 *
 * - الطلاب الذين انتهت باقاتهم ولم يطلبوا باقة جديدة: كل حجوزاتهم (المؤكدة/الجارية/المكتملة)
 *   منتهية، وليس لهم أي حجز مفتوح أو قيد الطلب/الدفع. الحجز يُعدّ منتهياً إن:
 *     • تجاوز expires_at، أو
 *     • انقضت آخر جلساته غير الملغاة — الحالة الأهم عملياً: الرصيد (sessions_used) لا يُخصم
 *       إلا عند دخول الطالب الغرفة، وأغلب الباقات بلا validity_days، فيبقى الحجز confirmed
 *       للأبد بعد آخر جلسة ولا يصل إلى 'completed' إن غاب الطالب عن أي جلسة، أو
 *     • status='completed' (استُهلك رصيده، SessionService::consumeSessionCredit).
 *   المؤكد بلا جلسات بعد (ينتظر الجدولة) مفتوح. status='expired' في bookings تعني انقضاء مهلة
 *   الدفع لا انتهاء الباقة فلا تُحتسب، ولا الملغاة.
 * - توزيع الطلاب على المناهج ونوع التعليم.
 * - الطلاب النشطون أسبوعياً حسب المرحلة: من حضر (present/partial أو له joined_at) أو دخل غرفة
 *   (session_join_logs) جلسةً موعدها ضمن الأسبوع. الأسبوع من السبت إلى الجمعة بتوقيت المنصة
 *   (REPORT_TIMEZONE) — المواعيد مخزّنة بتوقيت التطبيق (UTC) فتُحوَّل حدود الأسبوع إليه.
 */
class StudentsReportService
{
    /** نفس افتراضي users.timezone — توقيت المنصة لحدود الأيام والأسابيع */
    public const REPORT_TIMEZONE = 'Asia/Riyadh';

    private const PENDING_STATUSES = ['pending_teacher_confirmation', 'pending_payment'];

    private const PAID_STATUSES = ['confirmed', 'active', 'completed'];

    public function getReport(?Carbon $weekStart = null): array
    {
        $weekStart = ($weekStart ? Carbon::parse($weekStart->toDateString(), self::REPORT_TIMEZONE) : now(self::REPORT_TIMEZONE))
            ->startOfWeek(Carbon::SATURDAY)
            ->startOfDay();

        return [
            'totals' => [
                'studentsCount' => Student::count(),
            ],
            'expiredWithoutRenewal' => $this->expiredWithoutRenewal(),
            'curricula' => $this->curriculumDistribution(),
            'educationTypes' => $this->educationTypeDistribution(),
            'weeklyActive' => $this->weeklyActive($weekStart),
        ];
    }

    public function expiredWithoutRenewal(): array
    {
        $now = now();
        $today = $now->copy()->setTimezone(self::REPORT_TIMEZONE)->startOfDay();

        // نهاية آخر جلسة غير ملغاة يحضرها صاحب الحجز (session_attendees تشمل المنضمّين لباقة مجموعة)
        $lastSessionEnd = DB::table('session_attendees')
            ->join('class_sessions', 'class_sessions.id', '=', 'session_attendees.class_session_id')
            ->whereColumn('session_attendees.booking_id', 'bookings.id')
            ->where('class_sessions.status', '!=', 'cancelled')
            ->selectRaw('MAX(DATE_ADD(class_sessions.scheduled_at, INTERVAL class_sessions.duration_min MINUTE))');

        $bookings = Booking::query()
            ->whereIn('status', [...self::PENDING_STATUSES, ...self::PAID_STATUSES])
            ->whereHas('student')
            ->select('bookings.*')
            ->selectSub($lastSessionEnd, 'last_session_end')
            ->get();

        // [تاريخ الانتهاء، السبب] للحجز المنتهي، null للمفتوح
        $ended = function (Booking $booking) use ($now, $today): ?array {
            if (in_array($booking->status, self::PENDING_STATUSES, true)) {
                return null;
            }

            $lastSession = $booking->last_session_end ? Carbon::parse($booking->last_session_end) : null;

            if ($booking->expires_at && $booking->expires_at->lt($today)) {
                return [$lastSession && $lastSession->lt($booking->expires_at) ? $lastSession : $booking->expires_at, 'validity_expired'];
            }
            if ($lastSession && $lastSession->lt($now)) {
                return [$lastSession, 'sessions_finished'];
            }
            if ($booking->status === 'completed') {
                return [$lastSession ?? $booking->updated_at, 'sessions_finished'];
            }

            return null;
        };

        $endedByStudent = $bookings
            ->groupBy('student_id')
            ->map(fn (Collection $studentBookings) => $studentBookings->map(fn (Booking $b) => ['booking' => $b, 'ended' => $ended($b)]))
            ->filter(fn (Collection $rows) => $rows->every(fn (array $row) => $row['ended'] !== null));

        if ($endedByStudent->isEmpty()) {
            return [];
        }

        $students = Student::query()
            ->whereIn('id', $endedByStudent->keys())
            ->with(['user:id,name,email,phone', 'stage:id,name_ar', 'curriculum:id,name_ar'])
            ->get()
            ->keyBy('id');

        $lastBookings = $endedByStudent->map(fn (Collection $rows) => $rows->sortByDesc(fn (array $row) => $row['ended'][0]->timestamp)->first());

        (new EloquentCollection($lastBookings->pluck('booking')->all()))->load([
            'package' => fn ($q) => $q->withTrashed()->select('id', 'title'),
            'teacher' => fn ($q) => $q->withTrashed()->with('user:id,name'),
        ]);

        return $lastBookings
            ->map(function (array $last, int $studentId) use ($endedByStudent, $students, $today) {
                $student = $students[$studentId];
                [$endedAt, $reason] = $last['ended'];
                $endedDay = $endedAt->copy()->setTimezone(self::REPORT_TIMEZONE)->startOfDay();

                return [
                    'studentId' => $student->id,
                    'name' => $student->user?->name,
                    'email' => $student->user?->email,
                    'phone' => $student->user?->phone,
                    'guardianPhone' => $student->guardian_phone,
                    'stage' => $student->stage?->name_ar,
                    'curriculum' => $student->curriculum?->name_ar,
                    'lastPackage' => $last['booking']->package?->title,
                    'lastTeacher' => $last['booking']->teacher?->user?->name,
                    'endReason' => $reason,
                    'endedAt' => $endedDay->toDateString(),
                    'daysSinceEnded' => (int) abs($endedDay->diffInDays($today)),
                    'endedPackagesCount' => $endedByStudent[$studentId]->count(),
                ];
            })
            ->sortByDesc('endedAt')
            ->values()
            ->all();
    }

    /**
     * كل طالب اختار منهجاً يُحسب عليه (بصرف النظر عن نوع تعليمه)، و"غير محدد" = طلاب
     * التعليم المدرسي بلا منهج — المنهج غير مطلوب أصلاً للجامعة والتدريب.
     */
    public function curriculumDistribution(): array
    {
        $counts = Student::query()
            ->select('curriculum_id', DB::raw('COUNT(*) as total'))
            ->where(fn ($q) => $q->whereNotNull('curriculum_id')->orWhere('education_type', 'school'))
            ->groupBy('curriculum_id')
            ->pluck('total', 'curriculum_id');

        $rows = Curriculum::query()
            ->orderBy('sort_order')
            ->get(['id', 'name_ar', 'is_active'])
            ->map(fn (Curriculum $c) => [
                'curriculumId' => $c->id,
                'name' => $c->name_ar,
                'studentsCount' => (int) ($counts[$c->id] ?? 0),
            ]);

        $unassigned = (int) ($counts[''] ?? 0);
        if ($unassigned > 0) {
            $rows->push(['curriculumId' => null, 'name' => null, 'studentsCount' => $unassigned]);
        }

        return $rows->values()->all();
    }

    public function educationTypeDistribution(): array
    {
        $counts = Student::query()
            ->select('education_type', DB::raw('COUNT(*) as total'))
            ->groupBy('education_type')
            ->pluck('total', 'education_type');

        return collect(['school', 'university', 'training', null])
            ->map(fn (?string $type) => [
                'type' => $type,
                'studentsCount' => (int) ($counts[$type ?? ''] ?? 0),
            ])
            ->filter(fn (array $row) => $row['type'] !== null || $row['studentsCount'] > 0)
            ->values()
            ->all();
    }

    /** $weekStart: بداية الأسبوع (السبت 00:00) بتوقيت REPORT_TIMEZONE */
    public function weeklyActive(Carbon $weekStart): array
    {
        $weekEnd = $weekStart->copy()->addWeek();
        $previousStart = $weekStart->copy()->subWeek();

        $appTime = fn (Carbon $date) => $date->copy()->setTimezone(config('app.timezone'));
        $current = $this->activeStudentIds($appTime($weekStart), $appTime($weekEnd));
        $previous = $this->activeStudentIds($appTime($previousStart), $appTime($weekStart));

        $students = Student::query()->get(['id', 'stage_id', 'education_type']);
        $groupKey = fn (Student $s) => $s->stage_id ? 'stage:'.$s->stage_id : 'type:'.($s->education_type ?? 'unknown');
        $byGroup = $students->groupBy($groupKey);

        $stages = Stage::query()->orderBy('education_type')->orderBy('sort_order')->get(['id', 'name_ar', 'education_type']);

        $groups = $stages->map(fn (Stage $stage) => [
            'key' => 'stage:'.$stage->id,
            'stageId' => $stage->id,
            'name' => $stage->name_ar,
            'educationType' => $stage->education_type,
        ]);

        // طلاب بلا مرحلة (الجامعة/التدريب غالباً، أو ملف غير مكتمل) يُجمَّعون حسب نوع التعليم
        foreach (['school', 'university', 'training', 'unknown'] as $type) {
            if ($byGroup->has('type:'.$type)) {
                $groups->push(['key' => 'type:'.$type, 'stageId' => null, 'name' => null, 'educationType' => $type === 'unknown' ? null : $type]);
            }
        }

        $rows = $groups->map(function (array $group) use ($byGroup, $current, $previous) {
            $ids = $byGroup->get($group['key'], collect())->pluck('id');

            return $group + [
                'totalStudents' => $ids->count(),
                'activeStudents' => $ids->intersect($current)->count(),
                'previousWeekActive' => $ids->intersect($previous)->count(),
            ];
        });

        return [
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->copy()->subDay()->toDateString(),
            'totalActive' => $current->intersect($students->pluck('id'))->count(),
            'previousWeekTotalActive' => $previous->intersect($students->pluck('id'))->count(),
            'rows' => $rows->values()->all(),
        ];
    }

    private function activeStudentIds(Carbon $from, Carbon $to): Collection
    {
        $attended = DB::table('session_attendees')
            ->join('class_sessions', 'class_sessions.id', '=', 'session_attendees.class_session_id')
            ->where('class_sessions.scheduled_at', '>=', $from)
            ->where('class_sessions.scheduled_at', '<', $to)
            ->where(fn ($q) => $q
                ->whereIn('session_attendees.attendance', ['present', 'partial'])
                ->orWhereNotNull('session_attendees.joined_at')
            )
            ->distinct()
            ->pluck('session_attendees.student_id');

        $joined = DB::table('session_join_logs')
            ->join('class_sessions', 'class_sessions.id', '=', 'session_join_logs.class_session_id')
            ->whereNotNull('session_join_logs.student_id')
            ->where('class_sessions.scheduled_at', '>=', $from)
            ->where('class_sessions.scheduled_at', '<', $to)
            ->distinct()
            ->pluck('session_join_logs.student_id');

        return $attended->merge($joined)->unique()->values();
    }
}
