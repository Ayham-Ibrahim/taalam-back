<?php

namespace Tests\Feature\Report;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Country;
use App\Models\Curriculum;
use App\Models\Package;
use App\Models\Region;
use App\Models\SessionAttendee;
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
        $this->booking($sharjah, 'confirmed', 0); // مجاني: باقة جارية لكنه لم يدفع — لا يُعد دافعاً ولا يدرس حالياً
        Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => 'school']); // بلا موقع

        $response = $this->as($this->adminToken())->getJson('/api/reports/students-location')->assertStatus(200);

        $response->assertJsonPath('data.totals.students', 5)
            ->assertJsonPath('data.totals.paying', 1)
            ->assertJsonPath('data.totals.running', 1)
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

    /**
     * الصف من ملف الطالب، والمواد من باقاته المدفوعة فقط — الطالب يُعد في كل مادة له، والإيراد
     * لكل مادة إيراد باقاتها وحدها. الفلتران يضيّقان التقرير وقائمة الطلاب.
     */
    public function test_report_groups_by_grade_and_subject_and_filters_by_them(): void
    {
        $physics = $this->package->replicate()->fill(['subject_id' => Subject::create(['code' => 'phy-'.uniqid(), 'name_ar' => 'فيزياء'])->id]);
        $physics->save();

        $both = $this->student('DXB', $this->british, grade: 8);
        $this->booking($both, 'confirmed', 160);
        $this->booking($both, 'confirmed', 200, $physics);
        $physicsOnly = $this->student('SHJ', $this->british, grade: 8);
        $this->booking($physicsOnly, 'confirmed', 100, $physics);
        $physicsUnpaid = $this->student('SHJ', $this->british, grade: 9);
        $this->booking($physicsUnpaid, 'expired', 100, $physics); // لم يدفع — ليست مادته
        $trial = $this->package->replicate()->fill(['subject_id' => null, 'is_trial' => true, 'sessions_count' => 1, 'teacher_price' => 0, 'platform_margin_percent' => 0, 'student_price' => 0, 'platform_revenue' => 0]);
        $trial->save();
        $this->booking($physicsOnly, 'confirmed', 0, $trial); // جلسة تعريفية بلا مادة — لا عمود لها
        $this->student('DXB', $this->american); // بلا صف

        $token = $this->adminToken();
        $response = $this->as($token)->getJson('/api/reports/students-location')->assertStatus(200);

        $grades = collect($response->json('data.byGrade'));
        $this->assertSame([8, 9, null], $grades->pluck('grade')->all());
        $this->assertSame('missing', $grades->last()['gradeBucket']); // مدرسي لم يحدد صفه
        $this->assertSame(2, $grades->firstWhere('grade', 8)['students']);
        $this->assertSame(2, $grades->firstWhere('grade', 8)['paying']);

        $subjects = collect($response->json('data.bySubject'));
        $this->assertCount(2, $subjects);
        $this->assertNotContains(null, $subjects->pluck('subject')->all());
        $physicsRow = $subjects->firstWhere('subject', 'فيزياء');
        $this->assertSame(2, $physicsRow['students']);
        $this->assertEquals(300, $physicsRow['revenue']);
        $this->assertSame(1, $subjects->firstWhere('subject', 'مادة')['students']);

        // المصفوفتان: الشارقة فيها طالبان (صف 8 وصف 9)، ومن يدرس الفيزياء منهما واحد فقط (الآخر لم يدفع)
        $gradeMatrix = $response->json('data.gradeMatrix');
        $sharjahGrades = collect($gradeMatrix['rows'])->firstWhere('region', 'الشارقة');
        $this->assertSame([1, 1, 0], $sharjahGrades['counts']); // أعمدة: 8، 9، بدون صف
        $this->assertSame(2, $sharjahGrades['total']);

        $subjectMatrix = $response->json('data.subjectMatrix');
        $this->assertCount(2, $subjectMatrix['columns']);
        $physicsCol = collect($subjectMatrix['columns'])->search(fn ($c) => $c['subject'] === 'فيزياء');
        $this->assertSame(1, collect($subjectMatrix['rows'])->firstWhere('region', 'الشارقة')['counts'][$physicsCol]);
        $this->assertSame(1, collect($subjectMatrix['rows'])->firstWhere('region', 'دبي')['counts'][$physicsCol]);

        // من بلا صف يُصنَّف: جامعي وتدريب منفصلين وملف غير مكتمل بعد المدرسي بلا صف، ويُفلتر بتصنيفه
        $university = $this->student('DXB', $this->american);
        $university->update(['education_type' => 'university']);
        $this->student('DXB', $this->american)->update(['education_type' => 'training']);
        Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => null]);

        $buckets = collect($this->as($token)->getJson('/api/reports/students-location')->json('data.byGrade'))->pluck('gradeBucket', 'gradeKey');
        $this->assertSame(
            ['8' => null, '9' => null, 'missing' => 'missing', 'university' => 'university', 'training' => 'training', 'incomplete' => 'incomplete'],
            $buckets->all(),
        );

        $this->as($token)->getJson('/api/reports/students-location/students?grade=university')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.studentId', $university->id);

        $query = http_build_query(['subject_id' => $physics->subject_id, 'grade' => 8]);
        $this->as($token)->getJson("/api/reports/students-location?{$query}")
            ->assertJsonPath('data.totals.students', 2);

        $list = $this->as($token)->getJson("/api/reports/students-location/students?{$query}")
            ->assertJsonCount(2, 'data')
            ->json('data');
        $this->assertSame(['فيزياء', 'مادة'], collect($list)->firstWhere('studentId', $both->id)['subjects']);
        $this->assertSame(8, collect($list)->firstWhere('studentId', $physicsOnly->id)['grade']);
    }

    public function test_running_counts_only_bookings_that_have_not_ended(): void
    {
        $finished = $this->student('DXB', $this->british); // confirmed مدفوع لكن انقضت كل جلساته
        $finishedBooking = $this->booking($finished, 'confirmed', 160);
        $this->addSession($finishedBooking, now()->subDays(3));
        $this->addSession($finishedBooking, now()->addDay(), 'cancelled'); // الملغاة لا تُبقيه جارياً
        $upcoming = $this->student('DXB', $this->british);
        $booking = $this->booking($upcoming, 'confirmed', 160);
        $this->addSession($booking, now()->subDays(3));
        $this->addSession($booking, now()->addDay());
        $awaiting = $this->student('SHJ', $this->british); // مؤكد بلا جلسات بعد — ينتظر الجدولة
        $this->booking($awaiting, 'confirmed', 160);
        $expired = $this->student('SHJ', $this->british); // تجاوز مدة صلاحيته
        $this->booking($expired, 'confirmed', 160)->update(['expires_at' => now()->subDays(2)]);

        $token = $this->adminToken();
        $response = $this->as($token)->getJson('/api/reports/students-location')
            ->assertJsonPath('data.totals.paying', 4)
            ->assertJsonPath('data.totals.running', 2);
        $this->assertSame(2, $response->json('data.bySubject.0.running'));

        $list = collect($this->as($token)->getJson('/api/reports/students-location/students')->json('data'));
        $this->assertFalse($list->firstWhere('studentId', $finished->id)['hasRunningPackage']);
        $this->assertTrue($list->firstWhere('studentId', $upcoming->id)['hasRunningPackage']);
    }

    public function test_students_without_country_or_region_can_be_filtered(): void
    {
        $this->student('DXB', $this->british);
        $countryOnly = Student::create(['user_id' => User::factory()->student()->create()->id, 'country_id' => Country::where('code', 'AE')->value('id')]);
        $noLocation = Student::create(['user_id' => User::factory()->student()->create()->id]);
        $token = $this->adminToken();

        $this->as($token)->getJson('/api/reports/students-location/students?country_id=none')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.studentId', $noLocation->id);

        $query = http_build_query(['country_id' => $countryOnly->country_id, 'region_id' => 'none']);
        $this->as($token)->getJson("/api/reports/students-location/students?{$query}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.studentId', $countryOnly->id);
        $this->as($token)->getJson("/api/reports/students-location?{$query}")
            ->assertJsonPath('data.totals.students', 1);

        $this->as($token)->getJson('/api/reports/students-location?country_id=abc')->assertStatus(422);
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

    private function student(string $regionCode, Curriculum $curriculum, ?int $grade = null): Student
    {
        return Student::create([
            'user_id' => User::factory()->student()->create()->id,
            'education_type' => 'school',
            'curriculum_id' => $curriculum->id,
            'region_id' => $this->region($regionCode),
            'grade' => $grade,
        ]);
    }

    private function booking(Student $student, string $status, float $amount, ?Package $package = null): Booking
    {
        $package ??= $this->package;

        return Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $package->teacher_id,
            'package_id' => $package->id,
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

    private function addSession(Booking $booking, $scheduledAt, string $status = 'scheduled'): void
    {
        $session = ClassSession::create([
            'booking_id' => $booking->id,
            'teacher_id' => $booking->teacher_id,
            'sequence_no' => $booking->sessions()->count() + 1,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => $status,
        ]);

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $booking->student_id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
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
