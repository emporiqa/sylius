<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Command;

use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'emporiqa:test-connection',
    description: 'Test the Emporiqa webhook connection using a real product via dry run',
)]
class TestConnectionCommand extends Command
{
    public function __construct(
        private WebhookSenderInterface $webhookSender,
        private ProductRepositoryInterface $productRepository,
        private ProductFormatterInterface $productFormatter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Emporiqa Dry Run Test');

        $product = $this->findTestProduct();
        if ($product === null) {
            $io->error('No products found in the store. Create at least one product before testing.');
            return Command::FAILURE;
        }

        $hasVariants = $product->getVariants()->count() > 1;
        $io->text(sprintf(
            'Using product: <info>%s</info> (ID: %d, %s)',
            $product->getName() ?? $product->getCode(),
            $product->getId(),
            $hasVariants ? $product->getVariants()->count() . ' variants' : 'simple',
        ));

        $events = $this->productFormatter->format($product);
        if (empty($events)) {
            $io->error('Product formatter returned no events. Check that the product has channels assigned.');
            return Command::FAILURE;
        }

        $io->text(sprintf('Sending %d event(s) via dry run...', count($events)));
        $io->newLine();

        $result = $this->webhookSender->sendDryRun($events);

        if (!$result['success']) {
            $this->renderError($io, $output, $result);
            return Command::FAILURE;
        }

        $response = $result['response'];
        if (!is_array($response) || ($response['status'] ?? null) !== 'dry_run') {
            $io->error('Unexpected response from server.');
            $io->text(is_string($response) ? $response : json_encode($response, JSON_PRETTY_PRINT));
            return Command::FAILURE;
        }

        $this->renderSuccess($io, $result, $response);

        return Command::SUCCESS;
    }

    private function findTestProduct(): ?ProductInterface
    {
        $products = $this->productRepository->findBy(['enabled' => true], ['id' => 'DESC'], 20);

        $withVariants = null;
        $simple = null;

        foreach ($products as $product) {
            if (!$product->getChannels()->isEmpty()) {
                if ($product->getVariants()->count() > 1) {
                    return $product;
                }
                if ($simple === null) {
                    $simple = $product;
                }
            }
        }

        return $simple;
    }

    private function renderError(SymfonyStyle $io, OutputInterface $output, array $result): void
    {
        $statusCode = $result['status_code'] ?? null;
        $friendly = $this->webhookSender->buildFriendlyError($result);

        if ($statusCode !== null) {
            $io->error(sprintf('Connection failed (HTTP %d): %s', $statusCode, $friendly));
        } else {
            $io->error(sprintf('Connection failed: %s', $friendly));
        }

        if (isset($result['url'])) {
            $io->text(sprintf('URL: %s', $result['url']));
        }

        // Raw response only on -v / --verbose, otherwise the friendly message
        // is the whole signal.
        if ($output->isVerbose()) {
            $response = $result['response'] ?? null;
            if (is_array($response)) {
                $io->section('Raw response');
                $io->text(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } elseif (is_string($response) && $response !== '') {
                $io->section('Raw response');
                $io->text($response);
            }
        }
    }

    private function renderSuccess(SymfonyStyle $io, array $result, array $response): void
    {
        $io->text(sprintf('URL: %s', $result['url']));
        $io->text(sprintf('Signature: <info>%s</info>', $response['signature'] ?? 'unknown'));
        $io->text(sprintf('Events validated: <info>%d</info>', $response['events_validated'] ?? 0));
        $io->newLine();

        $hasWarnings = false;

        foreach ($response['events'] ?? [] as $event) {
            $valid = $event['valid'] ?? false;
            $label = $valid ? '<info>VALID</info>' : '<error>INVALID</error>';

            $io->section(sprintf(
                '%s  %s  [%s]  SKU: %s',
                $label,
                $event['type'] ?? 'unknown',
                $event['identification_number'] ?? '?',
                $event['sku'] ?? '?',
            ));

            $rows = [];
            $rows[] = ['Languages', implode(', ', $event['languages_detected'] ?? [])];
            $rows[] = ['Channels', implode(', ', array_map(fn($c) => $c === '' ? '(default)' : $c, $event['channels_detected'] ?? []))];
            $rows[] = ['Is parent', ($event['is_parent'] ?? false) ? 'yes' : 'no'];

            if (isset($event['parent_sku'])) {
                $rows[] = ['Parent SKU', $event['parent_sku']];
            }

            $io->table(['Property', 'Value'], $rows);

            if (!empty($event['fields'])) {
                $fieldRows = [];
                foreach ($event['fields'] as $field => $ok) {
                    $fieldRows[] = [$field, $ok ? '<info>OK</info>' : '<comment>MISSING</comment>'];
                }
                $io->table(['Field', 'Status'], $fieldRows);
            }

            $warnings = $event['warnings'] ?? [];
            if (!empty($warnings)) {
                $hasWarnings = true;
                $io->warning('Warnings:');
                $io->listing($warnings);
            }
        }

        $io->newLine();
        if ($hasWarnings) {
            $io->warning('Dry run passed with warnings. Review the issues above.');
        } else {
            $io->success('Dry run passed — connection, signature, and data format are all valid.');
        }
    }
}
