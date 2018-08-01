<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers event listeners and subscribers to the available doctrine connections.
 *
 * @author Jeremy Mikola <jmikola@gmail.com>
 * @author Alexander <iam.asm89@gmail.com>
 * @author David Maicher <mail@dmaicher.de>
 */
class RegisterEventListenersAndSubscribersPass2 implements CompilerPassInterface
{
    private $connections;
    private $eventManagers;
    private $managerTemplate;
    private $tagPrefix;

    /**
     * @param string $connections     Parameter ID for connections
     * @param string $managerTemplate sprintf() template for generating the event
     *                                manager's service ID for a connection name
     * @param string $tagPrefix       Tag prefix for listeners and subscribers
     */
    public function __construct(string $connections, string $managerTemplate, string $tagPrefix)
    {
        $this->connections = $connections;
        $this->managerTemplate = $managerTemplate;
        $this->tagPrefix = $tagPrefix;
    }

    public function process(ContainerBuilder $container)
    {
        $taggedSubscribers = $container->findTaggedServiceIds($this->tagPrefix.'.event_subscriber', true);
        $taggedListeners = $container->findTaggedServiceIds($this->tagPrefix.'.event_listener', true);

        if (empty($taggedSubscribers) && empty($taggedListeners)) {
            return;
        }

        array_walk($taggedListeners, function (&$instances) {
            array_walk($instances, function (&$instance) {
                $instance['isListener'] = true;
            });
        });

        $services = $this->sortTaggedServices(array_merge($taggedListeners, $taggedSubscribers));
        $listenerRefs = array();

        foreach ($services as $service) {
            list($id, $tag) = $service;
            $connections = isset($tag['connection']) ? array($tag['connection']) : array_keys($this->connections);
            $isListener = $attributes['isListener'] ?? false;

            if ($isListener && !isset($tag['event'])) {
                throw new InvalidArgumentException(sprintf('Doctrine event listener "%s" must specify the "event" attribute.', $id));
            }

            foreach ($connections as $con) {
                if (!isset($this->connections[$con])) {
                    throw new RuntimeException(sprintf('The Doctrine connection "%s" referenced in service "%s" does not exist. Available connections names: %s', $con, $id, implode(', ', array_keys($this->connections))));
                }

                if ($isListener) {
                    $listenerRefs[$con][$id] = new Reference($id);

                    $this->getEventManagerDef($container, $con)->addMethodCall('addEventListener', array(array($tag['event']), $id));
                } else {
                    $this->getEventManagerDef($container, $con)->addMethodCall('addEventSubscriber', array(new Reference($id)));
                }
            }
        }

        // replace service container argument of event managers with smaller service locator
        // so services can even remain private
        foreach ($listenerRefs as $connection => $refs) {
            $this->getEventManagerDef($container, $connection)
                ->replaceArgument(0, ServiceLocatorTagPass::register($container, $refs));
        }
    }

    private function getEventManagerDef(ContainerBuilder $container, $name)
    {
        if (!isset($this->eventManagers[$name])) {
            $this->eventManagers[$name] = $container->getDefinition(sprintf($this->managerTemplate, $name));
        }

        return $this->eventManagers[$name];
    }

    /**
     * Finds and orders all service tags with the given name by their priority.
     *
     * The order of additions must be respected for services having the same priority,
     * and knowing that the \SplPriorityQueue class does not respect the FIFO method,
     * we should not use this class.
     *
     * @see https://bugs.php.net/bug.php?id=53710
     * @see https://bugs.php.net/bug.php?id=60926
     *
     * @param string           $tagName
     * @param ContainerBuilder $container
     *
     * @return array
     */
    private function sortTaggedServices(array $services)
    {
        foreach ($services as $id => $attributes) {
            $priority = isset($attributes[0]['priority']) ? $attributes[0]['priority'] : 0;
            $services[$priority][] = array($id, $attributes);
        }

        krsort($services);
        $services = array_merge(...$services);

        return $services;
    }
}