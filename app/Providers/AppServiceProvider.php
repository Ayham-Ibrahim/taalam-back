<?php

namespace App\Providers;

use App\Listeners\UpdateNotificationLogStatus;
use App\Models\Course;
use App\Models\CourseField;
use App\Models\Curriculum;
use App\Models\Language;
use App\Models\Major;
use App\Models\Package;
use App\Models\Review;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\University;
use App\Observers\CourseObserver;
use App\Observers\PackageObserver;
use App\Observers\ReviewObserver;
use App\Policies\TaxonomyPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        foreach ([Curriculum::class, Stage::class, Subject::class, University::class, Major::class, CourseField::class, Language::class] as $model) {
            Gate::policy($model, TaxonomyPolicy::class);
        }

        Event::listen(NotificationSent::class, UpdateNotificationLogStatus::class);

        Package::observe(PackageObserver::class);
        Course::observe(CourseObserver::class);
        Review::observe(ReviewObserver::class);

        // موثّق أعلى حداً من زائر مجهول (يُميَّز بـ IP) — يحمي نقاط الدخول العامة
        // (تسجيل الدخول، بحث السوق) من إساءة الاستخدام دون تقييد المستخدمين الفعليين.
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by('user:'.$request->user()->id)
                : Limit::perMinute(30)->by('guest:'.$request->ip());
        });
    }
}
