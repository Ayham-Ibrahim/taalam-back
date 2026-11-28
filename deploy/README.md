# إعدادات السيرفر المطلوبة (staging/production)

اثنان من متطلبات التشغيل غير مرتبطين بالكود مباشرة، ولن يعملا تلقائياً بعد أي
نشر (deploy) ما لم يُضبطا يدوياً مرة واحدة على السيرفر:

## 1. جدولة Laravel (`schedule:run`)

بدون هذا، لا تعمل أي مهمة مجدولة إطلاقاً (`routes/console.php`): لا تذكير
جلسات، لا إلغاء حجوزات منتهية الصلاحية، لا إعادة حساب ترتيب المعلمين.

أضف عبر `crontab -e`:

```
* * * * * cd /var/www/staging_back && php artisan schedule:run >> /dev/null 2>&1
```

## 2. Queue Worker دائم (`queue:work`)

**هذا هو الأهم وغالباً السبب الفعلي وراء وصول بعض الإشعارات (تذكير الجلسة،
البريد الترحيبي...) لطرف دون آخر أو عدم وصولها إطلاقاً.**

**بات استيراد الطلاب/المعلمين (Excel/CSV) يعتمد عليه بالكامل أيضاً** —
`ProcessStudentImportJob`/`ProcessTeacherImportJob` (راجع `StudentImportController`/
`TeacherImportController`) لا ينفَّذان مباشرة عند الرفع، بل يُدفَعان للطابور
فقط؛ بلا worker يعمل، يبقى كل استيراد عالقاً بحالة "queued" إلى الأبد ولا
يُنشأ أي طالب/معلم إطلاقاً مهما كان الملف صحيحاً. تابع حالة أي استيراد عبر
لوحة الأدمن → "سجل الاستيراد" (`/dashboard/admin/import-batches`).

`QUEUE_CONNECTION=database` يعني أن كل إشعار (بريد أو غيره) يُدفَع أولاً إلى
جدول `jobs` ولا يُرسَل فعلياً إلا حين يسحبه `queue:work`. كذلك
`SendSessionRemindersJob` نفسه مجدول عبر `Schedule::job(...)` — أي أنه أيضاً
يُدفَع إلى الطابور بدل أن يُنفَّذ مباشرة، فيعتمد بدوره على وجود worker يعمل
باستمرار.

إن لم يكن هناك worker دائم (supervisor/systemd)، فإن أي إشعار يصل فعلياً يكون
غالباً نتيجة تشغيل يدوي عابر لـ `php artisan queue:work` (ثم توقف)، مما يفسّر
وصول تذكير للمعلم دون الطالب: كلاهما يُدفَع لنفس الطابور بالتتابع، فمن الوارد
جداً أن يُعالَج أحدهما فقط قبل توقف العملية اليدوية أو انتهاء صلاحية الجلسة.

**التثبيت (VPS بصلاحية root):**

```bash
apt install -y supervisor   # إن لم يكن مثبتاً
cp deploy/supervisor-queue-worker.conf /etc/supervisor/conf.d/taalam-queue-worker.conf
# عدّل المسارات داخل الملف إن اختلف مسار المشروع عن /var/www/staging_back
supervisorctl reread
supervisorctl update
supervisorctl start taalam-queue-worker:*
```

**للتحقق أنه يعمل باستمرار:**

```bash
supervisorctl status taalam-queue-worker:*
```

**لتشخيص إشعارات فُقدت سابقاً (قبل تركيب الـ worker):**

```bash
php artisan tinker --execute="
echo 'jobs في الانتظار: ' . DB::table('jobs')->count() . PHP_EOL;
echo 'jobs فشلت نهائياً: ' . DB::table('failed_jobs')->count() . PHP_EOL;
DB::table('failed_jobs')->latest('failed_at')->limit(5)->get(['uuid','payload','exception'])
    ->each(fn (\$j) => print_r(['uuid' => \$j->uuid, 'exception' => substr(\$j->exception, 0, 300)]));
"
```

سطر `jobs في الانتظار` مرتفع (خصوصاً مع تراكمه مع الوقت) يؤكد غياب الـ worker.
`failed_jobs` تُظهر أي إشعارات فشلت فعلياً أثناء الإرسال (سبب مختلف: بريد غير
صالح، مشكلة اعتماد SMTP...) بدل أن تكون عالقة فقط.

## 3. صلاحيات الملفات (`storage/` و`bootstrap/cache/`)

Laravel يكتب فعلياً داخل هذين المجلدين وقت التشغيل (لا فقط وقت النشر) — إن لم
يكن مستخدم خادم الويب (PHP-FPM/Apache، غالباً `www-data`) يملك صلاحية كتابة
عليهما، تفشل عمليات صامتة أو بأخطاء 500 غامضة رغم أن الكود سليم تماماً:

- `storage/logs` — كل سطر في `laravel.log`.
- `storage/framework/{cache,sessions,views}` — كاش الإعدادات/الجلسات/Blade.
- `storage/framework/testing` — تُنشأ تلقائياً أثناء `php artisan test` فقط، لا صلة لها بالتشغيل الفعلي.
- `storage/app/public` — الملفات العامة (صور البروفايل...)، ويشير إليها `public/storage` (رابط رمزي أنشأه `php artisan storage:link`).
- `storage/app/private` — الملفات الخاصة (مستندات التوثيق `verification_documents`) — غير مصل بها من الخارج مباشرة، لكن لا تزال تحتاج كتابة فعلية من التطبيق.
- `storage/fonts` — **لا يُنشأ تلقائياً بالـ repo وحده** (خارج git)؛ يُنشئه dompdf عند أول استدعاء فقط إن كان `storage/` قابلاً للكتابة. إن لم يكن كذلك، **فشل تنزيل فاتورة PDF لأي حجز/تسجيل مدفوع بخطأ 500** رغم أن بيانات الحجز صحيحة تماماً (راجع `App\Services\InvoicePdfService`).
- `bootstrap/cache/` — كاش `config:cache`/`route:cache`/`event:cache`.

