<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * إعدادات النظام القابلة للتعديل من لوحة الأدمن.
 * ⚠️ لا تُكتب أي من هذه القيم مباشرةً في الكود (hardcoded).
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // ═══════ التسعير ═══════
            [
                'key' => 'default_platform_margin_percent',
                'value' => '60',
                'type' => 'decimal',
                'group' => 'pricing',
                'label_ar' => 'نسبة المنصة الافتراضية (%)',
                'description' => 'قيمة مقترحة تظهر للأدمن عند الموافقة على الباقة — قابلة للتعديل لكل باقة على حدة',
            ],
            [
                'key' => 'min_platform_margin_percent',
                'value' => '10',
                'type' => 'decimal',
                'group' => 'pricing',
                'label_ar' => 'الحد الأدنى لنسبة المنصة (%)',
            ],
            [
                'key' => 'max_platform_margin_percent',
                'value' => '200',
                'type' => 'decimal',
                'group' => 'pricing',
                'label_ar' => 'الحد الأقصى لنسبة المنصة (%)',
            ],
            [
                'key' => 'default_currency',
                'value' => 'USD',
                'type' => 'string',
                'group' => 'pricing',
                'label_ar' => 'العملة الافتراضية',
            ],

            // ═══════ الحجز والمواعيد ═══════
            [
                'key' => 'booking_payment_hold_minutes',
                'value' => '15',
                'type' => 'integer',
                'group' => 'booking',
                'label_ar' => 'مدة حجز الموعد مؤقتاً أثناء الدفع (دقيقة)',
            ],
            [
                'key' => 'session_duration_minutes',
                'value' => '60',
                'type' => 'integer',
                'group' => 'booking',
                'label_ar' => 'مدة الجلسة الافتراضية (دقيقة)',
                'description' => 'ثابتة لكل الجلسات — المعلم لا يتحكم بها عند إنشاء الجدول، فقط يوم/وقت البداية',
                'is_editable' => false,
            ],
            [
                'key' => 'reschedule_requires_admin',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'booking',
                'label_ar' => 'تغيير الموعد يتطلب موافقة الأدمن دائماً',
                'is_editable' => false, // قرار سياسة — غير قابل للتعطيل
            ],
            [
                'key' => 'reschedule_free_window_hours',
                'value' => '24',
                'type' => 'integer',
                'group' => 'booking',
                'label_ar' => 'نافذة التغيير المرنة (ساعة) — السبب اختياري ضمنها',
            ],
            [
                'key' => 'reschedule_min_hours_ahead',
                'value' => '2',
                'type' => 'integer',
                'group' => 'booking',
                'label_ar' => 'أقل مهلة بين الطلب والموعد الجديد (ساعة)',
            ],
            [
                'key' => 'reschedule_max_per_session',
                'value' => '1',
                'type' => 'integer',
                'group' => 'booking',
                'label_ar' => 'أقصى عدد تغييرات مسموحة لكل جلسة',
            ],
            [
                'key' => 'counterparty_response_hours',
                'value' => '12',
                'type' => 'integer',
                'group' => 'booking',
                'label_ar' => 'مهلة رد الطرف الآخر على اقتراح التغيير (ساعة)',
            ],

            // ═══════ الحضور والغياب ═══════
            [
                'key' => 'advance_notice_hours',
                'value' => '6',
                'type' => 'integer',
                'group' => 'attendance',
                'label_ar' => 'أقل مهلة للإشعار المسبق بالغياب (ساعة)',
                'description' => 'إن أبلغ الطالب قبل هذه المدة أو أكثر، لا تُحتسب الجلسة من رصيده',
            ],
            [
                'key' => 'no_show_grace_minutes',
                'value' => '15',
                'type' => 'integer',
                'group' => 'attendance',
                'label_ar' => 'مهلة التذكير قبل تسجيل الغياب (دقيقة)',
            ],
            [
                'key' => 'no_show_final_minutes',
                'value' => '30',
                'type' => 'integer',
                'group' => 'attendance',
                'label_ar' => 'المدة النهائية لتسجيل الغياب (دقيقة)',
            ],
            [
                'key' => 'disconnect_grace_minutes',
                'value' => '10',
                'type' => 'integer',
                'group' => 'attendance',
                'label_ar' => 'مهلة العودة بعد انقطاع الاتصال (دقيقة)',
            ],
            [
                'key' => 'teacher_no_show_warning_threshold',
                'value' => '1',
                'type' => 'integer',
                'group' => 'attendance',
                'label_ar' => 'عدد الغيابات قبل التحذير التلقائي',
            ],
            [
                'key' => 'teacher_no_show_suspend_threshold',
                'value' => '3',
                'type' => 'integer',
                'group' => 'attendance',
                'label_ar' => 'عدد الغيابات قبل تعليق حساب المعلم',
            ],
            [
                'key' => 'session_reminder_minutes_before',
                'value' => '120',
                'type' => 'integer',
                'group' => 'attendance',
                'label_ar' => 'إرسال رابط الجلسة تلقائياً قبل موعدها بـ (دقيقة)',
                'description' => 'يُرسل للطالب والمعلم معاً عبر البريد فور دخول الجلسة ضمن هذه المهلة',
            ],

            // ═══════ التقييمات ═══════
            [
                'key' => 'review_window_days',
                'value' => '7',
                'type' => 'integer',
                'group' => 'reviews',
                'label_ar' => 'مهلة إرسال التقييم بعد الجلسة (يوم)',
            ],
            [
                'key' => 'review_edit_window_hours',
                'value' => '24',
                'type' => 'integer',
                'group' => 'reviews',
                'label_ar' => 'مهلة تعديل التقييم بعد إرساله (ساعة)',
            ],
            [
                'key' => 'review_prompt_delay_minutes',
                'value' => '15',
                'type' => 'integer',
                'group' => 'reviews',
                'label_ar' => 'مهلة إرسال طلب التقييم بعد انتهاء الجلسة (دقيقة)',
            ],
            [
                'key' => 'rating_window_reviews',
                'value' => '30',
                'type' => 'integer',
                'group' => 'reviews',
                'label_ar' => 'عدد المراجعات المعتمدة في حساب المتوسط',
            ],

            // ═══════ الباقات والصلاحية ═══════
            [
                'key' => 'package_expiry_grace_days',
                'value' => '14',
                'type' => 'integer',
                'group' => 'packages',
                'label_ar' => 'مهلة السماح بعد انتهاء صلاحية الباقة (يوم)',
            ],
            [
                'key' => 'package_expiry_warning_days',
                'value' => '7',
                'type' => 'integer',
                'group' => 'packages',
                'label_ar' => 'التنبيه قبل انتهاء الصلاحية بـ (يوم)',
            ],
            [
                'key' => 'low_sessions_alert_threshold',
                'value' => '2',
                'type' => 'integer',
                'group' => 'packages',
                'label_ar' => 'التنبيه عند تبقي عدد جلسات',
            ],

            // ═══════ الحسابات ═══════
            [
                'key' => 'invitation_link_expiry_hours',
                'value' => '48',
                'type' => 'integer',
                'group' => 'accounts',
                'label_ar' => 'صلاحية رابط دعوة المعلم (ساعة)',
            ],
            [
                'key' => 'student_import_max_rows',
                'value' => '500',
                'type' => 'integer',
                'group' => 'accounts',
                'label_ar' => 'أقصى عدد صفوف في استيراد الطلاب',
            ],
            [
                'key' => 'teacher_import_max_rows',
                'value' => '500',
                'type' => 'integer',
                'group' => 'accounts',
                'label_ar' => 'أقصى عدد صفوف في استيراد المعلمين',
            ],

            // ═══════ خوارزمية الترتيب ═══════
            [
                'key' => 'ranking_weight_rating',
                'value' => '30',
                'type' => 'decimal',
                'group' => 'ranking',
                'label_ar' => 'وزن التقييم في ترتيب البحث (%)',
            ],
            [
                'key' => 'ranking_weight_completion',
                'value' => '25',
                'type' => 'decimal',
                'group' => 'ranking',
                'label_ar' => 'وزن معدل الإتمام (%)',
            ],
            [
                'key' => 'ranking_weight_response',
                'value' => '20',
                'type' => 'decimal',
                'group' => 'ranking',
                'label_ar' => 'وزن سرعة الرد (%)',
            ],
            [
                'key' => 'ranking_weight_profile',
                'value' => '15',
                'type' => 'decimal',
                'group' => 'ranking',
                'label_ar' => 'وزن اكتمال الملف (%)',
            ],
            [
                'key' => 'ranking_weight_recency',
                'value' => '10',
                'type' => 'decimal',
                'group' => 'ranking',
                'label_ar' => 'وزن حداثة المراجعات (%)',
            ],

            // ═══════ مهل الاستجابة SLA ═══════
            [
                'key' => 'sla_document_review_hours',
                'value' => '48',
                'type' => 'integer',
                'group' => 'sla',
                'label_ar' => 'مهلة مراجعة الوثائق (ساعة)',
            ],
            [
                'key' => 'sla_package_review_hours',
                'value' => '24',
                'type' => 'integer',
                'group' => 'sla',
                'label_ar' => 'مهلة مراجعة الباقة (ساعة)',
            ],
            [
                'key' => 'sla_reschedule_hours',
                'value' => '12',
                'type' => 'integer',
                'group' => 'sla',
                'label_ar' => 'مهلة الرد على طلب تغيير الموعد (ساعة)',
            ],
            [
                'key' => 'sla_cancellation_hours',
                'value' => '6',
                'type' => 'integer',
                'group' => 'sla',
                'label_ar' => 'مهلة الرد على طلب الإلغاء (ساعة)',
            ],
            [
                'key' => 'sla_complaint_hours',
                'value' => '24',
                'type' => 'integer',
                'group' => 'sla',
                'label_ar' => 'مهلة الرد على الشكوى (ساعة)',
            ],
            [
                'key' => 'objection_window_hours',
                'value' => '48',
                'type' => 'integer',
                'group' => 'sla',
                'label_ar' => 'مهلة اعتراض الطلاب على تغيير الجدول (ساعة)',
            ],
        ];

        foreach ($settings as $s) {
            DB::table('settings')->updateOrInsert(
                ['key' => $s['key']],
                array_merge($s, [
                    'is_editable' => $s['is_editable'] ?? true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
