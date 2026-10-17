<?php

namespace App\Http\Controllers\Coupon;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coupon\CreateSupportPackageCouponRequest;
use App\Http\Resources\Coupon\CouponResource;
use App\Models\Coupon;
use App\Models\SupportPackage;
use App\Services\CouponService;
use Illuminate\Http\Request;

/** كوبونات باقات الدعم الأكاديمي — الأدمن فقط (راجع CouponPolicy::viewAny/create). update/destroy مشتركان مع CouponController (نفس Coupon model). */
class SupportPackageCouponController extends Controller
{
    public function __construct(private readonly CouponService $couponService) {}

    public function index(Request $request, SupportPackage $supportPackage)
    {
        $this->authorize('viewAny', [Coupon::class, $supportPackage]);

        $coupons = $supportPackage->coupons()->latest()->get();

        return $this->success(CouponResource::collection($coupons));
    }

    public function store(CreateSupportPackageCouponRequest $request, SupportPackage $supportPackage)
    {
        $coupon = $this->couponService->createForSupportPackage($supportPackage, $request->user(), $request->validated());

        return $this->success(new CouponResource($coupon), 'تم إنشاء الكوبون بنجاح', 201);
    }
}
