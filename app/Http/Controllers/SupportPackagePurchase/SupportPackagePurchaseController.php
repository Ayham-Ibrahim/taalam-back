<?php

namespace App\Http\Controllers\SupportPackagePurchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportPackagePurchase\PurchaseSupportPackageRequest;
use App\Models\SupportPackage;
use App\Models\SupportPackagePurchase;
use App\Services\PaymentService;
use App\Services\SupportPackagePurchaseService;
use Illuminate\Http\Request;

class SupportPackagePurchaseController extends Controller
{
    public function __construct(
        private readonly SupportPackagePurchaseService $purchaseService,
        private readonly PaymentService $paymentService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SupportPackagePurchase::class);
        $this->purchaseService->expireStalePendingPurchases();

        $user = $request->user();

        $purchases = SupportPackagePurchase::query()
            ->with(['student.user:id,name,avatar_path', 'supportPackage:id,title,tier', 'supportPackage.subjects:id,name_ar', 'academicSupporter.user:id,name'])
            ->when(! $user->isAdmin() && $user->loadMissing('academicSupporter')->academicSupporter, fn ($q) => $q->where('academic_supporter_id', $user->academicSupporter->id))
            ->when(! $user->isAdmin() && $user->loadMissing('student')->student, fn ($q) => $q->where('student_id', $user->student->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($purchases);
    }

    public function show(Request $request, SupportPackagePurchase $supportPackagePurchase)
    {
        $this->authorize('view', $supportPackagePurchase);
        $this->purchaseService->expireStalePendingPurchases();
        $supportPackagePurchase->refresh();

        $supportPackagePurchase->load(['supportPackage.subjects:id,name_ar', 'academicSupporter.user:id,name']);

        return $this->success($supportPackagePurchase);
    }

    public function store(PurchaseSupportPackageRequest $request, SupportPackage $supportPackage)
    {
        $this->authorize('create', SupportPackagePurchase::class);
        // لازم قبل initiate() مباشرة: assertNoOpenPurchase بداخلها كانت سترفض محاولة
        // إعادة الشراء لو بقي شراء سابق مهجور pending_payment بلا هذا التنظيف أولاً
        $this->purchaseService->expireStalePendingPurchases();

        $student = $request->user()->loadMissing('student')->student;

        $purchase = $this->purchaseService->initiate($student, $supportPackage, $request->validated('coupon_code'));
        $checkoutUrl = $this->paymentService->createCheckoutSessionForSupportPackagePurchase($purchase);

        return $this->success([
            'purchase' => $purchase,
            'checkout_url' => $checkoutUrl,
        ], 'تم إنشاء طلب الاشتراك، أكمل الدفع لتأكيده', 201);
    }

    /** إعادة محاولة الدفع لاشتراك ما زال بانتظار الدفع. */
    public function checkout(Request $request, SupportPackagePurchase $supportPackagePurchase)
    {
        $this->authorize('view', $supportPackagePurchase);

        if ($supportPackagePurchase->status !== 'pending_payment') {
            return $this->error('هذا الاشتراك ليس بانتظار الدفع', 422);
        }

        $checkoutUrl = $this->paymentService->createCheckoutSessionForSupportPackagePurchase($supportPackagePurchase);

        return $this->success(['checkout_url' => $checkoutUrl]);
    }
}
