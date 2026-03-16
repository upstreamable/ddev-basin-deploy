<?php

namespace Basin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Attribute\Argument;

#[AsCommand(name: 'deploy:generate')]
class DeployGenerate
{
    public function __invoke(
        #[Argument('The environment to create defaults for.')] string $environment = 'production',
        OutputInterface $output
    ): int
    {
        $ddevDockerCompose = Yaml::parseFile(getenv('DDEV_APPROOT') . '/.ddev/.ddev-docker-compose-full.yaml');
        $ddevFilesDirs = $ddevDockerCompose['services']['web']['environment']['DDEV_FILES_DIRS'];
        // Get the upload dirs as an array of relative paths like:
        // ['web/sites/default/files'].
        $ddevUploadDirs = array_map(function ($uploadDir) {
            return str_replace(getenv('DDEV_APPROOT') . '/', '', $uploadDir);
        }, explode(',', $ddevFilesDirs));

        $afterUpdateCodeTasksPath = getenv('DDEV_APPROOT') . '/.ddev/deploy-after-update-code.yml';

        $ansiblePlaybook = [
            'name' => 'Ansistrano deploy',
            'hosts' => 'all',
            'vars' => [
                'ansistrano_deploy_from' => "{{ lookup('env','DDEV_APPROOT') }}/",
                'ansistrano_deploy_to' => "~/deploy/{{ lookup('env','DDEV_PROJECT') }}-{{ lookup('env','DDEV_ANSIBLE_ENVIRONMENT') | default('production', true) }}",
                'ansistrano_keep_releases' => 3,
                'ansistrano_deploy_via' => 'rsync',
                'ansistrano_after_update_code_tasks_file' => $afterUpdateCodeTasksPath,
                'ansistrano_before_cleanup_tasks_file' => "{{ lookup('env','DDEV_APPROOT') }}/.ddev/ansible/tasks/before-cleanup-tasks.yml",
                'ansistrano_rsync_extra_params' => [
                    "--filter='merge " . getenv('DDEV_APPROOT') . "/.ddev/ansible/rsync-filter'",
                ],
                'ddev_project_name' => "{{ lookup('env','DDEV_PROJECT') }}",
                'ddev_approot' => "{{ lookup('env','DDEV_APPROOT') }}",
                'ddev_upload_dirs' => $ddevUploadDirs,
                'ddev_redirect_https' => "{{ lookup('env','DDEV_REDIRECT_HTTPS') | default('true', true) }}",
                'ddev_ansible_environment' => "{{ lookup('env','DDEV_ANSIBLE_ENVIRONMENT') | default('production', true) }}",
                'ddev_cron_job_minute' => "{{ lookup('env','DDEV_CRON_JOB_MINUTE') | default(60 | random(), true) }}",
                'ddev_cron_job_hour' => "{{ lookup('env','DDEV_CRON_JOB_HOUR') | default(6 | random(start=1), true) }}",
                'ddev_cron_job_day' => "{{ lookup('env','DDEV_CRON_JOB_DAY') | default('*', true) }}",
                'ddev_cron_job_month' => "{{ lookup('env','DDEV_CRON_JOB_MONTH') | default('*', true) }}",
                'ddev_cron_job_weekday' => "{{ lookup('env','DDEV_CRON_JOB_WEEKDAY') | default('*', true) }}",
            ],
            'roles' => [[
                'role' => 'ansistrano.deploy',
            ]],
        ];

        // Exclude uploaded files from the sync.
        foreach ($ddevUploadDirs as $dir) {
            $ansiblePlaybook['vars']['ansistrano_rsync_extra_params'][] = "--filter='exclude /" . $dir ."'";
        }

        $afterUpdateCodeTasks = [];
        $afterUpdateCodeTasks[] = [
            'name' => 'Get status of current release',
            'stat' => [
                'path' => "{{ ansistrano_deploy_to }}/current",
            ],
            'register' => 'current_release',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Register the current release version',
            'ansible.builtin.slurp' => [
                'src' => "{{ ansistrano_deploy_to }}/current/REVISION",
            ],
            'register' => 'ansistrano_current_release_version',
            'when' => 'current_release.stat.exists',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Stop current release',
            'shell' => 'ddev stop',
            'args' => [
                'chdir' => "{{ ansistrano_deploy_to }}/current",
            ],
            'when' => 'current_release.stat.exists',
            'register' => 'stop_result',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Log the standard output of stopping the current release',
            'copy' => [
                'content' => '{{ stop_result.stdout }}',
                'dest' =>
                '{{ ansistrano_shared_path }}/{{ ansistrano_release_version }}.stop-current.stdout.log.txt',
            ],
            'when' => 'current_release.stat.exists',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Log the standard error output of stopping the current release',
            'copy' => [
                'content' => '{{ stop_result.stderr }}',
                'dest' =>
                '{{ ansistrano_shared_path }}/{{ ansistrano_release_version }}.stop-current.stderr.log.txt',
            ],
            'when' => 'current_release.stat.exists',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Copy upload_dirs from the current release to the next one',
            'copy' => [
                'src' => "{{ ansistrano_deploy_to }}/current/{{ item }}",
                'dest' => '{{ ansistrano_release_path.stdout }}/{{ item }}',
                'remote_src' => TRUE,
            ],
            'loop' => '{{ ddev_upload_dirs }}',
            'when' => 'current_release.stat.exists',
        ];

        // TODO: Inspect volumes to avoid hardcoding mariadb.
        $afterUpdateCodeTasks[] = [
            'name' => 'Copy volumes from the current release to the next one',
            'shell' => 'docker container run --rm ' .
            '--volume {{ ddev_project_name }}-{{ ddev_ansible_environment }}-{{ ansistrano_current_release_version.content | b64decode | lower }}-mariadb:/from ' .
            '--volume {{ ddev_project_name }}-{{ ddev_ansible_environment }}-{{ ansistrano_release_version | lower }}-mariadb:/to ' .
            'alpine:3.23.3 sh -c "cd /from ; cp -a . /to"',
            'when' => 'current_release.stat.exists',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Config the app to include environment and version',
            'shell' => 'ddev config --project-name "{{ ddev_project_name }}-{{ ddev_ansible_environment }}-{{ ansistrano_release_version | lower }}"',
            'args' => [
                'chdir' => '{{ ansistrano_release_path.stdout }}',
            ],
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Get possible environment overrides',
            'stat' => [
                'path' => "{{ ansistrano_release_path.stdout }}/.ddev/deploy.{{ ddev_ansible_environment }}.env.web",
            ],
            'register' => 'ddev_env_web_overrides',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Copy web env web overrides',
            'copy' => [
                'src' => "{{ ansistrano_release_path.stdout }}/.ddev/deploy.{{ ddev_ansible_environment }}.env.web",
                'dest' => "{{ ansistrano_release_path.stdout }}/.ddev/.env.web",
                'remote_src' => TRUE,
            ],
            'when' => 'ddev_env_web_overrides.stat.exists',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Get possible hostname overrides',
            'stat' => [
                'path' => "{{ ansistrano_release_path.stdout }}/.ddev/deploy.{{ ddev_ansible_environment }}.hostname.config.yaml",
            ],
            'register' => 'ddev_hostname_overrides',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Copy hostname overrides',
            'copy' => [
                'src' => "{{ ansistrano_release_path.stdout }}/.ddev/deploy.{{ ddev_ansible_environment }}.hostname.config.yaml",
                'dest' => "{{ ansistrano_release_path.stdout }}/.ddev/config.hostname.yaml",
                'remote_src' => TRUE,
            ],
            'when' => 'ddev_hostname_overrides.stat.exists',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Register the hostname configuration',
            'ansible.builtin.slurp' => [
                'src' => "{{ ansistrano_release_path.stdout }}/.ddev/config.hostname.yaml",
            ],
            'register' => 'ddev_hostname_config',
            'when' => 'ddev_hostname_overrides.stat.exists',
        ];

        $afterUpdateCodeTasks[] = [
            'name' => 'Configure HTTPS overrides for traefik to issue letsencrypt certificates only for additional_fqdns',
            'ansible.builtin.template' => [
                'src' => getenv('DDEV_APPROOT') . '/.ddev/ansible/traefik-https-overrides.yaml',
                'dest' => "{{ ansistrano_release_path.stdout }}/.ddev/traefik/config/deploy.yaml",
            ],
            'when' => 'ddev_hostname_overrides.stat.exists',
        ];

        $afterUpdateCodeTasks[] = [
            'name' => 'Start the app',
            'shell' => 'ddev start',
            'args' => [
                'chdir' => '{{ ansistrano_release_path.stdout }}',
            ],
            'register' => 'start_result',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Log the standard output of starting the app',
            'copy' => [
                'content' => '{{ start_result.stdout }}',
                'dest' => '{{ ansistrano_shared_path }}/{{ ansistrano_release_version }}.start.stdout.log.txt',
            ],
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Log the error output of starting the app',
            'copy' => [
                'content' => '{{ start_result.stderr }}',
                'dest' => '{{ ansistrano_shared_path }}/{{ ansistrano_release_version }}.start.stderr.log.txt',
            ],
        ];

        // TODO: do not hardcode drush.
        $ansibleCronConfig = [
            'name' => 'DDEV cron job for {{ ddev_project_name }}-{{ ddev_ansible_environment }}',
            'job' => 'cd {{ ansistrano_deploy_to }}/current && ddev drush cron ',
            'minute' => '{{ ddev_cron_job_minute }}',
            'hour' => '{{ ddev_cron_job_hour }}',
            'day' => '{{ ddev_cron_job_day }}',
            'month' => '{{ ddev_cron_job_month }}',
            'weekday' => '{{ ddev_cron_job_weekday }}',
        ];

        $afterUpdateCodeTasks[] = [
            'name' => 'Install cron job on the host machine',
            'cron' => $ansibleCronConfig,
        ];

        file_put_contents(
            $afterUpdateCodeTasksPath,
            Yaml::dump(
                input: $afterUpdateCodeTasks ,
                inline: 4,
                flags: Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
            )
        );

        $ansiblePlaybookPath = getenv('DDEV_APPROOT') . '/.ddev/deploy.yaml';

        file_put_contents(
            $ansiblePlaybookPath,
            Yaml::dump(
                input: [$ansiblePlaybook],
                inline: 4,
                flags: Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
            )
        );

        $output->writeln('/.ddev/deploy.yaml generated');

        $deployConfigPath = getenv('DDEV_APPROOT') . '/.ddev/config.basin-deploy.yaml');
        if (!file_exists($deployConfigPath)) {
            copy(__DIR__ '/../Templates/config.basin-deploy.yaml', $deployConfigPath);
            $output->writeln('/.ddev/config.basin-deploy.yaml generated. Edit it to complete the server details');
        }

        $ddevConfig = Yaml::parseFile(getenv('DDEV_APPROOT') . '/.ddev/config.yaml');
        $deployEnvironmentPath = getenv('DDEV_APPROOT') . '/.ddev/deploy.' . $environment . '.env.web');
        if (!file_exists($deployEnvironmentPath) && str_starts_with($ddevConfig['type'], 'drupal')) {
            copy(__DIR__ '/../Templates/deploy.drupal.env.web', $deployEnvironmentPath);
            $output->writeln('/.ddev/deploy.' . $environment . '.env.web generated for "' . $ddevConfig['type'] . '". Edit it to complete the environment details');
        }

        $deployHostnamePath = getenv('DDEV_APPROOT') . '/.ddev/deploy.' . $environment . '.hostname.config.yaml');
        if (!file_exists($deployHostnamePath)) {
            copy(__DIR__ '/../Templates/deploy.hostname.config.yaml', $deployEnvironmentPath);
            $output->writeln('/.ddev/deploy.' . $environment . '.hostname.config.yaml generated. Edit it to complete the hostname details');
        }

        return Command::SUCCESS;
    }
}
