<?php

declare(strict_types=1);

namespace PosAdmin\Service;

final class SaasCapabilityDisableBlockedException extends \RuntimeException
{
    /** @param array<string,mixed> $diagnostic */
    public function __construct(private array $diagnostic)
    {
        parent::__construct('CAPACIDAD_DESACTIVACION_BLOQUEADA');
    }

    /** @return array<string,mixed> */
    public function diagnostic(): array
    {
        return $this->diagnostic;
    }
}
