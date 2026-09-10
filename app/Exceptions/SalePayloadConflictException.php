<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

class SalePayloadConflictException extends DomainException
{
    public function __construct(string $externalId)
    {
        parent::__construct(
            "A sale with external_id [{$externalId}] already exists with different data.",
        );
    }
}
