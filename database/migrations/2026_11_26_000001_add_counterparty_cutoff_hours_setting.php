<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * - counterparty_cutoff_hours_before_session: مهلة رد الطرف الآخر على اقتراح تغيير الموعد تنتهي قبل
 *   الجلسة الأصلية (أو قبل الموعد المقترح للتعويضية) بهذه المدة على الأقل — ليبقى للأدمن وقت يتصرف فيه
 *   إن رُفض الطلب أو لم يُرد عليه. كانت ثابتاً في RescheduleService (ساعتان).
 * - reschedule_min_hours_ahead: أقل مهلة بين إرسال طلب تغيير الموعد/التعويضية والموعد المقترح.
 *   موجود عادةً من الـ seeder، فـ insertOrIgnore يتركه بقيمته — يُضاف فقط حيث لم يُشغَّل الـ seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            [
                'key' => 'counterparty_cutoff_hours_before_session',
                'value' => '2',
                'type' => 'integer',
                'group' => 'booking',
                'label_ar' => 'آخر وقت لرد الطرف الآخر قبل الجلسة (ساعة)',
                'description' => 'مهلة رد الطرف الآخر تنتهي قبل موعد الجلسة بهذه المدة على الأقل، ثم يُحال الطلب للأدمن ليبقى له وقت يتصرف فيه. إن لم يبقَ للرد ساعة على الأقل يذهب الطلب للأدمن مباشرة',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'reschedule_min_hours_ahead',
                'value' => '2',
                'type' => 'integer',
                'group' => 'booking',
                'label_ar' => 'أقل مهلة بين الطلب والموعد الجديد (ساعة)',
                'description' => 'لا يستطيع الطالب أو المعلم اقتراح موعد جديد (تغيير موعد أو جلسة تعويضية) أقرب من هذه المدة من وقت إرسال الطلب، ليتسنى للطرف الآخر رؤية الطلب والاستعداد له. 0 = يكفي أن يكون الموعد في المستقبل',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        // reschedule_min_hours_ahead موجود من الـ seeder قبل هذه الـ migration في البيئات القائمة —
        // لا نحذفه هنا وإلا ضاعت قيمة عدّلها الأدمن
        DB::table('settings')->where('key', 'counterparty_cutoff_hours_before_session')->delete();
    }
};
