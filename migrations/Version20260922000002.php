<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email verification tokens table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_verification_tokens (id UUID NOT NULL, user_id UUID DEFAULT NULL, email VARCHAR(180) NOT NULL, selector VARCHAR(64) NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_verify_token_selector ON email_verification_tokens (selector)');
        $this->addSql('CREATE INDEX idx_verify_token_email ON email_verification_tokens (email)');
        $this->addSql('CREATE INDEX idx_verify_token_expires ON email_verification_tokens (expires_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EMAIL_VERIFICATION_TOKEN_SELECTOR ON email_verification_tokens (selector)');
        $this->addSql('ALTER TABLE email_verification_tokens ADD CONSTRAINT FK_EMAIL_VERIFICATION_TOKEN_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX idx_verify_token_user ON email_verification_tokens (user_id) WHERE user_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_verification_tokens');
    }
}
