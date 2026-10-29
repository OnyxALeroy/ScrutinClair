<?php

namespace App\Dto;

final readonly class SenatScrutin
{
    public function __construct(
        public string $uid,
        public string $titre,
        public string $objet,
        public ?\DateTimeImmutable $dateScrutin,
        public ?string $resultat,
        public ?string $dossierRef,
        public string $lien,
        public ?int $votants,
        public ?int $suffragesExprimes,
        public ?int $pour,
        public ?int $contre,
        public ?int $abstentions,
        public ?int $nonVotants,
    ) {
    }
}
