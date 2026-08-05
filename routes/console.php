<?php

use App\Jobs\ExpireStaleBookingsJob;
use App\Jobs\SendSessionRemindersJob;
use App\Services\RankingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ExpireStaleBookingsJob)->everyMinute();

// إرسال رابط الجلسة تلقائياً للطالب والمعلم قبل موعدها (session_reminder_minutes_before).
Schedule::job(new SendSessionRemindersJob)->everyMinute();

// إعادة حساب ranking_score لكل معلم موثّق — موزّعة على طابور واحد لكل معلم
// كي لا تُحسَب مباشرة عند القراءة (متطلب أداء صريح).
Schedule::call(fn () => app(RankingService::class)->recalculateAllVerified())->hourly();
