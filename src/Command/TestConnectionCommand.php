<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Command;

use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'emporiqa:test-connection',
    description: 'Test the connection to the Emporiqa webhook endpoint',
)]
class TestConnectionCommand extends Command
{
    public function __construct(
        private WebhookSenderInterface $webhookSender,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Testing Emporiqa Connection');

        $result = $this->webhookSender->testConnection();

        if ($result['success']) {
            $io->success('Connection successful!');
            $io->table(
                ['Property', 'Value'],
                [
                    ['URL', $result['url']],
                    ['Status Code', $result['status_code']],
                    ['Response', substr($result['response'] ?? '', 0, 200)],
                ]
            );
            return Command::SUCCESS;
        }

        $io->error('Connection failed!');
        $io->table(
            ['Property', 'Value'],
            [
                ['URL', $result['url']],
                ['Error', $result['error'] ?? 'Unknown error'],
                ['Status Code', $result['status_code'] ?? 'N/A'],
            ]
        );

        return Command::FAILURE;
    }
}
