<?php
return [
    'cron' => 'ddev drush cron',
    'beforeSymlinkTasks' = [
        [
            'name' => 'Composer install',
            'shell' => 'ddev composer install --no-interaction --no-dev --optimize-autoloader',
            'args' => [
                'chdir' => '{{ ansistrano_release_path }}',
            ],
        ],
        [
            'name' => 'Deploy drupal',
            'shell' => 'ddev drush deploy',
            'args' => [
                'chdir' => '{{ ansistrano_release_path }}',
            ],
        ];
    ],
    'envFiles' => [
        'web' => <<<ENVVARS
            DRUPAL_ENABLED_SPLITS=production
            COMPOSER_NO_DEV=1
            DRUPAL_UPDATE_NOTIFICATION_EMAILS=admin@example.com
            ENVVARS,
    ],
];
