<?php

namespace Openbucks\Openbucks\Model;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order;

/**
 * Class Openbucks
 * @package Openbucks\Openbucks\Model
 */
class Openbucks extends \Magento\Payment\Model\Method\AbstractMethod
{
    const CODE = 'openbucks';

    /**
     * Payment code
     *
     * @var string
     */
    protected $_code = 'openbucks';

    protected $_gateRedirectUrl = "https://pay.openbucks.com/pwgc_uri_v3.php";
    protected $_demoGateRedirectUrl = "https://demo-pay.openbucks.com/pwgc_uri_v3.php";

    protected $_encryptor;

    protected $orderFactory;

    protected $urlBuilder;

    protected $_transactionBuilder;

    protected $_logger;

    protected $_canUseCheckout = true;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $builderInterface,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    )
    {
        $this->orderFactory = $orderFactory;
        $this->urlBuilder = $urlBuilder;
        $this->_transactionBuilder = $builderInterface;
        $this->_encryptor = $encryptor;
        parent::__construct($context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data);
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/openbucks.log');
        $this->_logger = new \Zend\Log\Logger();
        $this->_logger->addWriter($writer);
    }


    /**
     * Получить объект Order по его orderId
     *
     * @param $orderId
     * @return Order
     */
    protected function getOrder($orderId)
    {
        return $this->orderFactory
            ->create()
            ->loadByIncrementId($orderId);
    }


    /**
     * Получить сумму платежа по orderId заказа
     *
     * @param $orderId
     * @return float
     */
    public function getAmount($orderId)
    {
        return $this->getOrder($orderId)->getGrandTotal();
    }

    public function getSignature($data, $password, $encoded = true)
    {
        $data = array_filter($data, function ($var) {
            return $var !== '' && $var !== null;
        });
        ksort($data);
        $str = $password;
        foreach ($data as $k => $v) {
            $str .= '|' . $v;
        }
        if ($encoded) {
            return sha1($str);
        } else {
            return $str;
        }
    }

    /**
     * Получить идентификатор клиента по orderId заказа
     *
     * @param $orderId
     * @return int|null
     */
    public function getCustomerId($orderId)
    {
        return $this->getOrder($orderId)->getCustomerId();
    }


    /**
     * Получить код используемой валюты по orderId заказа
     *
     * @param $orderId
     * @return null|string
     */
    public function getCurrencyCode($orderId)
    {
        return $this->getOrder($orderId)->getBaseCurrencyCode();
    }


    /**
     * Check whether payment method can be used with selected shipping method
     * @param $shippingMethod
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function isCarrierAllowed($shippingMethod)
    {
        return true;
    }

    /**
     * Check whether payment method can be used
     * @param CartInterface|null $quote
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($quote === null) {
            return false;
        }
        return parent::isAvailable($quote) && $this->isCarrierAllowed(
                $quote->getShippingAddress()->getShippingMethod()
            );
    }


    /**
     * Get form array
     * @param $orderId
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getPostData($orderId)
    {
        $publicKey = $this->getConfigData("credentials/openbucks_public_key");
        $privateKey = $this->getConfigData("credentials/openbucks_private_key");
        $postBackUrl = $this->getConfigData("advanced/openbucks_postback");

        $order = $this->getOrder($orderId);

        $token                = date("Y-m-d H:i:s") . uniqid('', true);
        $amount               = number_format($this->getAmount($orderId), 2, '.', '');
        $customer_email       = false;
        $anonymous_id         = $customer_email ? md5($customer_email) : md5(time());
        $merchant_tracking_id = $orderId . "_" . time();

        $currency = $this->getCurrencyCode($orderId);

        $postData = [
            'req_token'                 => $token,
            'req_public_key'            => $publicKey,
            'req_merchant_tracking_id'  => $merchant_tracking_id,
            'req_item_description'      => __("Payment for the order #") . $orderId,
            'req_currency_code'         => $currency,
            'req_amount'                => $amount,
            'req_customer_anonymous_id' => $anonymous_id,
            'req_success_url'           => $this->urlBuilder->getUrl('checkout/onepage/success'),
            'req_sub_property_name'     => 'Magento',
            'req_sub_property_id'       => $_SERVER['SERVER_NAME'],
            'req_sub_property_url'      => !empty($postBackUrl) ? $postBackUrl : $this->urlBuilder->getUrl('openbucks/url/openbuckssuccess'),
            'req_force_cards'           => '',
        ];

        if ($customer_email) {
            $postData['req_customer_info_email'] = $customer_email;
        }

        $productIds = [];
        foreach ($order->getAllItems() as $item) {
            $productIds[] = $item->getId();

            // need fix for order with several products
            $postData['req_item_description'] = __('Payment for ') . $item->getName();
        }

        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $postData['req_customer_info_billing'] = json_encode($billingAddress);
        $postData['req_customer_info_shipping'] = json_encode($shippingAddress);
        $postData['req_product_id'] = implode('+', $productIds);
        $postData['req_hash'] = hash("sha256", $token . $privateKey . $merchant_tracking_id . $amount . $currency . $postData['req_force_cards']);

        return $postData;
    }

    /**
     * Checking callback data
     * @param $response
     * @return bool
     */
    private function checkOpenbucksResponse($response)
    {
        $this->log("Openbucks::checkOpenbucksResponse");

        try {

            // We expect a payment response
            if (!isset($response["payment"]) || !isset($response["error"]) || !isset($response["payment"]['merchantData'])
                || !isset($response["payment"]['transaction']) || !isset($response["payment"]['amount'])) {

                throw new \Exception("A payment response was expected.");
            }

            $payload = $response['payment'];
            $errorPayload = $response['error'];
            $merchantPayload = $payload['merchantData'];
            $transactionPayload = $payload['transaction'];

            $publicKey = $this->getConfigData("credentials/openbucks_public_key");
            $privateKey = $this->getConfigData("credentials/openbucks_private_key");

            $hash = hash("sha256", $publicKey . ":" . $transactionPayload['pwgcTrackingID'] . ":" . $privateKey);
            if ($hash != $transactionPayload['pwgcHash']) {
                throw new \Exception("The authenticity of the Openbucks payment server could not be established.");
            }

            // Check that the public key in the repsonse is yours
            if ($publicKey != $merchantPayload['publicKey']) {
                throw new \Exception("Could not validate public key.");
            }

            $transaction_id = (string) $transactionPayload['transactionID'];
            if (is_null($transaction_id)) {
                throw new \Exception("Could not retrieve transaction id from postback");
            }

            if (intval($errorPayload['errorCode']) != 0) {
                throw new \Exception("Error Processing Request: " . (string) $errorPayload['errorDescription']);
            }

            $this->log("Response checked - OK");
            return true;
        } catch (\Exception $e) {

            $this->log("Payment failed: {$e->getMessage()}");
            return false;
        }

    }

    /**
     * @param $responseData
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function processResponse($response)
    {
        if (!isset($response['response'])) {
            return 'FAIL';
        }

        $responseData = (array) $response['response'];
        $this->log("Openbucks::processResponse", $responseData);

        if ($this->checkOpenbucksResponse($responseData)) {

            $merchantTrackingId = explode('_', (string) $responseData['payment']['merchantData']['merchantTrackingID']);
            $orderId = reset($merchantTrackingId);
            $order = $this->getOrder($orderId);

            $this->log("STATUS {$order->getStatus()}");

            $processOrder = $this->_processOrder($order, $responseData);
            if ($order && $order->getStatus() && $processOrder === true) {
                return 'OK';
            } else {
                return 'FAIL';
            }
        }

        return 'FAIL';
    }

    /**
     * Метод вызывается при вызове callback URL
     *
     * @param Order $order
     * @param mixed $response
     * @return bool
     */
    protected function _processOrder(Order $order, $response)
    {
        $this->log("Openbucks::_processOrder", [
            "\$order" => $order,
            "\$response" => $response
        ]);


        try {
            $amountPayload = $response['payment']['amount'];

            $orderAmount = round($order->getGrandTotal() * 100);
            $amount = round($amountPayload['amountValue'] * 100);
            if ($orderAmount !== $amount) {
                throw new \Exception("_processOrder: amount mismatch, order FAILED $orderAmount != $amount");
            }

            $currencyCode = (string) $amountPayload['currencyCode'];
            $orderCurrency = $order->getBaseCurrencyCode();
            if ($orderCurrency != $currencyCode) {
                throw new \Exception("Wrong payment currency: $orderCurrency != $currencyCode");
            }

            $errorCode = $response["error"]['errorCode'];
            if ($errorCode == 0) {
                $this->createTransaction($order, $response);
                $order
                    ->setStatus($order->getConfig()->getStateDefaultStatus($this->getConfigData("options/order_status")))
                    ->save();
                $this->log("_processOrder: order state changed:" . $this->getConfigData("options/order_status"));
            } else if ($errorCode == 86797368) {
                // payment is voided
                $order
                    ->setState(Order::STATE_CANCELED)
                    ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_CANCELED))
                    ->save();

                $this->log("_processOrder: order data saved, order not approved");
            }
            return true;
        } catch (\Exception $e) {
            $this->log("_processOrder exception: {$e->getMessage()} ", $e->getTrace());
            return false;
        }
    }

    public function isPaymentValid($openbucksSettings, $response)
    {
        if ($openbucksSettings['merchant_id'] != $response['merchant_id']) {
            return 'An error has occurred during payment. Merchant data is incorrect.';
        }
        if ($response['order_status'] == 'declined') {
            return 'An error has occurred during payment. Order is declined.';
        }

        $responseSignature = $response['signature'];
        if (isset($response['response_signature_string'])) {
            unset($response['response_signature_string']);
        }
        if (isset($response['signature'])) {
            unset($response['signature']);
        }
        if ($this->getSignature($response, $openbucksSettings['secret_key']) != $responseSignature) {
            return 'An error has occurred during payment. Signature is not valid.';
        }
        return true;
    }

    /**
     * @param null $order
     * @param array $paymentData
     * @return mixed
     */
    public function createTransaction($order = null, $paymentData = array())
    {
        try {
            //get payment object from order object
            $payment = $order->getPayment();

            $paymentId = $paymentData['payment']['transaction']['pwgcTrackingID'];
            $payment->setLastTransId($paymentId);
            $payment->setTransactionId($paymentId);
            $payment->setAdditionalInformation(
                [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => (array)$paymentData]
            );
            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $message = __('The authorized amount is %1.', $formatedPrice);
            //get the object of builder class
            $trans = $this->_transactionBuilder;
            $transaction = $trans->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($paymentId)
                ->setAdditionalInformation(
                    [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => (array)$paymentData]
                )
                ->setFailSafe(true)
                //build method creates the transaction and returns the object
                ->build(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_ORDER);

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );
            $payment->setParentTransactionId(null);
            $payment->save();
            $order->save();

            return $transaction->save()->getTransactionId();

        } catch (\Exception $e) {
            $this->log("_processOrder exception", $e->getTrace());
            return false;
        }
    }

    public function getRedirectUrl()
    {
        $useSandbox = true; // $this->getConfigData("options/openbucks_sandbox");

        return $useSandbox ? $this->_demoGateRedirectUrl : $this->_gateRedirectUrl;
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return 'Cash and Gift Cards';
    }

    /**
     * @param string $field
     * @param null $storeId
     * @return mixed
     */
    public function getConfigData($field, $storeId = null)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager = $objectManager->get('\Magento\Store\Model\StoreManagerInterface');

        if (null === $storeId) {
            $storeId = $storeManager->getStore()->getStoreId();
        }
        $path = 'payment/' . $this->_code . '/' . $field;

        return $this->_scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    protected function log($message, $data = [])
    {
        if ($this->getConfigData('advanced/openbucks_debug'))

        $this->_logger->debug($message, $data);
    }
}
