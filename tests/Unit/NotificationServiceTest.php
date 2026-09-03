<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * bulkDelaySeconds() يوزّع أي حجم استيراد جماعي عبر ساعات بدل دفعة واحدة
 * فورية — راجع StudentImportService/TeacherImportService لموقع الاستخدام
 * الفعلي، وسبب وجوده أصلاً في NotificationServiceTest أعلاه (استيراد 1500+
 * سجل دفع كل بريده الترحيبي للطابور فوراً، فعالجه الـ worker بأقصى سرعته
 * الفعلية — نمط burst من دومين واحد فعّل حماية Mailtrap تلقائياً وعلّق الحساب).
 */
class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_delay_is_linear_by_the_configured_stagger_seconds(): void
    {
        $this->setStagger(5);
        $service = app(NotificationService::class);

        $this->assertSame(0, $service->bulkDelaySeconds(0));
        $this->assertSame(5, $service->bulkDelaySeconds(1));
        $this->assertSame(50, $service->bulkDelaySeconds(10));
        // العنصر رقم 1541 (آخر عنصر في استيراد 1542 سجلاً) بتأخير 5 ثوانٍ لكل عنصر
        $this->assertSame(1541 * 5, $service->bulkDelaySeconds(1541));
    }

    public function test_falls_back_to_the_default_stagger_when_unconfigured(): void
    {
        $service = app(NotificationService::class);

        // القيمة الافتراضية المزروعة في SettingsSeeder (8 ثوانٍ) عند عدم وجود الإعداد إطلاقاً
        $this->assertSame(8, $service->bulkDelaySeconds(1));
    }

    public function test_a_negative_configured_stagger_is_treated_as_zero_not_a_negative_delay(): void
    {
        $this->setStagger(-5);
        $service = app(NotificationService::class);

        $this->assertSame(0, $service->bulkDelaySeconds(10));
    }

    private function setStagger(int $seconds): void
    {
        Setting::create([
            'key' => 'bulk_notification_stagger_seconds',
            'value' => (string) $seconds,
            'type' => 'integer',
            'group' => 'accounts',
            'label_ar' => 'تأخير الاستيراد الجماعي',
            'is_editable' => true,
        ]);
    }
}
