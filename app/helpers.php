<?php

if (! function_exists('frontend_url')) {
    /**
     * رابط في الفرونت اند (React SPA على t3allem.com) لا الباك اند. url()/route()
     * القياسيتان في Laravel تبنيان الرابط من الـ request الحالي إن وُجد، لكن
     * الإشعارات هنا كلها ShouldQueue — تُعالَج عبر queue worker بلا أي request
     * حقيقي، فتتراجعان صامتاً إلى APP_URL (مضيف الباك اند نفسه). أي رابط داخل
     * بريد إلكتروني يُفترض أن يفتح صفحة في تطبيق الفرونت اند المنفصل تماماً
     * يجب أن يُبنى من FRONTEND_URL/config('app.frontend_url') دائماً، لا url().
     */
    function frontend_url(string $path = ''): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $path = ltrim($path, '/');

        return $path === '' ? $base : "{$base}/{$path}";
    }
}
