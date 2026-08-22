<?php

namespace App\Http\Requests\Booking;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;

/**
 * موعد مستقل لكل جلسة (وليس تاريخاً/وقتاً واحداً يتكرر أسبوعياً على كل
 * الجلسات) — slots مصفوفة يتحقق BookingService لاحقاً أن عدد عناصرها يساوي
 * sessions_count الخاصة بالباقة بالضبط (لا يمكن التحقق من ذلك هنا، الباقة
 * تصل كـ route model binding بعد فحص هذا الـ Request).
 */
class RequestIndividualBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Booking::class);
    }

    public function rules(): array
    {
        return [
            'slots' => ['required', 'array', 'min:1'],
            // لا "after_or_equal:today" هنا عمداً — تلك القاعدة تقارن بتاريخ
            // اليوم بتوقيت السيرفر (UTC)، بينما "اليوم" الحقيقي للطالب يختلف
            // عنه حسب منطقته الزمنية (قد يكون تاريخ اليوم عند الطالب "أمس" أو
            // "غداً" بتوقيت UTC وقت الطلب) فيُرفض تاريخ صالح تماماً بالخطأ.
            // الفحص الملزم الفعلي (BookingService::assertSlotsNotInPast) يتم
            // على اللحظة الكاملة بعد تحويلها لتوقيت الطالب الفعلي.
            'slots.*.date' => ['required', 'date'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
        ];
    }
}
