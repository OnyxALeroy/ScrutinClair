<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Indique si la génération de résumés par IA est autorisée sur l'instance.
 *
 * Pilotée par la variable d'environnement AI_ENABLED (0/1). En Docker, c'est
 * la cible make utilisée qui la positionne (make up-light force AI_ENABLED=0).
 */
final class AiAvailability
{
    public function __construct(
        #[Autowire('%env(AI_ENABLED)%')]
        private readonly string $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return filter_var($this->enabled, FILTER_VALIDATE_BOOL);
    }
}
