<?php

namespace App\Http\Requests\Booking;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;

class CreateManualBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createManual', Booking::class);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'reason' => ['required', 'string', 'max:500'],
            // إلزامي فقط للباقات الفردية (موعد مستقل لكل جلسة) — تُتحقق ضمنها
            // في BookingService (لا جدول على مستوى الباقة الفردية أصلاً).
            'slots' => ['nullable', 'array', 'min:1'],
            // بلا "after_or_equal:today" لنفس سبب RequestIndividualBookingRequest —
            // المقارنة الصحيحة تتم لاحقاً بتوقيت الطالب الفعلي (BookingService::assertSlotsNotInPast).
            'slots.*.date' => ['required_with:slots', 'date'],
            'slots.*.start_time' => ['required_with:slots', 'date_format:H:i'],
        ];
    }
}
