<?php

/**
 * @package     System.Fgemailremover
 * @subpackage  plg_system_fgemailremover
 * @version     1.8.1
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

                // PluginHelper::getPlugin() can return null in
                // transitional states (e.g. during install/update,
                // before the #__extensions row is fully in place) -
                // an explicit check here, rather than casting null
                // straight to (array), makes that fallback to an
                // empty config deliberate and visible in the code
                // rather than a silent side effect of PHP's casting
                // rules.
                $pluginConfig = PluginHelper::getPlugin('system', 'fgemailremover');
                $config       = $pluginConfig ? (array) $pluginConfig : [];

                $plugin = new Fgemailremover($dispatcher, $config);
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
