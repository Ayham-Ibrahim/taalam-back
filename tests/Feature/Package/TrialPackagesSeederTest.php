<?php

namespace Tests\Feature\Package;

use App\Models\AvailabilitySlot;
use App\Models\Package;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\TrialPackagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialPackagesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_free_trial_package_for_each_verified_school_teacher_with_days(): void
    {
        $teacher = $this->createTeacher('school', 'verified', days: [1, 3]);

        $this->seed(TrialPackagesSeeder::class);

        $package = Package::where('teacher_id', $teacher->id)->sole();
        $this->assertTrue($package->is_trial);
        $this->assertSame('active', $package->status);
        $this->assertSame('individual', $package->session_format);
        $this->assertSame(1, $package->sessions_count);
        $this->assertNull($package->subject_id);
        $this->assertEquals(0, (float) $package->student_price);
        $this->assertSame([1, 3], $package->schedules()->orderBy('day_of_week')->pluck('day_of_week')->all());
        $this->assertTrue($package->isBookable());
    }

    public function test_it_skips_other_types_unverified_teachers_and_teachers_without_days(): void
    {
        $this->createTeacher('university', 'verified', days: [1]);
        $this->createTeacher('school', 'active_unverified', days: [1]);
        $this->createTeacher('school', 'suspended', days: [1]);
        $this->createTeacher('school', 'verified', days: []);

        $this->seed(TrialPackagesSeeder::class);

        $this->assertDatabaseCount('packages', 0);
    }

    /** إعادة التشغيل لا تكرر الباقة — وتضيف من حدد أيامه بعد التشغيل الأول */
    public function test_running_it_again_does_not_duplicate_and_picks_up_teachers_who_added_days(): void
    {
        $withDays = $this->createTeacher('school', 'verified', days: [2]);
        $later = $this->createTeacher('school', 'verified', days: []);

        $this->seed(TrialPackagesSeeder::class);
        AvailabilitySlot::create(['teacher_id' => $later->id, 'day_of_week' => 4]);
        $this->seed(TrialPackagesSeeder::class);

        $this->assertSame(1, Package::where('teacher_id', $withDays->id)->count());
        $this->assertSame(1, Package::where('teacher_id', $later->id)->where('is_trial', true)->count());
    }

    /** أيام باقة التجربة الموجودة تُطابَق مع أيام التوفّر الحالية عند إعادة التشغيل */
    public function test_running_it_again_syncs_existing_trial_package_days_with_availability(): void
    {
        $teacher = $this->createTeacher('school', 'verified', days: [1, 3]);
        $this->seed(TrialPackagesSeeder::class);

        AvailabilitySlot::where('teacher_id', $teacher->id)->where('day_of_week', 1)->delete();
        AvailabilitySlot::create(['teacher_id' => $teacher->id, 'day_of_week' => 5]);
        $this->seed(TrialPackagesSeeder::class);

        $package = Package::where('teacher_id', $teacher->id)->sole();
        $this->assertSame([3, 5], $package->schedules()->orderBy('day_of_week')->pluck('day_of_week')->all());
    }

    /** إضافة/حذف يوم توفّر عبر الـ API يحدّث أيام باقة التجربة فوراً — ولا يلمس الباقات العادية */
    public function test_changing_availability_slots_updates_trial_package_days_immediately(): void
    {
        $teacher = $this->createTeacher('school', 'verified', days: [1, 3]);
        $this->seed(TrialPackagesSeeder::class);
        $trial = Package::where('teacher_id', $teacher->id)->sole();

        $regular = $trial->replicate()->fill(['is_trial' => false, 'title' => 'باقة عادية', 'teacher_price' => 100, 'student_price' => 120, 'platform_margin_percent' => 20, 'platform_revenue' => 20]);
        $regular->save();
        $regular->schedules()->create(['day_of_week' => 1]);

        $this->actingAs($teacher->user)
            ->postJson("/api/teachers/{$teacher->id}/availability-slots", ['day_of_week' => 6])
            ->assertSuccessful();
        $this->assertSame([1, 3, 6], $trial->schedules()->orderBy('day_of_week')->pluck('day_of_week')->all());

        $slot = AvailabilitySlot::where('teacher_id', $teacher->id)->where('day_of_week', 1)->sole();
        $this->actingAs($teacher->user)
            ->deleteJson("/api/teachers/{$teacher->id}/availability-slots/{$slot->id}")
            ->assertSuccessful();
        $this->assertSame([3, 6], $trial->schedules()->orderBy('day_of_week')->pluck('day_of_week')->all());
        $this->assertSame([1], $regular->schedules()->pluck('day_of_week')->all());
    }

    private function createTeacher(string $type, string $status, array $days): Teacher
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => $type, 'status' => $status]);

        foreach ($days as $day) {
            AvailabilitySlot::create(['teacher_id' => $teacher->id, 'day_of_week' => $day]);
        }

        return $teacher;
    }
}
