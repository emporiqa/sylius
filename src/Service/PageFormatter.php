<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Model\PageInterface;
use Emporiqa\SyliusPlugin\Trait\TranslationHelperTrait;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;

class PageFormatter implements PageFormatterInterface
{
    use TranslationHelperTrait;

    private ?array $resolvedChannelKeys = null;

    public function __construct(
        private PageUrlResolverInterface $urlResolver,
        private array $enabledLanguages = [],
        private array $channelMapping = [],
        private ?LoggerInterface $logger = null,
        private ?ChannelRepositoryInterface $channelRepository = null,
    ) {}

    public function format(PageInterface $page): array
    {
        try {
            $translations = $page->getTranslations();
        } catch (\Throwable) {
            $this->logger?->warning('Failed to load translations for page', ['page_id' => $page->getId()]);
            return [];
        }

        if ($translations === null || $translations->isEmpty()) {
            $this->logger?->debug('Page has no translations', ['page_id' => $page->getId()]);
            return [];
        }

        $channelKeys = $this->getAllChannelKeys();

        $titles = [];
        $contents = [];
        $links = [];

        foreach ($channelKeys as $empChannelKey) {
            foreach ($this->enabledLanguages as $locale) {
                $translation = $this->findTranslationForLocale($translations, $locale);
                if (!$translation || !$translation->getTitle()) {
                    $this->logger?->debug('Page translation missing for locale', [
                        'page_id' => $page->getId(),
                        'locale' => $locale,
                    ]);
                    continue;
                }

                $lang = $locale;

                $titles[$empChannelKey][$lang] = $translation->getTitle();
                $contents[$empChannelKey][$lang] = strip_tags($translation->getContent() ?? '');
                $links[$empChannelKey][$lang] = $this->urlResolver->resolveUrl($page, $locale);
            }
        }

        if (empty($titles)) {
            return [];
        }

        return [
            [
                'type' => 'page.updated',
                'data' => [
                    'identification_number' => 'page-' . $page->getId(),
                    'channels' => $channelKeys,
                    'titles' => $titles,
                    'contents' => $contents,
                    'links' => $links,
                ],
            ],
        ];
    }

    private function getAllChannelKeys(): array
    {
        if ($this->resolvedChannelKeys !== null) {
            return $this->resolvedChannelKeys;
        }

        if (!empty($this->channelMapping)) {
            $this->resolvedChannelKeys = array_unique(array_values($this->channelMapping));
            return $this->resolvedChannelKeys;
        }

        if ($this->channelRepository === null) {
            $this->resolvedChannelKeys = [''];
            return $this->resolvedChannelKeys;
        }

        $channels = $this->channelRepository->findAll();
        if (count($channels) <= 1) {
            $this->resolvedChannelKeys = [''];
            return $this->resolvedChannelKeys;
        }

        // Same convention as ProductFormatter: first channel → "", rest → lowercased code
        $keys = [];
        $first = true;
        foreach ($channels as $ch) {
            if ($first) {
                $keys[] = '';
                $first = false;
            } else {
                $keys[] = strtolower($ch->getCode());
            }
        }

        $this->resolvedChannelKeys = $keys;
        return $this->resolvedChannelKeys;
    }

    public function formatForDeletion(PageInterface $page): array
    {
        return [
            [
                'type' => 'page.deleted',
                'data' => [
                    'identification_number' => 'page-' . $page->getId(),
                ],
            ],
        ];
    }
}
