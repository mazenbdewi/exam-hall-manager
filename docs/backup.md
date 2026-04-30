# النسخ الاحتياطي لقاعدة البيانات

تم إعداد النسخ الاحتياطي باستخدام:

- `shuvroroy/filament-spatie-laravel-backup`
- `spatie/laravel-backup`

النسخ في هذا المشروع مخصصة لقاعدة بيانات MySQL فقط. لا يتم نسخ ملفات التطبيق أو السورس كود أو `vendor` أو `storage` أو ملفات Excel/PDF أو المرفقات.

## الوصول من لوحة التحكم

تظهر صفحة النسخ الاحتياطي داخل لوحة Filament ضمن:

`إدارة النظام -> النسخ الاحتياطي`

الوصول والعمليات محصورة بالسوبر أدمن فقط:

- `view-backup`: عرض صفحة النسخ.
- `create-backup`: إنشاء نسخة من قاعدة البيانات.
- `download-backup`: تحميل ملف النسخة.
- `delete-backup`: حذف نسخة.
- `restore-backup`: محجوز للاسترجاع من لوحة الصيانة التقنية.

## مكان التخزين

تُخزن النسخ في disk خاص اسمه:

`backups`

ومساره:

`storage/backups`

هذا المسار خارج `public` ولا يملك رابطًا عامًا. التحميل يتم فقط من خلال Filament Action مصرح بها.

## محتوى النسخة

النسخة تحتوي على dump قاعدة بيانات MySQL فقط عبر `mysqldump`.

زر Filament ينفذ منطقيًا:

```bash
php artisan backup:run --only-db
```

## التشغيل اليدوي

إنشاء نسخة قاعدة البيانات:

```bash
php artisan backup:run --only-db
```

تنظيف النسخ القديمة:

```bash
php artisan backup:clean
```

عرض النسخ:

```bash
php artisan backup:list
```

## التشغيل من Filament

افتح صفحة `النسخ الاحتياطي` ثم استخدم زر:

`إنشاء نسخة من قاعدة البيانات`

بعد ظهور النسخة في الجدول، استخدم زر `تحميل` لتنزيل ملف ZIP إلى جهازك.

يمكن تعديل وقت النسخ اليومي من بطاقة `جدولة النسخ الاحتياطي` عبر الحقل:

`وقت النسخ الاحتياطي اليومي`

القيمة تحفظ في إعداد النظام:

`database_backup_time`

## الجدولة التلقائية

تمت جدولة:

- نسخة قاعدة بيانات فقط يوميًا في الوقت المحدد من لوحة Filament، والقيمة الافتراضية `02:00` بتوقيت `Asia/Damascus`.
- تنظيف النسخ القديمة يوميًا الساعة `03:00` بتوقيت `Asia/Damascus`.

أوامر الجدولة معرفة في `routes/console.php`:

```php
$backupTime = app(\App\Services\AppSettingsService::class)
    ->get('database_backup_time', '02:00');

Schedule::command('backup:run --only-db')
    ->dailyAt($backupTime)
    ->timezone('Asia/Damascus')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/database-backup-schedule.log'));

Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->timezone('Asia/Damascus')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/database-backup-clean.log'));
```

يجب إعداد cron على الخادم:

```cron
* * * * * cd /var/www/html/studentQr && php artisan schedule:run >> /dev/null 2>&1
```

وإذا كان مسار المشروع مختلفًا:

```cron
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

## سياسة الاحتفاظ

الإعداد الحالي يحتفظ بـ:

- كل النسخ لمدة 7 أيام.
- نسخة يومية لمدة 7 أيام.
- نسخة أسبوعية لمدة 4 أسابيع.
- نسخة شهرية واحدة.
- لا يحتفظ بنسخ سنوية.

كما يحذف الأقدم عند تجاوز مساحة النسخ `10000 MB`.

## الاستعادة

لا يوجد زر استرجاع تنفيذي في Filament حاليًا لأن استبدال قاعدة البيانات من الويب عملية عالية الخطورة.

الاسترجاع متاح فقط لمسؤول النظام من لوحة الصيانة التقنية أو الطرفية، ويجب أن يسبق ذلك إنشاء نسخة قاعدة بيانات جديدة والاحتفاظ بسجل للعملية.

## اختبار الإعداد

تحقق من وجود `mysqldump`:

```bash
which mysqldump
```

شغّل نسخة تجريبية:

```bash
php artisan backup:run --only-db
```

اعرض الجدولة:

```bash
php artisan schedule:list
```

افتح صفحة النسخ في Filament وتأكد من ظهور:

- زر `إنشاء نسخة من قاعدة البيانات`.
- بطاقة `جدولة النسخ الاحتياطي`.
- زر `تحميل`.
- زر `حذف`.
- حقل `وقت النسخ الاحتياطي اليومي`.
