<?php
namespace EGBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use EGBackup\Autoloader;
use EGBackup\Core\MaintenanceMode;
use EGBackup\Core\Archiver;

class PluginTest extends TestCase {
    public function testConstantsAreDefined(): void {
        $this->assertTrue(defined('EG_BACKUP_VERSION'));
        $this->assertTrue(defined('EG_BACKUP_PLUGIN_DIR'));
        $this->assertTrue(defined('EG_BACKUP_PLUGIN_URL'));
        $this->assertTrue(defined('EG_BACKUP_PLUGIN_BASENAME'));
    }

    public function testAutoloaderClassExists(): void {
        $this->assertTrue(class_exists(Autoloader::class));
    }

    public function testCoreClassesExist(): void {
        $this->assertTrue(class_exists(MaintenanceMode::class));
        $this->assertTrue(class_exists(Archiver::class));
    }
}
