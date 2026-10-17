<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * شراء طالب جامعي لباقة دعم — نظير Booking/Enrollment المبسّط (لا جلسات/BBB).
 * amount/currency ثابتان وقت الشراء (snapshot من SupportPackage::student_price وقتها).
 */
class SupportPackagePurchase extends Model
{
    protected $attributes = [
        'currency' => 'AED',
    ];

    protected $fillable = [
        'student_id',
        'academic_supporter_id',
        'support_package_id',
        'coupon_id',
        'amount',
        'discount_amount',
        'currency',
        'status',
        'hold_expires_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'hold_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function academicSupporter()
    {
        return $this->belongsTo(AcademicSupporter::class);
    }

    public function supportPackage()
    {
        return $this->belongsTo(SupportPackage::class);
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
