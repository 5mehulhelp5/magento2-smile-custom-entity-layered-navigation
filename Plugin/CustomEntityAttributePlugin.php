<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 *
 * @category  Amadeco
 * @package   Amadeco_SmileCustomEntityLayeredNavigation
 * @copyright Copyright (c) Amadeco (https://www.amadeco.fr) - Ilan Parmentier
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
declare(strict_types=1);

namespace Amadeco\SmileCustomEntityLayeredNavigation\Plugin;

use Smile\CustomEntity\Model\CustomEntity\Attribute;
use Amadeco\SmileCustomEntityLayeredNavigation\Api\Data\FilterableAttributeInterface;

/**
 * Plugin to add isFilterable and position functionality to custom entity attributes
 */
class CustomEntityAttributePlugin
{
    /**
     * Add getIsFilterable and getPosition methods
     *
     * TODO: Ideally, remove this plugin and use extension_attributes.xml.
     *
     * @param Attribute $subject
     * @param callable $proceed
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function aroundCall(Attribute $subject, callable $proceed, $method, $args)
    {
        // Strict switch to avoid overhead
        return match ($method) {
            'getIsFilterable' => (int)($subject->getData(FilterableAttributeInterface::IS_FILTERABLE) ?: 0),
            'getPosition' => (int)($subject->getData(FilterableAttributeInterface::POSITION) ?: 0),
            'setIsFilterable' => $subject->setData(FilterableAttributeInterface::IS_FILTERABLE, (int)($args[0] ?? 0)),
            'setPosition' => $subject->setData(FilterableAttributeInterface::POSITION, (int)($args[0] ?? 0)),
            default => $proceed($method, $args),
        };
    }
}
