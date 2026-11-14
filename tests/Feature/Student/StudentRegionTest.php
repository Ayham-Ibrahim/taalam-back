<?php

namespace Tests\Feature\Student;

use App\Models\ImportBatch;
use App\Models\Region;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentImportService;
use App\Support\RegionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ولاية الطالب (regions) — تُجمَع من التسجيل والملف والاستيراد كقائمة ثابتة بدل
 * city النصّي الحر، كي تُبنى عليها تقارير التسويق (ولاية × منهاج).
 */
class StudentRegionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seven_emirates_are_seeded_and_listed_publicly(): void
    {
        $response = $this->getJson('/api/taxonomy/regions');

        $response->assertStatus(200)->assertJsonCount(7, 'data');
        $this->assertSame('AUH', $response->json('data.0.code'));
    }

    public function test_admin_can_add_a_region(): void
    {
        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->as($token)->postJson('/api/taxonomy/regions', [
            'code' => 'MCT', 'name_ar' => 'مسقط', 'name_en' => 'Muscat', 'country' => 'OM',
        ])->assertStatus(201);

        $this->assertDatabaseHas('regions', ['code' => 'MCT', 'country' => 'OM']);
    }

    public function test_student_registration_stores_the_region(): void
    {
        $dubai = $this->region('DXB');

        $this->postJson('/api/auth/register/student', [
            'name' => 'أحمد',
            'email' => 'region.reg@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_policy' => true,
            'education_type' => 'training',
            'course_field_id' => $this->courseFieldId(),
            'level' => 'beginner',
            'region_id' => $dubai,
        ])->assertStatus(201)->assertJsonPath('data.user.student.region_id', $dubai);

        $this->assertSame($dubai, User::where('email', 'region.reg@example.com')->first()->student->region_id);
    }

    public function test_registration_rejects_an_unknown_region(): void
    {
        $this->postJson('/api/auth/register/student', [
            'name' => 'أحمد',
            'email' => 'region.bad@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_policy' => true,
            'education_type' => 'training',
            'course_field_id' => $this->courseFieldId(),
            'level' => 'beginner',
            'region_id' => 99999,
        ])->assertStatus(422)->assertJsonValidationErrors(['region_id']);
    }

    public function test_student_can_set_region_from_profile_and_admin_can_filter_by_it(): void
    {
        $sharjah = $this->region('SHJ');
        $user = User::factory()->student()->create();
        $student = Student::create(['user_id' => $user->id, 'education_type' => 'training']);
        Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => 'training']);

        $this->as($user->createToken('t')->plainTextToken)->putJson("/api/students/{$student->id}", [
            'education_type' => 'training',
            'course_field_id' => $this->courseFieldId(),
            'level' => 'beginner',
            'region_id' => $sharjah,
        ])->assertStatus(200)->assertJsonPath('data.region_id', $sharjah);

        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->as($adminToken)->getJson("/api/students?region_id={$sharjah}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('data.0.region', 'الشارقة');
    }

    public function test_sibling_inherits_the_primary_region_unless_given_one(): void
    {
        $ajman = $this->region('AJM');
        $primary = User::factory()->student()->create();
        Student::create(['user_id' => $primary->id, 'education_type' => 'school', 'region_id' => $ajman]);
        $token = $primary->createToken('t')->plainTextToken;

        $payload = ['education_type' => 'training', 'course_field_id' => $this->courseFieldId(), 'level' => 'beginner'];

        $this->as($token)->postJson('/api/me/siblings', ['name' => 'سارة', ...$payload])->assertStatus(201);
        $this->as($token)->postJson('/api/me/siblings', ['name' => 'ليلى', 'region_id' => $this->region('RAK'), ...$payload])->assertStatus(201);

        $siblings = User::where('primary_user_id', $primary->id)->with('student')->orderBy('id')->get();
        $this->assertSame($ajman, $siblings[0]->student->region_id);
        $this->assertSame($this->region('RAK'), $siblings[1]->student->region_id);
    }

    public function test_matcher_handles_spelling_variants_and_cities(): void
    {
        $matcher = app(RegionMatcher::class);

        $this->assertSame($this->region('DXB'), $matcher->match('دبى'));
        $this->assertSame($this->region('DXB'), $matcher->match(' DUBAI '));
        $this->assertSame($this->region('AUH'), $matcher->match('أبو ظبي'));
        $this->assertSame($this->region('AUH'), $matcher->match('العين'));
        $this->assertSame($this->region('SHJ'), $matcher->match('الشارقه'));
        $this->assertSame($this->region('RAK'), $matcher->match('Ras Al-Khaimah'));
        $this->assertSame($this->region('UAQ'), $matcher->match('ام القيوين'));
        $this->assertNull($matcher->match('Cairo'));
        $this->assertNull($matcher->match(null));
    }

    public function test_import_resolves_region_column_or_falls_back_to_city(): void
    {
        Notification::fake();
        Storage::fake('local');
        $admin = User::factory()->admin()->create();

        $csv = <<<'CSV'
        name,email,city,region
        طالب أول,region.one@example.com,,FUJ
        طالب ثان,region.two@example.com,دبي,
        طالب ثالث,region.three@example.com,Cairo,
        طالب رابع,region.four@example.com,,المريخ
        CSV;

        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);
        $batch = ImportBatch::create([
            'type' => 'student', 'status' => 'queued', 'file_name' => 'students.csv',
            'file_path' => $file->store('imports/students', 'local'), 'created_by' => $admin->id,
        ]);

        app(StudentImportService::class)->processBatch($batch);
        $batch->refresh();

        $this->assertSame(3, $batch->imported_count);
        $this->assertSame(5, $batch->errors[0]['row']);
        $this->assertArrayHasKey('region', $batch->errors[0]['errors']);

        $regionOf = fn (string $email) => User::where('email', $email)->first()->student->region_id;
        $this->assertSame($this->region('FUJ'), $regionOf('region.one@example.com'));
        $this->assertSame($this->region('DXB'), $regionOf('region.two@example.com'));
        $this->assertNull($regionOf('region.three@example.com'));
    }

    public function test_match_regions_command_backfills_legacy_city_values(): void
    {
        $matched = Student::create(['user_id' => User::factory()->student()->create()->id, 'city' => 'Sharjah']);
        $unmatched = Student::create(['user_id' => User::factory()->student()->create()->id, 'city' => 'Cairo']);

        $this->artisan('students:match-regions', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($matched->fresh()->region_id);

        $this->artisan('students:match-regions')->assertSuccessful();
        $this->assertSame($this->region('SHJ'), $matched->fresh()->region_id);
        $this->assertNull($unmatched->fresh()->region_id);
    }

    private function region(string $code): int
    {
        return Region::where('code', $code)->value('id');
    }

    private function courseFieldId(): int
    {
        return DB::table('course_fields')->insertGetId([
            'code' => 'cf-'.uniqid(), 'name_ar' => 'برمجة', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
