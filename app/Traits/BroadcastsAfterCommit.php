<?php

namespace App\Traits;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * بث أحداث المحادثة بعد حفظ المعاملة فعلاً — لا يصل حدث لعملية تراجعت عنها القاعدة.
 * فشل Reverb (متوقف أو غير مُعدّ) يُسجَّل ولا يُفشل العملية: الرسالة محفوظة، والفرونت
 * يجلب ما فاته عبر HTTP عند عودة الاتصال. مهلة الاتصال بـ Reverb قصيرة
 * (config/broadcasting.php) كي لا يعلق الطلب إن لم يستجب.
 *
 * $queued: عبر الطابور بدل البث المتزامن داخل الطلب — لرسائل النظام التي قد تتكرر لعدة
 * محادثات في طلب واحد (مرفق جلسة جماعية لعشرات الطلاب، webhook الدفع) ولا تحتاج فورية.
 */
trait BroadcastsAfterCommit
{
    protected function broadcastAfterCommit(object $event, bool $toOthers = true, bool $queued = false): void
    {
        DB::afterCommit(function () use ($event, $toOthers, $queued) {
            try {
                if ($toOthers && method_exists($event, 'dontBroadcastToCurrentUser')) {
                    $event->dontBroadcastToCurrentUser();
                }

                $queued ? dispatch(new BroadcastEvent(clone $event)) : event($event);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
