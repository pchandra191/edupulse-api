<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912113200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add leave_requests.reject permission and assign to Institute and Teacher roles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO permissions (id, module, group_name, code, name, description, created_at, updated_at, deleted_at)
            SELECT gen_random_uuid(), 'leave-requests', 'Leave Requests', 'leave_requests.reject', 'Reject Leave Requests', 'Reject leave requests.', NOW(), NOW(), NULL
            WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'leave_requests.reject')");

        $this->addSql("INSERT INTO role_permissions (id, role_id, permission_id, created_at, updated_at, deleted_at)
            SELECT gen_random_uuid(), r.id, p.id, NOW(), NOW(), NULL
            FROM roles r, permissions p
            WHERE r.code = 'ROLE_INSTITUTE' AND p.code = 'leave_requests.reject'
            AND NOT EXISTS (SELECT 1 FROM role_permissions rp
                JOIN roles r2 ON rp.role_id = r2.id
                JOIN permissions p2 ON rp.permission_id = p2.id
                WHERE r2.code = 'ROLE_INSTITUTE' AND p2.code = 'leave_requests.reject')");

        $this->addSql("INSERT INTO role_permissions (id, role_id, permission_id, created_at, updated_at, deleted_at)
            SELECT gen_random_uuid(), r.id, p.id, NOW(), NOW(), NULL
            FROM roles r, permissions p
            WHERE r.code = 'ROLE_TEACHER' AND p.code = 'leave_requests.reject'
            AND NOT EXISTS (SELECT 1 FROM role_permissions rp
                JOIN roles r2 ON rp.role_id = r2.id
                JOIN permissions p2 ON rp.permission_id = p2.id
                WHERE r2.code = 'ROLE_TEACHER' AND p2.code = 'leave_requests.reject')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code = 'leave_requests.reject')");
        $this->addSql("DELETE FROM permissions WHERE code = 'leave_requests.reject'");
    }
}