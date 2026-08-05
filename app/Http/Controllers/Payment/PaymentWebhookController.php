<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService) {}

    public function stripe(Request $request)
    {
        try {
            $this->paymentService->handleWebhook(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
            );
        } catch (UnexpectedValueException|SignatureVerificationException) {
            return $this->error('توقيع Webhook غير صالح', 400);
        }

        return $this->success(null, 'تم استلام الحدث');
    }
}
