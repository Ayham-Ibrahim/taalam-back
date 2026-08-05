<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Course;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function overview(Request $request)
    {
        $this->authorize('viewAny', Teacher::class);

        $stats = [
            'verifiedTeachersCount' => Teacher::where('status', 'verified')->count(),
            'pendingVerificationsCount' => Teacher::where('status', 'pending_verification')->count(),
            'pendingApprovalsCount' => Package::where('status', 'pending_approval')->count()
                + Course::where('status', 'pending_approval')->count(),
            'openComplaintsCount' => Complaint::where('status', 'open')->count(),
            'totalStudents' => Student::count(),
            'monthlyRevenue' => (float) Payment::where('status', 'paid')
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('platform_amount'),
        ];

        $pendingVerifications = Teacher::where('status', 'pending_verification')
            ->with('user:id,name,avatar_path')
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (Teacher $teacher) => [
                'id' => $teacher->id,
                'name' => $teacher->user?->name,
                'avatar' => $teacher->user?->avatar_path,
                'type' => $teacher->teacher_type,
                'submittedAt' => $teacher->updated_at,
            ]);

        return $this->success(['stats' => $stats, 'pendingVerifications' => $pendingVerifications]);
    }
}
