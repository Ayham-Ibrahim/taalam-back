<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\GuestTrialBookingRequest;
use App\Models\Package;
use App\Services\GuestTrialBookingService;
use Illuminate\Http\Request;

/**
 * حجز الباقة المجانية بلا حساب — مساران عامّان بلا auth:sanctum: طلب رمز تحقق على البريد،
 * ثم الحجز بالرمز. القرار (موافقة/رفض) ورابط الانضمام يصلان الزائر بالبريد.
 */
class GuestTrialBookingController extends Controller
{
    public function __construct(private readonly GuestTrialBookingService $guestTrials) {}

    public function sendCode(Request $request, Package $package)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
        ]);

        $this->guestTrials->sendCode($package, $data['email'], $request->ip());

        return $this->success(null, 'أرسلنا رمز التحقق إلى بريدك الإلكتروني');
    }

    public function store(GuestTrialBookingRequest $request, Package $package)
    {
        $booking = $this->guestTrials->book($package, $request->validated());

        return $this->success([
            'reference' => $booking->reference,
            'status' => $booking->status,
            'requested_slots' => $booking->requested_slots,
            'requested_timezone' => $booking->requested_timezone,
        ], 'تم إرسال طلب الحجز للمعلم — سنراسلك على بريدك بالرد', 201);
    }
}
