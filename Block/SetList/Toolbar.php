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

use Amadeco\SmileCustomEntityLayeredNavigation\Helper\SetList as SetListHelper;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\Config\Source\SortBy;
use Amadeco\SmileCustomEntityLayeredNavigation\Model\Set\SetList\Toolbar as ToolbarModel;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Data\Helper\PostHelper;
use Magento\Framework\Url\EncoderInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Theme\Block\Html\Pager;
use Smile\CustomEntity\Model\ResourceModel\CustomEntity\Collection;

/**
 * Toolbar block for Custom Entity listings.
 */
class Toolbar extends Template
{
    /**
     * Default template for the toolbar.
     *
     * @var string
     */
    protected $_template = 'Smile_CustomEntity::set/list/toolbar.phtml';

    /**
     * @var Collection|null
     */
    protected ?Collection $_collection = null;

    /**
     * Memoization for current grid order.
     */
    private ?string $currentOrder = null;

    /**
     * Memoization for current grid direction.
     */
    private ?string $currentDirection = null;

    /**
     * Memoization for current view mode.
     */
    private ?string $currentMode = null;

    /**
     * Memoization for current limit.
     */
    private ?string $currentLimit = null;

    /**
     * List of available limits.
     */
    protected ?array $availableLimit = null;

    /**
     * List of available order fields.
     */
    protected ?array $availableOrder = null;

    /**
     * List of available view types.
     */
    protected array $availableMode = [];

    /**
     * Is view switcher enabled.
     */
    protected bool $enableViewSwitcher = true;

    /**
     * Is toolbar expanded.
     */
    protected bool $isExpanded = true;

    /**
     * Default Order field.
     */
    protected ?string $orderField = null;

    /**
     * Default direction.
     */
    protected string $defaultDirection = SetListHelper::DEFAULT_SORT_DIRECTION;

    /**
     * @param Context $context
     * @param ToolbarModel $toolbarModel
     * @param EncoderInterface $urlEncoder
     * @param SetListHelper $setListHelper
     * @param PostHelper $postDataHelper
     * @param FormKey $formKey
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly ToolbarModel $toolbarModel,
        private readonly EncoderInterface $urlEncoder,
        private readonly SetListHelper $setListHelper,
        private readonly PostHelper $postDataHelper,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Set collection to pager and apply sorting/limits.
     *
     * @param Collection $collection
     * @return $this
     */
    public function setCollection(Collection $collection): self
    {
        $this->_collection = $collection;

        $this->_collection->setCurPage($this->getCurrentPage());

        // Apply pagination limit
        $limit = (int) $this->getLimit();
        if ($limit > 0) {
            $this->_collection->setPageSize($limit);
        }

        // Apply sorting
        if ($this->getCurrentOrder()) {
            $this->_collection->setOrder($this->getCurrentOrder(), $this->getCurrentDirection());
        }

        return $this;
    }

    /**
     * Return custom entity collection instance.
     *
     * @return Collection|null
     */
    public function getCollection(): ?Collection
    {
        return $this->_collection;
    }

    /**
     * Return current page from request.
     *
     * @return int
     */
    public function getCurrentPage(): int
    {
        return $this->toolbarModel->getCurrentPage();
    }

    /**
     * Get grid sort order field.
     *
     * @return string
     */
    public function getCurrentOrder(): string
    {
        if ($this->currentOrder !== null) {
            return $this->currentOrder;
        }

        $order = $this->toolbarModel->getOrder();
        $orders = $this->getAvailableOrders();
        $defaultOrder = $this->getOrderField();

        // Fallback to first available order if default is invalid
        if (!isset($orders[$defaultOrder])) {
            $keys = array_keys($orders);
            $defaultOrder = $keys[0] ?? '';
        }

        if (!$order || !isset($orders[$order])) {
            $order = $defaultOrder;
        }

        $this->currentOrder = (string) $order;

        return $this->currentOrder;
    }

    /**
     * Retrieve current direction.
     *
     * @return string
     */
    public function getCurrentDirection(): string
    {
        if ($this->currentDirection !== null) {
            return $this->currentDirection;
        }

        $direction = $this->toolbarModel->getDirection();
        $direction = is_string($direction) ? strtolower($direction) : '';

        // Validate direction
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = $this->defaultDirection;
        }

        $this->currentDirection = $direction;

