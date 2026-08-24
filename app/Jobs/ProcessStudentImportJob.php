<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\StudentImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * فشل غير متوقَّع هنا (لا فشل تحقّق صف بعينه — ذاك يُجمَع أصلاً في
 * $batch->errors عبر StudentImportService) يُعلَّم على $batch صراحةً قبل
 * إعادة رميه، وإلا يبقى الأدمن يرى "processing" إلى الأبد بلا أي تفسير.
 */
class ProcessStudentImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public readonly int $importBatchId) {}

    public function handle(StudentImportService $service): void
    {
        $batch = ImportBatch::find($this->importBatchId);

        if (! $batch) {
            return;
        }

        try {
            $service->processBatch($batch);
        } catch (Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        } finally {
            Storage::disk('local')->delete($batch->file_path);
        }
    }
}
