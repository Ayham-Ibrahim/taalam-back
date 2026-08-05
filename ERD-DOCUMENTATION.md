# TAALAM Backend — ERD & Schema Documentation

> **الإصدار:** v1.0 · **Laravel 11 + MySQL 8**
> **المرجع:** Policies v1.0 · SRS v1.0 · قرارات معمارية محسومة

---

## نظرة عامة

**52 جدولاً** موزعة على 11 migration، مرتبة بحيث لا يوجد forward reference في الـ foreign keys.

| # | Migration | الجداول | الغرض |
|---|---|---|---|
| 01 | users | 4 | الهويات، الدعوات، الجلسات |
| 02 | taxonomy | 8 | التصنيفات التي يديرها الأدمن |
| 03 | profiles | 5 | ملفات المدرس (شاملاً المركز) والطالب |
| 04 | verification | 3 | الوثائق والشارات |
| 05 | packages | 6 | الباقات والجداول والتوفر |
| 06 | courses | 3 | الدورات التدريبية (للمراكز) |
| 07 | bookings | 4 | الحجوزات والجلسات والحضور |
| 08 | payments | 4 | المدفوعات والاسترداد والمستحقات |
| 09 | requests | 4 | طلبات التغيير والشكاوى |
| 10 | system | 11 | التقييمات، الإشعارات، التدقيق، الإعدادات |
| 11 | constraints | — | قيود CHECK و Triggers |

---

## 1. آلية التسعير — الأهم في النظام

### التدفق الكامل

```
┌─────────────┐
│  المعلم     │  يُدخل teacher_price فقط
└──────┬──────┘
       │ يرسل للمراجعة
       ▼
  status = pending_approval
  platform_margin_percent = NULL
  student_price = NULL
       │
       ▼
┌─────────────┐
│  الأدمن     │  يُدخل platform_margin_percent  ← لكل باقة على حدة
└──────┬──────┘
       │ يوافق
       ▼
  student_price   = teacher_price × (1 + margin/100)
  platform_revenue = student_price - teacher_price
  status = active
       │
       ▼
   ظاهرة للطلاب — السعر مُجمَّد لا يتغير
```

### لماذا لا نستخدم GENERATED COLUMN؟

لأن النسبة **تُحدَّد لاحقاً** عند الموافقة وليست ثابتة عالمياً. الحقول الثلاثة تُملأ في لحظة الموافقة وتُجمَّد.

```sql
-- عند الموافقة (في PackageApprovalService)
UPDATE packages SET
  platform_margin_percent = 60.00,
  student_price   = teacher_price * 1.60,
  platform_revenue = teacher_price * 0.60,
  status = 'active',
  approved_at = NOW(),
  approved_by = :admin_id
WHERE id = :package_id;
```

### تجميد السعر في الحجز

عند إنشاء الحجز، تُنسَخ القيم إلى `bookings` كـ snapshot:

```
bookings.amount_paid             ← ما دفعه الطالب
bookings.teacher_amount          ← مستحق المعلم
bookings.platform_amount         ← عائد المنصة
bookings.margin_percent_snapshot ← النسبة وقت الحجز
```

هذا يضمن أن تغيير سعر الباقة لاحقاً **لا يؤثر** على الحجوزات القائمة.

### رؤية الأسعار

| الطرف | teacher_price | student_price | margin_percent | platform_revenue |
|---|:---:|:---:|:---:|:---:|
| Admin | ✅ | ✅ | ✅ | ✅ |
| Teacher | ✅ | ✅ | ❌ | ❌ |
| Student | ❌ | ✅ | ❌ | ❌ |

يُطبَّق عبر **API Resources** منفصلة لكل دور — لا يُترك للـ frontend.

---

## 2. أنماط الحجز الثلاثة

| النمط | الجدول المحدد للمواعيد | من يختار | الجدول التنفيذي |
|---|---|---|---|
| **فردي** | `availability_slots` (pool) | الطالب | `bookings` → `class_sessions` |
| **مجموعة** | `package_schedules` (ثابت) | لا اختيار | `bookings` (عدة طلاب) → `class_sessions` مشتركة |
| **دورة** | `course_schedules` (صارم) | لا اختيار | `enrollments` → `class_sessions` |

### التمييز في قاعدة البيانات

