<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\Curriculum;
use App\Models\Language;
use App\Models\Package;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * إحصائيات وخيارات فلترة عامة للصفحة الرئيسية/البحث — بلا مصادقة عمداً،
 * بنفس منطق TeacherSearchController العام. مخبَّأة ساعة لأنها أرقام تجميعية
 * لا تتغير لحظياً.
 */
class MetaController extends Controller
{
    private const CACHE_TTL = 3600;

    /** قيم education_type على جداول subjects/stages (تختلف عن teacher_type: training مقابل training_center) */
    private const EDUCATION_TYPES = ['school', 'university', 'training'];

    public function stats()
    {
        $stats = Cache::remember('meta:stats', self::CACHE_TTL, function () {
            $avgRating = (float) Teacher::where('reviews_count', '>', 0)->avg('rating_avg');

            return [
                ['key' => 'rating', 'value' => round($avgRating, 1), 'label' => 'متوسط التقييم', 'icon' => 'star'],
                ['key' => 'students', 'value' => Student::count(), 'label' => 'طالب نشط', 'icon' => 'graduation'],
                ['key' => 'sessions', 'value' => ClassSession::where('status', 'completed')->count(), 'label' => 'جلسة مكتملة', 'icon' => 'book'],
                ['key' => 'teachers', 'value' => Teacher::where('status', 'verified')->count(), 'label' => 'معلم معتمد', 'icon' => 'users'],
            ];
        });

        return $this->success($stats);
    }

    /**
     * ?education_type=school|university|training يقصر المواد/المراحل/الصفوف على
     * نوع التعليم المطلوب — تستخدمه صفحات "أنواع التعليم" المخصّصة كي لا تعرض
     * قائمة "المادة" مثلاً مواد جامعية داخل صفحة التعليم المدرسي. المناهج
     * واللغات ونطاق السعر تبقى عامة لأنها غير مرتبطة بعمود education_type.
     * بلا الباراميتر: السلوك القديم تماماً (كل القوائم) — متوافق رجعياً.
     */
    public function filters(Request $request)
    {
        $eduType = $request->query('education_type');
        $eduType = in_array($eduType, self::EDUCATION_TYPES, true) ? $eduType : null;

        $filters = Cache::remember('meta:filters:'.($eduType ?? 'all'), self::CACHE_TTL, function () use ($eduType) {
            $grades = $eduType === 'school' || $eduType === null ? range(1, 12) : [];

            return [
                'educationType' => $eduType,
                'levels' => [
                    ['value' => 'school', 'label' => 'مدرسي'],
                    ['value' => 'university', 'label' => 'جامعي'],
                    ['value' => 'training', 'label' => 'دورات تدريبية'],
                ],
                'grades' => collect($grades)->map(fn ($g) => ['value' => (string) $g, 'label' => "الصف {$g}"])->all(),
                'subjects' => Subject::where('is_active', true)
                    ->when($eduType, fn ($q) => $q->where('education_type', $eduType))
                    ->orderBy('sort_order')->get(['id', 'name_ar'])
                    ->map(fn ($s) => ['value' => $s->id, 'label' => $s->name_ar])->all(),
                'stages' => Stage::where('is_active', true)
                    ->when($eduType, fn ($q) => $q->where('education_type', $eduType))
                    ->orderBy('sort_order')->get(['id', 'name_ar'])
                    ->map(fn ($s) => ['value' => $s->id, 'label' => $s->name_ar])->all(),
                'languages' => Language::where('is_active', true)->get(['id', 'name_ar'])
                    ->map(fn ($l) => ['value' => $l->id, 'label' => $l->name_ar])->all(),
                'curricula' => Curriculum::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar'])
                    ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name_ar])->all(),
                'priceRange' => [
                    'min' => (int) (Package::where('status', 'active')->min('student_price') ?? 50),
                    'max' => (int) (Package::where('status', 'active')->max('student_price') ?? 550),
                ],
            ];
        });

        return $this->success($filters);
    }
}
