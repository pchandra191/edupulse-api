<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add partial unique indexes for primary address and bank account per user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_user_address_primary ON user_addresses (user_id) WHERE is_primary = true');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_bank_account_primary ON user_bank_accounts (user_id) WHERE is_primary = true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_user_address_primary');
        $this->addSql('DROP INDEX uniq_user_bank_account_primary');
    }
}
