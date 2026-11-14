<?php

namespace Tests\Feature\Student;

use App\Exports\StudentsExport;
use App\Models\Booking;
use App\Models\Package;
use App\Models\Region;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** تصدير قائمة الطلاب لدى الأدمن — بنفس فلاتر صفحة إدارة الطلاب والأعمدة المختارة */
class StudentExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_exports_chosen_columns_respecting_filters(): void
    {
        Excel::fake();
        $dubai = Region::where('code', 'DXB')->value('id');
        $paying = $this->student('سارة', ['region_id' => $dubai, 'education_type' => 'school']);
        $this->student('خالد', ['region_id' => $dubai, 'education_type' => 'training']);
        $this->student('ليلى', ['education_type' => 'school']);
        $this->paidBooking($paying, 160);

        $this->as($this->adminToken())
            ->get("/api/students/export?region_id={$dubai}&education_type=school&columns[]=total_paid&columns[]=name&columns[]=region&columns[]=is_paying")
            ->assertStatus(200);

        Excel::assertDownloaded('students.xlsx', function (StudentsExport $export) {
            $sheet = $export->sheets()[0];
            $rows = $sheet->array();

            return $sheet->headings() === ['الطالب', 'الولاية', 'هل دفع؟', 'إجمالي ما دفعه (AED)']
                && count($rows) === 2
                && $rows[0] === ['سارة', 'دبي', 'نعم', 160.0]
                && $rows[1][0] === 'الإجمالي: 1 طالب';
        });
    }

    /**
     * تنزيل فعلي بلا Excel::fake — المكتبة تستدعي sheets() أكثر من مرة، وكان تعديل الـ query
     * الأصلي يكرر الـ join (Not unique table/alias: 'bs').
     */
    public function test_real_download_with_booking_columns_succeeds(): void
    {
        $this->paidBooking($this->student('سارة', []), 160);

        $response = $this->as($this->adminToken())->get('/api/students/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('students.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_without_columns_all_are_exported(): void
    {
        Excel::fake();
        $this->student('سارة', []);

        $this->as($this->adminToken())->get('/api/students/export')->assertStatus(200);

        Excel::assertDownloaded('students.xlsx', fn (StudentsExport $export) => count($export->sheets()[0]->headings()) === count(StudentsExport::columns()->options()));
    }

    public function test_columns_endpoint_and_validation(): void
    {
        $token = $this->adminToken();

        $this->as($token)->getJson('/api/students/export/columns')
            ->assertStatus(200)
            ->assertJsonPath('data.0', ['key' => 'name', 'label' => 'الطالب']);

        $this->as($token)->getJson('/api/students/export?columns[]=password')->assertStatus(422);
    }

    public function test_non_admin_cannot_export_students(): void
    {
        $token = User::factory()->teacher()->create()->createToken('t')->plainTextToken;

        $this->as($token)->get('/api/students/export')->assertStatus(403);
    }

    private function student(string $name, array $attributes): Student
    {
        return Student::create(['user_id' => User::factory()->student()->create(['name' => $name])->id, ...$attributes]);
    }

    private function paidBooking(Student $student, float $amount): void
    {
        $teacher = Teacher::create(['user_id' => User::factory()->teacher()->create()->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $package = Package::create([
            'teacher_id' => $teacher->id, 'title' => 'باقة', 'subject_id' => Subject::create(['code' => 'sx-'.uniqid(), 'name_ar' => 'مادة'])->id,
            'session_format' => 'individual', 'capacity' => 1, 'sessions_count' => 4, 'teacher_price' => 100,
            'platform_margin_percent' => 60, 'student_price' => 160, 'platform_revenue' => 60, 'status' => 'active', 'approved_at' => now(),
        ]);
        Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()), 'student_id' => $student->id, 'teacher_id' => $teacher->id,
            'package_id' => $package->id, 'amount_paid' => $amount, 'teacher_amount' => $amount, 'platform_amount' => 0,
            'margin_percent_snapshot' => 60, 'sessions_total' => 4, 'sessions_remaining' => 4, 'status' => 'confirmed',
        ]);
    }

    private function adminToken(): string
    {
        return User::factory()->admin()->create()->createToken('t')->plainTextToken;
    }
}
