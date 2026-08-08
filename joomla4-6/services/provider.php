<?php

/**
 * @package     System.Fgemailremover
 * @subpackage  plg_system_fgemailremover
 * @version     1.2.0
 *
 * @copyright   (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use FG\Plugin\System\Fgemailremover\Extension\Fgemailremover;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin     = new Fgemailremover(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('system', 'fgemailremover')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
