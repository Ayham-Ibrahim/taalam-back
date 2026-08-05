<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\Language;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
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

        $language = Language::firstOrCreate(
            ['code' => 'ar'],
            ['name_ar' => 'العربية', 'is_active' => true],
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
                'bio' => 'معلم رياضيات بخبرة أكثر من 5 سنوات',
                'qualification' => 'bachelor',
                'experience_years' => 'over_5',
            ],
        );
        $teacher->subjects()->syncWithoutDetaching([$subject->id]);
        $teacher->curricula()->syncWithoutDetaching([$curriculum->id]);
        $teacher->languages()->syncWithoutDetaching([$language->id]);

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
        Student::updateOrCreate(
            ['user_id' => $studentUser->id],
            [
                'education_type' => 'school',
                'curriculum_id' => $curriculum->id,
                'stage_id' => $stage->id,
                'grade' => 10,
            ],
        );

        $this->command?->info('Demo accounts ready (password: "password"):');
        $this->command?->table(['role', 'email'], [
            ['admin', 'admin@taalam.test'],
            ['teacher (school)', 'teacher@taalam.test'],
            ['training center', 'center@taalam.test'],
            ['student', 'student@taalam.test'],
        ]);
    }
}
