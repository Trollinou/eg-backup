<?php
namespace EGBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use EGBackup\Core\DatabaseImporter;
use EGBackup\Cli\BackupCommand;

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

    /**
     * Vérifie que recursiveReplace nettoie les placeholders dans les données sérialisées
     * tout en recalculant exactement la longueur d'octets déclarée (s:XX:).
     */
    public function testRecursiveReplacePreservesSerializedDataAndRestoresPercent(): void {
        $hashSha256 = '0d79169b8f15f5e2d4f89a01e973d1ccf04aede02f76ce03e3f912b7c758d4fe';
        $originalData = [
            'title_format' => '%%title%% {' . $hashSha256 . '}sep{' . $hashSha256 . '} %%sitename%%',
            'nested'       => [
                'archive_format' => 'Archive {' . $hashSha256 . '}page{' . $hashSha256 . '}',
            ],
        ];
        $serialized = serialize($originalData);

        // Exécution du remplacement récursif sans remplacement de domaine.
        $cleanedSerialized = DatabaseImporter::recursiveReplace($serialized, '', '');

        $this->assertIsString($cleanedSerialized);
        $unserialized = @unserialize($cleanedSerialized);
        $this->assertNotFalse($unserialized, 'La désérialisation doit réussir sans corruption.');
        $this->assertIsArray($unserialized);
        $this->assertSame('%%title%% %sep% %%sitename%%', $unserialized['title_format']);
        $this->assertSame('Archive %page%', $unserialized['nested']['archive_format']);

        // Vérification de la validité de la longueur d'octets de la chaîne formatée.
        $expectedLength = strlen('%%title%% %sep% %%sitename%%');
        $this->assertStringContainsString('s:' . $expectedLength . ':"%%title%% %sep% %%sitename%%";', $cleanedSerialized);
    }

    /**
     * Vérifie que recursiveReplace opère conjointement un search & replace de domaine
     * et le nettoyage des tokens d'échappement.
     */
    public function testRecursiveReplaceWithSearchAndReplaceAndPlaceholders(): void {
        $hashSha256 = '0d79169b8f15f5e2d4f89a01e973d1ccf04aede02f76ce03e3f912b7c758d4fe';
        $originalData = [
            'home' => 'https://old-site.com/blog/{' . $hashSha256 . '}postname{' . $hashSha256 . '}/',
        ];
        $serialized = serialize($originalData);

        $replacedSerialized = DatabaseImporter::recursiveReplace($serialized, 'old-site.com', 'new-site.com');
        $unserialized = @unserialize($replacedSerialized);

        $this->assertNotFalse($unserialized);
        $this->assertIsArray($unserialized);
        $this->assertSame('https://new-site.com/blog/%postname%/', $unserialized['home']);
    }

    /**
     * Vérifie que cleanPlaceholderEscape répare la longueur d'octets d'un INSERT contenant du sérialisé.
     */
    public function testCleanPlaceholderEscapeWithSerializedQueryRepairsLengths(): void {
        $hashSha256 = '0d79169b8f15f5e2d4f89a01e973d1ccf04aede02f76ce03e3f912b7c758d4fe';
        $token = '{' . $hashSha256 . '}';
        $valWithToken = 'before ' . $token . ' after';
        $data = ['seo' => $valWithToken];
        $serializedWithToken = serialize($data);

        $query = "INSERT INTO `wp_options` VALUES (50, 'wpseo_titles', '" . $serializedWithToken . "', 'yes');";
        $cleanedQuery = DatabaseImporter::cleanPlaceholderEscape($query);

        // Extraction de la chaîne sérialisée nettoyée de la requête.
        $this->assertMatchesRegularExpression("/'a:1:.*'/", $cleanedQuery);
        preg_match("/'(a:1:.*)'/", $cleanedQuery, $matches);
        $this->assertNotEmpty($matches[1]);

        $unserialized = @unserialize($matches[1]);
        $this->assertNotFalse($unserialized, 'La structure sérialisée réparée doit être désérialisable.');
        $this->assertSame(['seo' => 'before % after'], $unserialized);
    }

    /**
     * Teste la réinitialisation automatique d'un permalink_structure corrompu par checkPermalinksHealth.
     */
    public function testCheckPermalinksHealthResetsCorruptedPermalinkStructure(): void {
        global $wp_test_options, $wp_test_rewrite_flushed;

        $hashSha256 = '0d79169b8f15f5e2d4f89a01e973d1ccf04aede02f76ce03e3f912b7c758d4fe';
        $wp_test_options['permalink_structure'] = '/{' . $hashSha256 . '}postname{' . $hashSha256 . '}/';
        $wp_test_rewrite_flushed = false;

        $result = DatabaseImporter::checkPermalinksHealth();

        $this->assertTrue($result);
        $this->assertSame('/%postname%/', get_option('permalink_structure'));
        $this->assertTrue($wp_test_rewrite_flushed, 'flush_rewrite_rules doit être appelé.');
    }

    /**
     * Teste que checkPermalinksHealth conserve un permalink_structure sain et régénère les règles.
     */
    public function testCheckPermalinksHealthPreservesHealthyPermalinkStructure(): void {
        global $wp_test_options, $wp_test_rewrite_flushed;

        $wp_test_options['permalink_structure'] = '/%category%/%postname%/';
        $wp_test_rewrite_flushed = false;

        $result = DatabaseImporter::checkPermalinksHealth();

        $this->assertTrue($result);
        $this->assertSame('/%category%/%postname%/', get_option('permalink_structure'));
        $this->assertTrue($wp_test_rewrite_flushed, 'flush_rewrite_rules doit être appelé.');
    }

    /**
     * Vérifie que l'enregistrement WP-CLI n'enregistre pas la racine comme commande feuille.
     */
    public function testBackupCommandRegistersSubcommandsWithoutCollision(): void {
        \WP_CLI::$commands = [];
        BackupCommand::register();

        $this->assertArrayNotHasKey('eg-backup', \WP_CLI::$commands, 'eg-backup ne doit pas être une commande feuille.');
        $this->assertArrayHasKey('eg-backup create', \WP_CLI::$commands);
        $this->assertArrayHasKey('eg-backup list', \WP_CLI::$commands);
        $this->assertArrayHasKey('eg-backup inspect', \WP_CLI::$commands);
        $this->assertArrayHasKey('eg-backup restore', \WP_CLI::$commands);
        $this->assertArrayHasKey('eg-backup purge-temp', \WP_CLI::$commands);
    }
}


