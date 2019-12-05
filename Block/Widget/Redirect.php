<?php

namespace Openbucks\Openbucks\Block\Widget;

/**
 * Abstract class
 */

use \Magento\Framework\View\Element\Template;


class Redirect extends Template
{

    static protected $orderId;

    /**
     * @var \Openbucks\Openbucks\Model\Openbucks
     */
    protected $config;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var \Magento\Sales\Model\Order\Config
     */
    protected $_orderConfig;

    /**
     * @var \Magento\Framework\App\Http\Context
     */
    protected $httpContext;

    /**
     * @var string
     */
    protected $_template = 'html/openbucks_form.phtml';


    /**
     * @param Template\Context                    $context
     * @param \Magento\Checkout\Model\Session     $checkoutSession
     * @param \Magento\Customer\Model\Session     $customerSession
     * @param \Magento\Sales\Model\OrderFactory   $orderFactory
     * @param \Magento\Sales\Model\Order\Config   $orderConfig
     * @param \Magento\Framework\App\Http\Context $httpContext
     * @param \Openbucks\Openbucks\Model\Openbucks        $paymentConfig
     * @param array                               $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Sales\Model\Order\Config $orderConfig,
        \Magento\Framework\App\Http\Context $httpContext,
        \Openbucks\Openbucks\Model\Openbucks $paymentConfig,
        array $data = []
    )
    {
        parent::__construct($context, $data);
        $this->_checkoutSession = $checkoutSession;
        $this->_customerSession = $customerSession;
        $this->_orderFactory    = $orderFactory;
        $this->_orderConfig     = $orderConfig;
        $this->_isScopePrivate  = true;
        $this->httpContext      = $httpContext;
        $this->config           = $paymentConfig;
    }


    /**
     * Get instructions text from config
     *
     * @return null|string
     */
    public function getGateUrl()
    {
        return $this->config->getGateUrl();
    }


    /**
     * Получить сумму к оплате
     *
     * @return float|null
     */
    public function getAmount()
    {
        $orderId = $this->_checkoutSession->getLastOrderId();
        if ($orderId) {
            $incrementId = $this->_checkoutSession->getLastRealOrderId();
            return $this->config->getAmount($incrementId);
        }
        return null;
    }


    /**
     * @return array|null
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getPostData()
    {
        $orderId = $this->_checkoutSession->getLastRealOrderId();

        if (!$orderId && self::$orderId) {
            $orderId = self::$orderId;
        }

        if ($orderId) {
            self::$orderId = $orderId;

            return $this->config->getPostData($orderId);
        }

        return null;
    }


    /**
     * Get url to get payment redirect url
     *
     * @return string
     */
    public function getPayUrl()
    {
        return $this->config->getRedirectUrl();
    }
}
