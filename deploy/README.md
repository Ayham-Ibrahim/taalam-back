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
