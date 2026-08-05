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
use Illuminate\Support\Facades\Cache;

/**
 * إحصائيات وخيارات فلترة عامة للصفحة الرئيسية/البحث — بلا مصادقة عمداً،
 * بنفس منطق TeacherSearchController العام. مخبَّأة ساعة لأنها أرقام تجميعية
 * لا تتغير لحظياً.
 */
class MetaController extends Controller
{
    private const CACHE_TTL = 3600;

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

    public function filters()
    {
        $filters = Cache::remember('meta:filters', self::CACHE_TTL, function () {
            return [
                'levels' => [
                    ['value' => 'school', 'label' => 'مدرسي'],
                    ['value' => 'university', 'label' => 'جامعي'],
                    ['value' => 'training', 'label' => 'دورات تدريبية'],
                ],
                'grades' => collect(range(1, 12))->map(fn ($g) => ['value' => (string) $g, 'label' => "الصف {$g}"])->all(),
                'subjects' => Subject::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar'])
                    ->map(fn ($s) => ['value' => $s->id, 'label' => $s->name_ar])->all(),
                'stages' => Stage::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar'])
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
