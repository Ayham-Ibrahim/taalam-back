<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TAALAM</title>
    <style nonce="{{ $cspNonce ?? '' }}">
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #F7F8FD; font-family: Tahoma, Arial, sans-serif; color: #1F2937; }
        .card { max-width: 420px; margin: 16px; padding: 32px 24px; background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.06); text-align: center; }
        p { line-height: 1.8; margin: 0 0 16px; }
        .countdown { font-size: 36px; font-weight: bold; letter-spacing: 2px; color: #3B5998; margin: 8px 0 16px; direction: ltr; font-variant-numeric: tabular-nums; }
        .hint { font-size: 13px; color: #6B7280; margin-bottom: 24px; }
        a { display: inline-block; padding: 10px 24px; border-radius: 12px; background: #3B5998; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <p>لم يُفتح الانضمام لهذه الجلسة بعد. يُفتح قبل موعدها بـ {{ $earlyJoinMinutes }} دقائق.</p>
        <div class="countdown" id="countdown">--:--</div>
        <p class="hint">أبقِ هذه الصفحة مفتوحة، وسيتم إدخالك إلى الجلسة تلقائياً عند انتهاء العدّ.</p>
        <a href="{{ $platformUrl }}">الذهاب إلى المنصة</a>
    </div>
    <script nonce="{{ $cspNonce ?? '' }}">
        (function () {
            // العدّ من ثوانٍ حسبها السيرفر (لا من ساعة الجهاز) + هامش ثانيتين قبل إعادة المحاولة.
            var deadline = Date.now() + ({{ (int) $secondsUntilJoinable }} + 2) * 1000;
            var el = document.getElementById('countdown');

            function pad(n) { return n < 10 ? '0' + n : String(n); }

            function tick() {
                var left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
                var h = Math.floor(left / 3600);
                var m = Math.floor((left % 3600) / 60);
                var s = left % 60;
                el.textContent = (h > 0 ? h + ':' : '') + pad(m) + ':' + pad(s);

                if (left === 0) {
                    window.location.reload();
                    return;
                }

                setTimeout(tick, 1000);
            }

            tick();
        })();
    </script>
</body>
</html>
