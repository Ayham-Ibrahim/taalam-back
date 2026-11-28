<?php

use App\Jobs\CompleteEndedSessionsJob;
use App\Jobs\CreateMissingBbbRoomsJob;
use App\Jobs\EscalateRescheduleRequestsJob;
use App\Jobs\ExpireStaleBookingsJob;
use App\Jobs\FetchSessionRecordingsJob;
use App\Jobs\PrewarmBbbRoomsJob;
use App\Jobs\SendSessionQualityRatingRequestsJob;
use App\Jobs\SendSubscriptionEndedEmailsJob;
use App\Jobs\SendSubscriptionEndingSoonNotificationsJob;
use App\Jobs\SendSessionRemindersJob;
use App\Models\Student;
use App\Services\RankingService;
use App\Support\RegionMatcher;
use Database\Seeders\TrialPackagesSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// مرة واحدة عند إطلاق المحادثة (ويمكن إعادته بأمان): المحادثة تُفتح عند حدث التأكيد فقط،
// فالعلاقات المؤكدة قبل الإطلاق تبقى بلا محادثة. يُنشئها لكل زوج (طالب، معلم/داعم) بينهما
// حجز أو تسجيل كورس أو باقة دعم أُكِّدت يوماً — بلا رسائل نظام. insertOrIgnore على القيد الفريد.
Artisan::command('chat:backfill-conversations {--dry-run : عرض العدد دون حفظ}', function () {
    $bookingPairs = DB::table('bookings')
        ->join('students', 'students.id', '=', 'bookings.student_id')
        ->join('teachers', 'teachers.id', '=', 'bookings.teacher_id')
        ->where(fn ($q) => $q->whereNotNull('bookings.confirmed_at')->orWhereIn('bookings.status', ['confirmed', 'active', 'completed']))
        ->select('students.user_id as student_user_id', 'teachers.user_id as provider_user_id');

    $enrollmentPairs = DB::table('enrollments')
        ->join('students', 'students.id', '=', 'enrollments.student_id')
        ->join('courses', 'courses.id', '=', 'enrollments.course_id')
        ->join('teachers', 'teachers.id', '=', 'courses.teacher_id')
        ->where(fn ($q) => $q->whereNotNull('enrollments.confirmed_at')->orWhereIn('enrollments.status', ['confirmed', 'in_progress', 'completed']))
        ->select('students.user_id as student_user_id', 'teachers.user_id as provider_user_id');

    $supportPairs = DB::table('support_package_purchases')
        ->join('students', 'students.id', '=', 'support_package_purchases.student_id')
        ->join('academic_supporters', 'academic_supporters.id', '=', 'support_package_purchases.academic_supporter_id')
        ->whereNotNull('support_package_purchases.confirmed_at')
        ->select('students.user_id as student_user_id', 'academic_supporters.user_id as provider_user_id');

    $pairs = $bookingPairs->union($enrollmentPairs)->union($supportPairs)->get()
        ->reject(fn ($pair) => $pair->student_user_id === $pair->provider_user_id);

    if ($this->option('dry-run')) {
        $this->info("أزواج مؤهلة: {$pairs->count()} (dry-run — لم يُحفظ شيء)");

        return;
    }

    $now = now();
    $created = 0;
    foreach ($pairs->chunk(500) as $chunk) {
        $created += DB::table('conversations')->insertOrIgnore($chunk->map(fn ($pair) => [
            'student_user_id' => $pair->student_user_id,
            'provider_user_id' => $pair->provider_user_id,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());
    }

    $this->info("أزواج مؤهلة: {$pairs->count()}، محادثات جديدة: {$created}");
})->purpose('إنشاء محادثات للعلاقات المؤكدة السابقة لإطلاق نظام المحادثة');

// مرة واحدة بعد إضافة جدولي countries/regions (ويمكن إعادته بأمان): يطابق students.city
// و students.country النصّيين القديمين مع الولايات/الدول للطلاب بلا موقع، ويطبع القيم غير
// المطابقة لمراجعتها يدوياً. الولاية تضبط دولتها تلقائياً (Student::saving).
Artisan::command('students:match-regions {--dry-run : عرض النتيجة دون حفظ}', function (RegionMatcher $matcher) {
    $students = Student::query()
        ->whereNull('country_id')
        ->where(fn ($q) => $q->whereNotNull('city')->orWhereNotNull('country'))
        ->get(['id', 'city', 'country', 'country_id', 'region_id']);
    $matched = 0;
    $unmatched = [];

    foreach ($students as $student) {
        $regionId = $matcher->match($student->city);
        $countryId = $regionId ? null : $matcher->matchCountry($student->country);

        if ($regionId === null && $countryId === null) {
            $key = trim(($student->country ?? '').' / '.($student->city ?? ''), ' /');
            $unmatched[$key] = ($unmatched[$key] ?? 0) + 1;

            continue;
        }

        if (! $this->option('dry-run')) {
            $student->update($regionId ? ['region_id' => $regionId] : ['country_id' => $countryId]);
        }
        $matched++;
    }

    $this->info("طوبق {$matched} من {$students->count()} طالب".($this->option('dry-run') ? ' (تجربة — لم يُحفظ شيء)' : ''));

    if ($unmatched) {
        arsort($unmatched);
        $this->warn('قيم لم تُطابَق:');
        $this->table(['country / city', 'عدد الطلاب'], collect($unmatched)->map(fn ($count, $value) => [$value, $count])->values()->all());
    }
})->purpose('Match legacy students.city/country values to regions and countries');

Schedule::job(new ExpireStaleBookingsJob)->everyMinute();

// الحالة الوحيدة التي تجعل جلسة "منتهية" فعلياً لبقية النظام (مستحقات مالية،
// تقييمات، منع تغيير موعد) — بلا هذا الـ job تبقى كل الجلسات scheduled للأبد.
Schedule::job(new CompleteEndedSessionsJob)->everyMinute();

// طلب تغيير موعد لم يرد عليه الطرف الآخر قبل مهلته — يُصعَّد للأدمن.
Schedule::job(new EscalateRescheduleRequestsJob)->everyMinute();

// إرسال رابط الجلسة تلقائياً للطالب والمعلم قبل موعدها (session_reminder_minutes_before).
Schedule::job(new SendSessionRemindersJob)->everyMinute();

// عند بدء الجلسة ودخول المعلم والطالب: رابط تقييم جودتها للطالب + إشعار شكر بنفس الرابط للمعلم.
Schedule::job(new SendSessionQualityRatingRequestsJob)->everyMinute();

// انتهت كل جلسات اشتراك الطالب بباقة: بريد لحسابه يدعوه للتجديد مع رابط بروفايل المعلم.
Schedule::job(new SendSubscriptionEndedEmailsJob)->everyFiveMinutes();

// بقيت جلسة واحدة في اشتراك الطالب: إشعار لكل الأدمنز (جرس + بريد) للتواصل معه للتجديد.
Schedule::job(new SendSubscriptionEndingSoonNotificationsJob)->everyFiveMinutes();

// شبكة أمان: إنشاء غرفة BBB لأي جلسة مدفوعة قادمة بقيت بلا رابط انضمام لأي سبب.
Schedule::job(new CreateMissingBbbRoomsJob)->everyFiveMinutes();

// تجهيز غرف BBB للجلسات القريبة/الجارية كي تكون جاهزة لحظة الضغط على "انضمام".
Schedule::job(new PrewarmBbbRoomsJob)->everyMinute();

// جلب روابط تسجيلات الجلسات المنتهية من BBB فور توفّرها (تستغرق معالجتها وقتاً على BBB نفسه).
Schedule::job(new FetchSessionRecordingsJob)->everyFifteenMinutes();

// إعادة حساب ranking_score لكل معلم موثّق — موزّعة على طابور واحد لكل معلم
// كي لا تُحسَب مباشرة عند القراءة (متطلب أداء صريح).
Schedule::call(fn () => app(RankingService::class)->recalculateAllVerified())->hourly();

// جلسة تعريفية مجانية لكل معلم مدرسي موثّق جديد + مطابقة أيام باقات التجربة مع أيام توفّر معلميها.
Schedule::command('db:seed', ['--class' => TrialPackagesSeeder::class, '--force' => true])
    ->everySixHours()
    ->withoutOverlapping();
