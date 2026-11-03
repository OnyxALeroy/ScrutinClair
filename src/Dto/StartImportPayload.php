<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class StartImportPayload
{
    public function __construct(
        #[Assert\NotBlank]
        public string $command = '',
        public array $params = [],
    ) {
    }
}
