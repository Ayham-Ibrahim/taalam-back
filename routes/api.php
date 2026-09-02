<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\NotificationLogController;
use App\Http\Controllers\Admin\ImportBatchController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Booking\BookingController;
use App\Http\Controllers\Booking\EnrollmentController;
use App\Http\Controllers\Complaint\ComplaintController;
use App\Http\Controllers\Course\CourseController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\MetaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Package\AvailabilityController;
use App\Http\Controllers\Package\PackageController;
use App\Http\Controllers\Payment\PaymentWebhookController;
use App\Http\Controllers\Payout\PayoutController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Reschedule\RescheduleRequestController;
use App\Http\Controllers\Review\ReviewController;
use App\Http\Controllers\Session\ClassSessionController;
use App\Http\Controllers\Session\SessionAttendanceController;
use App\Http\Controllers\Student\StudentController;
use App\Http\Controllers\Student\StudentImportController;
use App\Http\Controllers\TaxonomyController;
use App\Http\Controllers\Teacher\BadgeController;
use App\Http\Controllers\Teacher\TeacherController;
use App\Http\Controllers\Teacher\TeacherImportController;
use App\Http\Controllers\Teacher\VerificationDocumentController;
use App\Http\Controllers\TeacherSearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::middleware('throttle:register')->post('register/student', [AuthController::class, 'registerStudent']);
    Route::middleware('throttle:login')->post('login', [AuthController::class, 'login']);
    Route::middleware('throttle:password-reset')->post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::middleware('throttle:password-reset')->post('reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware(['auth:sanctum', 'throttle:uploads'])->post('me/avatar', [ProfileController::class, 'uploadAvatar']);
Route::middleware('auth:sanctum')->delete('me/avatar', [ProfileController::class, 'deleteAvatar']);
Route::middleware('auth:sanctum')->put('me/profile', [ProfileController::class, 'updateProfile']);
Route::middleware('auth:sanctum')->put('me/timezone/sync', [ProfileController::class, 'syncTimezone']);
Route::middleware(['auth:sanctum', 'throttle:password-change'])->put('me/password', [ProfileController::class, 'updatePassword']);

Route::post('teachers/accept-invitation', [TeacherController::class, 'acceptInvitation']);

Route::prefix('taxonomy')->group(function () {
    Route::get('{type}', [TaxonomyController::class, 'index']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('{type}', [TaxonomyController::class, 'store']);
        Route::put('{type}/{id}', [TaxonomyController::class, 'update']);
        Route::delete('{type}/{id}', [TaxonomyController::class, 'destroy']);
    });
});

// وصول خاص لملفات التوثيق — محمي بتوقيع مؤقت فقط (RULE-06)، لا auth:sanctum هنا عمداً
Route::get('verification-documents/{document}/download', [VerificationDocumentController::class, 'download'])
    ->middleware('signed')
    ->name('verification-documents.download');

// Stripe يستدعي هذا المسار مباشرة — التحقق يتم عبر توقيع Stripe-Signature وليس Sanctum
Route::post('webhooks/stripe', [PaymentWebhookController::class, 'stripe']);

// بحث السوق العام، ملف المعلم العام، باقاته النشطة، وتقييماته — بلا مصادقة عمداً.
// الحجز نفسه فقط يتطلب تسجيل الدخول (داخل مجموعة auth:sanctum أدناه).
Route::get('teachers/search', [TeacherSearchController::class, 'index']);
Route::get('teachers/{teacher}', [TeacherController::class, 'show']);
Route::get('teachers/{teacher}/packages', [PackageController::class, 'indexForTeacher']);
Route::get('teachers/{teacher}/courses', [CourseController::class, 'indexForTeacher']);
Route::get('teachers/{teacher}/reviews', [ReviewController::class, 'indexForTeacher']);

