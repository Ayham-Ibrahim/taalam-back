<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FileStorage
{
    /**
     * Store photo and protect the site
     *
     * @param  mixed  $file  The uploaded file
     * @param  string  $folderName  The folder to upload the file to
     * @param  string  $suffix  The file type suffix (img, vid, aud, docs)
     * @return string|null The url of the stored file
     *
     * @throws HttpResponseException
     */
    public static function storeFile($file, string $folderName, $suffix, string $disk = 'public')
    {
        try {
            $originalName = $file->getClientOriginalName();

            // Check for double extensions in the file name
            if (preg_match('/\.[^.]+\./', $originalName)) {
                self::throwValidationError('file', 'ان الملف الذي ارسلته غير امن');
            }

            switch ($suffix) {
                case 'img':
                    $allowedMimeTypes = ['image/jpeg', 'image/png'];
                    $allowedExtensions = ['jpeg', 'png', 'jpg'];
                    break;

                case 'vid':
                    $allowedMimeTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-ms-wmv'];
                    $allowedExtensions = ['mp4', 'webm', 'ogg', 'mov', 'wmv'];
                    break;

                case 'aud':
                    $allowedMimeTypes = ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/aac'];
                    $allowedExtensions = ['mp3', 'wav', 'ogg', 'aac'];
                    break;

                // وثائق التوثيق (هوية/شهادات/خبرة...) — صورة أو PDF فقط عمداً؛ لا
                // Office ولا أي نوع آخر (RULE: يُرفَض HTML/فيديو/سكربت كوثيقة توثيق).
                case 'docs':
                    $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
                    $allowedExtensions = ['pdf', 'jpeg', 'jpg', 'png'];
                    break;

                default:
                    self::throwValidationError('file', 'ان الملف الذي ارسلته غير امن');
            }

            $mime_type = $file->getMimeType();
            $extension = $file->getClientOriginalExtension();
            // Log::info("File validation details", [
            //     'file_name' => $file->getClientOriginalName(),
            //     'mime_type' => $mime_type,
            //     'extension' => $extension,
            //     'allowed_types' => $allowedMimeTypes,
            //     'allowed_extensions' => $allowedExtensions,
            //     'validation_result' => in_array($mime_type, $allowedMimeTypes) &&
            //         in_array($extension, $allowedExtensions)
            // ]);

            if (! in_array($mime_type, $allowedMimeTypes) || ! in_array($extension, $allowedExtensions)) {
                self::throwValidationError('file', 'نوع الملف غير مسموح به');
            }

            $fileName = Str::random(32);
            $fileName = preg_replace('/[^A-Za-z0-9_\-]/', '', $fileName);

            $path = $file->storeAs($folderName, $fileName.'.'.$extension, $disk);

            if ($disk !== 's3') {
                $expectedPath = Storage::disk($disk)->path($folderName.'/'.$fileName.'.'.$extension);
                $actualPath = Storage::disk($disk)->path($path);

                if ($actualPath !== $expectedPath) {
                    Storage::disk($disk)->delete($path);
                    self::throwValidationError('file', 'حدث خطأ أثناء حفظ الملف');
                }
            }

            // القرص العام يعيد رابطاً عاماً دائماً — الأقراص الخاصة (local/s3) تعيد المسار الخام
            // فقط، ليُصار إلى الوصول له عبر Pre-signed URL محدود الصلاحية (RULE-06).
            // Storage::disk($disk) صراحةً لا Storage::url() المختصرة — تلك تحل القرص الافتراضي
            // (local، بلا إعداد url) وليس القرص الفعلي الذي خُزِّن عليه الملف.
            return $disk === 'public' ? Storage::disk($disk)->url($path) : $path;
        } catch (HttpResponseException $e) {
            // throwValidationError أعلاه (نوع/امتداد غير مسموح، اسم ملف غير آمن...)
            // يطرح هذا الاستثناء نفسه من داخل try — يجب أن يمر كما هو بلا تغيير،
            // وإلا فرسالة الرفض المحدَّدة تُستبدَل دوماً بالرسالة العامة أدناه فتضيع
            // على المستخدم معرفة السبب الفعلي للرفض.
            throw $e;
        } catch (Exception $e) {
            self::throwValidationError('file', 'حدث خطأ أثناء معالجة الملف');
        }
    }

    /**
     * Throw validation error in JSON format
     *
     * @param  string  $field
     * @param  string  $message
     *
     * @throws HttpResponseException
     */
    protected static function throwValidationError($field, $message)
    {
        $validator = Validator::make([], []);
        $validator->errors()->add($field, $message);

        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'حدث خطأ في التحقق من صحة البيانات',
            'errors' => $validator->errors(),
        ], 422));
    }

    /**
     * Check if a file exists and upload it.
     *
     * This method checks if a file exists in the request and uploads it to the specified folder.
     * If the file doesn't exist, it returns null.
     *
     * @param  Request  $request  The HTTP request object.
     * @param  string  $folder  The folder to upload the file to.
     * @param  string  $fileColumnName  The name of the file input field in the request.
     * @return string|null The file path if the file exists, otherwise null.
     */
    public static function fileExists($file, $old_file, string $folderName, $suffix)
    {
        if (! isset($file)) {
            return null;
        }
        self::deleteFile($old_file);

        return self::storeFile($file, $folderName, $suffix);
    }

    /**
     * Delete the specified file.
     *
     * This method takes a file path as input and deletes the corresponding file from the public directory.
     * It first checks if the file exists at the given file path, and if it does, it deletes the file using the `unlink()` function.
     *
     * @param  string  $file  The file path of the file to be deleted.
     * @return void
     */
    public static function deleteFile($file)
    {
        $filePath = public_path($file);
        if (file_exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
    }

    /**
     * تنزيل إجباري (Content-Disposition: attachment) من قرص خاص (RULE-06) —
     * يُستدعى فقط خلف مسار محمي بتوقيع مؤقت.
     */
    public static function download(string $disk, string $path, string $downloadName)
    {
        return Storage::disk($disk)->download($path, $downloadName);
    }

    /**
     * عرض داخل المتصفح (Content-Disposition: inline) بدل تنزيل إجباري — لزر
     * "عرض الملف"، إذ يفتح PDF/صورة الوثيقة مباشرة في تبويب جديد بدل حفظه.
     */
    public static function view(string $disk, string $path, string $downloadName)
    {
        return Storage::disk($disk)->response($path, $downloadName);
    }
}
