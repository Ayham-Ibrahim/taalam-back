<?php

namespace App\Http\Controllers;

use App\Http\Requests\Teacher\SearchTeachersRequest;
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
            ->select(['id', 'user_id', 'teacher_type', 'bio', 'city', 'rating_avg', 'reviews_count', 'ranking_score', 'profile_completeness'])
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
                $q->whereHas('subjects.stages', fn ($sq) => $sq->where('stages.id', $stageId));
            })
            ->when($filters['language_id'] ?? null, function ($q, $languageId) {
                $q->whereHas('languages', fn ($lq) => $lq->where('languages.id', $languageId));
            })
            ->when($filters['min_price'] ?? null, function ($q, $minPrice) {
                $q->whereHas('packages', fn ($pq) => $pq->where('status', 'active')->where('student_price', '>=', $minPrice));
            })
            ->when($filters['max_price'] ?? null, function ($q, $maxPrice) {
                $q->whereHas('packages', fn ($pq) => $pq->where('status', 'active')->where('student_price', '<=', $maxPrice));
            })
            ->with('user:id,name,avatar_path')
            ->orderByDesc('ranking_score')
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($teachers);
    }
}
