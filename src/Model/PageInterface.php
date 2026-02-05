<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Model;

use Doctrine\Common\Collections\Collection;

interface PageInterface
{
    public function getId(): ?int;

    /** @return Collection<int, object> */
    public function getTranslations(): Collection;
}
