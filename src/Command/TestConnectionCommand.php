<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Command;

use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
        private ?UrlGeneratorInterface $urlGenerator = null,
        private ?WebhookEventQueue $webhookQueue = null,
    ) {
        parent::__construct();
    }

    /** Emporiqa refuses signatures more than 5 minutes off; warn well before. */
    private const CLOCK_SKEW_WARN_SECONDS = 120;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Emporiqa Dry Run Test');

        $waiting = $this->webhookQueue?->retainedCount() ?? 0;
        if ($waiting > 0) {
            $io->warning(sprintf(
                '%d product and page change(s) did not reach Emporiqa yet. They are sent again, as they are then, with later changes in the shop (up to 25 at a time, each at most every 10 minutes); to send everything now, run emporiqa:sync:all.',
                $waiting,
            ));
        }

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
        $this->renderClockSkew($io, $result['clock_skew'] ?? null);
        $this->renderReadyMadeRules($io, $response);

        return Command::SUCCESS;
    }

    private function renderClockSkew(SymfonyStyle $io, mixed $skew): void
    {
        if (is_int($skew) && abs($skew) > self::CLOCK_SKEW_WARN_SECONDS) {
            $io->warning(sprintf(
                'This server\'s clock is %d seconds %s Emporiqa\'s. Emporiqa refuses signatures more than 5 minutes off: set up time sync (NTP) on this server.',
                abs($skew),
                $skew > 0 ? 'ahead of' : 'behind',
            ));
        }
    }

    /**
     * The Order status rule: where Emporiqa reaches this shop, and whether
     * the rule is on, as the dry run reports it (rules_available / live_rules).
     */
    private function renderReadyMadeRules(SymfonyStyle $io, array $response): void
    {
        if (empty($response['rules_available'])) {
            return;
        }
        $live = is_array($response['live_rules'] ?? null) ? $response['live_rules'] : [];
        $orderStatusOn = in_array('order_status', $live, true);

        $io->section('Ready-made rules');
        $io->text(sprintf('Order status: <info>%s</info>', $orderStatusOn ? 'On' : 'Not added'));

        $address = $this->orderStatusAddress();
        if ($address === null) {
            $io->warning('The Order status address could not be built. Import the plugin routes (config/routes/emporiqa.yaml) and set framework.router.default_uri to your shop\'s https address.');
        } elseif (!str_starts_with($address, 'https://')) {
            $io->text(sprintf('Order status address: %s', $address));
            $io->warning('Emporiqa only calls https addresses. Set framework.router.default_uri to your shop\'s https address, then run this command again.');
        } else {
            $io->text(sprintf('Order status address: <info>%s</info>', $address));
            $io->text('Emporiqa asks for this address when you add the Order status rule: paste it there, then click the link Emporiqa emails you to confirm it.');
        }
    }

    /**
     * The actions base URL: the route of the order-status action without the
     * actions/order-status suffix Emporiqa appends.
     */
    private function orderStatusAddress(): ?string
    {
        if ($this->urlGenerator === null) {
            return null;
        }
        try {
            $url = $this->urlGenerator->generate('emporiqa_action_order_status', [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return null;
        }
        $suffix = 'actions/order-status';

        return str_ends_with($url, $suffix) ? substr($url, 0, -strlen($suffix)) : null;
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
