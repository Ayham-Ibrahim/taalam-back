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
