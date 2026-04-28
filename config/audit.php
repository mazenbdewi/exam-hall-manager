<?php

return [
    'enabled' => env('AUDIT_LOG_ENABLED', true),

    'async' => env('AUDIT_LOG_ASYNC', true),

    'log_model_changes' => env('AUDIT_LOG_MODEL_CHANGES', true),

    'retention_days' => env('AUDIT_LOG_RETENTION_DAYS', 180),

    'queue' => env('AUDIT_LOG_QUEUE', 'audit'),

    'max_json_items' => 80,

    'max_string_length' => 1000,

    'excluded_fields' => [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'remember_token',
        'api_token',
        'api_key',
        'access_token',
        'refresh_token',
        'secret',
        'private_key',
        'otp',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ],
];
