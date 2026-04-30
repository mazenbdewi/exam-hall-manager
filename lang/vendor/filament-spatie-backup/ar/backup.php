<?php

return [

    'components' => [
        'backup_destination_list' => [
            'table' => [
                'actions' => [
                    'download' => 'تحميل',
                    'delete' => 'حذف',
                ],

                'fields' => [
                    'path' => 'ملف النسخة',
                    'disk' => 'قرص التخزين',
                    'date' => 'تاريخ النسخة',
                    'size' => 'حجم النسخة',
                ],

                'filters' => [
                    'disk' => 'قرص التخزين',
                ],
            ],
        ],

        'backup_destination_status_list' => [
            'table' => [
                'fields' => [
                    'name' => 'الاسم',
                    'disk' => 'قرص التخزين',
                    'healthy' => 'الحالة',
                    'amount' => 'عدد النسخ',
                    'newest' => 'آخر نسخة احتياطية',
                    'used_storage' => 'المساحة المستخدمة',
                    'no_backups_present' => 'لا توجد نسخ احتياطية',
                ],
            ],
        ],
    ],

    'pages' => [
        'backups' => [
            'actions' => [
                'create_backup' => 'إنشاء نسخة من قاعدة البيانات',
            ],

            'heading' => 'النسخ الاحتياطي',

            'messages' => [
                'backup_success' => 'تم إنشاء نسخة قاعدة البيانات بنجاح',
                'backup_delete_success' => 'تم حذف النسخة الاحتياطية بنجاح',
            ],

            'modal' => [
                'buttons' => [
                    'only_db' => 'قاعدة البيانات',
                    'only_files' => 'الملفات',
                    'db_and_files' => 'الملفات والقاعدة',
                ],

                'label' => 'اختر نوع النسخة الاحتياطية',
            ],

            'navigation' => [
                'group' => 'إدارة النظام',
                'label' => 'النسخ الاحتياطي',
            ],
        ],
    ],

];
