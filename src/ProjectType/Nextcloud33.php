<?php
return [
    'cron' => 'ddev exec -s nextcloud -u www-data "php -f /var/www/html/cron.php"',
    'afterUpdateCodeTasks' => [
        [
            'name' => 'Enable strict transport security',
            'ansible.builtin.replace' => [
                'regexp' => '^\s*#+\s*(add_header Strict-Transport-Security)',
                'replace' => '\1',
                'dest' => '{{ ansistrano_release_path }}/.ddev/nextcloud.conf',
            ]
        ],
        [
            'name' => 'Add hostname to nginx nextcloud configuration',
            'ansible.builtin.replace' => [
                'regexp' => '^\s*server_name\s+\$\{DDEV_HOSTNAME\};\s*$',
                'replace' => 'server_name {{ (ddev_hostname_config.content | b64decode | from_yaml).additional_fqdns | first }};',
                'dest' => '{{ ansistrano_release_path }}/.ddev/nextcloud.conf',
            ]
        ],
    ],
    'envFiles' => [
        'web' => <<<ENVARS
            DDEV_CRON_JOB_MINUTE=*/5
            DDEV_CRON_JOB_HOUR=*
            DDEV_CRON_JOB_DAY=*
            DDEV_CRON_JOB_MONTH=*
            DDEV_CRON_JOB_WEEKDAY=*

        'nextcloud' => <<<ENVVARS
            SMTP_HOST=smtp.example.com
            SMTP_SECURE=yes
            SMTP_NAME=username
            SMTP_PASSWORD=password
            MAIL_FROM_ADDRESS=username
            MAIL_DOMAIN=example.com
            ENVVARS,
    ],
];
