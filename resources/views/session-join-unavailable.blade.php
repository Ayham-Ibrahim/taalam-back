<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TAALAM</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #F7F8FD; font-family: Tahoma, Arial, sans-serif; color: #1F2937; }
        .card { max-width: 420px; margin: 16px; padding: 32px 24px; background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.06); text-align: center; }
        p { line-height: 1.8; margin: 0 0 24px; }
        a { display: inline-block; padding: 10px 24px; border-radius: 12px; background: #3B5998; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <p>{{ $message }}</p>
        <a href="{{ $platformUrl }}">الذهاب إلى المنصة</a>
    </div>
</body>
</html>
