<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

/**
 * كتالوج شارات الإنجاز — الأدمن يمنحها للمعلم يدوياً (BadgeController::grant)،
 * لا يضيفها المعلم لنفسه. updateOrCreate بمفتاح code كي يبقى تشغيلها آمناً
 * أكثر من مرة (لا يكرّر الصفوف عند إعادة seed).
 */
class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [
            ['code' => 'reviewed_credentials', 'name_ar' => 'مؤهلات مراجعة', 'icon' => '🎓', 'sort_order' => 1],
            ['code' => 'background_checked', 'name_ar' => 'فحص أمني', 'icon' => '🛡️', 'sort_order' => 2],
            ['code' => 'featured', 'name_ar' => 'معلم مميز', 'icon' => '🥇', 'sort_order' => 3],
            ['code' => 'accredited_center', 'name_ar' => 'مركز معتمد', 'icon' => '🏅', 'sort_order' => 4],
        ];

        foreach ($badges as $badge) {
            Badge::updateOrCreate(['code' => $badge['code']], $badge);
        }
    }
}