        return $this->currentDirection;
    }

    /**
     * Set default Order field.
     *
     * @param string $field
     * @return $this
     */
    public function setDefaultOrder(string $field): self
    {
        $this->loadAvailableOrders();
        if (isset($this->availableOrder[$field])) {
            $this->orderField = $field;
        }
        return $this;
    }

    /**
     * Set default sort direction.
     *
     * @param string $dir
     * @return $this
     */
    public function setDefaultDirection(string $dir): self
    {
        $dir = strtolower($dir);
        if (in_array($dir, ['asc', 'desc'], true)) {
            $this->defaultDirection = $dir;
        }
        return $this;
    }

    /**
     * Retrieve available Order fields list.
     *
     * @return array
     */
    public function getAvailableOrders(): array
    {
        $this->loadAvailableOrders();
        return $this->availableOrder ?? [];
    }

    /**
     * Set Available order fields list.
     *
     * @param array $orders
     * @return $this
     */
    public function setAvailableOrders(array $orders): self
    {
        $this->availableOrder = $orders;
        return $this;
    }

    /**
     * Add order to available orders.
     *
     * @param string $order
     * @param string $value
     * @return $this
     */
    public function addOrderToAvailableOrders(string $order, string $value): self
    {
        $this->loadAvailableOrders();
        $this->availableOrder[$order] = $value;
        return $this;
    }

    /**
     * Remove order from available orders if exists.
     *
     * @param string $order
     * @return $this
     */
    public function removeOrderFromAvailableOrders(string $order): self
    {
        $this->loadAvailableOrders();
        unset($this->availableOrder[$order]);
        return $this;
    }

    /**
     * Compare defined order field with current order field.
     *
     * @param string $order
     * @return bool
     */
    public function isOrderCurrent(string $order): bool
    {
        return $order === $this->getCurrentOrder();
    }

    /**
     * Return current URL with rewrites and additional parameters.
     *
     * @param array $params Query parameters
     * @return string
     */
    public function getPagerUrl(array $params = []): string
    {
        $urlParams = [
            '_current' => true,
            '_escape' => false,
            '_use_rewrite' => true,
            '_query' => $params,
        ];

        return $this->getUrl('*/*/*', $urlParams);
    }

    /**
     * Get pager encoded url.
     *
     * @param array $params
     * @return string
     */
    public function getPagerEncodedUrl(array $params = []): string
    {
        return $this->urlEncoder->encode($this->getPagerUrl($params));
    }

    /**
     * Retrieve current View mode.
     *
     * @return string
     */
    public function getCurrentMode(): string
    {
        if ($this->currentMode !== null) {
            return $this->currentMode;
        }

        $defaultMode = $this->setListHelper->getDefaultViewMode($this->getModes());
        $mode = $this->toolbarModel->getMode();

        if (!$mode || !isset($this->availableMode[$mode])) {
            $mode = $defaultMode;
        }

        $this->currentMode = (string) $mode;

        return $this->currentMode;
    }

    /**
     * Compare defined view mode with current active mode.
     *
     * @param string $mode
     * @return bool
     */
    public function isModeActive(string $mode): bool
    {
        return $this->getCurrentMode() === $mode;
    }

    /**
     * Retrieve available view modes.
     *
     * @return array
     */
    public function getModes(): array
    {
        if (empty($this->availableMode)) {
            $this->availableMode = $this->setListHelper->getAvailableViewMode() ?? [];
        }
        return $this->availableMode;
    }

    /**
     * Set available view modes list.
     *
     * @param array $modes
     * @return $this
     */
    public function setModes(array $modes): self
    {
        $this->availableMode = $modes;
        return $this;
    }

    /**
     * Disable view switcher.
     *
     * @return $this
     */
    public function disableViewSwitcher(): self
    {
        $this->enableViewSwitcher = false;
        return $this;
    }

    /**
     * Enable view switcher.
     *
     * @return $this
     */
    public function enableViewSwitcher(): self
    {
        $this->enableViewSwitcher = true;
        return $this;
    }

    /**
     * Is view switcher enabled.
     *
     * @return bool
     */
    public function isEnabledViewSwitcher(): bool
    {
        return $this->enableViewSwitcher;
    }

    /**
     * Disable Expanded.
     *
     * @return $this
     */
    public function disableExpanded(): self
    {
        $this->isExpanded = false;
        return $this;
    }

    /**
     * Enable Expanded.
     *
     * @return $this
     */
    public function enableExpanded(): self
    {
        $this->isExpanded = true;
        return $this;
    }

    /**
     * Check is Expanded.
     *
     * @return bool
     */
    public function isExpanded(): bool
    {
        return $this->isExpanded;
    }

    /**
     * Retrieve default per page values.
     *
     * @return string
     */
    public function getDefaultPerPageValue(): string
    {
        // Check for specific mode overrides if they exist (legacy support)
        if ($this->getCurrentMode() === 'list' && ($default = $this->getDefaultListPerPage())) {
            return (string) $default;
        }

        if ($this->getCurrentMode() === 'grid' && ($default = $this->getDefaultGridPerPage())) {
            return (string) $default;
        }

        return (string) $this->setListHelper->getDefaultLimitPerPageValue($this->getCurrentMode());
    }

    /**
     * Get default list per page from config (Legacy wrapper).
     *
     * @return int|null
     */
    private function getDefaultListPerPage(): ?int
    {
        // Logic moved to Helper, but keeping method if template calls explicitly (rare)
        return null;
    }

    /**
     * Get default grid per page from config (Legacy wrapper).
     *
     * @return int|null
     */
    private function getDefaultGridPerPage(): ?int
    {
        // Logic moved to Helper, but keeping method if template calls explicitly (rare)
        return null;
    }

    /**
     * Set pager limit.
     *
     * @param array $limits
     * @return $this
     */
    public function setAvailableLimit(array $limits): self
    {
        $this->availableLimit = $limits;
        return $this;
    }

    /**
     * Retrieve pager limit.
     *
     * @return array
     */
    public function getAvailableLimit(): array
    {
        if ($this->availableLimit !== null) {
            return $this->availableLimit;
        }
        return $this->setListHelper->getAvailableLimit($this->getCurrentMode());
    }

    /**
     * Get specified products limit display per page.
     *
     * @return string
     */
    public function getLimit(): string
    {
        if ($this->currentLimit !== null) {
            return $this->currentLimit;
        }

        $limits = $this->getAvailableLimit();
        $defaultLimit = $this->getDefaultPerPageValue();

        if (!$defaultLimit || !isset($limits[$defaultLimit])) {
            $keys = array_keys($limits);
            $defaultLimit = (string) ($keys[0] ?? '20');
        }

        $limit = $this->toolbarModel->getLimit();
        if (!$limit || !isset($limits[$limit])) {
            $limit = $defaultLimit;
        }

        $this->currentLimit = (string) $limit;

        return $this->currentLimit;
    }

    /**
     * Check if limit is current used in toolbar.
     *
     * @param string|int $limit
     * @return bool
     */
    public function isLimitCurrent(string|int $limit): bool
    {
        return (string) $limit === $this->getLimit();
    }

    /**
     * Pager number of items from which products started on current page.
     *
     * @return int
     */
    public function getFirstNum(): int
    {
        $collection = $this->getCollection();
        if (!$collection) {
            return 0;
        }
        return ($collection->getPageSize() * ($collection->getCurPage() - 1)) + 1;
    }

    /**
     * Pager number of items products finished on current page.
     *
     * @return int
     */
    public function getLastNum(): int
    {
        $collection = $this->getCollection();
        if (!$collection) {
            return 0;
        }
        return ($collection->getPageSize() * ($collection->getCurPage() - 1)) + $collection->count();
    }

    /**
     * Total number of products in current category.
     *
     * @return int
     */
    public function getTotalNum(): int
    {
        $collection = $this->getCollection();
        return $collection ? $collection->getSize() : 0;
    }

    /**
     * Check if current page is the first.
     *
     * @return bool
     */
    public function isFirstPage(): bool
    {
        $collection = $this->getCollection();
        return $collection && $collection->getCurPage() === 1;
    }

    /**
     * Return last page number.
     *
     * @return int
     */
    public function getLastPageNum(): int
    {
        $collection = $this->getCollection();
        return $collection ? $collection->getLastPageNumber() : 1;
    }

    /**
     * Render pagination HTML.
     *
     * @return string
     */
    public function getPagerHtml(): string
    {
        $pagerBlock = $this->getChildBlock('set_list_toolbar_pager');

        if ($pagerBlock instanceof Pager) {
            $pagerBlock->setAvailableLimit($this->getAvailableLimit());
            $pagerBlock->setUseContainer(false)
                ->setShowPerPage(false)
                ->setShowAmounts(false)
                ->setFrameLength(
                    (int) $this->_scopeConfig->getValue(
                        'design/pagination/pagination_frame',
                        ScopeInterface::SCOPE_STORE
                    )
                )
                ->setJump(
                    (int) $this->_scopeConfig->getValue(
                        'design/pagination/pagination_frame_skip',
                        ScopeInterface::SCOPE_STORE
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
     * Retrieve widget options in json format.
     *
     * @param array $customOptions Optional parameter for passing custom selectors from template
     * @return string
     */
    public function getWidgetOptionsJson(array $customOptions = []): string
    {
        $defaultMode = $this->setListHelper->getDefaultViewMode($this->getModes());

        $options = [
            'mode' => ToolbarModel::MODE_PARAM_NAME,
            'direction' => ToolbarModel::DIRECTION_PARAM_NAME,
            'order' => ToolbarModel::ORDER_PARAM_NAME,
            'limit' => ToolbarModel::LIMIT_PARAM_NAME,
            'modeDefault' => $defaultMode,
            'directionDefault' => $this->defaultDirection,
            'orderDefault' => $this->getOrderField(),
            'limitDefault' => $this->setListHelper->getDefaultLimitPerPageValue($defaultMode),
            'url' => $this->getPagerUrl(),
            'formKey' => $this->formKey->getFormKey(),
        ];

        $options = array_replace_recursive($options, $customOptions);

        return json_encode(['entityListToolbarForm' => $options]) ?: '{}';
    }

    /**
     * Get order field.
     *
     * @return string
     */
    protected function getOrderField(): string
    {
        if ($this->orderField === null) {
            $this->orderField = (string) $this->setListHelper->getDefaultSortField();
        }
        return $this->orderField;
    }

    /**
     * Load Available Orders.
     *
     * @return void
     */
    private function loadAvailableOrders(): void
    {
        if ($this->availableOrder === null) {
            $this->availableOrder = SortBy::toArray();
        }
    }
}