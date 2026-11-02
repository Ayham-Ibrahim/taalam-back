<?php

namespace App\Http\Controllers\Payout;

use App\Exports\PayoutsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payout\GeneratePayoutRequest;
use App\Http\Requests\Payout\MarkPayoutPaidRequest;
use App\Http\Resources\Payout\PayoutDetailResource;
use App\Http\Resources\Payout\PayoutResource;
use App\Models\Payout;
use App\Models\Teacher;
use App\Services\InvoicePdfService;
use App\Services\PayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class PayoutController extends Controller
{
    public function __construct(
        private readonly PayoutService $payoutService,
        private readonly InvoicePdfService $invoicePdfService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Payout::class);

        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $user = $request->user();

        $payouts = Payout::query()
            ->with('teacher.user:id,name')
            ->when(! $user->isFinance(), function ($q) use ($user) {
                $teacher = $user->loadMissing('teacher')->teacher;
                $q->where('teacher_id', $teacher?->id ?? 0);
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('month'), fn ($q) => $q->overlappingMonth($request->string('month')->value()))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($payouts->through(fn (Payout $payout) => new PayoutResource($payout)));
    }

    public function show(Request $request, Payout $payout)
    {
        $this->authorize('view', $payout);

        $payout->load([
            'teacher.user:id,name',
            'approver:id,name',
            'items.session.course:id,title',
            'items.session.booking.package:id,title,session_format',
        ]);

        return $this->success(new PayoutDetailResource($payout));
    }

    public function downloadInvoice(Payout $payout)
    {
        $this->authorize('view', $payout);

        try {
            $pdf = $this->invoicePdfService->forPayout($payout);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->pdfDownload($pdf, "payout-{$payout->id}.pdf");
    }

    public function export(Request $request)
    {
        $this->authorize('export', Payout::class);

        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return Excel::download(new PayoutsExport($request->only(['status', 'teacher_id', 'month'])), 'payouts.xlsx');
    }

    /** زر الأدمن والمحاسب: كشف لكل معلم بكل مستحقاته غير المصروفة حتى الآن — راجع PayoutService::generateAll */
    public function generate(GeneratePayoutRequest $request)
    {
        $created = $this->payoutService->generateAll();

        return $this->success(
            ['created' => $created],
            $created > 0 ? "تم توليد {$created} كشف مستحقات" : 'لا توجد مستحقات غير مدفوعة لتوليدها',
            $created > 0 ? 201 : 200,
        );
    }

    public function markPaid(MarkPayoutPaidRequest $request, Payout $payout)
    {
        $payout = $this->payoutService->markPaid($payout, $request->validated('transfer_reference'));

        return $this->success($payout, 'تم تحويل المستحقات');
    }
}
