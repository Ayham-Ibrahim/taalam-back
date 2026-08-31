<?php

use App\Jobs\CompleteEndedSessionsJob;
use App\Jobs\ExpireStaleBookingsJob;
use App\Jobs\FetchSessionRecordingsJob;
use App\Jobs\SendSessionRemindersJob;
use App\Services\RankingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ExpireStaleBookingsJob)->everyMinute();

// الحالة الوحيدة التي تجعل جلسة "منتهية" فعلياً لبقية النظام (مستحقات مالية،
// تقييمات، منع تغيير موعد) — بلا هذا الـ job تبقى كل الجلسات scheduled للأبد.
Schedule::job(new CompleteEndedSessionsJob)->everyMinute();

// إرسال رابط الجلسة تلقائياً للطالب والمعلم قبل موعدها (session_reminder_minutes_before).
Schedule::job(new SendSessionRemindersJob)->everyMinute();

// جلب روابط تسجيلات الجلسات المنتهية من BBB فور توفّرها (تستغرق معالجتها وقتاً على BBB نفسه).
Schedule::job(new FetchSessionRecordingsJob)->everyFifteenMinutes();

// إعادة حساب ranking_score لكل معلم موثّق — موزّعة على طابور واحد لكل معلم
// كي لا تُحسَب مباشرة عند القراءة (متطلب أداء صريح).
Schedule::call(fn () => app(RankingService::class)->recalculateAllVerified())->hourly();
