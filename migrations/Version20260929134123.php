<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260929134123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vérification de l\'email à l\'inscription (is_verified + jeton)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD is_verified TINYINT DEFAULT 0 NOT NULL, ADD verification_token VARCHAR(100) DEFAULT NULL, ADD verification_token_expires_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649C1CC006B ON user (verification_token)');
        // Les comptes existants (neuropsychologue + patients déjà inscrits) restent utilisables
        $this->addSql('UPDATE user SET is_verified = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D649C1CC006B ON `user`');
        $this->addSql('ALTER TABLE `user` DROP is_verified, DROP verification_token, DROP verification_token_expires_at');
    }
}
