<?php

namespace App\Http\Controllers\Coupon;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coupon\CreateMultiPackageCouponRequest;
use App\Http\Requests\Coupon\UpdateMultiPackageCouponRequest;
use App\Http\Resources\Coupon\CouponResource;
use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Http\Request;

/**
 * كوبونات الأدمن متعددة الباقات (باقات المعلمين فقط، لا دعم أكاديمي). الحذف
 * مشترك مع CouponController::destroy (CouponPolicy::owns يسمح للأدمن دائماً).
 */
class MultiPackageCouponController extends Controller
{
    public function __construct(private readonly CouponService $couponService) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $coupons = Coupon::multiPackage()
            ->with(['packages:id,title', 'courses:id,title'])
            ->latest()
            ->get();

        return $this->success(CouponResource::collection($coupons));
    }

    public function store(CreateMultiPackageCouponRequest $request)
    {
        $coupon = $this->couponService->createMultiPackage($request->user(), $request->validated());

        return $this->success(new CouponResource($coupon), 'تم إنشاء الكوبون بنجاح', 201);
    }

    public function update(UpdateMultiPackageCouponRequest $request, Coupon $coupon)
    {
        $coupon = $this->couponService->updateMultiPackage($coupon, $request->validated());

        return $this->success(new CouponResource($coupon), 'تم تحديث الكوبون');
    }
}
