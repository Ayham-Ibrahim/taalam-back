<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\SessionAttendee;
use App\Models\Teacher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * تقرير "كل مدرس: عدد الحصص والعائد" للمحاسب/الأدمن (لوحة المحاسبة).
 *
 * الحصة "المحقَّقة فعلاً" هنا أدق من status='completed' وحدها: تلك الحالة
 * في ClassSession (راجع SessionService::completeEndedSessions) تعني فقط أن
 * وقت الحصة انقضى — تشملها حتى لو تغيّب الطالب بالكامل أو لم يحضر أحد. هذا
 * التقرير يضيف شرطاً إضافياً: وجود حاضر واحد على الأقل (attendance ضمن
 * present/partial) كي نحسب فقط الحصص التي "أُعطيت" فعلاً من معلم وطالب
 * معاً، لا كل حصة انتهى وقتها فقط. هذا يختلف عمداً عن منطق PayoutService
 * (الذي يستخدم status='completed' وحدها لتوليد مستحقات المعلم فعلياً) —
 * لم نُغيّر ذاك المنطق هنا لأنه نظام مدفوعات فعلي قائم، وتغيير تعريف
 * "مكتملة" فيه قرار منفصل بأثر مالي مباشر.
 */
class TeacherSessionsReportService
{
    public function getReport(?Carbon $from, ?Carbon $to, ?int $teacherId = null): array
    {
        $sessions = $this->genuinelyCompletedSessionsQuery($from, $to, $teacherId)
            ->with('course:id,total_sessions')
            ->get(['id', 'teacher_id', 'course_id', 'booking_id']);

        if ($sessions->isEmpty()) {
            return ['rows' => [], 'totals' => ['sessionsCount' => 0, 'teacherRevenue' => 0.0, 'platformRevenue' => 0.0]];
        }

        $amounts = $this->calculateSessionAmounts($sessions);

        $teacherIds = $sessions->pluck('teacher_id')->unique();
        $teachers = Teacher::whereIn('id', $teacherIds)->with('user:id,name')->get()->keyBy('id');

        $rows = $sessions->groupBy('teacher_id')->map(function (Collection $group, int $tId) use ($amounts, $teachers) {
            $teacherRevenue = 0.0;
            $platformRevenue = 0.0;

            foreach ($group as $session) {
                $teacherRevenue += $amounts[$session->id]['teacher'] ?? 0.0;
                $platformRevenue += $amounts[$session->id]['platform'] ?? 0.0;
            }

            return [
                'teacherId' => $tId,
                'teacherName' => $teachers->get($tId)?->user?->name,
                'sessionsCount' => $group->count(),
                'teacherRevenue' => round($teacherRevenue, 2),
                'platformRevenue' => round($platformRevenue, 2),
            ];
        })->sortByDesc('platformRevenue')->values();

        return [
            'rows' => $rows->all(),
            'totals' => [
                'sessionsCount' => $sessions->count(),
                'teacherRevenue' => round($rows->sum('teacherRevenue'), 2),
                'platformRevenue' => round($rows->sum('platformRevenue'), 2),
            ],
        ];
    }

    public function genuinelyCompletedSessionsQuery(?Carbon $from, ?Carbon $to, ?int $teacherId = null)
    {
        return ClassSession::query()
            ->where('status', 'completed')
            ->whereHas('attendees', fn ($q) => $q->whereIn('attendance', ['present', 'partial']))
            ->when($from, fn ($q) => $q->where('scheduled_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($q) => $q->where('scheduled_at', '<=', $to->copy()->endOfDay()))
            ->when($teacherId, fn ($q) => $q->where('teacher_id', $teacherId));
    }

    /**
     * نفس بنية PayoutService::calculateSessionAmounts تماماً (أربعة استعلامات
     * ثابتة بغض النظر عن عدد الجلسات)، لكن تُرجع مستحق المعلم *وحصة المنصة*
     * معاً بدل مستحق المعلم فقط — كلا الحقلين متاحان بنفس البنية على bookings
     * (teacher_amount/platform_amount) و enrollments (provider_amount/platform_amount).
     *
     * @return array<int, array{teacher: float, platform: float}>
     */
    private function calculateSessionAmounts(Collection $sessions): array
    {
        $amounts = [];

        $courseSessions = $sessions->filter(fn (ClassSession $s) => $s->course_id !== null);

        if ($courseSessions->isNotEmpty()) {
            $courseIds = $courseSessions->pluck('course_id')->unique();

            $revenueByCourseId = Enrollment::whereIn('course_id', $courseIds)
                ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
                ->selectRaw('course_id, SUM(provider_amount) as teacher_total, SUM(platform_amount) as platform_total')
                ->groupBy('course_id')
                ->get()
                ->keyBy('course_id');

            foreach ($courseSessions as $session) {
                $course = $session->course;
                $revenue = $course ? $revenueByCourseId->get($course->id) : null;
                $divisor = $course?->total_sessions ?? 0;

                $amounts[$session->id] = [
                    'teacher' => $divisor > 0 ? ((float) ($revenue?->teacher_total ?? 0)) / $divisor : 0.0,
                    'platform' => $divisor > 0 ? ((float) ($revenue?->platform_total ?? 0)) / $divisor : 0.0,
                ];
            }
        }

        $bookingSessions = $sessions->filter(fn (ClassSession $s) => $s->course_id === null);

        if ($bookingSessions->isNotEmpty()) {
            $sessionIds = $bookingSessions->pluck('id');

            $bookingIdsBySession = SessionAttendee::whereIn('class_session_id', $sessionIds)
                ->whereNotNull('booking_id')
                ->get(['class_session_id', 'booking_id'])
                ->groupBy('class_session_id')
                ->map(fn ($rows) => $rows->pluck('booking_id')->unique());

            $allBookingIds = $bookingIdsBySession->flatten()
                ->merge($bookingSessions->pluck('booking_id')->filter())
                ->unique();

            $bookingsById = Booking::whereIn('id', $allBookingIds)->get()->keyBy('id');

            foreach ($bookingSessions as $session) {
                $bookingIds = $bookingIdsBySession->get($session->id, collect());

                if ($bookingIds->isEmpty() && $session->booking_id) {
                    $bookingIds = collect([$session->booking_id]);
                }

                $teacherTotal = 0.0;
                $platformTotal = 0.0;

                foreach ($bookingIds as $bookingId) {
                    $booking = $bookingsById->get($bookingId);

                    if ($booking && $booking->sessions_total > 0) {
                        $teacherTotal += ((float) $booking->teacher_amount) / $booking->sessions_total;
                        $platformTotal += ((float) $booking->platform_amount) / $booking->sessions_total;
                    }
                }

                $amounts[$session->id] = ['teacher' => $teacherTotal, 'platform' => $platformTotal];
            }
        }

        return $amounts;
    }
}
