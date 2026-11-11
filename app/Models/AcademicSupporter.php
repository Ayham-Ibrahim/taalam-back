<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * الداعم الأكاديمي — كيان منفصل تماماً عن Teacher (قرار صريح، راجع هجرة
 * create_academic_supporters_tables). موظف شركة شريكة خارجية يدعم طلاباً
 * جامعيين بمهامهم الدراسية عبر باقات دعم بسعر ثابت. لا وثائق توثيق — الأدمن
 * يضيف الحساب فيصير نشطاً فوراً (AcademicSupporterService::createByAdmin).
 */
class AcademicSupporter extends Model
{
    use SoftDeletes;

    /** نفس منطق Teacher::MAX_VIDEOS — يمنع نمواً غير محدود لغاليري نماذج الأعمال */
    public const MAX_GALLERY_ITEMS = 20;

    /**
     * يعكس افتراضي عمود status في قاعدة البيانات — بدونها يبقى null في الذاكرة
     * مباشرة بعد create() (نفس ملاحظة Package::$attributes/currency بالضبط).
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = [
        'user_id',
        // ملف داعم لمدرس جامعي (يضيف له الأدمن باقات دعم) — null للداعم المستقل
        'teacher_id',
        'bio',
        'qualification',
        'experience_years',
        'university_id',
        'major_id',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * ما يظهر للطالب الجامعي بتاب الدعم الأكاديمي: الداعم المستقل النشط كما كان، والمدرس
     * الجامعي فقط إن كان موثّقاً ولديه باقة دعم نشطة واحدة على الأقل.
     */
    public function scopeVisibleToStudents($query)
    {
        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('teacher_id')
                ->orWhere(fn ($tq) => $tq
                    ->whereHas('teacher', fn ($t) => $t->where('teacher_type', 'university')->where('status', 'verified'))
                    ->whereHas('supportPackages', fn ($p) => $p->where('status', 'active'))));
    }

    public function university()
    {
        return $this->belongsTo(University::class);
    }

    public function major()
    {
        return $this->belongsTo(Major::class);
    }

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'academic_supporter_subject');
    }

    public function languages()
    {
        return $this->belongsToMany(Language::class, 'academic_supporter_language');
    }

    public function galleryItems()
    {
        return $this->hasMany(AcademicSupporterGalleryItem::class)->orderBy('sort_order');
    }

    public function supportPackages()
    {
        return $this->hasMany(SupportPackage::class);
    }

    public function purchases()
    {
        return $this->hasMany(SupportPackagePurchase::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
