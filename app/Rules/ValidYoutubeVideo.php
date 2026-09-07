<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * يقبل رابط يوتيوب بأي صيغة معروفة (watch؟v=، youtu.be/، shorts/، embed/) أو
 * معرّف الفيديو نفسه مباشرة (11 حرفاً)، ويرفض أي شيء آخر. extractId() هي
 * مصدر الحقيقة الوحيد للاستخراج — تُستدعى مرة في prepareForValidation() على
 * مستوى الطلب لتطبيع القيمة قبل الوصول لهذه القاعدة (فتُخزَّن دائماً كمعرّف
 * نظيف لا كرابط خام)، ومرة أخرى هنا للتأكد النهائي.
 */
class ValidYoutubeVideo implements ValidationRule
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    private const URL_PATTERN = '~(?:youtube\.com/(?:watch\?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})~i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || self::extractId($value) === null) {
            $fail('رابط يوتيوب غير صالح — تحقق من نسخ الرابط كاملاً.');
        }
    }

    public static function extractId(string $value): ?string
    {
        $value = trim($value);

        if (preg_match(self::ID_PATTERN, $value)) {
            return $value;
        }

        if (preg_match(self::URL_PATTERN, $value, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