**الإعداد الموصى به (لا `chmod 777` إطلاقاً):**

```bash
cd /var/www/staging_back

# amazon-linux/rhel: nginx أو apache — تحقق فعلياً بـ: ps aux | grep -E 'php-fpm|nginx|apache'
WEB_USER=www-data

# أضف مستخدم النشر الحالي لمجموعة مستخدم خادم الويب (مرة واحدة فقط)
sudo usermod -aG "$WEB_USER" "$(whoami)"

sudo chown -R "$(whoami)":"$WEB_USER" storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# setgid على المجلدات كي تحافظ أي ملفات جديدة يُنشئها أي من الطرفين على نفس
# ملكية المجموعة تلقائياً — بدونه ستحتاج إعادة هذا الضبط بعد كل نشر جديد
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;
```

**للتحقق أن كل شيء يعمل فعلياً بعد الضبط:**

```bash
php artisan tinker --execute="echo is_writable(storage_path()) ? 'storage قابل للكتابة' : 'storage غير قابل للكتابة — أعد فحص الصلاحيات';"
```

ثم تأكد عمليًا بتنزيل فاتورة حقيقية لحجز/تسجيل مدفوع من الواجهة — هذا يغطي فعلياً `storage/fonts` و`storage/app/private` معاً دفعة واحدة.

## 4. المحادثة اللحظية (Laravel Reverb)

المحادثة بين الطالب والمعلم/الداعم تُرسَل عبر HTTP ثم تُبث عبر WebSocket. بلا Reverb
تبقى المحادثة تعمل (الإرسال والجلب)، لكن لا يصل شيء لحظياً إلا بعد تحديث الصفحة.

**1. متغيرات `.env` على السيرفر** (أنشئ القيم مرة واحدة، والفرونت يحتاج نفس `REVERB_APP_KEY`):

```bash
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<رقم عشوائي>
REVERB_APP_KEY=<سلسلة عشوائية>      # php -r 'echo bin2hex(random_bytes(10));'
REVERB_APP_SECRET=<سلسلة عشوائية>
# العنوان العام الذي يتصل به المتصفح (عبر Nginx)
REVERB_HOST=<دومين الباك>
REVERB_PORT=443
REVERB_SCHEME=https
# ما يستمع عليه reverb:start داخلياً خلف Nginx
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
# دومينات الفرونت فقط (المضيف بلا https://) — يمنع فتح WebSocket من مواقع أخرى
REVERB_ALLOWED_ORIGINS=t3allem.com,www.t3allem.com
```

ثم `php artisan config:cache`.

**2. Supervisor:**

```bash
cp deploy/supervisor-reverb.conf /etc/supervisor/conf.d/taalam-reverb.conf
supervisorctl reread && supervisorctl update && supervisorctl start taalam-reverb
supervisorctl status taalam-reverb
```

**3. Nginx** — داخل `server { ... }` الخاص بدومين الباك (HTTPS)، يمرر مسارات WebSocket
إلى Reverb:

```nginx
location ~ ^/(app|apps)/ {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_read_timeout 60s;
    proxy_pass http://127.0.0.1:8080;
}
```

ثم `nginx -t && systemctl reload nginx`.

**ملاحظات:**
- رسائل النظام (تأكيد حجز، مرفق جلسة) تُبث عبر الطابور لا داخل الطلب — تعتمد على
  الـ Queue Worker في القسم 2.
- `REVERB_MAX_REQUEST_SIZE` افتراضياً 65536 في `config/reverb.php`: رسالة بحدها الأقصى (2000 حرف
  عربي) تتجاوز 10 آلاف بايت، فكانت تُرفض بـ "Payload too large" بالحد الافتراضي لـ Reverb.
- مهلة اتصال Laravel بـ Reverb قصيرة (`REVERB_CLIENT_TIMEOUT=3` ثوانٍ) كي لا يعلق إرسال الرسالة
  إن لم يستجب Reverb.

**4. خطوات كل نشر** — أضف بعد `php artisan migrate`:

```bash
php artisan reverb:restart
```

**5. مرة واحدة عند الإطلاق** — المحادثة تُفتح عند حدث التأكيد فقط، فالعلاقات المؤكدة قبل
الإطلاق تحتاج إنشاء محادثاتها (آمن التكرار، بلا رسائل نظام):

```bash
php artisan chat:backfill-conversations --dry-run   # العدد فقط
php artisan chat:backfill-conversations
```

**6. الفرونت** — قيم Reverb تُدمج وقت البناء (`npm run build`)، فتُضبط في `.env` الفرونت قبله:

```bash
VITE_REVERB_APP_KEY=<نفس REVERB_APP_KEY في الباك>
VITE_REVERB_HOST=<دومين الباك>
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

بلا `VITE_REVERB_APP_KEY` تعمل المحادثة بالاستطلاع الدوري (أبطأ، بلا "يكتب الآن" ولا مؤشر الاتصال).

**مصادقة القنوات من الفرونت:** `POST /api/broadcasting/auth` بنفس توكن Sanctum
(`Authorization: Bearer ...`). القنوات: `presence-conversation.{id}` و `private-user.{id}`.
