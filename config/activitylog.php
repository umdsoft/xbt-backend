<?php

declare(strict_types=1);

use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    'clean_after_days' => 365,

    'default_log_name' => 'default',

    'default_auth_driver' => null,

    'include_soft_deleted_subjects' => false,

    /*
     * HR domeni — UUID + tenant-aware audit modeli (`hr` ulanishi, public schema).
     */
    'activity_model' => App\Domains\Hr\Models\Activity::class,

    'default_except_attributes' => [],

    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
