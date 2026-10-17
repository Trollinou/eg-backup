<?php
namespace EGBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use EGBackup\Core\Archiver;

class ArchiverTest extends TestCase {
    private string $testDir;

    protected function setUp(): void {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/eg_backup_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
    }

    protected function tearDown(): void {
        if (is_dir($this->testDir)) {
            Archiver::cleanDirectory($this->testDir);
        }
        parent::tearDown();
    }

    /**
     * Teste le nettoyage récursif d'un dossier avec sous-dossiers et fichiers.
     */
    public function testCleanDirectoryRemovesFilesAndFolders(): void {
        $subDir = $this->testDir . '/subdir';
        mkdir($subDir, 0777, true);
        file_put_contents($subDir . '/test.txt', 'hello');
        file_put_contents($this->testDir . '/root.txt', 'world');

        $result = Archiver::cleanDirectory($this->testDir);
        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($this->testDir);
    }

    /**
     * Teste le nettoyage d'un dossier contenant un lien symbolique.
     */
    public function testCleanDirectoryRemovesSymlinkWithoutDeletingTarget(): void {
        $targetDir = $this->testDir . '/target';
        mkdir($targetDir, 0777, true);
        file_put_contents($targetDir . '/keep.txt', 'preserve me');

        $jobDir = $this->testDir . '/job_123';
        mkdir($jobDir, 0777, true);
        symlink($targetDir, $jobDir . '/files_link');

        $result = Archiver::cleanDirectory($jobDir);
        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($jobDir);
        $this->assertDirectoryExists($targetDir);
        $this->assertFileExists($targetDir . '/keep.txt');
    }

    /**
     * Teste la purge sélective des dossiers temporaires selon leur âge.
     */
    public function testPurgeOldTempDirectoriesDeletesOnlyExpired(): void {
        $tempBase = Archiver::getTempDir();
        if (!is_dir($tempBase)) {
            mkdir($tempBase, 0777, true);
        }

        $oldJobDir = $tempBase . 'job_test_old_' . uniqid();
        mkdir($oldJobDir, 0777, true);
        file_put_contents($oldJobDir . '/state.json', '{}');
        // Définit une date de modification ancienne (3 heures en arrière).
        touch($oldJobDir, time() - 10800);
        touch($oldJobDir . '/state.json', time() - 10800);

        $newJobDir = $tempBase . 'job_test_new_' . uniqid();
        mkdir($newJobDir, 0777, true);
        file_put_contents($newJobDir . '/state.json', '{}');
        // Définit une date de modification récente (maintenant).
        touch($newJobDir, time());
        touch($newJobDir . '/state.json', time());

        // Purge avec seuil de 7200s (2 heures).
        $purgedCount = Archiver::purgeOldTempDirectories(7200);

        $this->assertGreaterThanOrEqual(1, $purgedCount);
        $this->assertDirectoryDoesNotExist($oldJobDir);
        $this->assertDirectoryExists($newJobDir);

        // Nettoie le dossier récent créé pour le test.
        Archiver::cleanDirectory($newJobDir);
    }
}

