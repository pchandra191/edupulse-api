<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260922133027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_verify_token_user');
        $this->addSql('ALTER TABLE email_verification_tokens ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER INDEX uniq_email_verification_token_selector RENAME TO UNIQ_C81CA2AC9692E25D');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email_verification_tokens DROP deleted_at');
        $this->addSql('CREATE UNIQUE INDEX idx_verify_token_user ON email_verification_tokens (user_id) WHERE (user_id IS NOT NULL)');
        $this->addSql('ALTER INDEX uniq_c81ca2ac9692e25d RENAME TO uniq_email_verification_token_selector');
    }
}
