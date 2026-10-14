<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * باقات الدعم الطلابي — خاصة بالداعم الأكاديمي (Teacher::isAcademicSupport)
 * فقط، منفصلة تماماً عن packages العادية: لا جلسات/جدول/سعة، فقط مادة + فئة
 * سعر ثابتة (basic/premium). الطالب يدفع ثمنها كاملاً للداعم — بلا هامش منصة
 * (platform_margin_percent=0 دائماً، student_price=teacher_price دائماً)؛ تسوية
 * تعلّم مع شركة الداعم الشريكة تتم يدوياً خارج المنصة بالكامل.
 *
 * لا مرحلة draft (بعكس packages العادية) — نموذج واحد يُرسَل مباشرة
 * pending_approval (انظر SupportPackageService::create).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->enum('tier', ['basic', 'premium']);
            $table->text('description');

            // السعر ثابت حسب الفئة وقت الإنشاء (snapshot من الإعدادات) — لا يتحكم
            // به الداعم الأكاديمي ولا الأدمن يدوياً. student_price = teacher_price
            // دائماً وplatform_revenue = 0 دائماً (بلا هامش منصة إطلاقاً).
            $table->decimal('teacher_price', 10, 2);
            $table->decimal('platform_margin_percent', 5, 2)->default(0);
            $table->decimal('student_price', 10, 2);
            $table->decimal('platform_revenue', 10, 2)->default(0);
            $table->char('currency', 3)->default('AED');

            $table->enum('status', ['pending_approval', 'active', 'rejected', 'disabled'])
                ->default('pending_approval')
                ->index();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['teacher_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_packages');
    }
};
