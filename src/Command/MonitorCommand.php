<?php

declare(strict_types=1);

namespace App\Command;

use App\Monitor\MonitorRunner;
use App\Persistence\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:monitor:run', description: 'Run one monitoring pass over all listing sources',)]
final class MonitorCommand extends Command
{
    public function __construct(
        private readonly Database $database,
        private readonly MonitorRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->database->migrate();
        $io->info('Monitoring pass started.');

        $this->runner->run();

        $io->success('Monitoring pass finished.');

        return Command::SUCCESS;
    }
}
