<?php

namespace Deployer;

use Deployer\Exception\RuntimeException;

/**
 * ===============================================================
 * Tasks
 * ===============================================================
 */

// Restore local database with remote one
task(
    'database:retrieve',
    static function () {
        $src = get('rsync_src');
        while (is_callable($src)) {
            $src = $src();
        }
        if (!trim($src)) {
            throw new RuntimeException('You need to specify a source path.');
        }
        $dst = get('rsync_dest');
        while (is_callable($dst)) {
            $dst = $dst();
        }
        if (!trim($dst)) {
            throw new RuntimeException('You need to specify a destination path.');
        }
        $server = \Deployer\Task\Context::get()->getHost();
        if ($server instanceof \Deployer\Host\Localhost) {
            throw new RuntimeException('Remote host is localhost.');
        }

        echo 'Preparing backup on remote..';

        $dumpFilename = 'backup_remote__'.date('YmdHis');
        run(
            sprintf(
                'cd {{release_path}} && {{bin/php}} {{bin/console}} contao:backup:create %s.sql.gz',
                $dumpFilename
            )
        );
        echo ".finished\n";
        runLocally('mkdir -p backups');
        echo 'Downloading backup archive..';
        $host = $server->getRealHostname();
        $port = $server->getPort() ? ' -P ' . $server->getPort() : '';
        $user = !$server->getUser() ? '' : $server->getUser() . '@';
        runLocally("scp$port '$user$host:$dst/var/backups/$dumpFilename.sql.gz' '$src/var/backups'");

        echo ".finished\n";
        echo 'Restoring local database....';
        runLocally(
            "php vendor/bin/contao-console contao:backup:restore $dumpFilename.sql.gz"
        );
        echo ".finished\n";
        echo 'Run migration scripts.......';
        try {
            runLocally('php vendor/bin/contao-console contao:migrate --no-interaction');
            echo ".finished\n";
        } catch (\Exception $e) {
            echo ".skipped\n";
        }

        echo "  Restore of local database completed\n";
    }
)->desc('Downloads a database dump from given host and overrides the local database.');

task(
    'ask_retrieve',
    static function () {
        if (!askConfirmation('Local database will be overriden. OK?')) {
            die("Restore cancelled.\n");
        }
    }
);

before('database:retrieve', 'ask_retrieve');

// Restore remote database with local one
task(
    'database:release',
    static function () {
        $src = get('rsync_src');
        while (is_callable($src)) {
            $src = $src();
        }
        if (!trim($src)) {
            throw new RuntimeException('You need to specify a source path.');
        }
        $dst = get('rsync_dest');
        while (is_callable($dst)) {
            $dst = $dst();
        }
        if (!trim($dst)) {
            throw new RuntimeException('You need to specify a destination path.');
        }
        $server = \Deployer\Task\Context::get()->getHost();
        if ($server instanceof \Deployer\Host\Localhost) {
            throw new RuntimeException('Remote host is localhost.');
        }

        echo 'Preparing local backup.....';

        $dumpFilename = 'backup_local__'.date('YmdHis');
        runLocally(
            sprintf(
                'php vendor/bin/contao-console contao:backup:create %s.sql.gz',
                $dumpFilename
            )
        );
        echo ".finished\n";
        echo 'Uploading backup archive...';
        $host = $server->getRealHostname();
        $port = $server->getPort() ? ' -P ' . $server->getPort() : '';
        $user = !$server->getUser() ? '' : $server->getUser() . '@';
        runLocally("scp$port '$src/var/backups/$dumpFilename.sql.gz' '$user$host:$dst/var/backups'");

        echo ".finished\n";
        echo 'Restoring remote database..';
        run(
            "cd {{release_path}} && {{bin/php}} {{bin/console}} contao:backup:restore $dumpFilename.sql.gz"
        );
        echo ".finished\n";

        echo "  Restore of remote database completed\n";
    }
)->desc('Restores the local database on the given host.');

task(
    'ask_release',
    static function () {
        if (!askConfirmation('Remote (!) database will be overriden. OK?')) {
            die("Restore cancelled.\n");
        }
    }
);

before('database:release', 'ask_release');
after('database:release', 'contao:migrate');
