<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * قيود مستوى قاعدة البيانات — خط الدفاع الأخير
 * ═══════════════════════════════════════════════════════════════
 *
 * هذه القيود تحمي القواعد الجوهرية حتى لو أخطأ كود التطبيق.
 * MySQL 8.0.16+ ينفّذ CHECK constraints فعلياً.
 *
 * ملاحظة: القاعدة "المركز لا ينشئ باقات والمدرس لا ينشئ دورات"
 * لا يمكن فرضها بـ CHECK لأنها cross-table — تُفرَض عبر:
 *   1. Policy classes في Laravel
 *   2. Model observers
 *   3. Triggers (مضافة أدناه)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── الباقات ──
        DB::statement("
            ALTER TABLE packages
            ADD CONSTRAINT chk_packages_capacity
            CHECK (
                (session_format = 'individual' AND capacity = 1)
                OR (session_format = 'group' AND capacity >= 2)
            )
        ");

        DB::statement('
            ALTER TABLE packages
            ADD CONSTRAINT chk_packages_enrolled
            CHECK (enrolled_count >= 0 AND enrolled_count <= capacity)
        ');

        DB::statement('
            ALTER TABLE packages
            ADD CONSTRAINT chk_packages_price
            CHECK (teacher_price > 0)
        ');

        // السعر النهائي يجب أن يكون أكبر من سعر المعلم عند تحديده
        DB::statement('
            ALTER TABLE packages
            ADD CONSTRAINT chk_packages_student_price
            CHECK (student_price IS NULL OR student_price >= teacher_price)
        ');

        // الباقة النشطة يجب أن تحمل نسبة وسعراً نهائياً
        DB::statement("
            ALTER TABLE packages
            ADD CONSTRAINT chk_packages_active_pricing
            CHECK (
                status NOT IN ('active','full')
                OR (platform_margin_percent IS NOT NULL AND student_price IS NOT NULL)
            )
        ");

        // ── الدورات ──
        DB::statement('
            ALTER TABLE courses
            ADD CONSTRAINT chk_courses_dates
            CHECK (end_date >= start_date)
        ');

        DB::statement('
            ALTER TABLE courses
            ADD CONSTRAINT chk_courses_seats
            CHECK (enrolled_count >= 0 AND enrolled_count <= max_seats)
        ');

        DB::statement('
            ALTER TABLE courses
            ADD CONSTRAINT chk_courses_price
            CHECK (provider_price > 0)
        ');

        DB::statement("
            ALTER TABLE courses
            ADD CONSTRAINT chk_courses_active_pricing
            CHECK (
                status NOT IN ('active','full','in_progress')
                OR (platform_margin_percent IS NOT NULL AND student_price IS NOT NULL)
            )
        ");

        // ── الحجوزات ──
        DB::statement('
            ALTER TABLE bookings
            ADD CONSTRAINT chk_bookings_sessions
            CHECK (
                sessions_used >= 0
                AND sessions_used <= sessions_total
                AND sessions_remaining = sessions_total - sessions_used
            )
        ');

        DB::statement('
            ALTER TABLE bookings
            ADD CONSTRAINT chk_bookings_amounts
            CHECK (amount_paid = teacher_amount + platform_amount)
        ');

        // الحجز اليدوي يجب أن يحمل سبباً وهوية الأدمن
        DB::statement('
            ALTER TABLE bookings
            ADD CONSTRAINT chk_bookings_manual
            CHECK (
                is_manual = 0
                OR (created_by_admin_id IS NOT NULL AND manual_reason IS NOT NULL)
            )
        ');

        DB::statement('
            ALTER TABLE enrollments
            ADD CONSTRAINT chk_enrollments_manual
            CHECK (
                is_manual = 0
                OR (created_by_admin_id IS NOT NULL AND manual_reason IS NOT NULL)
            )
        ');

        // ── الجداول الزمنية ──
        // package_schedules: جماعية تملأ date+start_time+end_time، فردية day_of_week فقط (الباقي NULL)
        DB::statement('
            ALTER TABLE package_schedules
            ADD CONSTRAINT chk_pkg_sched_time
            CHECK (
                ((start_time IS NULL AND end_time IS NULL) OR end_time > start_time)
                AND day_of_week BETWEEN 0 AND 6
            )
        ');

        DB::statement('
            ALTER TABLE course_schedules
            ADD CONSTRAINT chk_crs_sched_time
            CHECK (end_time > start_time AND day_of_week BETWEEN 0 AND 6)
        ');

        // availability_slots الآن أيام فقط (بلا أوقات) — لا حاجة لقيد وقت
        DB::statement('
            ALTER TABLE availability_slots
            ADD CONSTRAINT chk_avail_day
            CHECK (day_of_week BETWEEN 0 AND 6)
        ');

        // ── التقييمات ──
        DB::statement('
            ALTER TABLE reviews
            ADD CONSTRAINT chk_reviews_rating
            CHECK (rating BETWEEN 1 AND 5)
        ');

        /**
         * ═══════ Triggers لفرض قاعدة النوع ═══════
         * المركز التدريبي لا ينشئ باقات — والمدرس لا ينشئ دورات.
         */
        DB::unprepared("
            CREATE TRIGGER trg_packages_teacher_type
            BEFORE INSERT ON packages
            FOR EACH ROW
            BEGIN
                DECLARE v_type VARCHAR(20);
                SELECT teacher_type INTO v_type FROM teachers WHERE id = NEW.teacher_id;
                IF v_type = 'training_center' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Training centers cannot create packages — only courses';
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER trg_courses_teacher_type
            BEFORE INSERT ON courses
            FOR EACH ROW
            BEGIN
                DECLARE v_type VARCHAR(20);
                SELECT teacher_type INTO v_type FROM teachers WHERE id = NEW.teacher_id;
                IF v_type <> 'training_center' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Only training centers can create courses';
                END IF;
            END
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_courses_teacher_type');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_packages_teacher_type');

        $constraints = [
            'reviews' => ['chk_reviews_rating'],
            'availability_slots' => ['chk_avail_day'],
            'course_schedules' => ['chk_crs_sched_time'],
            'package_schedules' => ['chk_pkg_sched_time'],
            'enrollments' => ['chk_enrollments_manual'],
            'bookings' => ['chk_bookings_manual', 'chk_bookings_amounts', 'chk_bookings_sessions'],
            'courses' => ['chk_courses_active_pricing', 'chk_courses_price', 'chk_courses_seats', 'chk_courses_dates'],
            'packages' => ['chk_packages_active_pricing', 'chk_packages_student_price', 'chk_packages_price', 'chk_packages_enrolled', 'chk_packages_capacity'],
        ];

        foreach ($constraints as $table => $names) {
            foreach ($names as $name) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }
    }
};
