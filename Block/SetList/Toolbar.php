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

namespace Amadeco\SmileCustomEntityLayeredNavigation\Block\SetList;

use Amadeco\SmileCustomEntityLayeredNavigation\Helper\SetList;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\Set\SetList\Toolbar as ToolbarModel;
use Magento\Catalog\Block\Product\ProductList\Toolbar as NativeToolbar;
use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product\ProductList\Toolbar as NativeToolbarModel;
use Magento\Catalog\Model\Session as CatalogSession;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Url\EncoderInterface;
use Magento\Framework\View\Element\Template\Context;
use Smile\CustomEntity\Model\ResourceModel\CustomEntity\Collection;

/**
 * Custom Entity List Toolbar
 *
 * Extends Native Catalog Toolbar to inherit pagination/sorting logic
 * while overriding state retrieval to support Custom Entity parameters.
 */
class Toolbar extends NativeToolbar
{
    /**
     * @var string
     */
    protected $_template = 'Smile_CustomEntity::set/list/toolbar.phtml';

    /**
     * @param Context $context
     * @param CatalogSession $catalogSession
     * @param CatalogConfig $catalogConfig
     * @param NativeToolbarModel $toolbarModel
     * @param EncoderInterface $urlEncoder
     * @param ToolbarModel $customToolbarModel
     * @param SetList $setListHelper
     * @param FormKey $formKey
     * @param array $data
     */
    public function __construct(
        Context $context,
        CatalogSession $catalogSession,
        CatalogConfig $catalogConfig,
        NativeToolbarModel $toolbarModel,
        EncoderInterface $urlEncoder,
        protected ToolbarModel $customToolbarModel,
        protected SetList $setListHelper,
        protected FormKey $formKey,
        array $data = []
    ) {
        // Pass native dependencies to parent to satisfy constructor contract
        parent::__construct(
            $context,
            $catalogSession,
            $catalogConfig,
            $toolbarModel,
            $urlEncoder,
            [], // Custom product list order not needed here
            $data
        );
    }

    /**
     * Set collection to pager
     *
     * Override strictly to apply custom entity parameters (limit/order)
     * without triggering native Product logic.
     *
     * @param Collection $collection
     * @return $this
     */
    public function setCollection($collection)
    {
        $this->_collection = $collection;

        $this->_collection->setCurPage($this->getCurrentPage());

        // Apply pagination
        $limit = (int)$this->getLimit();
        if ($limit) {
            $this->_collection->setPageSize($limit);
        }

        // Apply sorting
        if ($this->getCurrentOrder()) {
            $this->_collection->setOrder($this->getCurrentOrder(), $this->getCurrentDirection());
        }

        return $this;
    }

    /**
     * Return current page from request
     *
     * @return int
     */
    public function getCurrentPage()
    {
        return $this->customToolbarModel->getCurrentPage();
    }

    /**
     * Get sorting order field
     *
     * @return string
     */
    public function getCurrentOrder()
    {
        $order = $this->_getData('_current_grid_order');
        if ($order) {
            return $order;
        }

        $orders = $this->getAvailableOrders();
        $defaultOrder = $this->setListHelper->getDefaultSortField();

        if (!isset($orders[$defaultOrder])) {
            $keys = array_keys($orders);
            $defaultOrder = $keys[0] ?? null;
        }

        $order = $this->customToolbarModel->getOrder();
        if (!$order || !isset($orders[$order])) {
            $order = $defaultOrder;
        }

        $this->setData('_current_grid_order', $order);
        return $order;
    }

    /**
     * Retrieve current direction
     *
     * @return string
     */
    public function getCurrentDirection()
    {
        $dir = $this->_getData('_current_grid_direction');
        if ($dir) {
            return $dir;
        }

        $directions = ['asc', 'desc'];
        $dir = is_string($this->customToolbarModel->getDirection()) ?
            strtolower($this->customToolbarModel->getDirection()) : '';

        if (!$dir || !in_array($dir, $directions)) {
            $dir = SetList::DEFAULT_SORT_DIRECTION;
        }

        $this->setData('_current_grid_direction', $dir);
        return $dir;
    }

