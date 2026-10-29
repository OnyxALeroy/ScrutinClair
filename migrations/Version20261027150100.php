<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027150100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cancel_requested to import_job';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_job ADD cancel_requested BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_job DROP cancel_requested');
    }
}
