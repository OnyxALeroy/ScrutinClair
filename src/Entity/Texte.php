<?php

namespace App\Entity;

use App\Repository\TexteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TexteRepository::class)]
class Texte
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objet = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $synthese = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lien = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $dossierRef = null;

    #[ORM\Column(length: 20, nullable: true, unique: true)]
    private ?string $uidScrutin = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $vulgarisation = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vulgarisationSource = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $vulgarisationAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $vulgarisationStatut = null;

    /**
     * @var Collection<int, Vote>
     */
    #[ORM\OneToMany(targetEntity: Vote::class, mappedBy: 'texte', orphanRemoval: true)]
    private Collection $votes;

    public function __construct()
    {
        $this->votes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function setObjet(?string $objet): static
    {
        $this->objet = $objet;

        return $this;
    }

    public function getSynthese(): ?string
    {
        return $this->synthese;
    }

    public function setSynthese(?string $synthese): static
    {
        $this->synthese = $synthese;

        return $this;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    public function setLien(?string $lien): static
    {
        $this->lien = $lien;

        return $this;
    }

    public function getDossierRef(): ?string
    {
        return $this->dossierRef;
    }

    public function setDossierRef(?string $dossierRef): static
    {
        $this->dossierRef = $dossierRef;

        return $this;
    }

    public function getUidScrutin(): ?string
    {
        return $this->uidScrutin;
    }

    public function setUidScrutin(?string $uidScrutin): static
    {
        $this->uidScrutin = $uidScrutin;

        return $this;
    }

    public function getVulgarisation(): ?string
    {
        return $this->vulgarisation;
    }

    public function setVulgarisation(?string $vulgarisation): static
    {
        $this->vulgarisation = $vulgarisation;

        return $this;
    }

    public function getVulgarisationSource(): ?string
    {
        return $this->vulgarisationSource;
    }

    public function setVulgarisationSource(?string $vulgarisationSource): static
    {
        $this->vulgarisationSource = $vulgarisationSource;

        return $this;
    }

    public function getVulgarisationAt(): ?\DateTimeImmutable
    {
        return $this->vulgarisationAt;
    }

    public function setVulgarisationAt(?\DateTimeImmutable $vulgarisationAt): static
    {
        $this->vulgarisationAt = $vulgarisationAt;

        return $this;
    }

    public function getVulgarisationStatut(): ?string
    {
        return $this->vulgarisationStatut;
    }

    public function setVulgarisationStatut(?string $vulgarisationStatut): static
    {
        $this->vulgarisationStatut = $vulgarisationStatut;

        return $this;
    }

    /**
     * @return Collection<int, Vote>
     */
    public function getVotes(): Collection
    {
        return $this->votes;
    }

    public function addVote(Vote $vote): static
    {
        if (!$this->votes->contains($vote)) {
            $this->votes->add($vote);
            $vote->setTexte($this);
        }

        return $this;
    }

    public function removeVote(Vote $vote): static
    {
        if ($this->votes->removeElement($vote)) {
            // set the owning side to null (unless already changed)
            if ($vote->getTexte() === $this) {
                $vote->setTexte(null);
            }
        }

        return $this;
    }

    public function getHemicyclePolitique(): array
    {
        $votesParGroupe = [];
        $totalVotes = 0;

        // 1. Groupement des votes
        foreach ($this->getVotes() as $vote) {
            $groupe = $vote->getGroupePolitique();
            if (!$groupe)
                continue;

            $uid = $groupe->getUid();

            if (!isset($votesParGroupe[$uid])) {
                $votesParGroupe[$uid] = [
                    'groupe' => $groupe,
                    'Pour' => 0,
                    'Contre' => 0,
                    'Abstention' => 0,
                    'total' => 0
                ];
            }

            $statut = $vote->getStatut();
            if (isset($votesParGroupe[$uid][$statut])) {
                $votesParGroupe[$uid][$statut]++;
                $votesParGroupe[$uid]['total']++;
                $totalVotes++;
            }
        }

        if ($totalVotes === 0)
            return ['total' => 0];

        // 2. Tri dynamique basé sur la colonne "ordre" de votre base de données
        usort($votesParGroupe, function ($a, $b) {
            return $a['groupe']->getOrdre() <=> $b['groupe']->getOrdre();
        });

        // 3. Calcul du CSS
        $degresActuels = 0;
        $gradientPartis = [];
        $gradientVotes = [];
        $details = [];

        foreach ($votesParGroupe as $data) {
            $groupe = $data['groupe'];
            $partDeg = ($data['total'] / $totalVotes) * 180;
            $finParti = $degresActuels + $partDeg;

            // La couleur vient de l'entité GroupePolitique
            $gradientPartis[] = "{$groupe->getCouleur()} {$degresActuels}deg {$finParti}deg";

            $degPour = ($data['Pour'] / $data['total']) * $partDeg;
            $degContre = ($data['Contre'] / $data['total']) * $partDeg;

            $finPour = $degresActuels + $degPour;
            $finContre = $finPour + $degContre;

            if ($degPour > 0)
                $gradientVotes[] = "#22c55e {$degresActuels}deg {$finPour}deg";
            if ($degContre > 0)
                $gradientVotes[] = "#ef4444 {$finPour}deg {$finContre}deg";
            if ($data['Abstention'] > 0)
                $gradientVotes[] = "#9ca3af {$finContre}deg {$finParti}deg";

            $degresActuels = $finParti;

            $details[] = [
                'nom' => $groupe->getAcronyme() ?? $groupe->getNom(),
                'couleur' => $groupe->getCouleur(),
                'Pour' => $data['Pour'],
                'Contre' => $data['Contre'],
                'Abstention' => $data['Abstention']
            ];
        }

        return [
            'total' => $totalVotes,
            'css_partis' => implode(', ', $gradientPartis),
            'css_votes' => implode(', ', $gradientVotes),
            'details' => $details
        ];
    }
}
