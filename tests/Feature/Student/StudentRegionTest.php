<?php

namespace Tests\Feature\Student;

use App\Models\Country;
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
 * موقع الطالب مستويان (دولة ← ولاية) — يُجمَع من التسجيل والملف والاستيراد كقوائم
 * ثابتة بدل city/country النصّيين، كي تُبنى عليه تقارير التسويق (موقع × منهاج).
 */
class StudentRegionTest extends TestCase
{
    use RefreshDatabase;

    public function test_countries_and_emirates_are_seeded_and_listed_publicly(): void
    {
        $this->getJson('/api/taxonomy/countries')->assertStatus(200)->assertJsonPath('data.0.code', 'AE');

        $regions = $this->getJson('/api/taxonomy/regions')->assertStatus(200)->assertJsonCount(7, 'data');
        $this->assertSame('AUH', $regions->json('data.0.code'));
        $this->assertSame($this->country('AE'), $regions->json('data.0.country_id'));
    }

    public function test_admin_can_add_a_country_and_its_regions(): void
    {
        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $jordan = $this->as($token)->postJson('/api/taxonomy/countries', ['code' => 'JO', 'name_ar' => 'الأردن'])
            ->assertStatus(201)->json('data.id');

        $this->as($token)->postJson('/api/taxonomy/regions', ['country_id' => $jordan, 'code' => 'AMM', 'name_ar' => 'عمّان'])
            ->assertStatus(201);
        $this->as($token)->postJson('/api/taxonomy/regions', ['country_id' => $jordan, 'code' => 'AMM', 'name_ar' => 'مكرر'])
            ->assertStatus(422)->assertJsonValidationErrors(['code']);
        // نفس الرمز في دولة أخرى مقبول
        $this->as($token)->postJson('/api/taxonomy/regions', ['country_id' => $this->country('SA'), 'code' => 'AMM', 'name_ar' => 'منطقة'])
            ->assertStatus(201);
        $this->as($token)->postJson('/api/taxonomy/regions', ['code' => 'X', 'name_ar' => 'بلا دولة'])
            ->assertStatus(422)->assertJsonValidationErrors(['country_id']);
    }

    public function test_student_registration_stores_country_and_region(): void
    {
        $dubai = $this->region('DXB');

        $this->postJson('/api/auth/register/student', $this->registration('reg', ['country_id' => $this->country('AE'), 'region_id' => $dubai]))
            ->assertStatus(201)
            ->assertJsonPath('data.user.student.country_id', $this->country('AE'))
            ->assertJsonPath('data.user.student.region_id', $dubai);
    }

    public function test_country_without_regions_can_be_chosen_alone(): void
    {
        $this->postJson('/api/auth/register/student', $this->registration('sa', ['country_id' => $this->country('SA')]))
            ->assertStatus(201);

        $student = User::where('email', 'region.sa@example.com')->first()->student;
        $this->assertSame($this->country('SA'), $student->country_id);
        $this->assertNull($student->region_id);
    }

    public function test_region_must_belong_to_the_chosen_country(): void
    {
        $this->postJson('/api/auth/register/student', $this->registration('bad', ['country_id' => $this->country('SA'), 'region_id' => $this->region('DXB')]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['region_id']);
    }

    public function test_region_alone_sets_its_country(): void
    {
        $this->postJson('/api/auth/register/student', $this->registration('only', ['region_id' => $this->region('SHJ')]))
            ->assertStatus(201);

        $this->assertSame($this->country('AE'), User::where('email', 'region.only@example.com')->first()->student->country_id);
    }

    public function test_changing_country_drops_a_region_of_the_old_country(): void
    {
        $student = Student::create(['user_id' => User::factory()->student()->create()->id, 'region_id' => $this->region('DXB')]);
        $this->assertSame($this->country('AE'), $student->country_id);

        $student->update(['country_id' => $this->country('SA')]);

        $this->assertNull($student->fresh()->region_id);
        $this->assertSame($this->country('SA'), $student->fresh()->country_id);
    }

    public function test_student_can_set_location_from_profile_and_admin_can_filter_by_it(): void
    {
        $sharjah = $this->region('SHJ');
        $user = User::factory()->student()->create();
        $student = Student::create(['user_id' => $user->id, 'education_type' => 'training']);
        Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => 'training', 'country_id' => $this->country('SA')]);

