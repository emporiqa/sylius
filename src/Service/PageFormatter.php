<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Model\PageInterface;
use Emporiqa\SyliusPlugin\Trait\TranslationHelperTrait;
use Psr\Log\LoggerInterface;

class PageFormatter implements PageFormatterInterface
{
    use TranslationHelperTrait;

    public function __construct(
        private PageUrlResolverInterface $urlResolver,
        private array $enabledLanguages = [],
        private array $channelMapping = [],
        private ?LoggerInterface $logger = null,
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

        // Pages don't have channel associations in Sylius, so we use the store-wide channel key
        $empChannelKey = '';
        $channelKeys = [$empChannelKey];

        $titles = [];
        $contents = [];
        $links = [];

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
