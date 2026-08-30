<?php

namespace App\Jobs;

use App\Services\BookingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ينظّف الحجوزات/التسجيلات التي انتهت مهلة دفعها المؤقتة (booking_payment_hold_minutes)
 * دون إتمام الدفع. مجدول ليعمل دورياً (routes/console.php).
 */
class ExpireStaleBookingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(BookingService $bookingService): void
    {
        $bookingService->expireStalePendingTeacherConfirmations();
        $bookingService->expireStalePendingPayments();
    }
}