```
packages.session_format = 'individual'
  → capacity = 1
  → الطالب يحجز من availability_slots
  → كل حجز له class_session خاصة

packages.session_format = 'group'
  → capacity = N (يحدده المعلم)
  → package_schedules فيه الجدول المشترك
  → عدة bookings تشترك في نفس class_session
  → عند enrolled_count == capacity → status = 'full'
```

### جدول session_attendees

جوهري لأن جلسة المجموعة/الدورة فيها **عدة طلاب**. يربط:
- `class_session_id` — الجلسة
- `student_id` — الطالب
- `booking_id` أو `enrollment_id` — مصدر الاستحقاق
- `attendance` — حالة الحضور لهذا الطالب تحديداً

---

## 3. دورات حياة الحالات (State Machines)

### حساب المعلم

```
created → invited → active_unverified → pending_verification → verified
                                                                  ↕
                                                             suspended
                                                                  ↓
                                                             rejected
```

**القيود:**
- لا يمكن إنشاء باقة إلا في حالة `verified`
- لا يظهر في البحث إلا `verified` + لديه باقة `active`

### الباقة

```
draft → pending_approval → active → full
   ↑           ↓              ↓
   └──────  rejected      disabled → archived
```

**نقاط حرجة:**
- `platform_margin_percent` تُملأ فقط في الانتقال `pending_approval → active`
- `active → full` يحدث تلقائياً عند `enrolled_count == capacity`
- تعديل سعر باقة `active` **ممنوع** — يجب تعطيلها وإنشاء جديدة

### الحجز

```
pending_payment → confirmed → active → completed
       ↓              ↓          ↓
    expired      cancelled   cancelled → refunded
```

`hold_expires_at` = وقت الإنشاء + 15 دقيقة. Job دوري ينظّف المنتهية.

### الجلسة

```
scheduled → active → completed
    ↓                    
reschedule_pending → rescheduled → scheduled
    ↓
cancelled / suspended / no_show_student / no_show_teacher
```

---

## 4. طلبات تغيير المواعيد

### القاعدة المحسومة: موافقة الأدمن إلزامية دائماً

لا يوجد مسار تلقائي إطلاقاً. الفرق بين ما قبل/بعد 24 ساعة:

| | خلال 24 ساعة | بعد 24 ساعة |
|---|---|---|
| `within_free_window` | `true` | `false` |
| `reason` | اختياري | **إلزامي** |
| الأولوية في قائمة الأدمن | عالية | عادية |
| SLA | 12 ساعة | 12 ساعة |

جميع الطلبات تبدأ بـ `status = 'pending'`.

### التمييز بين نوعي الطلبات

| الجدول | النطاق | أمثلة |
|---|---|---|
| `reschedule_requests` | جلسة واحدة | تغيير موعد جلسة الثلاثاء |
| `change_requests` | باقة/دورة كاملة | تجميد الدورة، استبدال مدرب، إعادة جدولة السلسلة |

`change_requests` يستخدم `morphs('changeable')` ليخدم Package و Course معاً، مع `payload` JSON مرن حسب نوع الطلب.

---

## 5. سياسة الغياب

### القواعد المُنفَّذة

```
غياب الطالب بإشعار مسبق ≥ 6 ساعات
  → session_attendees.absence_notified_at مملوء
  → deducted_from_balance = false
  → الجلسة تُعاد جدولتها

غياب الطالب بدون إشعار
  → attendance = 'absent'
  → deducted_from_balance = true  (تُحتسب من رصيده)
  → المعلم يستلم مستحق وقت الانتظار فقط

غياب المعلم
  → class_sessions.status = 'no_show_teacher'
  → teachers.no_show_count++
  → deducted_from_balance = false للطالب
  → جلسة تعويضية مجانية: is_makeup = true, makeup_for_session_id
  → المعلم لا يستلم مستحق هذه الجلسة
```

### تتبع تكرار غياب المعلم

`teachers.no_show_count` يزداد تلقائياً. عند بلوغ العتبة (من settings):
- 1 → تحذير تلقائي
- 3 → تعليق الحساب

---

## 6. الأمان والتدقيق

### RULE-05 — Audit Log

كل إجراء حساس يُسجَّل في `audit_logs`:

```
user_id · action · model_type · model_id
old_values (JSON) · new_values (JSON)
ip_address · user_agent · created_at
```

