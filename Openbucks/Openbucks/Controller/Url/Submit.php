<?php

namespace Openbucks\Openbucks\Controller\Url;

use Magento\Framework\App\Action\Action;
use Magento\Sales\Model\Order;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

class Submit extends Action implements CsrfAwareActionInterface
{
    /** @var \Magento\Framework\View\Result\PageFactory */
    public $resultPageFactory;
    /**
     * @var \Openbucks\Openbucks\Block\Widget\Redirect
     */
    public $openbucks;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Openbucks\Openbucks\Block\Widget\Redirect $openbucks_form
    )
    {
        $this->resultPageFactory = $resultPageFactory;
        $this->_checkoutSession = $checkoutSession;
        $this->openbucks = $openbucks_form;
        parent::__construct($context);
    }

    public function execute()
    {
        $this->openbucks->getPostData();

        $post_data = $this->openbucks->getPostData();
        $response = $this->doRequest($post_data);

        $result = json_decode($response, true);

        if (isset($result['errorCode']) && $result['errorCode'] == 0 && isset($result['redirectUrl'])) {
            return $this->_redirect->redirect($this->_response, $result['redirectUrl']);
        }

        $this->restoreCart();

        return $this->_redirect->redirect($this->_response, '/');
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): bool
    {
        return true;
    }

    /**
     * @param array $data
     * @return mixed
     *
     * @throws \Magento\Framework\Validator\Exception
     */
    private function doRequest($data)
    {
        try {
            $httpHeaders = new \Zend\Http\Headers();
            $httpHeaders->addHeaders([
                'User-Agent' => 'Magento 2 CMS',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]);
            $request = new \Zend\Http\Request();
            $request->setHeaders($httpHeaders);

            $request->setUri($this->openbucks->getPayUrl());
            $request->setMethod(\Zend\Http\Request::METHOD_POST);

            foreach ($data as $key => $value) {
                $request->getPost()->set($key, $value);
            }

            $client = new \Zend\Http\Client();
            $options = [
                'adapter' => 'Zend\Http\Client\Adapter\Curl',
                'curloptions' => [CURLOPT_FOLLOWLOCATION => true],
                'maxredirects' => 1,
                'timeout' => 30
            ];
            $client->setOptions($options);

            $response = $client->send($request);

            return $response->getBody();

        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__('Payment capturing error.'));
        }

    }

    /**
     * Restore cart data
     */
    public function restoreCart()
    {
        $lastQuoteId = $this->_checkoutSession->getLastQuoteId();
        if ($quote = $this->_objectManager->get('Magento\Quote\Model\Quote')->loadByIdWithoutStore($lastQuoteId)) {
            $quote->setIsActive(true)
                ->setReservedOrderId(null)
                ->save();
            $this->_checkoutSession->setQuoteId($lastQuoteId);
        }
        $message = __('Payment failed. Please try again.');
        $this->messageManager->addError($message);
    }
}
