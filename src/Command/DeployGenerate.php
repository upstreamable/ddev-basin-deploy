<?php

namespace Basin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'deploy:generate')]
class DeployGenerate
{
    public function __invoke(OutputInterface $output): int
    {
        $ansiblePlaybook = [
            'name' => 'Ansistrano deploy',
            'hosts' => 'all',
            'vars' => [
                'ansistrano_deploy_from' => "{{ lookup('env','DDEV_APPROOT') }}/",
                'ansistrano_deploy_to' => "~/deploy/{{ lookup('env','DDEV_PROJECT') }}-{{ lookup('env','DDEV_ANSIBLE_ENVIRONMENT') | default('production', true) }}",
                'ansistrano_keep_releases' => 3,
                // TODO: add 'ansistrano_rsync_extra_params' => '--exclude-from=/etc/ansible/rsync-exclude',
                'ansistrano_deploy_via' => 'rsync',
            ],
            'roles' => [[
                'role' => 'ansistrano.deploy',
            ]],
        ];
        $ansiblePlaybookPath = getenv('DDEV_APPROOT') . '/.ddev/deploy.yml';
        file_put_contents(
            $ansiblePlaybookPath,
            Yaml::dump(
                input: [$ansiblePlaybook],
                inline: 4,
                flags: Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
            )
        );

        return Command::SUCCESS;
    }
}
