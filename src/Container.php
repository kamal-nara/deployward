<?php

namespace Deployward;

use Deployward\Config\DeploymentRepository;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\BackupManager;
use Deployward\Deploy\Deployer;
use Deployward\Deploy\DeployerInterface;
use Deployward\Deploy\DirectoryMover;
use Deployward\Deploy\Extractor;
use Deployward\Deploy\HealthChecker;
use Deployward\Deploy\MaintenanceMode;
use Deployward\Deploy\PayloadValidator;
use Deployward\GitHub\GitHubClient;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Log\DeployLog;
use Deployward\Log\DeployLogInterface;
use Deployward\Notify\Notifier;
use Deployward\Security\Encryptor;

final class Container
{
    /** @var object */
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function repository(): DeploymentRepositoryInterface
    {
        return new DeploymentRepository(Encryptor::fromSalts());
    }

    public function log(): DeployLogInterface
    {
        return new DeployLog($this->wpdb);
    }

    public function github(): GitHubClientInterface
    {
        return new GitHubClient();
    }

    public function deployer(): DeployerInterface
    {
        $uploads = wp_upload_dir();
        $backupsBase = trailingslashit($uploads['basedir'])
            . 'deployward-backups-' . substr(md5(AUTH_SALT), 0, 8);
        $workBase = trailingslashit($uploads['basedir'])
            . 'deployward-work-' . substr(md5(AUTH_SALT), 0, 8);
        $muDir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        $mover = new DirectoryMover();

        return new Deployer(
            $this->github(),
            new Extractor($workBase),
            new PayloadValidator(),
            new BackupManager($backupsBase, $mover),
            $mover,
            new HealthChecker(),
            new MaintenanceMode(ABSPATH),
            $this->log(),
            $this->repository(),
            new Notifier((string) get_option('admin_email', '')),
            array(
                'plugin' => WP_PLUGIN_DIR,
                'theme' => get_theme_root(),
                'mu-plugin' => $muDir,
            ),
            home_url('/'),
            get_temp_dir(),
            defined('DEPLOYWARD_SLUG') ? DEPLOYWARD_SLUG : 'deployward',
            3
        );
    }
}
