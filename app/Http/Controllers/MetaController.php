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
use App\Services\SettingsService;
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

    /** كل نسخ كاش filters() — بلا نوع ('all') ولكل نوع تعليم؛ يمسحها TaxonomyController عند أي تعديل */
    public const FILTER_CACHE_SCOPES = ['all', 'school', 'university', 'training'];

    public static function filtersCacheKey(string $scope): string
    {
        return "meta:filters:v3:{$scope}";
    }

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

        $filters = Cache::remember(self::filtersCacheKey($eduType ?? 'all'), self::CACHE_TTL, function () use ($eduType) {
            $grades = $eduType === 'school' || $eduType === null ? range(1, 12) : [];

            // صفوف كل مرحلة من نطاقها المحدَّد (grade_from..grade_to) — تستخدمها
            // الواجهة لجعل قائمة "الصف" تابعة للمرحلة المختارة (الإعدادية 7–9
            // فقط مثلاً). مرحلة بلا نطاق لا تظهر هنا فتعود الواجهة للقائمة الكاملة.
            $stageGrades = Stage::where('is_active', true)
                ->whereNotNull('grade_from')->whereNotNull('grade_to')
                ->get(['id', 'grade_from', 'grade_to'])
                ->mapWithKeys(fn ($s) => [$s->id => array_map('strval', range($s->grade_from, $s->grade_to))])
                ->all();

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
                'stageGrades' => (object) $stageGrades,
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

    /**
     * البريد والعنوان الرسميان يُعدَّلان من إعدادات الأدمن (contact_email/contact_address)
     * بدل أن يكونا hardcoded بالفرونت إند — تستهلكه صفحة تواصل معنا، الفوتر، وJSON-LD.
     * بلا طبقة تخبئة إضافية هنا عمداً: SettingsService::all() مخبَّأة أصلاً ومُفرَّغة
     * تلقائياً عند أي تعديل من الأدمن (Cache::forget('settings:all'))، فتخبئة ثانية هنا
     * كانت ستُبقي القيمة القديمة ظاهرة للزوار حتى ساعة كاملة بعد كل تعديل.
     */
    public function contactInfo(SettingsService $settings)
    {
        return $this->success([
            'email' => $settings->get('contact_email'),
            'address' => $settings->get('contact_address'),
        ]);
    }
}
