<?php

namespace App\Http\Controllers\Coupon;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coupon\CreateCouponRequest;
use App\Http\Requests\Coupon\UpdateCouponRequest;
use App\Http\Resources\Coupon\CouponResource;
use App\Models\Coupon;
use App\Models\Package;
use App\Services\CouponService;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function __construct(private readonly CouponService $couponService) {}

    public function index(Request $request, Package $package)
    {
        $this->authorize('viewAny', [Coupon::class, $package]);

        $coupons = $package->coupons()->latest()->get();

        return $this->success(CouponResource::collection($coupons));
    }

    public function store(CreateCouponRequest $request, Package $package)
    {
        $coupon = $this->couponService->create($package, $request->user(), $request->validated());

        return $this->success(new CouponResource($coupon), 'تم إنشاء الكوبون بنجاح', 201);
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon)
    {
        $coupon = $this->couponService->update($coupon, $request->validated());

        return $this->success(new CouponResource($coupon), 'تم تحديث الكوبون');
    }

    public function destroy(Request $request, Coupon $coupon)
    {
        $this->authorize('delete', $coupon);

        $coupon->delete();

        return $this->success(null, 'تم حذف الكوبون');
    }
}
