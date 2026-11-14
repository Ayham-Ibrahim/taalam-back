<?php

namespace Tests\Feature\Teacher;

use App\Exports\TeachersExport;
use App\Models\Booking;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** تصدير قائمة المعلمين لدى الأدمن — بنفس فلاتر صفحة إدارة المعلمين */
class TeacherExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_downloads_teachers_excel_respecting_filters(): void
    {
        Excel::fake();
        $this->teacher('أحمد المعتمد', 'school', 'verified');
        $this->teacher('سارة المنتظرة', 'school', 'pending_verification');
        $this->teacher('مركز معتمد', 'training_center', 'verified');

        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->as($token)->get('/api/teachers/export?status=verified&teacher_type=school')->assertStatus(200);

        Excel::assertDownloaded('teachers.xlsx', function (TeachersExport $export) {
            $rows = $export->sheets()[0]->array();
            $names = array_column(array_slice($rows, 0, -1), 0);

            return $names === ['أحمد المعتمد'] && $rows[1][0] === 'الإجمالي: 1 معلم';
        });
    }

    public function test_admin_chooses_columns_and_they_keep_definition_order(): void
    {
        Excel::fake();
        $this->teacher('أحمد', 'school', 'verified');
        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        // تُرسَل بترتيب مختلف — تخرج بترتيب التعريف
        $this->as($token)->get('/api/teachers/export?columns[]=phone&columns[]=name&columns[]=status')->assertStatus(200);

        Excel::assertDownloaded('teachers.xlsx', function (TeachersExport $export) {
            $sheet = $export->sheets()[0];

            return $sheet->headings() === ['المعلم', 'الهاتف', 'الحالة']
                && count($sheet->array()[0]) === 3
                && $sheet->array()[0][2] === 'معتمد';
        });
    }

    public function test_unknown_column_is_rejected(): void
    {
        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->as($token)->getJson('/api/teachers/export?columns[]=password')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['columns.0']);
    }

    public function test_admin_lists_available_export_columns(): void
    {
        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->as($token)->getJson('/api/teachers/export/columns')
            ->assertStatus(200)
            ->assertJsonCount(17, 'data')
            ->assertJsonPath('data.0', ['key' => 'name', 'label' => 'المعلم']);
    }

    public function test_students_count_comes_from_bookings_and_zeros_are_written(): void
    {
        Excel::fake();
        $teacher = $this->teacher('أحمد', 'school', 'verified');
        $package = Package::create([
            'teacher_id' => $teacher->id, 'title' => 'باقة', 'subject_id' => Subject::create(['code' => 'exp-'.uniqid(), 'name_ar' => 'مادة'])->id,
            'session_format' => 'individual', 'capacity' => 1, 'sessions_count' => 4, 'teacher_price' => 100,
            'platform_margin_percent' => 60, 'student_price' => 160, 'platform_revenue' => 60, 'status' => 'active', 'approved_at' => now(),
        ]);
        $twice = Student::create(['user_id' => User::factory()->student()->create()->id]);
        $once = Student::create(['user_id' => User::factory()->student()->create()->id]);
        $cancelled = Student::create(['user_id' => User::factory()->student()->create()->id]);
        foreach ([[$twice, 'confirmed'], [$twice, 'completed'], [$once, 'active'], [$cancelled, 'cancelled']] as [$student, $status]) {
            Booking::create([
                'reference' => 'BK-'.strtoupper(uniqid()), 'student_id' => $student->id, 'teacher_id' => $teacher->id,
                'package_id' => $package->id, 'amount_paid' => 160, 'teacher_amount' => 160, 'platform_amount' => 0,
                'margin_percent_snapshot' => 60, 'sessions_total' => 4, 'sessions_remaining' => 4, 'status' => $status,
            ]);
        }

        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;
        $this->as($token)->get('/api/teachers/export')->assertStatus(200);

        Excel::assertDownloaded('teachers.xlsx', function (TeachersExport $export) {
            $sheet = $export->sheets()[0];
            $row = $sheet->array()[0];

            // عمود "عدد الطلاب" (الخامس عشر): طالبان مختلفان، والملغى لا يُحسب
            return $row[14] === 2 && $row[13] === 0 && $sheet instanceof WithStrictNullComparison;
        });
    }

    /** تنزيل فعلي بلا Excel::fake — المكتبة تستدعي sheets() أكثر من مرة */
    public function test_real_download_succeeds(): void
    {
        $this->teacher('أحمد', 'school', 'verified');
        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->as($token)->get('/api/teachers/export')->assertStatus(200);
    }

    public function test_non_admin_cannot_export_teachers(): void
    {
        $token = User::factory()->student()->create()->createToken('t')->plainTextToken;

        $this->as($token)->get('/api/teachers/export')->assertStatus(403);
    }

    private function teacher(string $name, string $type, string $status): Teacher
    {
        return Teacher::create([
            'user_id' => User::factory()->teacher()->create(['name' => $name])->id,
            'teacher_type' => $type,
            'status' => $status,
        ]);
    }
}
