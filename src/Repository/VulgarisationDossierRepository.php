<?php

namespace App\Repository;

use App\Entity\VulgarisationDossier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VulgarisationDossier>
 */
class VulgarisationDossierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VulgarisationDossier::class);
    }
}
