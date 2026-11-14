<?php

namespace Tests\Feature\Report;

use App\Models\Booking;
use App\Models\Country;
use App\Models\Curriculum;
use App\Models\Package;
use App\Models\Region;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** تقرير التسويق: الطلاب حسب الموقع × المنهج، مع الدفع والباقات الجارية والإيراد */
class StudentsLocationReportTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    private Curriculum $british;

    private Curriculum $american;

    protected function setUp(): void
    {
        parent::setUp();

        $teacher = Teacher::create(['user_id' => User::factory()->teacher()->create()->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $this->package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => Subject::create(['code' => 'loc-'.uniqid(), 'name_ar' => 'مادة'])->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160,
            'platform_revenue' => 60,
            'status' => 'active',
            'approved_at' => now(),
        ]);
        $this->british = Curriculum::create(['code' => 'british', 'name_ar' => 'بريطاني']);
        $this->american = Curriculum::create(['code' => 'american', 'name_ar' => 'أمريكي']);
    }

    public function test_report_groups_students_by_location_and_curriculum_with_conversion_and_revenue(): void
    {
        $dubaiBritishPaid = $this->student('DXB', $this->british);
        $this->booking($dubaiBritishPaid, 'confirmed', 160);
        $this->booking($dubaiBritishPaid, 'completed', 200);
        $this->student('DXB', $this->british);
        $dubaiAmerican = $this->student('DXB', $this->american);
        $this->booking($dubaiAmerican, 'cancelled', 160); // ملغى لا يُحسب
        $sharjah = $this->student('SHJ', $this->british);
        $this->booking($sharjah, 'confirmed', 0); // مجاني: باقة جارية لكنه لم يدفع
        Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => 'school']); // بلا موقع

        $response = $this->as($this->adminToken())->getJson('/api/reports/students-location')->assertStatus(200);

        $response->assertJsonPath('data.totals.students', 5)
            ->assertJsonPath('data.totals.paying', 1)
            ->assertJsonPath('data.totals.running', 2)
            ->assertJsonPath('data.totals.withoutLocation', 1)
            ->assertJsonPath('data.totals.conversionRate', 20);
        $this->assertEquals(360, $response->json('data.totals.revenue'));

        $dubai = collect($response->json('data.byLocation'))->firstWhere('region', 'دبي');
        $this->assertSame('الإمارات', $dubai['country']);
        $this->assertSame(3, $dubai['students']);
        $this->assertSame(1, $dubai['paying']);
        $this->assertEquals(33.3, $dubai['conversionRate']);

        $british = collect($response->json('data.byCurriculum'))->firstWhere('curriculum', 'بريطاني');
        $this->assertSame(3, $british['students']);

        $matrix = $response->json('data.matrix');
        $britishCol = collect($matrix['columns'])->search(fn ($c) => $c['curriculum'] === 'بريطاني');
        $dubaiRow = collect($matrix['rows'])->firstWhere('region', 'دبي');
        $this->assertSame(2, $dubaiRow['counts'][$britishCol]);
        $this->assertSame(3, $dubaiRow['total']);
    }

    public function test_filters_narrow_the_report_and_the_students_list(): void
    {
        $target = $this->student('DXB', $this->british);
        $this->student('DXB', $this->american);
        $this->student('SHJ', $this->british);
        $old = $this->student('DXB', $this->british);
        $old->forceFill(['created_at' => now()->subYear()])->save();

        $token = $this->adminToken();
        $query = http_build_query([
            'region_id' => $this->region('DXB'),
            'curriculum_id' => $this->british->id,
            'from' => now()->subMonth()->toDateString(),
        ]);

        $this->as($token)->getJson("/api/reports/students-location?{$query}")
            ->assertStatus(200)
            ->assertJsonPath('data.totals.students', 1);

        $this->as($token)->getJson("/api/reports/students-location/students?{$query}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.studentId', $target->id)
            ->assertJsonPath('data.0.region', 'دبي')
            ->assertJsonPath('data.0.curriculum', 'بريطاني');
    }

    public function test_export_downloads_an_excel_file(): void
    {
        $this->student('DXB', $this->british);

        $response = $this->as($this->adminToken())->get('/api/reports/students-location/export?country_id='.Country::where('code', 'AE')->value('id'));

        $response->assertStatus(200);
        $this->assertStringContainsString('students-location-report.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_non_admin_cannot_view_the_report(): void
    {
        $token = User::factory()->teacher()->create()->createToken('t')->plainTextToken;

        $this->as($token)->getJson('/api/reports/students-location')->assertStatus(403);
    }

    private function student(string $regionCode, Curriculum $curriculum): Student
    {
        return Student::create([
            'user_id' => User::factory()->student()->create()->id,
            'education_type' => 'school',
            'curriculum_id' => $curriculum->id,
            'region_id' => $this->region($regionCode),
        ]);
    }

    private function booking(Student $student, string $status, float $amount): void
    {
        Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $this->package->teacher_id,
            'package_id' => $this->package->id,
            'amount_paid' => $amount,
            // chk_bookings_amounts: المدفوع = مستحق المعلم + عائد المنصة
            'teacher_amount' => $amount,
            'platform_amount' => 0,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => $status,
        ]);
    }

    private function region(string $code): int
    {
        return Region::where('code', $code)->value('id');
    }

    private function adminToken(): string
    {
        return User::factory()->admin()->create()->createToken('t')->plainTextToken;
    }
}
