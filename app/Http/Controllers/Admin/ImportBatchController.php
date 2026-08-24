<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ImportBatchResource;
use App\Models\ImportBatch;
use Illuminate\Http\Request;

/**
 * "مراقبة الطابور" من منظور الأدمن — لا لوحة عامة لكل الطوابور في النظام،
 * بل تحديداً حالة عمليات استيراد الطلاب/المعلمين (ProcessStudentImportJob/
 * ProcessTeacherImportJob)، وهذا ما يحتاجه الأدمن فعلياً بعد أن أصبح الاستيراد
 * غير متزامن: متابعة تقدّمه ونتيجته النهائية بدل انتظار استجابة الطلب الأصلي.
 */
class ImportBatchController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', ImportBatch::class);

        $batches = ImportBatch::query()
            ->with('creator:id,name')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->value()))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($batches->through(fn (ImportBatch $batch) => new ImportBatchResource($batch)));
    }

    public function show(ImportBatch $importBatch)
    {
        $this->authorize('view', $importBatch);

        return $this->success(new ImportBatchResource($importBatch->loadMissing('creator:id,name')));
    }
}
