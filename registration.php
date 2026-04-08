<?php
/**
 * Transparent Edge CDN integration for Magento 2
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services <desarrollo@transparentedge.eu>
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'TransparentEdge_CDN',
    __DIR__
);
