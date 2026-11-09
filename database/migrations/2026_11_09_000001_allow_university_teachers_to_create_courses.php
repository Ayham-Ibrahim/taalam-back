<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * المدرس الجامعي صار يستطيع إنشاء دورات تدريبية (إضافة لباقاته) — الـ trigger الأصلي
 * (add_check_constraints) كان يرفض أي دورة لغير المركز التدريبي. يُستبدَل بنسخة تسمح
 * بـ training_center وuniversity معاً (نفس Teacher::COURSE_PROVIDER_TYPES). لا يمس أي بيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_courses_teacher_type');

        DB::unprepared("
            CREATE TRIGGER trg_courses_teacher_type
            BEFORE INSERT ON courses
            FOR EACH ROW
            BEGIN
                DECLARE v_type VARCHAR(20);
                SELECT teacher_type INTO v_type FROM teachers WHERE id = NEW.teacher_id;
                IF v_type NOT IN ('training_center', 'university') THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Only training centers and university teachers can create courses';
                END IF;
            END
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_courses_teacher_type');

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
};
