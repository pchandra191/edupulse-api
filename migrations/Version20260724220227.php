<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724220227 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_3967a2165f37a13b');
        $this->addSql('ALTER TABLE password_reset_tokens ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE password_reset_tokens ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE password_reset_tokens RENAME COLUMN token TO selector');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3967A2169692E25D ON password_reset_tokens (selector)');
        $this->addSql('CREATE INDEX idx_reset_token_selector ON password_reset_tokens (selector)');
        $this->addSql('CREATE INDEX idx_reset_token_email ON password_reset_tokens (email)');
        $this->addSql('CREATE INDEX idx_reset_token_expires ON password_reset_tokens (expires_at)');
        $this->addSql('ALTER TABLE users ADD phone VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD department VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD status VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE users DROP is_active');
        $this->addSql('ALTER TABLE users ALTER first_name DROP NOT NULL');
        $this->addSql('ALTER TABLE users ALTER last_name DROP NOT NULL');
        $this->addSql('CREATE INDEX idx_user_email ON users (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_3967A2169692E25D');
        $this->addSql('DROP INDEX idx_reset_token_selector');
        $this->addSql('DROP INDEX idx_reset_token_email');
        $this->addSql('DROP INDEX idx_reset_token_expires');
        $this->addSql('ALTER TABLE password_reset_tokens DROP updated_at');
        $this->addSql('ALTER TABLE password_reset_tokens DROP deleted_at');
        $this->addSql('ALTER TABLE password_reset_tokens RENAME COLUMN selector TO token');
        $this->addSql('CREATE UNIQUE INDEX uniq_3967a2165f37a13b ON password_reset_tokens (token)');
        $this->addSql('DROP INDEX idx_user_email');
        $this->addSql('ALTER TABLE users ADD is_active BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE users DROP phone');
        $this->addSql('ALTER TABLE users DROP department');
        $this->addSql('ALTER TABLE users DROP status');
        $this->addSql('ALTER TABLE users ALTER first_name SET NOT NULL');
        $this->addSql('ALTER TABLE users ALTER last_name SET NOT NULL');
    }
}
