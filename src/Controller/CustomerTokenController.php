<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Controller;

use Emporiqa\SyliusPlugin\Service\SignatureHelper;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The signed-in shopper's customer token for the chat widget. POST only and
 * no-store, so no page cache or proxy can hand one customer's token to
 * another; public/js/emporiqa-customer-token.js fetches it when the chat
 * opens. A guest, or a signed-in admin, gets an empty token.
 */
class CustomerTokenController
{
    public function __construct(
        private string $webhookSecret,
        private string $storeId,
        private ?Security $security = null,
    ) {}

    public function token(): JsonResponse
    {
        $token = '';
        $user = $this->security?->getUser();
        $customerId = $user instanceof ShopUserInterface ? $user->getCustomer()?->getId() : null;
        if ($customerId !== null && $this->webhookSecret !== '' && $this->storeId !== '') {
            $token = SignatureHelper::customerToken((string) $customerId, $this->webhookSecret, $this->storeId);
        }

        $response = new JsonResponse(['token' => $token]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setVary('Cookie');

        return $response;
    }
}
