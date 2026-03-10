<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Command;

use Emporiqa\SyliusPlugin\Command\SyncAllCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class SyncAllCommandTest extends TestCase
{
    private function createSyncCommand(string $name, int $returnCode = Command::SUCCESS): Command
    {
        return new class($name, $returnCode) extends Command {
            private int $returnCode;

            public function __construct(string $name, int $returnCode)
            {
                parent::__construct($name);
                $this->returnCode = $returnCode;
            }

            protected function configure(): void
            {
                $this
                    ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, '', 50)
                    ->addOption('dry-run', null, InputOption::VALUE_NONE)
                    ->addOption('no-session', null, InputOption::VALUE_NONE);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return $this->returnCode;
            }
        };
    }

    public function testSyncAllSuccessWithBothCommands(): void
    {
        $application = new Application();
        $application->add(new SyncAllCommand());
        $application->add($this->createSyncCommand('emporiqa:sync:products'));
        $application->add($this->createSyncCommand('emporiqa:sync:pages'));

        $command = $application->find('emporiqa:sync:all');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Syncing Products', $tester->getDisplay());
        $this->assertStringContainsString('Syncing Pages', $tester->getDisplay());
    }

    public function testSyncAllSkipsPagesWhenCommandNotFound(): void
    {
        $application = new Application();
        $application->add(new SyncAllCommand());
        $application->add($this->createSyncCommand('emporiqa:sync:products'));

        $command = $application->find('emporiqa:sync:all');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Page sync not configured', $tester->getDisplay());
    }

    public function testSyncAllFailsWhenProductsSyncFails(): void
    {
        $application = new Application();
        $application->add(new SyncAllCommand());
        $application->add($this->createSyncCommand('emporiqa:sync:products', Command::FAILURE));
        $application->add($this->createSyncCommand('emporiqa:sync:pages'));

        $command = $application->find('emporiqa:sync:all');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testSyncAllFailsWhenPagesSyncFails(): void
    {
        $application = new Application();
        $application->add(new SyncAllCommand());
        $application->add($this->createSyncCommand('emporiqa:sync:products'));
        $application->add($this->createSyncCommand('emporiqa:sync:pages', Command::FAILURE));

        $command = $application->find('emporiqa:sync:all');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testSyncAllSucceedsWithPagesNotConfiguredAndProductsOk(): void
    {
        $application = new Application();
        $application->add(new SyncAllCommand());
        $application->add($this->createSyncCommand('emporiqa:sync:products'));

        $command = $application->find('emporiqa:sync:all');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('All data synced successfully', $tester->getDisplay());
    }
}