    /**
     * Get specified entities limit display per page
     *
     * @return string
     */
    public function getLimit()
    {
        $limit = $this->_getData('_current_limit');
        if ($limit) {
            return $limit;
        }

        $limits = $this->getAvailableLimit();
        $defaultLimit = $this->setListHelper->getDefaultLimitPerPageValue($this->getCurrentMode());

        if (!$defaultLimit || !isset($limits[$defaultLimit])) {
            $keys = array_keys($limits);
            $defaultLimit = $keys[0] ?? 20;
        }

        $limit = $this->customToolbarModel->getLimit();
        if (!$limit || !isset($limits[$limit])) {
            $limit = $defaultLimit;
        }

        $this->setData('_current_limit', (string)$limit);
        return (string)$limit;
    }

    /**
     * Retrieve available view modes
     *
     * @return array
     */
    public function getModes()
    {
        if ($this->_availableMode === []) {
            $this->_availableMode = $this->setListHelper->getAvailableViewMode();
        }
        return $this->_availableMode;
    }

    /**
     * Retrieve current View mode
     *
     * @return string
     */
    public function getCurrentMode()
    {
        $mode = $this->_getData('_current_grid_mode');
        if ($mode) {
            return $mode;
        }

        $defaultMode = $this->setListHelper->getDefaultViewMode($this->getModes());
        $mode = $this->customToolbarModel->getMode();

        if (!$mode || !isset($this->_availableMode[$mode])) {
            $mode = $defaultMode;
        }

        $this->setData('_current_grid_mode', $mode);
        return $mode;
    }

    /**
     * Retrieve available limits for current view mode
     *
     * @return array
     */
    public function getAvailableLimit()
    {
        return $this->setListHelper->getAvailableLimit($this->getCurrentMode());
    }

    /**
     * Render pagination HTML
     *
     * @return string
     */
    public function getPagerHtml()
    {
        $pagerBlock = $this->getChildBlock('set_list_toolbar_pager');

        if ($pagerBlock instanceof \Magento\Framework\DataObject) {
            /** @var \Magento\Theme\Block\Html\Pager $pagerBlock */
            $pagerBlock->setAvailableLimit($this->getAvailableLimit());
            $pagerBlock->setUseContainer(false)
                ->setShowPerPage(false)
                ->setShowAmounts(false)
                ->setFrameLength(
                    $this->_scopeConfig->getValue(
                        'design/pagination/pagination_frame',
                        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                    )
                )
                ->setJump(
                    $this->_scopeConfig->getValue(
                        'design/pagination/pagination_frame_skip',
                        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                    )
                )
                ->setLimitVarName(ToolbarModel::LIMIT_PARAM_NAME)
                ->setLimit($this->getLimit())
                ->setCollection($this->getCollection());

            return $pagerBlock->toHtml();
        }

        return '';
    }

    /**
     * Retrieve widget options in json format
     *
     * @param array $customOptions
     * @return string
     */
    public function getWidgetOptionsJson(array $customOptions = [])
    {
        $defaultMode = $this->setListHelper->getDefaultViewMode($this->getModes());
        $options = [
            'mode' => ToolbarModel::MODE_PARAM_NAME,
            'direction' => ToolbarModel::DIRECTION_PARAM_NAME,
            'order' => ToolbarModel::ORDER_PARAM_NAME,
            'limit' => ToolbarModel::LIMIT_PARAM_NAME,
            'modeDefault' => $defaultMode,
            'directionDefault' => SetList::DEFAULT_SORT_DIRECTION,
            'orderDefault' => $this->setListHelper->getDefaultSortField(),
            'limitDefault' => $this->setListHelper->getDefaultLimitPerPageValue($defaultMode),
            'url' => $this->getPagerUrl(),
            'formKey' => $this->formKey->getFormKey()
        ];
        $options = array_replace_recursive($options, $customOptions);
        return json_encode(['entityListToolbarForm' => $options]);
    }
}
