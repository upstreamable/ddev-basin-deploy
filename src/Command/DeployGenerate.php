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
        OutputInterface $output,
        #[Argument('The environment to create defaults for.')] string $environment = 'production',
    ): int
    {
        $ddevDockerCompose = Yaml::parseFile(getenv('DDEV_APPROOT') . '/.ddev/.ddev-docker-compose-full.yaml');

        $projectType = $this->getProjectType($ddevDockerCompose);

        if (!$projectType) {
            $output->writeln('Project type not supported');
        }

        $projectTypeConfig = require __DIR__  . '/../ProjectType/' . $projectType . '.php';

        $ddevFilesDirs = $ddevDockerCompose['services']['web']['environment']['DDEV_FILES_DIRS'];
        // Get the upload dirs as an array of relative paths like:
        // ['web/sites/default/files'].
        $ddevUploadDirs = empty($ddevFilesDirs) ? [] : array_map(function ($uploadDir) {
            return str_replace(getenv('DDEV_APPROOT') . '/', '', $uploadDir);
        }, explode(',', $ddevFilesDirs));

        $afterUpdateCodeTasksPath = getenv('DDEV_APPROOT') . '/.ddev/deploy.after-update-code.yml';

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
                'ddev_services' => array_keys($ddevDockerCompose['services']),
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
            'name' => 'Log the output of stopping the current release',
            'copy' => [
                'content' => "STDOUT\n{{ stop_result.stdout }}\nSTDERR\n{{ stop_result.stderr }}",
                'dest' => '{{ ansistrano_release_path }}/ansible-ddev-stop-previous-current.log.txt',
            ],
            'when' => 'current_release.stat.exists',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Copy upload_dirs from the current release to the next one',
            'copy' => [
                // Trailing slash is important to copy the contents of src and
                // avoiding a structure like `/files/files/files`, etc.
                'src' => "{{ ansistrano_deploy_to }}/current/{{ item }}/",
                'dest' => '{{ ansistrano_release_path }}/{{ item }}',
                'remote_src' => true,
            ],
            'loop' => '{{ ddev_upload_dirs }}',
            'when' => 'current_release.stat.exists',
        ];

        foreach ($ddevDockerCompose['volumes'] as $volumeName => $volume) {
            // Consider only volumes that start with the project name ignoring
            // volumes like ddev-global-cache
            if (!str_starts_with($volume['name'], getenv('DDEV_PROJECT'))) {
                continue;
            }
            $name = str_replace(getenv('DDEV_PROJECT') . '-', '', $volume['name']);

            $afterUpdateCodeTasks[] = [
                'name' => 'Copy volume "' . $volumeName . '"(' . $name . ')  from the current release to the next one',
                'shell' => 'docker container run --rm ' .
                '--volume {{ ddev_project_name }}-{{ ddev_ansible_environment }}-{{ ansistrano_current_release_version.content | b64decode | lower }}-' . $name . ':/from ' .
                '--volume {{ ddev_project_name }}-{{ ddev_ansible_environment }}-{{ ansistrano_release_version | lower }}-' . $name . ':/to ' .
                'alpine:3.23.3 sh -c "cd /from ; cp -a . /to"',
                'when' => 'current_release.stat.exists',
            ];
        }
        $afterUpdateCodeTasks[] = [
            'name' => 'Config the app to include environment and version',
            'shell' => 'ddev config --project-name "{{ ddev_project_name }}-{{ ddev_ansible_environment }}-{{ ansistrano_release_version | lower }}"',
            'args' => [
                'chdir' => '{{ ansistrano_release_path }}',
            ],
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Get possible environment overrides',
            'stat' => [
                'path' => "{{ ansistrano_release_path }}/.ddev/deploy.{{ ddev_ansible_environment }}.env.{{ item}}",
            ],
            'register' => 'ddev_env_overrides',
            'loop' => '{{ ddev_services }}',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Enable environment overrides',
            'copy' => [
                'src' => "{{ ansistrano_release_path }}/.ddev/deploy.{{ ddev_ansible_environment }}.env.{{ item.item }}",
                'dest' => "{{ ansistrano_release_path }}/.ddev/.env.{{ item.item }}",
                'remote_src' => true,
            ],
            'when' => 'item.stat.exists',
            'loop' => '{{ ddev_env_overrides.results }}',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Get possible hostname overrides',
            'stat' => [
                'path' => "{{ ansistrano_release_path }}/.ddev/deploy.{{ ddev_ansible_environment }}.hostname.config.yaml",
            ],
            'register' => 'ddev_hostname_overrides',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Copy hostname overrides',
            'copy' => [
                'src' => "{{ ansistrano_release_path }}/.ddev/deploy.{{ ddev_ansible_environment }}.hostname.config.yaml",
                'dest' => "{{ ansistrano_release_path }}/.ddev/config.hostname.yaml",
                'remote_src' => true,
            ],
            'when' => 'ddev_hostname_overrides.stat.exists',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Register the hostname configuration',
            'ansible.builtin.slurp' => [
                'src' => "{{ ansistrano_release_path }}/.ddev/config.hostname.yaml",
            ],
            'register' => 'ddev_hostname_config',
            'when' => 'ddev_hostname_overrides.stat.exists',
        ];

        $afterUpdateCodeTasks[] = [
            'name' => 'Configure HTTPS overrides for traefik to issue letsencrypt certificates only for additional_fqdns',
            'ansible.builtin.template' => [
                'src' => getenv('DDEV_APPROOT') . '/.ddev/ansible/traefik-https-overrides.yaml',
                'dest' => "{{ ansistrano_release_path }}/.ddev/traefik/config/deploy.yaml",
            ],
            'when' => 'ddev_hostname_overrides.stat.exists',
        ];

        $afterUpdateCodeTasks[] = [
            'name' => 'Get mail sending (msmtp) configuration',
            'stat' => [
                'path' => "{{ ansistrano_release_path }}/.ddev/deploy.{{ ddev_ansible_environment }}.msmtprc",
            ],
            'register' => 'ddev_msmtprc',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Copy mail sending configuration to home',
            'copy' => [
                'src' => "{{ ansistrano_release_path }}/.ddev/deploy.{{ ddev_ansible_environment }}.msmtprc",
                'dest' => "{{ ansistrano_release_path }}/.ddev/homeadditions/.msmtprc",
                'remote_src' => true,
                'mode' => 'u=r',
            ],
            'when' => 'ddev_hostname_overrides.stat.exists',
        ];
        $afterUpdateCodeTasks[] = [
            'name' => 'Enable msmtp for php',
            'copy' => [
                'dest' => "{{ ansistrano_release_path }}/.ddev/php/msmtp.ini",
                'content' => "[PHP]\nsendmail_path = /usr/bin/msmtp -t -i"
            ],
            'when' => 'ddev_hostname_overrides.stat.exists',
        ];

        if (isset($projectTypeConfig['afterUpdateCodeTasks'])) {
            $afterUpdateCodeTasks = array_merge($afterUpdateCodeTasks, $projectTypeConfig['afterUpdateCodeTasks']);
        }

        $afterUpdateCodeTasks[] = [
            'name' => 'Start the app',
            'shell' => 'ddev start',
            'args' => [
                'chdir' => '{{ ansistrano_release_path }}',
            ],
            'register' => 'start_result',
        ];

        $afterUpdateCodeTasks[] = [
            'name' => 'Log the output of starting the app',
            'copy' => [
                'content' => "STDOUT\n{{ start_result.stdout }}\nSTDERR\n{{ start_result.stderr }}",
                'dest' => '{{ ansistrano_release_path }}/ansible-ddev-start.log.txt',
            ],
        ];

        $ansibleCronConfig = [
            'name' => 'DDEV cron job for {{ ddev_project_name }}-{{ ddev_ansible_environment }}',
            'job' => 'cd {{ ansistrano_deploy_to }}/current && ' . $projectTypeConfig['cron'],
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

        // Auto-create configuration files for deployment
        $deployConfigPath = getenv('DDEV_APPROOT') . '/.ddev/config.basin-deploy.yaml';
        if (!file_exists($deployConfigPath)) {
            copy(__DIR__  . '/../Templates/config.basin-deploy.yaml', $deployConfigPath);
            $output->writeln('/.ddev/config.basin-deploy.yaml generated. Edit it to complete the server details');
        }

        if (isset($projectTypeConfig['envFiles'])) {
            foreach($projectTypeConfig['envFiles'] as $suffix => $contents) {
                $deployEnvironmentPath = getenv('DDEV_APPROOT') . '/.ddev/deploy.' . $environment . '.env.' . $suffix;
                if (file_exists($deployEnvironmentPath)) {
                    continue;
                }
                file_put_contents($deployEnvironmentPath, $contents);
                $output->writeln('/.ddev/deploy.' . $environment . '.env.' . $suffix . ' generated for "' . $projectType . '". Edit it to complete the environment details');
            }
        }

        $deployHostnamePath = getenv('DDEV_APPROOT') . '/.ddev/deploy.' . $environment . '.hostname.config.yaml';
        if (!file_exists($deployHostnamePath)) {
            copy(__DIR__  . '/../Templates/deploy.hostname.config.yaml', $deployHostnamePath);
            $output->writeln('/.ddev/deploy.' . $environment . '.hostname.config.yaml generated. Edit it to complete the hostname details');
        }
        $msmtprcPath = getenv('DDEV_APPROOT') . '/.ddev/deploy.' . $environment . '.msmtprc';
        if (!file_exists($msmtprcPath)) {
            copy(__DIR__  . '/../Templates/msmtprc', $msmtprcPath);
            $output->writeln('/.ddev/deploy.' . $environment . '.msmtprc generated. Edit it to complete the mail sending details');
        }


        if (isset($projectTypeConfig['beforeSymlinkTasks'])) {
            $beforeSymlinkTasksPath = getenv('DDEV_APPROOT') . '/.ddev/deploy.before-symlink-tasks.yml';
            $ansiblePlaybook['vars']['ansistrano_before_symlink_tasks_file'] = $beforeSymlinkTasksPath;
            file_put_contents(
                $beforeSymlinkTasksPath,
                Yaml::dump(
                    input: $projectTypeConfig['beforeSymlinkTasks'],
                    inline: 4,
                    flags: Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
                )
            );

            $output->writeln('/.ddev/deploy.before-symlink-tasks.yaml generated for "' . $projectType . '".');
        }

        file_put_contents(
            $afterUpdateCodeTasksPath,
            Yaml::dump(
                input: $afterUpdateCodeTasks ,
                inline: 4,
                flags: Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
            )
        );
        $output->writeln('/.ddev/deploy.after-update-code.yml generated');

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
        return Command::SUCCESS;
    }

    protected function getProjectType($ddevDockerCompose): string {
        // Drupal, supported by DDEV
        if (file_exists(__DIR__ . '/../ProjectType/' .  ucfirst(getenv('DDEV_PROJECT_TYPE')) . '.php')) {
            return ucfirst(getenv('DDEV_PROJECT_TYPE'));
        }

        // Nextcloud. Capture the major version from the image declaration
        if (
            array_key_exists('nextcloud', $ddevDockerCompose['services']) &&
            preg_match('/^[^:]*:(\d+)\./', $ddevDockerCompose['services']['nextcloud']['image'], $capture)
        ) {
            $major = $capture[1];
            if (file_exists(__DIR__ . '/../ProjectType/Nextcloud' . $major . '.php')) {
                return 'Nextcloud' . $major;
            }
        }
        return '';
    }
}
