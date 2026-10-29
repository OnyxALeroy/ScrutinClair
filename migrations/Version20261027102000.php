<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027102000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add progress column to import_job';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_job ADD progress INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_job DROP progress');
    }
}
