<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807021000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create institutes table and add institute_id to users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE institutes (id UUID NOT NULL, name VARCHAR(255) NOT NULL, code VARCHAR(50) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(20) DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, logo VARCHAR(255) DEFAULT NULL, address_line1 VARCHAR(255) DEFAULT NULL, address_line2 VARCHAR(255) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, state VARCHAR(100) DEFAULT NULL, country VARCHAR(100) DEFAULT NULL, postal_code VARCHAR(20) DEFAULT NULL, timezone VARCHAR(100) DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9D17486A77153098 ON institutes (code)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9D17486AE7927C74 ON institutes (email)');
        $this->addSql('CREATE INDEX idx_institute_code ON institutes (code)');
        $this->addSql('CREATE INDEX idx_institute_email ON institutes (email)');
        $this->addSql('CREATE INDEX idx_institute_status ON institutes (status)');
        $this->addSql('ALTER TABLE users ADD institute_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9697B0F4C FOREIGN KEY (institute_id) REFERENCES institutes (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_1483A5E9697B0F4C ON users (institute_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE institutes');
        $this->addSql('ALTER TABLE users DROP CONSTRAINT FK_1483A5E9697B0F4C');
        $this->addSql('DROP INDEX IDX_1483A5E9697B0F4C');
    }
}
