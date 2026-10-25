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

    /**
     * Teste le streaming d'un fichier par morceaux.
     */
    public function testStreamFileToOutputStreamsCorrectly(): void {
        $filePath = $this->testDir . '/stream_test.txt';
        $content = str_repeat('ABCDEF1234567890', 100);
        file_put_contents($filePath, $content);

        // Démarre un buffer pour capturer la sortie du test sans fermer le buffer PHPUnit.
        ob_start();
        $result = Archiver::streamFileToOutput($filePath, 64, false);
        $output = ob_get_clean();

        $this->assertTrue($result);
        $this->assertSame($content, $output);
    }

    /**
     * Teste le streaming d'un fichier inexistant.
     */
    public function testStreamFileToOutputFailsOnNonExistentFile(): void {
        $result = Archiver::streamFileToOutput($this->testDir . '/does_not_exist.zip');
        $this->assertFalse($result);
    }

    /**
     * Teste le streaming partiel avec l'en-tête HTTP Range.
     */
    public function testStreamFileToOutputSupportsRangeRequests(): void {
        $filePath = $this->testDir . '/range_test.txt';
        $content = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        file_put_contents($filePath, $content);

        $_SERVER['HTTP_RANGE'] = 'bytes=5-14';

        ob_start();
        $result = Archiver::streamFileToOutput($filePath, 4, false);
        $output = ob_get_clean();

        unset($_SERVER['HTTP_RANGE']);

        $this->assertTrue($result);
        $this->assertSame('56789ABCDE', $output);
    }

    /**
     * Teste la génération d'une URL de téléchargement direct éphémère et sa purge.
     */
    public function testPrepareDirectDownloadUrlAndPurge(): void {
        $storageDir = Archiver::getStorageDir();
        wp_mkdir_p($storageDir);

        $dummyZip = $storageDir . 'backup_test.zip';
        file_put_contents($dummyZip, 'ZIP_BINARY_DATA');

        $directUrl = Archiver::prepareDirectDownloadUrl($dummyZip);
        $this->assertNotNull($directUrl);
        $this->assertStringContainsString('/eg-backup-data/dl/', (string) $directUrl);
        $this->assertStringEndsWith('/backup_test.zip', (string) $directUrl);

        // La purge immédiate (maxAge = 0) doit supprimer le dossier token sans supprimer l'archive source.
        $purged = Archiver::purgeOldDownloadLinks(0);
        $this->assertGreaterThanOrEqual(1, $purged);
        $this->assertFileExists($dummyZip);

        @unlink($dummyZip);
    }

    /**
     * Teste le streaming d'une tranche précise via streamFileChunk (Chunked Fetch).
     */
    public function testStreamFileChunkOutputsRequestedSlice(): void {
        $filePath = $this->testDir . '/chunk_slice_test.bin';
        $content = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        file_put_contents($filePath, $content);

        ob_start();
        $result = Archiver::streamFileChunk($filePath, 10, 19, false);
        $output = ob_get_clean();

        $this->assertTrue($result);
        $this->assertSame('ABCDEFGHIJ', $output);
    }
}

