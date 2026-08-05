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
            // إلزاميان فقط للباقات الفردية — تُتحقق ضمنها في BookingService (لا جدول على مستوى الباقة الفردية)
            'date' => ['nullable', 'date', 'after_or_equal:today'],
            'start_time' => ['nullable', 'date_format:H:i'],
        ];
    }
}