**الإجراءات الإلزامية التسجيل:**
- `package.approved` / `package.rejected` — مع النسبة المحددة
- `booking.manual_created` — حجز الأدمن نيابةً عن طالب
- `document.approved` / `document.rejected`
- `badge.granted` / `badge.revoked`
- `refund.issued`
- `teacher.suspended` / `teacher.rejected`
- `reschedule.approved` / `reschedule.rejected`
- `settings.updated`

### RULE-06 — تخزين الوثائق

`verification_documents.s3_path` — مسار خاص في S3، غير قابل للوصول العام.
الوصول عبر **Pre-signed URL بصلاحية 15 دقيقة** فقط.

---

## 7. الحجز اليدوي من الأدمن

```
bookings.is_manual            = true
bookings.created_by_admin_id  = <admin user id>
bookings.manual_reason        = "تعويض عن جلسة ملغاة — شكوى #C-1042"
```

عند الحجز اليدوي:
- لا يُنشأ `payment` بحالة Stripe (أو يُنشأ بـ `method = 'manual'`)
- يُسجَّل إلزامياً في `audit_logs`
- يظهر في تقارير الأدمن منفصلاً عن الحجوزات العادية

---

## 8. المخططات العلائقية الرئيسية

### دائرة المعلم

```
users (role=teacher)
  └─1:1─ teachers (teacher_type = 'school' | 'university')
           ├─1:N─ verification_documents
           ├─1:N─ badge_awards
           ├─N:M─ subjects / curricula / languages
           ├─1:N─ availability_slots
           ├─1:N─ teacher_blackouts
           └─1:N─ packages
                    ├─1:N─ package_schedules   (للمجموعة)
                    ├─N:M─ curricula / stages  (الاستهداف)
                    └─1:N─ bookings
```

### دائرة الحجز والجلسة

```
students ──1:N── bookings ──1:N── class_sessions
                    │                    │
                    │                    ├─1:N─ session_attendees
                    │                    ├─1:N─ reschedule_requests
                    │                    └─1:N─ learning_materials (morph)
                    │
                    ├─1:N─ payments ──1:N─ refunds
                    └─1:1─ reviews
```

### دائرة المركز التدريبي

⚠️ المركز **ليس كياناً مستقلاً** — هو حساب مدرس بنوع `training_center`.

```
users (role=teacher)
  └─1:1─ teachers (teacher_type = 'training_center')
           ├─1:N─ verification_documents
           ├─1:N─ badge_awards
           └─1:N─ courses          ← الفرق الوحيد: دورات بدل باقات
                    ├─1:N─ course_schedules
                    ├─N:M─ curricula
                    └─1:N─ enrollments ──1:N── payments
```

---

## 9. الفهارس المهمة (Performance)

```sql
-- البحث في السوق
teachers (status, teacher_type)
teachers (ranking_score)        -- ترتيب النتائج
packages (status, session_format)
packages (teacher_id, status)

-- التقويم والجلسات
class_sessions (teacher_id, scheduled_at)
class_sessions (status, scheduled_at)
availability_slots (teacher_id, day_of_week)

-- لوحات التحكم
bookings (student_id, status)
bookings (teacher_id, status)
payments (student_id, status)

-- التدقيق
audit_logs (model_type, model_id)
audit_logs (user_id, created_at)
```

---

## 10. الخطوات التالية

بعد اعتماد الـ schema:

1. **Models + Relationships** — Eloquent models مع العلاقات والـ casts
2. **Policies** — Laravel Policy class لكل model حسب مصفوفة RBAC
3. **State Machine** — تنفيذ الانتقالات المسموحة مع الحماية
4. **Services** — PackageApprovalService، BookingService، RescheduleService
5. **API Resources** — Resource منفصل لكل دور (إخفاء الأسعار حسب الصلاحية)
6. **Form Requests** — validation لكل endpoint
7. **Jobs** — تنظيف الحجوزات المنتهية، حساب ranking_score، تذكيرات BBB
8. **Observers** — تسجيل audit_logs تلقائياً

---

## ملاحظات تنفيذية للمطورين

```php
// ❌ خطأ — لا تحسب السعر في الكود
$studentPrice = $teacherPrice * 1.60;

// ✅ صحيح — النسبة تأتي من الباقة نفسها
$studentPrice = $package->teacher_price * (1 + $package->platform_margin_percent / 100);

// ❌ خطأ — لا تعتمد على قيمة ثابتة
if ($hoursSinceBooking < 24) { $request->approve(); }

// ✅ صحيح — كل طلب يمر بالأدمن
$request->update(['status' => 'pending', 'within_free_window' => $hoursSinceBooking < setting('reschedule_free_window_hours')]);
```

