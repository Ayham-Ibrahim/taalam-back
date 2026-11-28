<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * يمنع تبادل وسائل التواصل داخل المحادثة (أرقام هواتف، بريد، روابط) — المحادثة لا تُغلق
 * أبداً، فهذه الفلترة خط الحماية الأساسي من الاتفاق خارج المنصة. تُطبَّق على الإرسال
 * والتعديل معاً. الأرقام العربية/الفارسية تُوحَّد للإنجليزية أولاً، والفواصل بين الأرقام
 * (مسافات، شرطات، نقاط، أقواس) لا تُخفي الرقم. كتابة الأرقام بالحروف خارج نطاقها عمداً —
 * يغطيها زر الإبلاغ.
 */
class NoContactInfo implements ValidationRule
{
    /** 8 أرقام فأكثر في تسلسل واحد: أقصر رقم هاتف عملي مع رمز المنطقة/الدولة */
    private const PHONE_PATTERN = '/(?:\+?\d[\s\-\.\(\)\/_]{0,3}){7,}\d/u';

    /**
     * تُزال قبل فحص الهاتف — كتابات شائعة في منصة تعليمية كانت تُرفض كأنها رقم (8 أرقام
     * بفواصل): تاريخ يوم/شهر/سنة أو سنة/شهر/يوم، وعام دراسي (2024/2025). السنة بأربعة
     * أرقام تبدأ بـ 19/20 واليوم والشهر ضمن مداهما، كي لا يمر رقم هاتف مقسّم كأنه تاريخ.
     */
    private const DATE_PATTERNS = [
        '/(?<!\d)(?:0?[1-9]|[12]\d|3[01])\s?[\/\-\.]\s?(?:0?[1-9]|1[0-2])\s?[\/\-\.]\s?(?:19|20)\d{2}(?!\d)/u',
        '/(?<!\d)(?:19|20)\d{2}\s?[\/\-\.]\s?(?:0?[1-9]|1[0-2])\s?[\/\-\.]\s?(?:0?[1-9]|[12]\d|3[01])(?!\d)/u',
        '/(?<!\d)(?:19|20)\d{2}\s?[\/\-]\s?(?:19|20)\d{2}(?!\d)/u',
    ];

    private const EMAIL_PATTERN = '/[a-z0-9._%+\-]+\s*@\s*[a-z0-9.\-]+\.[a-z]{2,}/iu';

    /**
     * رابط صريح، أو www، أو نطاق بامتداد معروف (wa.me، t.me، example.com). قائمة امتدادات
     * محددة بدل أي "كلمة.كلمة" — وإلا يُرفض اسم ملف عادي يذكره الطالب (main.py، notes.pdf).
     */
    private const LINK_PATTERNS = [
        '/\b(?:https?|ftp):\/\//iu',
        '/\bwww\./iu',
        '/\b[a-z0-9][a-z0-9\-]*\.(?:com|net|org|me|io|co|info|biz|app|dev|ly|link|gg|tv|xyz|online|site|store|live|chat|sy|ae|sa|tr|eg|jo|lb|iq|uk|de|us|ru)\b/iu',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (self::containsContactInfo($value)) {
            $fail('لا يُسمح بإرسال أرقام الهواتف أو البريد الإلكتروني أو الروابط الخارجية داخل المحادثة.');
        }
    }

    public static function containsContactInfo(string $text): bool
    {
        $text = self::normalizeDigits($text);

        $withoutDates = preg_replace(self::DATE_PATTERNS, ' ', $text);

        if (preg_match(self::PHONE_PATTERN, $withoutDates) || preg_match(self::EMAIL_PATTERN, $text)) {
            return true;
        }

        foreach (self::LINK_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
