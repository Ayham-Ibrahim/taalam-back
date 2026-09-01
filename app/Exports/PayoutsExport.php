<?php

namespace App\Exports;

use App\Models\Payout;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * أول ميزة تصدير Excel في المشروع (maatwebsite/excel كان يُستخدم للاستيراد
 * فقط سابقاً — StudentsImport/TeachersImport). FromQuery لا FromCollection
 * عمداً كي لا يُحمَّل كل جدول payouts في الذاكرة دفعة واحدة على حساب طويل.
 */
class PayoutsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private readonly array $filters = []) {}

    public function query()
    {
        return Payout::query()
            ->with('teacher.user:id,name')
            ->when(! empty($this->filters['status']), fn ($q) => $q->where('status', $this->filters['status']))
            ->when(! empty($this->filters['teacher_id']), fn ($q) => $q->where('teacher_id', $this->filters['teacher_id']))
            ->latest();
    }

    public function headings(): array
    {
        return [
            'رقم المستحقات',
            'المعلم',
            'من',
            'إلى',
            'عدد الجلسات',
            'الإجمالي',
            'الخصومات',
            'الصافي',
            'العملة',
            'الحالة',
            'تاريخ الاعتماد',
            'تاريخ الدفع',
            'الرقم المرجعي للتحويل',
        ];
    }

    public function map($payout): array
    {
        return [
            $payout->id,
            $payout->teacher?->user?->name,
            $payout->period_start->format('Y-m-d'),
            $payout->period_end->format('Y-m-d'),
            $payout->sessions_count,
            (float) $payout->gross_amount,
            (float) $payout->deductions,
            (float) $payout->net_amount,
            $payout->currency,
            $this->statusLabel($payout->status),
            $payout->approved_at?->format('Y-m-d H:i'),
            $payout->paid_at?->format('Y-m-d H:i'),
            $payout->transfer_reference,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'قيد الانتظار',
            'approved' => 'معتمدة',
            'processing' => 'قيد المعالجة',
            'paid' => 'مدفوعة',
            'on_hold' => 'موقوفة',
            default => $status,
        };
    }
}
