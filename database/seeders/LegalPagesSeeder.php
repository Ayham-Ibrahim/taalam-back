<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * محتوى افتتاحي حقيقي ومبني على آلية عمل المنصة الفعلية (Stripe، وثائق توثيق
 * المعلمين، البيانات الأكاديمية، إلخ) — قابل للتعديل بالكامل لاحقاً من لوحة
 * الأدمن (LegalPageController::update)، وليس نصاً قانونياً نهائياً معتمداً.
 */
class LegalPagesSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            [
                'slug' => 'privacy',
                'sections' => [
                    [
                        'title_ar' => 'المعلومات التي نجمعها',
                        'title_en' => 'Information We Collect',
                        'body_ar' => "بيانات الحساب الأساسية: الاسم، البريد الإلكتروني، رقم الهاتف، وكلمة المرور (مشفّرة بالكامل، لا يراها أحد كنص صريح حتى فريقنا).\n\nالبيانات الأكاديمية: حسب دورك على المنصة. إن كنت طالباً، نجمع نوع تعليمك (مدرسي، جامعي، أو دورات تدريبية) والمنهج والمرحلة أو الجامعة والتخصص، وإن كنت قاصراً نجمع بيانات ولي أمرك عند التسجيل. إن كنت معلماً، نجمع مؤهلك العلمي وسنوات خبرتك والمواد والمناهج واللغات التي تُدرّس بها.\n\nوثائق التوثيق (للمعلمين فقط): إثبات الهوية، الشهادة الأكاديمية، وإثبات الخبرة. تُستخدم هذه الوثائق حصراً لمراجعة طلب التوثيق من فريقنا المختص، ولا تظهر علناً لأي طالب أو زائر إطلاقاً.\n\nبيانات الجلسات والحجوزات: تفاصيل الحجز، سجل الحضور، روابط الانضمام، وأي تسجيل للجلسة في حال تفعيله.\n\nبيانات الدفع: تُعالَج مدفوعاتك بالكامل عبر Stripe، مزوّد دفع عالمي معتمد — نحن لا نخزّن أرقام بطاقاتك المصرفية على خوادمنا إطلاقاً.\n\nبيانات الاستخدام والجهاز: نوع الجهاز والمتصفح ونظام التشغيل وعنوان IP، لأغراض الأمان وتحسين الأداء.",
                        'body_en' => "Core account data: your name, email, phone number, and password (fully encrypted — never visible as plain text, even to our own team).\n\nAcademic data: depending on your role. If you're a student, we collect your education type (school, university, or training courses) and your curriculum/grade or university/major, and if you're a minor we collect your guardian's details at registration. If you're a teacher, we collect your qualification, years of experience, and the subjects, curricula, and languages you teach.\n\nVerification documents (teachers only): proof of identity, an academic certificate, and proof of experience. These are used exclusively by our review team to process your verification request, and are never shown publicly to any student or visitor.\n\nSession and booking data: booking details, attendance records, join links, and any session recording if enabled.\n\nPayment data: your payments are processed entirely through Stripe, a certified global payment provider — we never store your card numbers on our own servers.\n\nUsage and device data: device type, browser, operating system, and IP address, for security and performance purposes.",
                    ],
                    [
                        'title_ar' => 'كيف نستخدم بياناتك',
                        'title_en' => 'How We Use Your Information',
                        'body_ar' => "لتشغيل حسابك وتوفير خدماتنا الأساسية: الحجز، إدارة الجلسات، ومعالجة المدفوعات.\n\nلمراجعة طلبات توثيق المعلمين والتحقق من مؤهلاتهم ووثائقهم قبل ظهور ملفهم للطلاب.\n\nللتواصل معك بشأن حسابك أو حجوزاتك أو أي تحديثات مهمة تخص المنصة.\n\nلتحسين تجربتك وتخصيص ما تراه من معلمين وباقات بناءً على احتياجاتك.\n\nلأغراض الأمان، منع الاحتيال، وحماية حسابك وحسابات بقية مستخدمينا.",
                        'body_en' => "To operate your account and provide our core services: booking, session management, and payment processing.\n\nTo review teacher verification requests and confirm their qualifications and documents before their profile appears to students.\n\nTo contact you about your account, your bookings, or any important platform updates.\n\nTo improve your experience and personalize the teachers and packages you see based on your needs.\n\nFor security, fraud prevention, and protecting your account and the accounts of our other users.",
                    ],
                    [
                        'title_ar' => 'مشاركة المعلومات',
                        'title_en' => 'Information Sharing',
                        'body_ar' => "لا نبيع بياناتك الشخصية لأي طرف ثالث إطلاقاً، ولا نؤجّرها أو نتاجر بها بأي شكل.\n\nعند إتمام حجز، يُشارَك اسمك وصورتك الشخصية مع الطرف الآخر في الحجز (المعلم أو الطالب) لإتمام الجلسة والتعرف على بعضكما البعض.\n\nنُشارِك بيانات الدفع اللازمة حصراً مع Stripe لمعالجة عمليات الدفع، وهو ملزم بحماية بياناتك وفق معاييره الأمنية الخاصة.\n\nقد نُفصح عن معلوماتك إذا تطلّب ذلك القانون أو إجراء قضائي رسمي، أو إذا رأينا بحسن نية أن ذلك ضروري لحماية حقوقنا أو سلامة مستخدمينا.",
                        'body_en' => "We never sell your personal data to any third party, and we never rent or trade it in any form.\n\nWhen a booking is confirmed, your name and profile photo are shared with the other party to the booking (the teacher or the student) so the session can take place and you can recognize each other.\n\nWe share only the payment data necessary with Stripe to process payments, and Stripe is bound to protect your data under its own security standards.\n\nWe may disclose your information if required by law or a formal legal process, or if we believe in good faith that doing so is necessary to protect our rights or the safety of our users.",
                    ],
                    [
                        'title_ar' => 'أمان البيانات',
                        'title_en' => 'Data Security',
                        'body_ar' => "كلمات المرور مشفّرة بالكامل، وكل اتصال بين جهازك وخوادمنا يمرّ عبر اتصال مشفّر (HTTPS).\n\nوثائق توثيق المعلمين تُخزَّن بوصول مقيّد حصراً لفريق المراجعة المختص، ولا يصل إليها أي طرف آخر.\n\nرغم كل الإجراءات المعقولة التي نتخذها، لا توجد وسيلة نقل أو تخزين إلكتروني آمنة بنسبة 100%، ونعمل باستمرار على تطوير إجراءاتنا الأمنية.",
                        'body_en' => "Passwords are fully encrypted, and every connection between your device and our servers goes through an encrypted (HTTPS) connection.\n\nTeacher verification documents are stored with access restricted exclusively to our dedicated review team — no other party can access them.\n\nDespite every reasonable measure we take, no method of electronic transmission or storage is 100% secure, and we continuously work on improving our security practices.",
                    ],
                    [
                        'title_ar' => 'خياراتك وحقوقك',
                        'title_en' => 'Your Choices and Rights',
                        'body_ar' => "يمكنك مراجعة وتعديل بيانات حسابك في أي وقت من صفحة الإعدادات في لوحة التحكم الخاصة بك.\n\nيمكنك طلب حذف حسابك وبياناته المرتبطة به بالتواصل معنا مباشرة عبر taalam@t3allem.com.\n\nيمكنك إلغاء الاشتراك من الرسائل الترويجية في أي وقت عبر رابط إلغاء الاشتراك الموجود في كل رسالة، دون أن يؤثر ذلك على إشعارات حسابك الأساسية.",
                        'body_en' => "You can review and update your account data at any time from the settings page in your dashboard.\n\nYou can request deletion of your account and its associated data by contacting us directly at taalam@t3allem.com.\n\nYou can unsubscribe from promotional messages at any time via the unsubscribe link in every email, without affecting your core account notifications.",
                    ],
                    [
                        'title_ar' => 'خصوصية القاصرين',
                        'title_en' => "Minors' Privacy",
                        'body_ar' => "يستخدم منصتنا طلاب مدرسيون قد يكونون قاصرين. عند تسجيل حساب لطالب قاصر، نجمع بيانات ولي الأمر (الاسم ورقم الهاتف) ونعتبر إنشاء الحساب أو الإشراف عليه بمثابة موافقة ولي الأمر على هذه السياسة.\n\nلا نستهدف هؤلاء المستخدمين بأي إعلانات تسويقية، ونقتصر في التواصل معهم على ما يخص تجربتهم التعليمية فقط.",
                        'body_en' => "Our platform is used by school students who may be minors. When registering an account for a minor student, we collect the guardian's details (name and phone number), and we treat the creation or supervision of that account as the guardian's consent to this policy.\n\nWe never target these users with marketing advertisements, and we limit our communication with them strictly to their learning experience.",
                    ],
                    [
                        'title_ar' => 'ملفات تعريف الارتباط والتخزين المحلي',
                        'title_en' => 'Cookies and Local Storage',
                        'body_ar' => "نستخدم التخزين المحلي في متصفحك (localStorage) للحفاظ على جلسة تسجيل دخولك وتفضيلاتك الأساسية، مثل اللغة والعملة المختارة، دون أن تُشارَك هذه البيانات مع أي طرف خارجي.",
                        'body_en' => "We use your browser's local storage to maintain your login session and basic preferences, such as your chosen language and currency, without sharing this data with any external party.",
                    ],
                    [
                        'title_ar' => 'التغييرات على هذه السياسة',
                        'title_en' => 'Changes to This Policy',
                        'body_ar' => "قد نُحدّث هذه السياسة من وقت لآخر لتعكس تطور خدماتنا أو متطلبات قانونية جديدة. سنُعلمك بأي تغييرات جوهرية عبر إشعار واضح على المنصة أو عبر بريدك الإلكتروني المسجَّل.",
                        'body_en' => "We may update this policy from time to time to reflect the evolution of our services or new legal requirements. We will notify you of any material changes through a clear notice on the platform or via your registered email.",
                    ],
                    [
                        'title_ar' => 'تواصل معنا',
                        'title_en' => 'Contact Us',
                        'body_ar' => "لأي استفسار حول سياسة الخصوصية هذه أو بياناتك، يسعدنا تواصلك معنا عبر taalam@t3allem.com.",
                        'body_en' => "For any question about this privacy policy or your data, we're happy to hear from you at taalam@t3allem.com.",
                    ],
                ],
            ],
            [
                'slug' => 'terms',
                'sections' => [
                    [
                        'title_ar' => 'قبول الشروط',
                        'title_en' => 'Acceptance of Terms',
                        'body_ar' => "باستخدامك لمنصة تعلّم بأي صفة — طالباً أو معلماً أو داعماً أكاديمياً — فإنك توافق على الالتزام بهذه الشروط والأحكام بالكامل. إن لم توافق على أي بند منها، يرجى التوقف عن استخدام المنصة.",
                        'body_en' => "By using the Ta'alam platform in any capacity — as a student, teacher, or academic supporter — you agree to be bound by these Terms & Conditions in full. If you do not agree to any part of them, please discontinue use of the platform.",
                    ],
                    [
                        'title_ar' => 'الأهلية وإنشاء الحساب',
                        'title_en' => 'Eligibility and Account Creation',
                        'body_ar' => "يجب تقديم معلومات صحيحة ودقيقة عند إنشاء حسابك، وتحديثها كلما تغيّرت. أنت المسؤول الوحيد عن سرية بيانات دخولك وعن كل نشاط يحدث على حسابك.\n\nإن كنت طالباً قاصراً، يجب أن يكون إنشاء حسابك أو الإشراف عليه بمعرفة ولي أمرك، وهو من يقدّم بيانات التواصل المطلوبة عند التسجيل.\n\nحسابات المعلمين لا تُنشأ ذاتياً عبر نموذج عام فقط — فريقنا أو المعلم نفسه عبر التسجيل المباشر ينشئ الحساب، وتبقى حالته \"غير موثَّق\" حتى تكتمل مراجعة ملفه ووثائقه من فريق الجودة.",
                        'body_en' => "You must provide accurate and truthful information when creating your account, and keep it updated whenever it changes. You are solely responsible for keeping your login credentials confidential and for all activity that occurs under your account.\n\nIf you are a minor student, your account must be created or supervised with your guardian's knowledge, and they are the ones who provide the required contact details at registration.\n\nTeacher accounts aren't created through a public form alone — either our team or the teacher themself via direct registration creates the account, and it remains \"unverified\" until our quality team completes reviewing their profile and documents.",
                    ],
                    [
                        'title_ar' => 'أدوار المستخدمين ومسؤولياتهم',
                        'title_en' => 'User Roles and Responsibilities',
                        'body_ar' => "الطالب مسؤول عن حضور جلساته في مواعيدها وعن سلوك لائق أثناء التفاعل مع المعلم.\n\nالمعلم مسؤول عن تقديم محتوى تعليمي أمين ومطابق لما أعلن عنه في ملفه الشخصي وباقاته، وعن الالتزام بمواعيد الجلسات التي وافق عليها.\n\nالداعم الأكاديمي (حصراً لطلاب الجامعات) مسؤول عن تقديم دعم أكاديمي متخصص وفق باقاته المعتمدة من الأدمن.",
                        'body_en' => "Students are responsible for attending their sessions on time and for respectful conduct while interacting with their teacher.\n\nTeachers are responsible for delivering honest educational content consistent with what's advertised on their profile and packages, and for honoring the session times they've approved.\n\nAcademic supporters (exclusively for university students) are responsible for providing specialized academic support in line with their admin-approved packages.",
                    ],
                    [
                        'title_ar' => 'توثيق المعلمين ومراجعتهم',
                        'title_en' => 'Teacher Verification and Review',
                        'body_ar' => "لا يظهر أي ملف معلم للطلاب قبل اكتمال مراجعة فريقنا لوثائقه الثلاث الإلزامية: إثبات الهوية، الشهادة الأكاديمية، وإثبات الخبرة.\n\nنحتفظ بالحق الكامل في رفض أو تعليق أو إنهاء أي حساب معلم لا يستوفي معايير الجودة والمصداقية الخاصة بنا، في أي وقت ودون الحاجة لتبرير مفصّل يتجاوز إشعار السبب الأساسي.",
                        'body_en' => "No teacher profile appears to students before our team completes reviewing their three required documents: proof of identity, an academic certificate, and proof of experience.\n\nWe reserve the full right to reject, suspend, or terminate any teacher account that doesn't meet our quality and credibility standards, at any time, without needing to provide more than a basic reason.",
                    ],
                    [
                        'title_ar' => 'الحجز والدفع والإلغاء',
                        'title_en' => 'Booking, Payment, and Cancellation',
                        'body_ar' => "يُرسَل طلب الحجز للمعلم أولاً، ولا يُطلَب منك الدفع إلا بعد موافقته الصريحة على موعدك.\n\nتُعالَج جميع المدفوعات عبر Stripe بأمان تام، ولا تُحجز الجلسة نهائياً إلا بعد إتمام الدفع خلال المهلة المحددة.\n\nفي حال عدم حضور المعلم للجلسة المتفق عليها، أو إلغاء جلسة مدفوعة مسبقاً لأسباب تخص المنصة أو المعلم، تتم معالجة استرجاع المبلغ أو تعويضه وفق سياسة المعالجة المعتمدة لدينا — للاستفسار تواصل معنا مباشرة.",
                        'body_en' => "Booking requests are sent to the teacher first, and you're only asked to pay after they explicitly approve your requested time.\n\nAll payments are processed securely through Stripe, and a session is only finally confirmed once payment is completed within the given time window.\n\nIf a teacher fails to show up for an agreed session, or a prepaid session is cancelled for reasons related to the platform or the teacher, the refund or compensation is handled according to our approved resolution process — contact us directly for any such case.",
                    ],
                    [
                        'title_ar' => 'سلوك الجلسات',
                        'title_en' => 'Session Conduct',
                        'body_ar' => "يُتوقَّع من جميع الأطراف الالتزام بالمواعيد المتفق عليها والتحلي بالاحترام المتبادل أثناء الجلسة.\n\nيُمنَع منعاً باتاً تسجيل أي جلسة أو مشاركتها دون موافقة صريحة من الطرف الآخر، باستثناء التسجيل الرسمي الذي قد توفّره المنصة نفسها عند تفعيله.\n\nأي سلوك مسيء أو غير لائق من أي طرف يُعرِّض صاحبه لتعليق أو إنهاء حسابه فوراً.",
                        'body_en' => "All parties are expected to honor agreed session times and show mutual respect throughout the session.\n\nRecording or sharing any session without the other party's explicit consent is strictly prohibited, except for the platform's own official recording feature when enabled.\n\nAny abusive or inappropriate conduct from either party may result in immediate suspension or termination of their account.",
                    ],
                    [
                        'title_ar' => 'الملكية الفكرية',
                        'title_en' => 'Intellectual Property',
                        'body_ar' => "جميع عناصر المنصة (الشعار، التصميم، النصوص، الكود البرمجي) ملك حصري لتعلّم، ولا يجوز نسخها أو إعادة توزيعها دون إذن كتابي مسبق.\n\nالمحتوى التعليمي الذي يُعدّه المعلم (شرحه، مواده الخاصة) يبقى ملكاً له، لكنه يمنح المنصة ترخيصاً لعرضه ضمن سياق تقديم الخدمة للطلاب.",
                        'body_en' => "All platform elements (logo, design, text, source code) are the exclusive property of Ta'alam, and may not be copied or redistributed without prior written permission.\n\nEducational content a teacher prepares (their explanations, their own materials) remains their property, but they grant the platform a license to display it in the context of delivering the service to students.",
                    ],
                    [
                        'title_ar' => 'حدود المسؤولية',
                        'title_en' => 'Limitation of Liability',
                        'body_ar' => "تعلّم منصة وسيطة تربط الطلاب بالمعلمين وتُشرف على جودتهم عبر عملية توثيق فعلية، لكنها ليست طرفاً في المحتوى التعليمي المقدَّم من كل معلم على حدة. نبذل أقصى جهد لضمان جودة التجربة، لكننا لا نتحمل مسؤولية أي ضرر غير مباشر ناتج عن استخدام المنصة خارج نطاق سيطرتنا المباشرة.",
                        'body_en' => "Ta'alam is an intermediary platform that connects students with teachers and oversees their quality through a real verification process, but it is not a party to the specific educational content each individual teacher provides. We make every effort to ensure a quality experience, but we are not liable for any indirect damages arising from use of the platform beyond our direct control.",
                    ],
                    [
                        'title_ar' => 'إنهاء الحساب',
                        'title_en' => 'Account Termination',
                        'body_ar' => "يمكنك طلب إغلاق حسابك في أي وقت بالتواصل معنا. كما نحتفظ بالحق في تعليق أو إنهاء أي حساب يخالف هذه الشروط، مع إشعار صاحب الحساب بالسبب حين يكون ذلك ممكناً.",
                        'body_en' => "You may request to close your account at any time by contacting us. We also reserve the right to suspend or terminate any account that violates these terms, notifying the account holder of the reason whenever practical.",
                    ],
                    [
                        'title_ar' => 'القانون الحاكم',
                        'title_en' => 'Governing Law',
                        'body_ar' => "تخضع هذه الشروط والأحكام لقوانين دولة الإمارات العربية المتحدة، وأي نزاع ينشأ عنها يُحال إلى الجهات القضائية المختصة فيها.",
                        'body_en' => "These Terms & Conditions are governed by the laws of the United Arab Emirates, and any dispute arising from them shall be referred to the competent courts therein.",
                    ],
                ],
            ],
        ];

        foreach ($pages as $page) {
            DB::table('legal_pages')->updateOrInsert(
                ['slug' => $page['slug']],
                [
                    'sections' => json_encode($page['sections'], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