---

## 11. ⚠️ قرار معماري: المركز التدريبي ليس كياناً مستقلاً

### التغيير

المركز التدريبي **حساب مدرس** بنوع مختلف — لا جدول منفصل، لا أساتذة تابعين له.

```
teachers.teacher_type = 'school'           → يقدّم باقات فقط
teachers.teacher_type = 'university'       → يقدّم باقات فقط
teachers.teacher_type = 'training_center'  → يقدّم دورات فقط
```

### الجداول المحذوفة

| الجدول | لماذا حُذف |
|---|---|
| `training_centers` | المركز صار صفاً في `teachers` |
| `center_trainer` | لا يوجد أساتذة تابعون للمركز |
| `course_trainer` | الدورة تتبع `teacher_id` مباشرة |

### العلاقات المُبسَّطة

جميع العلاقات التي كانت polymorphic أصبحت foreign key مباشراً:

| الجدول | قبل | بعد |
|---|---|---|
| `verification_documents` | `morphs('documentable')` | `teacher_id` |
| `badge_awards` | `morphs('awardable')` | `teacher_id` |
| `payouts` | `morphs('payable')` | `teacher_id` |
| `reviews` | `morphs('reviewable')` | `teacher_id` |

هذا يبسّط الاستعلامات ويحسّن الأداء (فهارس أوضح، لا حاجة لفلترة `_type`).

### حقول المركز الاختيارية

مضافة في `teachers`، `nullable` للأنواع الأخرى:

```
display_name_en      اسم المركز بالإنجليزية
logo_path            شعار المركز
commercial_register  السجل التجاري
website              الموقع الإلكتروني
address              العنوان
city                 المدينة
```

في الواجهات: تظهر هذه الحقول فقط عندما `teacher_type = 'training_center'`.

### فرض القاعدة على مستوى قاعدة البيانات

Trigger يمنع الخلط نهائياً — حتى لو أخطأ كود التطبيق:

```sql
trg_packages_teacher_type
  → يرفض INSERT في packages إذا كان teacher_type = 'training_center'

trg_courses_teacher_type
  → يرفض INSERT في courses إذا كان teacher_type <> 'training_center'
```

### الأثر على الكود

```php
// Model: Teacher
public function isTrainingCenter(): bool
{
    return $this->teacher_type === 'training_center';
}

public function offerings()
{
    return $this->isTrainingCenter()
        ? $this->courses()
        : $this->packages();
}

// Policy: PackagePolicy
public function create(User $user): bool
{
    return $user->teacher
        && $user->teacher->status === 'verified'
        && ! $user->teacher->isTrainingCenter();
}

// Policy: CoursePolicy
public function create(User $user): bool
{
    return $user->teacher
        && $user->teacher->status === 'verified'
        && $user->teacher->isTrainingCenter();
}
```

### الأثر على البحث في السوق

فلتر واحد يخدم الثلاثة:

```php
Teacher::query()
    ->where('status', 'verified')
    ->when($type, fn($q) => $q->where('teacher_type', $type))
    // school | university → لهم packages نشطة
    // training_center     → لهم courses نشطة
    ->orderByDesc('ranking_score');
```

---

## 12. قيود قاعدة البيانات (Migration 11)

**17 قيد CHECK + 2 Trigger** كخط دفاع أخير:

| القيد | يحمي من |
|---|---|
| `chk_packages_capacity` | فردي بنصاب ≠ 1، أو مجموعة بنصاب < 2 |
| `chk_packages_enrolled` | تجاوز النصاب |
| `chk_packages_student_price` | سعر طالب أقل من سعر المعلم |
| `chk_packages_active_pricing` | باقة نشطة بلا نسبة أو سعر نهائي |
| `chk_courses_dates` | تاريخ نهاية قبل البداية |
| `chk_courses_seats` | تجاوز المقاعد |
| `chk_bookings_sessions` | رصيد جلسات غير متسق |
| `chk_bookings_amounts` | المبلغ المدفوع ≠ مستحق المدرس + عائد المنصة |
| `chk_bookings_manual` | حجز يدوي بلا سبب أو بلا هوية أدمن |
| `chk_*_sched_time` | وقت نهاية قبل البداية، أو يوم خارج 0-6 |
| `chk_reviews_rating` | تقييم خارج 1-5 |
