<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface as CoreChannelInterface;

class ChannelMappingResolver
{
    public function __construct(
        private ?ChannelRepositoryInterface $channelRepository = null,
    ) {}

    public function resolveKey(ChannelInterface $channel): string
    {
        return $channel->getCode() ?? '';
    }

    /**
     * The enabled channel with this key; an empty key means the first
     * enabled channel, as on a single-channel shop.
     */
    public function findChannel(string $key): ?CoreChannelInterface
    {
        foreach ($this->channelRepository?->findBy([], ['id' => 'ASC']) ?? [] as $channel) {
            if ($channel instanceof CoreChannelInterface && $channel->isEnabled() && ($key === '' || $this->resolveKey($channel) === $key)) {
                return $channel;
            }
        }

        return null;
    }

    public function getAllKeys(): array
    {
        if ($this->channelRepository === null) {
            return [];
        }

        $keys = [];
        foreach ($this->channelRepository->findAll() as $channel) {
            $code = $channel->getCode() ?? '';
            if ($code !== '') {
                $keys[] = $code;
            }
        }

        return array_unique($keys);
    }
}
