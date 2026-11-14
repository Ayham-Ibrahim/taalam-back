<?php

namespace App\Support;

/**
 * توحيد البريد لقيود "مرة واحدة لكل بريد" (الباقة المجانية) — لا للتواصل: البريد الأصلي
 * يبقى كما أدخله صاحبه. يغلق أسهل طرق التكرار: حالة الأحرف، والوسم بعد "+"
 * (ali+1@x.com)، والنقاط في Gmail (a.li@gmail.com = ali@gmail.com).
 */
class EmailNormalizer
{
    private const GMAIL_DOMAINS = ['gmail.com', 'googlemail.com'];

    public static function normalize(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        $local = explode('+', $local, 2)[0];

        if (in_array($domain, self::GMAIL_DOMAINS, true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }
}
