<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * تفعيل المسار التلقائي لتغيير المواعيد (RescheduleService): طلب بموعد مقترح يذهب للطرف
 * الآخر أولاً، وموافقته تطبّق الموعد بلا أدمن. reschedule_requires_admin يصبح مفتاحاً قابلاً
 * للتعديل من لوحة الأدمن بدل قرار ثابت — تفعيله يعيد كل الطلبات للأدمن مباشرة كما كانت.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'reschedule_requires_admin')->update([
            'value' => '0',
            'is_editable' => true,
            'label_ar' => 'كل طلبات تغيير الموعد تذهب للأدمن مباشرة',
            'description' => 'معطّل: الطرف الآخر (المعلم أو الطالب) يوافق على الموعد المقترح فيُطبَّق فوراً، ولا يصل للأدمن إلا ما رُفض أو لم يُرَد عليه خلال المهلة. مفعّل: كل الطلبات تنتظر قرار الأدمن',
            'updated_at' => now(),
        ]);

        // SettingsService يخزّن الإعدادات ساعة كاملة — بلا هذا تبقى القيمة القديمة فعّالة بعد النشر
        Cache::forget('settings:all');
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'reschedule_requires_admin')->update([
            'value' => '1',
            'is_editable' => false,
            'label_ar' => 'تغيير الموعد يتطلب موافقة الأدمن دائماً',
            'description' => null,
            'updated_at' => now(),
        ]);

        Cache::forget('settings:all');
    }
};
