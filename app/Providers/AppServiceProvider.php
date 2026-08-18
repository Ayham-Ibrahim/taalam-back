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
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

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
        $apiRateLimitingEnabled = $this->apiRateLimitingEnabled();

        Model::preventLazyLoading(! $this->app->environment('production'));
        Model::preventSilentlyDiscardingAttributes(! $this->app->environment('production'));

        foreach ([Curriculum::class, Stage::class, Subject::class, University::class, Major::class, CourseField::class, Language::class] as $model) {
            Gate::policy($model, TaxonomyPolicy::class);
        }

        Event::listen(NotificationSent::class, UpdateNotificationLogStatus::class);

        Package::observe(PackageObserver::class);
        Course::observe(CourseObserver::class);
        Review::observe(ReviewObserver::class);

        // موثّق أعلى حداً من زائر مجهول (يُميَّز بـ IP) — يحمي نقاط الدخول العامة
        // (تسجيل الدخول، بحث السوق) من إساءة الاستخدام دون تقييد المستخدمين الفعليين.
        RateLimiter::for('api', function (Request $request) use ($apiRateLimitingEnabled) {
            if (! $apiRateLimitingEnabled) {
                return Limit::none();
            }

            return $request->user()
                ? Limit::perMinute(120)->by('user:'.$request->user()->id)
                : Limit::perMinute(30)->by('guest:'.$request->ip());
        });

        // 5 محاولات/دقيقة لكل (إيميل + IP) معاً — يمنع التخمين المتكرر بلا التأثير
        // على مستخدم شرعي ينسى كلمة المرور مرة أو اثنتين. راجع routes/api.php (login).
        RateLimiter::for('login', function (Request $request) use ($apiRateLimitingEnabled) {
            if (! $apiRateLimitingEnabled) {
                return Limit::none();
            }

            $key = Str::transliterate(Str::lower((string) $request->input('email'))).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // تسجيل ذاتي — أندر من محاولات الدخول لكن هدفه مختلف: منع إنشاء حسابات
        // وهمية بالجملة (سبام) بدل التخمين. 10/ساعة لكل IP كافية لمستخدم حقيقي.
        RateLimiter::for('register', function (Request $request) use ($apiRateLimitingEnabled) {
            if (! $apiRateLimitingEnabled) {
                return Limit::none();
            }

            return Limit::perHour(10)->by('register:'.$request->ip());
        });

        // رفع ملفات (صورة شخصية، وثيقة توثيق) — عملية مكلفة (تخزين + تحقق أبعاد/نوع)
        // لكل مستخدم موثّق فقط (المسارات كلها خلف auth:sanctum أصلاً)؛ 15/دقيقة يكفي
        // أي استخدام شرعي (إعادة محاولة رفع بعد خطأ) ويمنع استنزاف مساحة التخزين.
        RateLimiter::for('uploads', function (Request $request) use ($apiRateLimitingEnabled) {
            if (! $apiRateLimitingEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(15)->by('uploads:'.$request->user()?->id);
        });

        // تغيير كلمة المرور يتطلب كلمة المرور الحالية أصلاً، لكن هذا سطر دفاع إضافي
        // ضد تخمينها بالقوة الغاشمة عبر نداءات متكررة لنفس الحساب الموثّق.
        RateLimiter::for('password-change', function (Request $request) use ($apiRateLimitingEnabled) {
            if (! $apiRateLimitingEnabled) {
                return Limit::none();
            }

            return Limit::perHour(5)->by('password-change:'.$request->user()?->id);
        });

        // حد أدنى موحّد لكل كلمات المرور في التطبيق (تسجيل ذاتي، حساب ينشئه الأدمن...):
        // 8 أحرف + حرف كبير وصغير + رقم دائماً، وفحص كلمات مرور مسرَّبة (uncompromised)
        // في الإنتاج فقط — يستدعي Have I Been Pwned عبر الشبكة، لا داعي له محلياً/بالاختبارات.
        Password::defaults(function () {
            $rule = Password::min(8)->mixedCase()->numbers();

            return $this->app->environment('production') ? $rule->uncompromised() : $rule;
        });
    }

    private function apiRateLimitingEnabled(): bool
    {
        return (bool) config('app.api_rate_limiting_enabled', $this->app->environment('production'));
    }
}
