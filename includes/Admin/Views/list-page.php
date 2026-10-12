<?php
if (!defined('ABSPATH')) {
    exit;
}

use EGBackup\Core\FileScanner;

$totalSize = 0;
foreach ($backups as $b) {
    $totalSize += $b['size'];
}
?>
<div class="wrap eg-backup-wrap">
    <div class="eg-header">
        <h1><span class="dashicons dashicons-portfolio"></span> Sauvegardes existantes</h1>
        <p class="eg-subtitle">Consultez, téléchargez ou restaurez vos archives de sauvegarde locales.</p>
    </div>

    <div class="eg-stats-row">
        <div class="eg-stat-card">
            <span class="stat-value"><?php echo esc_html(count($backups)); ?></span>
            <span class="stat-label">Sauvegardes disponibles</span>
        </div>
        <div class="eg-stat-card">
            <span class="stat-value"><?php echo esc_html(FileScanner::formatSize($totalSize)); ?></span>
            <span class="stat-label">Espace disque utilisé</span>
        </div>
        <div class="eg-stat-card eg-stat-action">
            <a href="<?php echo esc_url(admin_url('admin.php?page=eg-backup')); ?>" class="button button-primary">
                <span class="dashicons dashicons-plus-alt2"></span> Créer une nouvelle sauvegarde
            </a>
        </div>
    </div>

    <div class="eg-card">
        <?php if (empty($backups)) : ?>
            <div class="eg-empty-state">
                <span class="dashicons dashicons-archive"></span>
                <p>Aucune sauvegarde locale n'a encore été créée.</p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=eg-backup')); ?>" class="button button-primary">
                    Créer ma première sauvegarde
                </a>
            </div>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped eg-backups-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">Nom de l'archive</th>
                        <th style="width: 20%;">Date de création</th>
                        <th style="width: 15%;">Taille</th>
                        <th style="width: 15%;">Contenu détecté</th>
                        <th style="width: 20%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup) : 
                        $downloadUrl = wp_nonce_url(
                            admin_url('admin.php?page=eg-backup-list&action=eg_backup_download&file=' . urlencode($backup['filename'])),
                            'eg_backup_download_action',
                            'nonce'
                        );
                        $restoreUrl = admin_url('admin.php?page=eg-backup-restore&file=' . urlencode($backup['filename']));
                        $manifest = $backup['manifest'];
                        $pluginsCount = !empty($manifest['plugins']) ? count($manifest['plugins']) : 0;
                    ?>
                        <tr id="row-<?php echo esc_attr(sanitize_title($backup['filename'])); ?>">
                            <td>
                                <strong><?php echo esc_html($backup['filename']); ?></strong>
                            </td>
                            <td><?php echo esc_html($backup['date']); ?></td>
                            <td><span class="eg-badge-size"><?php echo esc_html($backup['size_human']); ?></span></td>
                            <td>
                                <?php if ($manifest) : ?>
                                    <span class="eg-badge-info" title="<?php echo esc_attr(sprintf('%d extensions documentées', $pluginsCount)); ?>">
                                        <?php echo esc_html(sprintf('%d ext. / %s', $pluginsCount, $manifest['theme']['name'] ?? 'Thème')); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="description">Non analysé</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($downloadUrl); ?>" class="button button-small" title="Télécharger le fichier ZIP">
                                    <span class="dashicons dashicons-download"></span> Télécharger
                                </a>
                                <a href="<?php echo esc_url($restoreUrl); ?>" class="button button-small button-primary" title="Lancer l'assistant de restauration">
                                    <span class="dashicons dashicons-image-rotate"></span> Restaurer
                                </a>
                                <button type="button" class="button button-small eg-btn-delete-backup" data-filename="<?php echo esc_attr($backup['filename']); ?>" title="Supprimer définitivement cette archive">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