// إحصائيات الصفحة الرئيسية وخيارات فلاتر البحث — بلا مصادقة عمداً
Route::get('meta/stats', [MetaController::class, 'stats']);
Route::get('meta/filters', [MetaController::class, 'filters']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('dashboard/admin', [AdminDashboardController::class, 'overview']);

    Route::get('teachers', [TeacherController::class, 'index']);
    Route::post('teachers', [TeacherController::class, 'store']);
    Route::post('teachers/create-account', [TeacherController::class, 'createAccount']);
    Route::post('teachers/import', [TeacherImportController::class, 'store']);
    Route::get('teachers/{teacher}/admin-detail', [TeacherController::class, 'adminDetail']);
    Route::put('teachers/{teacher}', [TeacherController::class, 'update']);
    Route::post('teachers/{teacher}/submit-for-verification', [TeacherController::class, 'submitForVerification']);
    Route::post('teachers/{teacher}/approve', [TeacherController::class, 'approve']);
    Route::post('teachers/{teacher}/reject', [TeacherController::class, 'reject']);
    Route::post('teachers/{teacher}/suspend', [TeacherController::class, 'suspend']);
    Route::post('teachers/{teacher}/reactivate', [TeacherController::class, 'reactivate']);
    Route::put('teachers/{teacher}/password', [TeacherController::class, 'resetPassword']);
    Route::delete('teachers/{teacher}', [TeacherController::class, 'destroy']);

    Route::middleware('throttle:uploads')->post('teachers/{teacher}/verification-documents', [VerificationDocumentController::class, 'store']);
    Route::get('verification-documents', [VerificationDocumentController::class, 'index']);
    Route::post('verification-documents/{document}/approve', [VerificationDocumentController::class, 'approve']);
    Route::post('verification-documents/{document}/reject', [VerificationDocumentController::class, 'reject']);
    Route::post('verification-documents/{document}/download-url', [VerificationDocumentController::class, 'downloadUrl']);

    Route::get('badges', [BadgeController::class, 'index']);
    Route::post('teachers/{teacher}/badges', [BadgeController::class, 'grant']);
    Route::post('badge-awards/{badgeAward}/revoke', [BadgeController::class, 'revoke']);

    Route::get('packages', [PackageController::class, 'index']);
    Route::post('packages', [PackageController::class, 'store']);
    Route::get('packages/{package}', [PackageController::class, 'show']);
    Route::get('packages/{package}/busy-slots', [PackageController::class, 'busySlots']);
    Route::put('packages/{package}', [PackageController::class, 'update']);
    Route::post('packages/{package}/submit', [PackageController::class, 'submit']);
    Route::post('packages/{package}/disable', [PackageController::class, 'disable']);
    Route::post('packages/{package}/approve', [PackageController::class, 'approve']);
    Route::post('packages/{package}/reject', [PackageController::class, 'reject']);

    Route::get('teachers/{teacher}/availability-slots', [AvailabilityController::class, 'indexSlots']);
    Route::post('teachers/{teacher}/availability-slots', [AvailabilityController::class, 'storeSlot']);
    Route::delete('teachers/{teacher}/availability-slots/{slot}', [AvailabilityController::class, 'destroySlot']);
    Route::post('teachers/{teacher}/blackouts', [AvailabilityController::class, 'storeBlackout']);
    Route::delete('teachers/{teacher}/blackouts/{blackout}', [AvailabilityController::class, 'destroyBlackout']);

    Route::get('courses', [CourseController::class, 'index']);
    Route::post('courses', [CourseController::class, 'store']);
    Route::get('courses/{course}', [CourseController::class, 'show']);
    Route::put('courses/{course}', [CourseController::class, 'update']);
    Route::post('courses/{course}/submit', [CourseController::class, 'submit']);
    Route::post('courses/{course}/disable', [CourseController::class, 'disable']);
    Route::post('courses/{course}/approve', [CourseController::class, 'approve']);
    Route::post('courses/{course}/reject', [CourseController::class, 'reject']);

    Route::get('bookings', [BookingController::class, 'index']);
    Route::get('bookings/{booking}', [BookingController::class, 'show']);
    Route::post('packages/{package}/bookings/individual', [BookingController::class, 'bookIndividual']);
    Route::post('packages/{package}/bookings/group', [BookingController::class, 'joinGroup']);
    Route::post('packages/{package}/bookings/manual', [BookingController::class, 'createManual']);
    Route::post('bookings/{booking}/approve', [BookingController::class, 'approveRequest']);
    Route::post('bookings/{booking}/reject', [BookingController::class, 'rejectRequest']);
    Route::post('bookings/{booking}/checkout', [BookingController::class, 'checkout']);
    Route::get('bookings/{booking}/invoice', [BookingController::class, 'downloadInvoice']);

    Route::get('enrollments', [EnrollmentController::class, 'index']);
    Route::get('enrollments/{enrollment}', [EnrollmentController::class, 'show']);
    Route::post('courses/{course}/enrollments', [EnrollmentController::class, 'store']);
    Route::post('courses/{course}/enrollments/manual', [EnrollmentController::class, 'createManual']);
    Route::post('enrollments/{enrollment}/checkout', [EnrollmentController::class, 'checkout']);
    Route::get('enrollments/{enrollment}/invoice', [EnrollmentController::class, 'downloadInvoice']);

    Route::get('reschedule-requests', [RescheduleRequestController::class, 'index']);
    Route::get('reschedule-requests/{rescheduleRequest}', [RescheduleRequestController::class, 'show']);
    Route::post('class-sessions/{session}/reschedule-requests', [RescheduleRequestController::class, 'store']);
    Route::post('reschedule-requests/{rescheduleRequest}/approve', [RescheduleRequestController::class, 'approve']);
    Route::post('reschedule-requests/{rescheduleRequest}/reject', [RescheduleRequestController::class, 'reject']);

    Route::get('class-sessions', [ClassSessionController::class, 'index']);
    Route::get('class-sessions/{session}', [ClassSessionController::class, 'show']);
    Route::get('class-sessions/{session}/join', [ClassSessionController::class, 'join']);

    Route::post('session-attendees/{attendee}/present', [SessionAttendanceController::class, 'markPresent']);
    Route::post('session-attendees/{attendee}/absent', [SessionAttendanceController::class, 'recordAbsence']);
    Route::post('class-sessions/{session}/no-show-teacher', [SessionAttendanceController::class, 'teacherNoShow']);

    Route::get('complaints', [ComplaintController::class, 'index']);
    Route::get('complaints/{complaint}', [ComplaintController::class, 'show']);
    Route::post('complaints', [ComplaintController::class, 'store']);
    Route::post('complaints/{complaint}/resolve', [ComplaintController::class, 'resolve']);
    Route::post('complaints/{complaint}/escalate', [ComplaintController::class, 'escalate']);

    // ترتيب مقصود: export مسار حرفي ثابت — يجب تسجيله قبل payouts/{payout} كي
    // لا يحاول Route Model Binding مطابقة "export" كأنه معرّف مستحقات رقمي.
    Route::get('payouts/export', [PayoutController::class, 'export']);
    Route::get('payouts', [PayoutController::class, 'index']);
    Route::get('payouts/{payout}', [PayoutController::class, 'show']);
    Route::get('payouts/{payout}/invoice', [PayoutController::class, 'downloadInvoice']);
    Route::post('teachers/{teacher}/payouts/generate', [PayoutController::class, 'generate']);
    Route::post('payouts/{payout}/approve', [PayoutController::class, 'approve']);
    Route::post('payouts/{payout}/mark-paid', [PayoutController::class, 'markPaid']);

    Route::get('me/reviews', [ReviewController::class, 'myReviews']);
    Route::get('reviews', [ReviewController::class, 'index']);
    Route::post('class-sessions/{session}/reviews', [ReviewController::class, 'store']);
    Route::put('reviews/{review}', [ReviewController::class, 'update']);
    Route::post('reviews/{review}/respond', [ReviewController::class, 'respond']);
    Route::post('reviews/{review}/hide', [ReviewController::class, 'hide']);
    Route::post('reviews/{review}/unhide', [ReviewController::class, 'unhide']);
    Route::post('reviews/{review}/report', [ReviewController::class, 'report']);

    Route::get('favorites', [FavoriteController::class, 'index']);
    Route::post('favorites/teachers/{teacher}', [FavoriteController::class, 'toggleTeacher']);
    Route::post('favorites/courses/{course}', [FavoriteController::class, 'toggleCourse']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);

    Route::post('students/import', [StudentImportController::class, 'store']);
    Route::post('students/create-account', [StudentController::class, 'store']);
    Route::get('students', [StudentController::class, 'index']);
    Route::get('students/{student}', [StudentController::class, 'show']);
    Route::put('students/{student}', [StudentController::class, 'update']);
    Route::put('students/{student}/password', [StudentController::class, 'resetPassword']);

    Route::get('admin/settings', [SettingsController::class, 'index']);
    Route::put('admin/settings/{key}', [SettingsController::class, 'update']);
    Route::get('admin/audit-log', [AuditLogController::class, 'index']);
    Route::get('admin/notification-logs', [NotificationLogController::class, 'index']);

    Route::get('import-batches', [ImportBatchController::class, 'index']);
    Route::get('import-batches/{importBatch}', [ImportBatchController::class, 'show']);
});
