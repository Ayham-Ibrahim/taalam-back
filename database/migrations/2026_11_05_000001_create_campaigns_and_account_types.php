<?php

use App\Support\EmailNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الحملات الإعلانية ونوع الحساب — إضافة بحتة: كل حساب قائم يبقى regular بلا حملة.
 *
 * account_type:
 *   regular  → الحساب الطبيعي (كل الحسابات الحالية)
 *   campaign → سجّل من رابط حملة ولم يحوّل حسابه بعد: يرى الباقات العادية ولا يحجزها
 *   guest    → حساب ظل لزائر حجز الباقة المجانية ببريده فقط — بلا كلمة مرور، لا يدخل
 *
 * campaign_id دائم: أصل الحساب لا يتغير بالتحويل، وهو ما يُظهر الباقات المخفضة.
 * normalized_email: البريد بعد التوحيد (EmailNormalizer) — مفتاح قيد الباقة المجانية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 80)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->enum('account_type', ['regular', 'campaign', 'guest'])->default('regular')->after('role')->index();
            $table->foreignId('campaign_id')->nullable()->after('account_type')
                ->constrained('campaigns')->restrictOnDelete();
            $table->timestamp('converted_at')->nullable()->after('campaign_id');
            $table->string('normalized_email', 150)->nullable()->after('email')->index();
        });

        // تعبئة العمود الجديد فقط — لا يُمس أي عمود قائم
        DB::table('users')->whereNotNull('email')->orderBy('id')->chunkById(500, function ($users) {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update([
                    'normalized_email' => EmailNormalizer::normalize($user->email),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropIndex(['account_type']);
            $table->dropIndex(['normalized_email']);
            $table->dropColumn(['account_type', 'converted_at', 'normalized_email']);
        });

        Schema::dropIfExists('campaigns');
    }
};
