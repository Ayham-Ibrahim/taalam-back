<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * فحص DNS مجاني (بلا خدمة خارجية) — يرفض دومينات لا وجود لها فعلياً قبل أي
 * محاولة إرسال. الحادثة الحقيقية التي دفعت لهذا: استيراد جماعي فيه أخطاء
 * إملائية في الدومين (t3allam.com بدل t3allem.com، gmail.con...) مرّت كلها
 * كصيغة بريد صحيحة (قاعدة email القياسية تتحقق من الشكل فقط لا من الوجود
 * الفعلي)، فسبّب Hard Bounce مضموناً لكل واحد منها — ما أدى لتعليق حساب
 * Mailtrap تلقائياً (نسبة الارتداد تجاوزت الحد المسموح).
 */
class EmailHasMailExchangeRecord implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = (string) substr(strrchr((string) $value, '@') ?: '', 1);

        if ($domain === '' || ! $this->domainAcceptsMail($domain)) {
            $fail('دومين البريد الإلكتروني لا يستقبل بريداً فعلياً — تحقق من الإملاء.');
        }
    }

    /**
     * MX أولاً (الحالة الطبيعية)، ثم A/AAAA احتياطاً — بعض الدومينات الصغيرة
     * تستقبل البريد مباشرة على عنوانها بلا سجل MX منشور (RFC 5321 §5.1).
     */
    public function domainAcceptsMail(string $domain): bool
    {
        return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
    }
}
