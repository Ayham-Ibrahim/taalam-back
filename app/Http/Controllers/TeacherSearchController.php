<?php

namespace App\Http\Controllers;

use App\Http\Requests\Teacher\SearchTeachersRequest;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Package;
use App\Models\Teacher;

/**
 * بحث السوق العام — بلا auth عمداً. يعتمد على teachers(status, teacher_type)
 * لتضييق النتائج و teachers(ranking_score) للترتيب — كلا الفهرسين موجودان في
 * migration الملفات الشخصية. راجع ملخص M7 لتفصيل خطة EXPLAIN.
 */
class TeacherSearchController extends Controller
{
    public function index(SearchTeachersRequest $request)
    {
        $filters = $request->validated();

        $teachers = Teacher::query()
            ->select(['id', 'user_id', 'teacher_type', 'bio', 'city', 'qualification', 'experience_years', 'rating_avg', 'reviews_count', 'ranking_score', 'profile_completeness'])
            ->where('status', 'verified')
            ->when($filters['teacher_type'] ?? null, fn ($q, $type) => $q->where('teacher_type', $type))
            ->when($filters['city'] ?? null, fn ($q, $city) => $q->where('city', $city))
            ->when($filters['q'] ?? null, function ($q, $search) {
                $q->whereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$search}%"));
            })
            ->when($filters['min_rating'] ?? null, fn ($q, $rating) => $q->where('rating_avg', '>=', $rating))
            ->when($filters['subject_id'] ?? null, function ($q, $subjectId) {
                $q->whereHas('subjects', fn ($sq) => $sq->where('subjects.id', $subjectId));
            })
            ->when($filters['curriculum_id'] ?? null, function ($q, $curriculumId) {
                $q->whereHas('curricula', fn ($cq) => $cq->where('curricula.id', $curriculumId));
            })
            ->when($filters['stage_id'] ?? null, function ($q, $stageId) {
                // المرحلة تُستنتَج من الباقات الفعلية القابلة للحجز التي يقدّمها
                // المعلم (package_stage)، لا من تصنيف عام يربط مادته بمرحلة
                // (subject_stage) — الأخير كان يُظهر معلماً حتى لو لم تستهدف أي
                // من باقاته الفعلية هذه المرحلة إطلاقاً، لمجرد أن مادته عموماً
                // مصنَّفة لها إدارياً.
                $q->whereHas('packages', fn ($pq) => $pq->bookable()
                    ->whereHas('stages', fn ($sq) => $sq->where('stages.id', $stageId)));
            })
            ->when($filters['grade'] ?? null, function ($q, $grade) {
                $q->whereHas('packages', fn ($pq) => $pq->bookable()
                    ->whereJsonContains('grades', (int) $grade));
            })
            ->when($filters['language_id'] ?? null, function ($q, $languageId) {
                $q->whereHas('languages', fn ($lq) => $lq->where('languages.id', $languageId));
            })
            // فحص واحد مشترك للحدَّين معاً — لا نداءين مستقلَّين (كانا سابقاً
            // منفصلَين: EXISTS باقة ≥ الحد الأدنى، وEXISTS باقة ≤ الحد الأقصى،
            // بلا اشتراط أن تكون نفس الباقة). ذلك كان يُطابق معلماً بالخطأ لديه
            // باقة رخيصة جداً وأخرى غالية جداً كلتاهما خارج النطاق المطلوب، طالما
            // إحداهما فوق الحد الأدنى والأخرى تحت الحد الأقصى بمعزل عن بعضهما.
            ->when(($filters['min_price'] ?? null) !== null || ($filters['max_price'] ?? null) !== null, function ($q) use ($filters) {
                $q->whereHas('packages', function ($pq) use ($filters) {
                    $pq->bookable();
                    if (($filters['min_price'] ?? null) !== null) {
                        $pq->where('student_price', '>=', $filters['min_price']);
                    }
                    if (($filters['max_price'] ?? null) !== null) {
                        $pq->where('student_price', '<=', $filters['max_price']);
                    }
                });
            })
            ->with([
                'user:id,name,avatar_path',
                'subjects:id,name_ar',
                'curricula:id,name_ar',
                'languages:id,name_ar,code',
            ])
            ->orderByDesc('ranking_score')
            ->paginate($request->integer('per_page', 20));

        // مقاعد بطاقة نتائج البحث الخفيفة تحتاج بيانات إضافية مجمَّعة لكل
        // صفحة (بلا N+1) بدل استدعاء TeacherService::getStats الكامل لكل
        // معلم على حدة: الجلسات المكتملة، المراحل الدراسية (من باقاته الفعلية
        // القابلة للحجز)، عدد الطلاب الفريدين، مدى السعر، وتوفّر اليوم.
        $teacherIds = collect($teachers->items())->pluck('id');

        $completedCounts = ClassSession::whereIn('teacher_id', $teacherIds)
            ->where('status', 'completed')
            ->selectRaw('teacher_id, COUNT(*) as cnt')
            ->groupBy('teacher_id')
            ->pluck('cnt', 'teacher_id');

        $stagesByTeacher = Package::query()
            ->whereIn('teacher_id', $teacherIds)
            ->bookable()
            ->with('stages:id,name_ar')
            ->get(['id', 'teacher_id'])
            ->groupBy('teacher_id')
            ->map(fn ($packages) => $packages->pluck('stages')->flatten()->pluck('name_ar')->unique()->values());

        $priceRangeByTeacher = Package::query()
            ->whereIn('teacher_id', $teacherIds)
            ->bookable()
            ->selectRaw('teacher_id, MIN(student_price) as min_price, MAX(student_price) as max_price')
            ->groupBy('teacher_id')
            ->get()
            ->keyBy('teacher_id');

        $studentIdsByTeacher = Booking::whereIn('teacher_id', $teacherIds)
            ->whereNotNull('student_id')
            ->get(['teacher_id', 'student_id'])
            ->merge(Enrollment::whereIn('teacher_id', $teacherIds)->whereNotNull('student_id')->get(['teacher_id', 'student_id']))
            ->groupBy('teacher_id')
            ->map(fn ($rows) => $rows->pluck('student_id')->unique()->count());

        $availableTodayTeacherIds = AvailabilitySlot::whereIn('teacher_id', $teacherIds)
            ->where('day_of_week', now()->dayOfWeek)
            ->pluck('teacher_id')
            ->unique();

        foreach ($teachers->items() as $teacher) {
            $teacher->setAttribute('completed_sessions', (int) ($completedCounts->get($teacher->id) ?? 0));
            $teacher->setAttribute('stages', $stagesByTeacher->get($teacher->id, collect())->values());
            $teacher->setAttribute('total_students', (int) ($studentIdsByTeacher->get($teacher->id) ?? 0));
            $priceRange = $priceRangeByTeacher->get($teacher->id);
            $teacher->setAttribute('min_price', $priceRange?->min_price);
            $teacher->setAttribute('max_price', $priceRange?->max_price);
            $teacher->setAttribute('available_today', $availableTodayTeacherIds->contains($teacher->id));
        }

        return $this->paginate($teachers);
    }
}
