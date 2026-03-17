<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;

class ChannelMappingResolver
{
    public function __construct(
        private ?ChannelRepositoryInterface $channelRepository = null,
    ) {}

    public function resolveKey(ChannelInterface $channel): string
    {
        return $channel->getCode() ?? '';
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
