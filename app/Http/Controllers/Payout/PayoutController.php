<?php

namespace App\Http\Controllers\Payout;

use App\Exports\PayoutsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payout\GeneratePayoutRequest;
use App\Http\Requests\Payout\MarkPayoutPaidRequest;
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

        $user = $request->user();

        $payouts = Payout::query()
            ->with('teacher.user:id,name')
            ->when(! $user->isAdmin(), function ($q) use ($user) {
                $teacher = $user->loadMissing('teacher')->teacher;
                $q->where('teacher_id', $teacher?->id ?? 0);
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($payouts->through(fn (Payout $payout) => new PayoutResource($payout)));
    }

    public function show(Request $request, Payout $payout)
    {
        $this->authorize('view', $payout);

        return $this->success($payout->load('items'));
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

        return Excel::download(new PayoutsExport($request->only(['status', 'teacher_id'])), 'payouts.xlsx');
    }

    public function generate(GeneratePayoutRequest $request, Teacher $teacher)
    {
        $payout = $this->payoutService->generateForPeriod(
            $teacher,
            Carbon::parse($request->validated('period_start')),
            Carbon::parse($request->validated('period_end')),
        );

        return $this->success($payout, 'تم توليد كشف المستحقات بنجاح', 201);
    }

    public function approve(Request $request, Payout $payout)
    {
        $this->authorize('approve', Payout::class);

        $payout = $this->payoutService->approve($payout, $request->user());

        return $this->success($payout, 'تم اعتماد المستحقات');
    }

    public function markPaid(MarkPayoutPaidRequest $request, Payout $payout)
    {
        $payout = $this->payoutService->markPaid($payout, $request->validated('transfer_reference'));

        return $this->success($payout, 'تم تحويل المستحقات');
    }
}
