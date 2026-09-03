<?php

namespace Tests\Unit;

use App\Services\NotificationService;
use Tests\TestCase;

/**
 * bulkDelaySeconds() يوزّع أي حجم استيراد على دفعات بحجم
 * bulk_notification_rate_per_minute لكل دقيقة — راجع StudentImportService/
 * TeacherImportService لموقع الاستخدام الفعلي، وسبب وجوده أصلاً في
 * NotificationServiceTest أعلاه (استيراد 1500+ سجل كان يدفع كل بريده
 * الترحيبي للطابور فوراً بلا أي سيطرة).
 */
class NotificationServiceTest extends TestCase
{
    public function test_the_first_batch_of_items_has_no_delay(): void
    {
        config(['queue.bulk_notification_rate_per_minute' => 20]);
        $service = app(NotificationService::class);

        $this->assertSame(0, $service->bulkDelaySeconds(0));
        $this->assertSame(0, $service->bulkDelaySeconds(19));
    }

    public function test_each_subsequent_batch_of_rate_items_adds_one_more_minute(): void
    {
        config(['queue.bulk_notification_rate_per_minute' => 20]);
        $service = app(NotificationService::class);

        $this->assertSame(60, $service->bulkDelaySeconds(20));
        $this->assertSame(60, $service->bulkDelaySeconds(39));
        $this->assertSame(120, $service->bulkDelaySeconds(40));
        // العنصر رقم 1541 (آخر عنصر في استيراد 1542 سجلاً) بمعدل 20/دقيقة
        $this->assertSame(77 * 60, $service->bulkDelaySeconds(1541));
    }

    public function test_a_rate_of_zero_or_negative_is_treated_as_one_per_minute_not_a_division_error(): void
    {
        config(['queue.bulk_notification_rate_per_minute' => 0]);
        $service = app(NotificationService::class);

        $this->assertSame(0, $service->bulkDelaySeconds(0));
        $this->assertSame(60, $service->bulkDelaySeconds(1));
    }
}
