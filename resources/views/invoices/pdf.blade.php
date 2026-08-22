<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>فاتورة {{ $reference }}</title>
    <style>
        /* لا font-family محدَّد عمداً — mPDF (InvoicePdfService) يختار الخط المناسب
           تلقائياً لكل جزء نص حسب سكربته الفعلي (عربي/لاتيني) بنفسه. */
        body {
            color: #1A1A2E;
            font-size: 13px;
            direction: rtl;
        }
        .header {
            border-bottom: 3px solid #3B5998;
            padding-bottom: 16px;
            margin-bottom: 24px;
            overflow: hidden;
        }
        .header img {
            height: 40px;
        }
        .header .invoice-title {
            float: left;
            text-align: left;
            direction: ltr;
        }
        .invoice-title h1 {
            color: #3B5998;
            font-size: 22px;
            margin: 0;
        }
        .invoice-title p {
            color: #6B7280;
            font-size: 12px;
            margin: 2px 0 0;
        }
        .meta-table {
            width: 100%;
            margin-bottom: 24px;
        }
        .meta-table td {
            vertical-align: top;
            width: 50%;
            padding: 0;
        }
        .meta-table h3 {
            color: #6B7280;
            font-size: 11px;
            text-transform: uppercase;
            margin: 0 0 6px;
        }
        .meta-table p {
            margin: 0 0 3px;
            font-size: 13px;
        }
        .status-badge {
            display: inline-block;
            background-color: #2E9E6B;
            color: #ffffff;
            border-radius: 20px;
            padding: 4px 14px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-badge.cancelled {
            background-color: #C2185B;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        table.items th {
            background-color: #F7F8FA;
            color: #1A1A2E;
            text-align: right;
            padding: 10px 12px;
            font-size: 12px;
            border-bottom: 1px solid #E5E7EB;
        }
        table.items td {
            padding: 10px 12px;
            border-bottom: 1px solid #E5E7EB;
            font-size: 13px;
        }
        .totals {
            width: 100%;
        }
        .totals td {
            padding: 6px 12px;
            font-size: 13px;
        }
        .totals .label {
            text-align: right;
            color: #6B7280;
        }
        .totals .value {
            text-align: left;
            direction: ltr;
            width: 140px;
        }
        .totals .grand-total td {
            border-top: 2px solid #3B5998;
            font-size: 16px;
            font-weight: bold;
            color: #3B5998;
            padding-top: 10px;
        }
        .footer {
            margin-top: 40px;
            padding-top: 16px;
            border-top: 1px solid #E5E7EB;
            color: #9CA3AF;
            font-size: 11px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        @if($logoDataUri)
            <img src="{{ $logoDataUri }}" alt="Taalam">
        @else
            <strong style="color:#3B5998; font-size:18px;">TAALAM</strong>
        @endif
        <div class="invoice-title">
            <h1>INVOICE</h1>
            <p>#{{ $reference }}</p>
        </div>
    </div>

    <table class="meta-table">
        <tr>
            <td>
                <h3>الطالب</h3>
                <p>{{ $studentName ?? '—' }}</p>
                <p>{{ $studentEmail ?? '' }}</p>
            </td>
            <td style="text-align:left; direction:ltr;">
                <h3 style="text-align:left;">تفاصيل الفاتورة</h3>
                <p>تاريخ الإصدار: {{ $issueDate }}</p>
                <p>طريقة الدفع: {{ $paymentMethod }}</p>
                <p>
                    <span class="status-badge {{ $statusLabel === 'ملغاة' ? 'cancelled' : '' }}">
                        {{ $statusLabel }}
                    </span>
                </p>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>الوصف</th>
                <th>المادة</th>
                <th>عدد الجلسات</th>
                <th>المعلم</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $itemTitle ?? '—' }}</td>
                <td>{{ $subject ?? '—' }}</td>
                <td>{{ $sessionsCount ?? '—' }}</td>
                <td>{{ $teacherName ?? '—' }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">المجموع</td>
            <td class="value">{{ number_format((float) $amount, 2) }} {{ $currency }}</td>
        </tr>
        <tr class="grand-total">
            <td class="label">الإجمالي المدفوع</td>
            <td class="value">{{ number_format((float) $amount, 2) }} {{ $currency }}</td>
        </tr>
    </table>

    <div class="footer">
        <p>منصة تعلّم — Taalam &middot; هذه الفاتورة صادرة إلكترونياً ولا تتطلب توقيعاً</p>
    </div>
</body>
</html>
