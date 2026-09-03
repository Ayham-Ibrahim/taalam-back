<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * الحادثة الحقيقية التي دفعت لهذا: 1523 من 1542 طالب مستورَد (98.8٪ من
 * الدفعة!) كان عمود name عندهم القيمة الحرفية لصيغة إكسل غير محسوبة، مثل
 * =PROPER(C20&" "&D20) — الملف المصدر صُدِّر لـ CSV بصيغ كنص حرفي بدل نتيجة
 * حسابها. لا فحص شكلي كان يرفض هذا (أي سلسلة غير فارغة كانت تُقبل كاسم).
 * نفس نمط الرموز المعروف بـ CSV/Formula Injection (CWE-1236) — يحمي أيضاً
 * من أي محاولة حقن حقيقية لاحقاً عبر فتح الملف المُصدَّر ببرنامج جداول بيانات.
 *
 * لا يُطبَّق هذا على حقول الهاتف عمداً — بادئة "+" الدولية فيها شرعية تماماً.
 */
class NotSpreadsheetFormula implements ValidationRule
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $trimmed = ltrim((string) $value);

        if ($trimmed !== '' && in_array($trimmed[0], self::DANGEROUS_PREFIXES, true)) {
            $fail('القيمة تبدو صيغة جدول بيانات غير محسوبة، تحقق من تصدير الملف.');
        }
    }
}
