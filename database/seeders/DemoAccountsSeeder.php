<?php

namespace Database\Seeders;

use App\Models\Badge;
use App\Models\BadgeAward;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Curriculum;
use App\Models\Language;
use App\Models\Package;
use App\Models\PackageSchedule;
use App\Models\Review;
use App\Models\SessionAttendee;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherExperience;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * حسابات تجريبية للاختبار المحلي على XAMPP فقط — لا تُشغَّل في الإنتاج.
 * كل الحسابات بكلمة مرور: password
 */
class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        // صف الشارات في ترويسة الملف يحتاج كتالوج الشارات موجوداً حتى لو
        // شُغِّل هذا السيدر وحده (خارج DatabaseSeeder)
        $this->callOnce(BadgeSeeder::class);

        $curriculum = Curriculum::firstOrCreate(
            ['code' => 'national'],
            ['name_ar' => 'المنهج الوطني', 'name_en' => 'National', 'sort_order' => 1, 'is_active' => true],
        );

        $stage = Stage::firstOrCreate(
            ['code' => 'secondary'],
            ['name_ar' => 'المرحلة الثانوية', 'education_type' => 'school', 'sort_order' => 1, 'is_active' => true],
        );

        $subject = Subject::firstOrCreate(
            ['code' => 'math'],
            ['name_ar' => 'رياضيات', 'name_en' => 'Math', 'education_type' => 'school', 'sort_order' => 1, 'is_active' => true],
        );
        $subject->stages()->syncWithoutDetaching([$stage->id]);

        $extraSubjects = collect([
            ['code' => 'physics', 'name_ar' => 'فيزياء', 'name_en' => 'Physics'],
            ['code' => 'chemistry', 'name_ar' => 'كيمياء', 'name_en' => 'Chemistry'],
        ])->map(fn ($s, $i) => Subject::firstOrCreate(
            ['code' => $s['code']],
            $s + ['education_type' => 'school', 'sort_order' => $i + 2, 'is_active' => true],
        ));

        $curricula = collect([
            ['code' => 'british', 'name_ar' => 'بريطاني', 'name_en' => 'British'],
            ['code' => 'american', 'name_ar' => 'أمريكي', 'name_en' => 'American'],
            ['code' => 'igcse', 'name_ar' => 'IGCSE', 'name_en' => 'IGCSE'],
            ['code' => 'a_level', 'name_ar' => 'A-Level', 'name_en' => 'A-Level'],
        ])->map(fn ($c, $i) => Curriculum::firstOrCreate(
            ['code' => $c['code']],
            $c + ['sort_order' => $i + 2, 'is_active' => true],
        ));

        $language = Language::firstOrCreate(
            ['code' => 'ar'],
            ['name_ar' => 'العربية', 'is_active' => true],
        );
        $languageEn = Language::firstOrCreate(
            ['code' => 'en'],
            ['name_ar' => 'الإنجليزية', 'is_active' => true],
        );

        // ═══════ أدمن ═══════
        User::updateOrCreate(
            ['email' => 'admin@taalam.test'],
            ['name' => 'أدمن تجريبي', 'role' => 'admin', 'email_verified_at' => now(), 'password' => bcrypt('password')],
        );

        // ═══════ معلم (مدرسي) ═══════
        $teacherUser = User::updateOrCreate(
            ['email' => 'teacher@taalam.test'],
            ['name' => 'معلم تجريبي', 'role' => 'teacher', 'email_verified_at' => now(), 'password' => bcrypt('password')],
        );
        $teacher = Teacher::updateOrCreate(
            ['user_id' => $teacherUser->id],
            [
                'teacher_type' => 'school',
                'status' => 'verified',
                'verified_at' => now(),
                'bio' => 'معلم رياضيات متخصص في مناهج Cambridge وEdexcel وIB واختبارات IELTS/TOEFL، بخبرة أكثر من ثماني سنوات في تدريس الطلاب من مختلف المراحل الدراسية. أومن أن كل طالب قادر على تحقيق التفوق عند توفير الأسلوب المناسب والبيئة الداعمة، لذلك أركز على تبسيط المفاهيم وبناء أساسيات قوية قبل الانتقال إلى المسائل المتقدمة، مع متابعة فردية لكل طالب وتقارير دورية لولي الأمر.',
                'teaching_philosophy_quote' => 'التعليم الجيد لا يقاس بكمية المعلومات، بل بقدرة الطالب على استخدامها.',
                'teaching_philosophy_text' => 'أسعى إلى بناء علاقة تعلم قائمة على الثقة والتفاعل، حيث يشعر كل طالب بالراحة لطرح الأسئلة وارتكاب الأخطاء أثناء التعلم. أدمج أمثلة من الحياة الواقعية لتبسيط المفاهيم المعقدة، وأتابع تقدم كل طالب بشكل فردي لأضمن أنه يتقن الأساسيات قبل الانتقال لما هو أصعب.',
                'qualification' => 'bachelor',
                'experience_years' => 'over_5',
                'city' => 'الأردن - عمّان - حي الطويل',
                'teaching_methods' => ['شرح مباشر', 'حل واجبات', 'تدريب امتحانات'],
                'exam_prep' => ['SAT', 'ACT', 'IB', 'IGCSE'],
                'intro_youtube_id' => 'M7lc1UVf-VE',
                'intro_video_seconds' => 86,
            ],
        );
        // نسبة الرضا في ترويسة الملف مشتقة من هذا العمود (غير قابل للتعبئة الجماعية)
        $teacher->forceFill(['completion_rate' => 92])->save();
        $teacher->subjects()->syncWithoutDetaching(
            $extraSubjects->pluck('id')->push($subject->id)->all(),
        );
        $teacher->curricula()->syncWithoutDetaching(
            $curricula->pluck('id')->push($curriculum->id)->all(),
        );
        $teacher->languages()->syncWithoutDetaching([$language->id, $languageEn->id]);

        // مراحل دراسية إضافية + باقة جماعية نشطة حتى يظهر خيارا "فردية/جماعية"
        // في قسم "نوع الجلسة" (مُشتَق من باقات المعلم القابلة للحجز)
        $primary = Stage::firstOrCreate(
            ['code' => 'primary'],
            ['name_ar' => 'المرحلة الابتدائية', 'education_type' => 'school', 'sort_order' => 0, 'is_active' => true],
        );
        $groupPackage = Package::firstOrCreate(
            ['teacher_id' => $teacher->id, 'title' => 'باقة المجموعة المكثفة'],
            [
                'subject_id' => $subject->id,
                'session_format' => 'group',
                'capacity' => 6,
                'sessions_count' => 8,
                'session_duration_min' => 60,
                'validity_days' => 60,
                'teacher_price' => 400,
                'platform_margin_percent' => 20,
                'student_price' => 480,
                'platform_revenue' => 80,
                'currency' => 'USD',
                'status' => 'active',
                'approved_at' => now(),
            ],
        );
        $groupPackage->stages()->syncWithoutDetaching([$stage->id, $primary->id]);
        PackageSchedule::firstOrCreate(
            ['package_id' => $groupPackage->id, 'date' => now()->addWeek()->toDateString()],
            ['start_time' => '17:00', 'end_time' => '18:00', 'day_of_week' => now()->addWeek()->dayOfWeek],
        );
        $groupPackage->update(['enrolled_count' => 3]);

        // جلسات مكتملة تجريبية لإحصائية "الجلسات المكتملة" في الترويسة
        $completedCount = ClassSession::where('teacher_id', $teacher->id)->where('status', 'completed')->count();
        for ($i = $completedCount; $i < 48; $i++) {
            ClassSession::create([
                'teacher_id' => $teacher->id,
                'sequence_no' => 1,
                'scheduled_at' => now()->subDays($i + 2)->setTime(17, 0),
                'duration_min' => 60,
                'status' => 'completed',
            ]);
        }

        // مناهج تجريبية على كل باقات المعلم النشطة — تظهر كوسوم في بطاقة الباقة
        $curriculumIds = $curricula->pluck('id')->take(2)->all();
        Package::where('teacher_id', $teacher->id)->where('status', 'active')->each(
            fn (Package $p) => $p->curricula()->syncWithoutDetaching($curriculumIds),
        );

        // خبرات سابقة تجريبية لقسم "الخبرات السابقة"
        $experiences = [
            ['title' => 'مدرس في مدارس خاصة في دبي', 'period' => '2019 - 2022', 'sort_order' => 1],
            ['title' => 'مدرس في أكاديمية تعليمية', 'period' => '2022 - الآن', 'sort_order' => 2],
            ['title' => 'خبرة في مناهج British / American / IB', 'period' => '', 'sort_order' => 3],
        ];
        foreach ($experiences as $exp) {
            TeacherExperience::updateOrCreate(
                ['teacher_id' => $teacher->id, 'title' => $exp['title']],
                ['period' => $exp['period'], 'sort_order' => $exp['sort_order']],
            );
        }

        // شارات تجريبية لعرض صف الشارات في ترويسة الملف — بالترتيب المعروض
        // (يمين→يسار): مؤهلات مراجعة، فحص أمني، معلم مميز، مركز معتمد
        $badgeCodes = ['reviewed_credentials', 'background_checked', 'featured', 'accredited_center'];
        foreach ($badgeCodes as $i => $code) {
            $badge = Badge::where('code', $code)->first();
            if ($badge) {
                BadgeAward::updateOrCreate(
                    ['teacher_id' => $teacher->id, 'badge_id' => $badge->id],
                    ['granted_by' => null, 'granted_at' => now()->addSeconds($i), 'revoked_at' => null],
                );
            }
        }

        // تقييمات تجريبية لقسم "التقييم" (المتوسط ≈ 4.5)
        $demoReviews = [
            ['name' => 'سعيد صالح', 'rating' => 5, 'comment' => 'دروس منظمة جداً ومفيدة، لقد استفدت كثيراً، شكراً للمنصة وللمدرس.'],
            ['name' => 'ليلى الأحمد', 'rating' => 5, 'comment' => 'شرح واضح وأسلوب صبور، تحسّن مستوى ابني في الرياضيات بشكل ملحوظ.'],
            ['name' => 'محمد كريم', 'rating' => 5, 'comment' => 'أفضل مدرس تعاملت معه، يعطي أمثلة عملية ويتابع الواجبات أولاً بأول.'],
            ['name' => 'هدى ناصر', 'rating' => 4, 'comment' => 'تجربة جيدة جداً، فقط أتمنى توفير مواعيد إضافية في نهاية الأسبوع.'],
            ['name' => 'خالد عمر', 'rating' => 4, 'comment' => 'مدرس متمكن من المادة وملتزم بالمواعيد، أنصح به.'],
            ['name' => 'ريم فؤاد', 'rating' => 4, 'comment' => 'استفدت من الحصص التحضيرية للامتحانات، الشرح مبسّط ومركّز.'],
        ];
        foreach ($demoReviews as $i => $rev) {
            $revUser = User::updateOrCreate(
                ['email' => 'reviewer'.($i + 1).'@taalam.test'],
                ['name' => $rev['name'], 'role' => 'student', 'email_verified_at' => now(), 'password' => bcrypt('password')],
            );
            $revStudent = Student::updateOrCreate(
                ['user_id' => $revUser->id],
                ['education_type' => 'school', 'curriculum_id' => $curriculum->id, 'stage_id' => $stage->id, 'grade' => 11],
            );
            Review::updateOrCreate(
                ['student_id' => $revStudent->id, 'teacher_id' => $teacher->id, 'class_session_id' => null],
                ['rating' => $rev['rating'], 'comment' => $rev['comment'], 'is_hidden' => false],
            );
        }

        // ═══════ مركز تدريبي ═══════
        $centerUser = User::updateOrCreate(
            ['email' => 'center@taalam.test'],
            ['name' => 'مركز تجريبي', 'role' => 'teacher', 'email_verified_at' => now(), 'password' => bcrypt('password')],
        );
        $center = Teacher::updateOrCreate(
            ['user_id' => $centerUser->id],
            [
                'teacher_type' => 'training_center',
                'status' => 'verified',
                'verified_at' => now(),
                'display_name_en' => 'Demo Training Center',
                'commercial_register' => 'CR-000000',
                'city' => 'الرياض',
                'bio' => 'مركز تدريبي متخصص في الدورات المهنية',
            ],
        );
        $center->languages()->syncWithoutDetaching([$language->id]);

        // ═══════ طالب ═══════
        $studentUser = User::updateOrCreate(
            ['email' => 'student@taalam.test'],
            ['name' => 'طالب تجريبي', 'role' => 'student', 'email_verified_at' => now(), 'password' => bcrypt('password')],
        );
        $student = Student::updateOrCreate(
            ['user_id' => $studentUser->id],
            [
                'education_type' => 'school',
                'curriculum_id' => $curriculum->id,
                'stage_id' => $stage->id,
                'grade' => 10,
            ],
        );

        // جلسة مكتملة حضرها الطالب التجريبي مع المعلم التجريبي، بلا تقييم بعد —
        // تظهر في /dashboard/student/reviews كـ"بانتظار التقييم" فيستطيع الطالب
        // إدخال تقييمه فعلياً (POST /class-sessions/{session}/reviews).
        $demoBooking = Booking::firstOrCreate(
            ['student_id' => $student->id, 'teacher_id' => $teacher->id, 'package_id' => $groupPackage->id],
            [
                'reference' => 'BK-DEMO-0001',
                'amount_paid' => 480,
                'teacher_amount' => 400,
                'platform_amount' => 80,
                'margin_percent_snapshot' => 20,
                'currency' => 'USD',
                'sessions_total' => 8,
                'sessions_used' => 1,
                'sessions_remaining' => 7,
                'status' => 'active',
                'policy_accepted_at' => now()->subDays(10),
                'confirmed_at' => now()->subDays(10),
            ],
        );
        // جلستان مكتملتان — يبقى دائماً ما لا يقل عن واحدة بلا تقييم لتجربة الإدخال
        foreach ([1 => 3, 2 => 1] as $seq => $daysAgo) {
            $s = ClassSession::firstOrCreate(
                ['booking_id' => $demoBooking->id, 'sequence_no' => $seq],
                [
                    'teacher_id' => $teacher->id,
                    'scheduled_at' => now()->subDays($daysAgo)->setTime(17, 0),
                    'ended_at' => now()->subDays($daysAgo)->setTime(18, 0),
                    'duration_min' => 60,
                    'status' => 'completed',
                ],
            );
            SessionAttendee::firstOrCreate(
                ['class_session_id' => $s->id, 'student_id' => $student->id],
                [
                    'booking_id' => $demoBooking->id,
                    'attendance' => 'present',
                    'joined_at' => now()->subDays($daysAgo)->setTime(17, 0),
                    'left_at' => now()->subDays($daysAgo)->setTime(18, 0),
                    'duration_minutes' => 60,
                ],
            );
        }

        $this->command?->info('Demo accounts ready (password: "password"):');
        $this->command?->table(['role', 'email'], [
            ['admin', 'admin@taalam.test'],
            ['teacher (school)', 'teacher@taalam.test'],
            ['training center', 'center@taalam.test'],
            ['student', 'student@taalam.test'],
        ]);
    }
}