        $this->as($user->createToken('t')->plainTextToken)->putJson("/api/students/{$student->id}", [
            'education_type' => 'training',
            'course_field_id' => $this->courseFieldId(),
            'level' => 'beginner',
            'country_id' => $this->country('AE'),
            'region_id' => $sharjah,
        ])->assertStatus(200)->assertJsonPath('data.region_id', $sharjah);

        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->as($adminToken)->getJson("/api/students?region_id={$sharjah}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('data.0.country', 'الإمارات')
            ->assertJsonPath('data.0.region', 'الشارقة');

        $this->as($adminToken)->getJson('/api/students?country_id='.$this->country('SA'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_student_can_set_only_their_location(): void
    {
        $user = User::factory()->student()->create();
        $student = Student::create(['user_id' => $user->id, 'education_type' => 'school']);
        $token = $user->createToken('t')->plainTextToken;

        $this->as($token)->putJson('/api/me/location', ['region_id' => $this->region('AUH')])
            ->assertStatus(422)->assertJsonValidationErrors(['country_id']);

        $this->as($token)->putJson('/api/me/location', ['country_id' => $this->country('AE'), 'region_id' => $this->region('AUH')])
            ->assertStatus(200)
            ->assertJsonPath('data.region_id', $this->region('AUH'));

        $this->assertSame($this->region('AUH'), $student->fresh()->region_id);
        $this->assertSame('school', $student->fresh()->education_type);
    }

    public function test_non_student_cannot_set_a_location(): void
    {
        $token = User::factory()->teacher()->create()->createToken('t')->plainTextToken;

        $this->as($token)->putJson('/api/me/location', ['country_id' => $this->country('AE')])->assertStatus(403);
    }

    public function test_sibling_inherits_the_primary_location_unless_given_one(): void
    {
        $ajman = $this->region('AJM');
        $primary = User::factory()->student()->create();
        Student::create(['user_id' => $primary->id, 'education_type' => 'school', 'region_id' => $ajman]);
        $token = $primary->createToken('t')->plainTextToken;

        $payload = ['education_type' => 'training', 'course_field_id' => $this->courseFieldId(), 'level' => 'beginner'];

        $this->as($token)->postJson('/api/me/siblings', ['name' => 'سارة', ...$payload])->assertStatus(201);
        $this->as($token)->postJson('/api/me/siblings', ['name' => 'ليلى', 'country_id' => $this->country('SA'), ...$payload])->assertStatus(201);

        $siblings = User::where('primary_user_id', $primary->id)->with('student')->orderBy('id')->get();
        $this->assertSame($ajman, $siblings[0]->student->region_id);
        $this->assertSame($this->country('AE'), $siblings[0]->student->country_id);
        $this->assertSame($this->country('SA'), $siblings[1]->student->country_id);
        $this->assertNull($siblings[1]->student->region_id);
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

        $this->assertSame($this->country('AE'), $matcher->matchCountry('UAE'));
        $this->assertSame($this->country('AE'), $matcher->matchCountry('الإمارات العربية المتحدة'));
        $this->assertSame($this->country('SA'), $matcher->matchCountry('KSA'));
        $this->assertNull($matcher->matchCountry('Narnia'));
    }

    public function test_import_resolves_region_column_or_falls_back_to_city_and_country(): void
    {
        Notification::fake();
        Storage::fake('local');
        $admin = User::factory()->admin()->create();

        $csv = <<<'CSV'
        name,email,city,country,region
        طالب أول,region.one@example.com,,,FUJ
        طالب ثان,region.two@example.com,دبي,,
        طالب ثالث,region.three@example.com,Riyadh,Saudi Arabia,
        طالب رابع,region.four@example.com,,,المريخ
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

        $studentOf = fn (string $email) => User::where('email', $email)->first()->student;
        $this->assertSame($this->region('FUJ'), $studentOf('region.one@example.com')->region_id);
        $this->assertSame($this->country('AE'), $studentOf('region.one@example.com')->country_id);
        $this->assertSame($this->region('DXB'), $studentOf('region.two@example.com')->region_id);
        $this->assertNull($studentOf('region.three@example.com')->region_id);
        $this->assertSame($this->country('SA'), $studentOf('region.three@example.com')->country_id);
    }

    public function test_match_regions_command_backfills_legacy_city_and_country_values(): void
    {
        $byCity = Student::create(['user_id' => User::factory()->student()->create()->id, 'city' => 'Sharjah']);
        $byCountry = Student::create(['user_id' => User::factory()->student()->create()->id, 'city' => 'Jeddah', 'country' => 'KSA']);
        $unmatched = Student::create(['user_id' => User::factory()->student()->create()->id, 'city' => 'Cairo']);

        $this->artisan('students:match-regions', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($byCity->fresh()->region_id);

        $this->artisan('students:match-regions')->assertSuccessful();
        $this->assertSame($this->region('SHJ'), $byCity->fresh()->region_id);
        $this->assertSame($this->country('AE'), $byCity->fresh()->country_id);
        $this->assertSame($this->country('SA'), $byCountry->fresh()->country_id);
        $this->assertNull($unmatched->fresh()->country_id);
    }

    private function region(string $code): int
    {
        return Region::where('code', $code)->where('country_id', $this->country('AE'))->value('id');
    }

    private function country(string $code): int
    {
        return Country::where('code', $code)->value('id');
    }

    private function registration(string $suffix, array $location): array
    {
        return [
            'name' => 'أحمد',
            'email' => "region.{$suffix}@example.com",
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_policy' => true,
            'education_type' => 'training',
            'course_field_id' => $this->courseFieldId(),
            'level' => 'beginner',
            ...$location,
        ];
    }

    private function courseFieldId(): int
    {
        return DB::table('course_fields')->insertGetId([
            'code' => 'cf-'.uniqid(), 'name_ar' => 'برمجة', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
