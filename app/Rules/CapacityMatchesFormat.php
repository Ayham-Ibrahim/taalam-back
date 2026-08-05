<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * individual → النصاب 1 فقط. group → النصاب 2 على الأقل.
 * محمي أيضاً على مستوى قاعدة البيانات عبر chk_packages_capacity كخط دفاع أخير.
 */
class CapacityMatchesFormat implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $format = $this->data['session_format'] ?? null;

        if ($format === 'individual' && (int) $value !== 1) {
            $fail('النصاب في الباقة الفردية يجب أن يكون 1 فقط.');

            return;
        }

        if ($format === 'group' && (int) $value < 2) {
            $fail('النصاب في باقة المجموعة يجب أن يكون 2 على الأقل.');
        }
    }
}
