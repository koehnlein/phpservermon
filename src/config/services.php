<?php

/**
 * PHP Server Monitor
 * Monitor your servers and websites.
 *
 * This file is part of PHP Server Monitor.
 * PHP Server Monitor is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PHP Server Monitor is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PHP Server Monitor.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package     phpservermon
 * @license     http://www.gnu.org/licenses/gpl.txt GNU GPL v3
 * @version     Release: @package_version@
 * @link        http://www.phpservermonitor.org/
 **/

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container) {
    // the database constants are not defined until config.php has been created by the installer,
    // a "%" in a value (e.g. the password) has to be escaped to not be read as a parameter reference
    $constant = function ($name) {
        return defined($name) ? str_replace('%', '%%', (string) constant($name)) : null;
    };

    $container->parameters()
        ->set('config.theme', PSM_THEME)
        ->set('modules', array(
            'module.config',
            'module.error',
            'module.server',
            'module.user',
            'module.install',
        ))
        ->set('path.src', PSM_PATH_SRC)
        ->set('path.templates', '%path.src%templates')
        ->set('db.host', $constant('PSM_DB_HOST'))
        ->set('db.port', $constant('PSM_DB_PORT'))
        ->set('db.user', $constant('PSM_DB_USER'))
        ->set('db.pass', $constant('PSM_DB_PASS'))
        ->set('db.name', $constant('PSM_DB_NAME'));

    $services = $container->services()
        ->defaults()
        ->public();

    // modules
    $services->set('module.config', 'psm\Module\Config\ConfigModule');
    $services->set('module.error', 'psm\Module\Error\ErrorModule');
    $services->set('module.server', 'psm\Module\Server\ServerModule');
    $services->set('module.user', 'psm\Module\User\UserModule');
    $services->set('module.install', 'psm\Module\Install\InstallModule');

    // services
    $services->set('db', 'psm\Service\Database')
        ->args(array('%db.host%', '%db.user%', '%db.pass%', '%db.name%', '%db.port%'));
    $services->set('event', 'Symfony\Component\EventDispatcher\EventDispatcher');
    $services->set('user', 'psm\Service\User')
        ->args(array(service('db')));
    $services->set('twig.loader', 'Twig\Loader\FilesystemLoader')
        ->args(array('%path.templates%/%config.theme%'));
    $services->set('twig', 'Twig\Environment')
        ->args(array(service('twig.loader')));

    // utils
    $services->set('util.user.validator', 'psm\Util\User\UserValidator')
        ->args(array(service('user')));
    $services->set('util.server.updatemanager', 'psm\Util\Server\UpdateManager')
        ->args(array(service('service_container')));
};
