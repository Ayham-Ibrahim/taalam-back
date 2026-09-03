<?php

namespace Tests\Unit;

use App\Rules\NotSpreadsheetFormula;
use Tests\TestCase;

/**
 * الحادثة الحقيقية: 1523 من 1542 طالب مستورَد كان عمود name عندهم القيمة
 * الحرفية لصيغة إكسل غير محسوبة (=PROPER(C20&" "&D20)) — راجع الرسالة أعلى
 * الكلاس نفسه لتفاصيل الحادثة.
 */
class NotSpreadsheetFormulaTest extends TestCase
{
    /** @dataProvider dangerousValues */
    public function test_it_rejects_values_starting_with_a_dangerous_prefix(string $value): void
    {
        $rule = new NotSpreadsheetFormula;
        $failed = false;

        $rule->validate('name', $value, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, "expected \"{$value}\" to be rejected");
    }

    public static function dangerousValues(): array
    {
        return [
            'excel formula as text' => ['=PROPER(C20&" "&D20)'],
            'plus prefix' => ['+1+1'],
            'minus prefix' => ['-2+3'],
            'at prefix' => ['@SUM(A1:A2)'],
            'leading whitespace does not bypass the check' => ['   =cmd|/c calc'],
        ];
    }

    /** @dataProvider legitimateValues */
    public function test_it_accepts_ordinary_names(string $value): void
    {
        $rule = new NotSpreadsheetFormula;
        $failed = false;

        $rule->validate('name', $value, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, "expected \"{$value}\" to be accepted");
    }

    public static function legitimateValues(): array
    {
        return [
            'arabic name' => ['أحمد محمد'],
            'english name' => ['John O\'Brien'],
            'empty string' => [''],
        ];
    }
}
