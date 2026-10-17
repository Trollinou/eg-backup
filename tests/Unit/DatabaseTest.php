<?php
namespace EGBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use EGBackup\Core\DatabaseImporter;

class DatabaseTest extends TestCase {
    /**
     * Teste le nettoyage des placeholders de sécurité WordPress ({hash}) en symbole '%'.
     */
    public function testCleanPlaceholderEscapeRestoresPercent(): void {
        $hashSha256 = 'aa7de55961e6824e9e2f886cc474d94150c91b33ab5f29df2a4bdddf469cc8f5';
        $queryWithHash = "INSERT INTO `wp_options` VALUES (1, 'siteurl', 'https://example.com/{" . $hashSha256 . "}', 'yes');";
        $expectedQuery = "INSERT INTO `wp_options` VALUES (1, 'siteurl', 'https://example.com/%', 'yes');";

        $cleaned = DatabaseImporter::cleanPlaceholderEscape($queryWithHash);
        $this->assertSame($expectedQuery, $cleaned);
    }

    /**
     * Vérifie qu'une requête sans placeholder n'est pas altérée.
     */
    public function testCleanPlaceholderEscapeLeavesNormalQueryUntouched(): void {
        $normalQuery = "INSERT INTO `wp_posts` VALUES (10, 'Hello World', 'standard text');";
        $cleaned = DatabaseImporter::cleanPlaceholderEscape($normalQuery);
        $this->assertSame($normalQuery, $cleaned);
    }
}

