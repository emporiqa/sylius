<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;

class ChannelMappingResolver
{
    private ?array $resolvedMapping = null;

    public function __construct(
        private array $channelMapping = [],
        private ?ChannelRepositoryInterface $channelRepository = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function resolveKey(ChannelInterface $channel): string
    {
        $mapping = $this->getMapping();
        $code = $channel->getCode() ?? '';

        return $mapping[$code] ?? '';
    }

    public function getAllKeys(): array
    {
        return array_unique(array_values($this->getMapping()));
    }

    public function getMapping(): array
    {
        if ($this->resolvedMapping !== null) {
            return $this->resolvedMapping;
        }

        if (!empty($this->channelMapping)) {
            $this->resolvedMapping = $this->channelMapping;
            return $this->resolvedMapping;
        }

        if ($this->channelRepository === null) {
            $this->resolvedMapping = [];
            return $this->resolvedMapping;
        }

        $channels = $this->channelRepository->findAll();
        if (count($channels) <= 1) {
            $this->resolvedMapping = [];
            return $this->resolvedMapping;
        }

        $mapping = [];
        $first = true;
        foreach ($channels as $ch) {
            $code = $ch->getCode() ?? '';
            if ($code === '') {
                continue;
            }
            if ($first) {
                $mapping[$code] = '';
                $first = false;
            } else {
                $mapping[$code] = strtolower($code);
            }
        }

        $this->resolvedMapping = $mapping;
        $this->logger?->info('Emporiqa: auto-detected channel mapping', ['mapping' => $mapping]);

        return $this->resolvedMapping;
    }
}
