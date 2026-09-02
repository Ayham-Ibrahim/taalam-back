<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationLogResource;
use App\Models\NotificationLog;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * رؤية الأدمن على كل محاولة إرسال إشعار (بريد أساساً) — بلا هذه الصفحة لا
 * توجد أي طريقة لمعرفة أن بريد ترحيب طالب مستورَد فشل فعلياً (مثال حقيقي:
 * رفض Mailtrap الإرسال بسبب دومين From غير موثّق) قبل أن يشتكي المستخدم نفسه.
 * نفس نمط تفويض AuditLogController تماماً (إعادة استخدام Setting::viewAny
 * كبوابة "صفحات مراقبة داخلية للأدمن فقط" بدل Policy مخصصة لكل شاشة كهذه).
 */
class NotificationLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Setting::class);

        $logs = NotificationLog::query()
            ->with('user:id,name,email')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->string('event')))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->value();
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($logs->through(fn (NotificationLog $log) => new NotificationLogResource($log)));
    }
}
