<?php

namespace Tests\Unit;

use App\Rules\EmailHasMailExchangeRecord;
use Tests\TestCase;

/**
 * فحص DNS حقيقي (لا محاكاة) — example.com محجوز رسمياً من IANA لأغراض
 * التوثيق ويبقى دائماً قابلاً للحل (له سجلا MX وA فعلاً)، ولهذا يُستخدم في
 * كل بقية اختبارات المشروع كنطاق بريد آمن؛ هذا الاختبار يتحقق من ذلك صراحةً
 * قبل أي اعتماد ضمني عليه في StudentImportTest/TeacherImportTest.
 */
class EmailHasMailExchangeRecordTest extends TestCase
{
    public function test_a_domain_used_throughout_the_test_suite_passes(): void
    {
        $rule = new EmailHasMailExchangeRecord;

        $this->assertTrue($rule->domainAcceptsMail('example.com'));
    }

    public function test_a_nonexistent_domain_fails(): void
    {
        $rule = new EmailHasMailExchangeRecord;

        $this->assertFalse($rule->domainAcceptsMail('this-domain-certainly-does-not-exist-98765.invalid'));
    }

    /**
     * الحادثة الحقيقية: خطأ إملائي في دومين الشركة نفسه (t3allam.com بدل
     * t3allem.com) مرّ كصيغة بريد صحيحة (قاعدة email القياسية تتحقق من
     * الشكل فقط) فسبّب Hard Bounce مضموناً.
     */
    public function test_it_rejects_an_email_with_a_nonexistent_domain(): void
    {
        $rule = new EmailHasMailExchangeRecord;
        $failed = false;

        $rule->validate('email', 'someone@this-domain-certainly-does-not-exist-98765.invalid', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);
    }

    public function test_it_accepts_an_email_with_a_real_domain(): void
    {
        $rule = new EmailHasMailExchangeRecord;
        $failed = false;

        $rule->validate('email', 'someone@example.com', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }
}
