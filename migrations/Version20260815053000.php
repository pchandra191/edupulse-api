<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815053000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add currency enum constraint to institutes table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institutes ADD CONSTRAINT chk_institutes_currency CHECK (currency IS NULL OR currency IN (\'INR\', \'USD\', \'EUR\', \'GBP\', \'AED\', \'SGD\'))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institutes DROP CONSTRAINT chk_institutes_currency');
    }
}
