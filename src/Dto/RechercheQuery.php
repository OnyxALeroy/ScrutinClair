<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RechercheQuery
{
    public function __construct(
        #[Assert\Length(max: 200)]
        public string $q = '',
    ) {
    }
}
