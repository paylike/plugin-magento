<?php

/**
 * Attribution Notice: Based on the Paypal payment module included with Magento 2.
 *
 * @copyright  Copyright (c) 2015 Magento
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace PayLike\Paylike\Model;

use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use PayLike\Paylike\Model\Paylike;

class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod {

    const METHOD_CODE = 'paylikeio';
    protected $_code;
    protected $_isInitializeNeeded = true;
    protected $_canOrder = true;
    protected $_canRefund = true;
    protected $_canCapture = true;
    protected $_canAuthorize = true;

    /**
     * @var \Magento\Framework\Exception\LocalizedExceptionFactory
     */
    protected $_exception;

    /**
     * @var \Magento\Sales\Api\TransactionRepositoryInterface
     */
    protected $_transactionRepository;

    /**
     * @var Transaction\BuilderInterface
     */
    protected $_transactionBuilder;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $_urlBuilder;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Magento\Framework\Exception\LocalizedExceptionFactory $exception
     * @param \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository
     * @param Transaction\BuilderInterface $transactionBuilder
     * @param \Magento\Sales\Model\OrderFactory $orderFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
    public function __construct(
    \Magento\Framework\UrlInterface $urlBuilder, \Magento\Framework\Exception\LocalizedExceptionFactory $exception, \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository, \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder, \Magento\Sales\Model\OrderFactory $orderFactory, \Magento\Store\Model\StoreManagerInterface $storeManager, \Magento\Framework\Model\Context $context, \Magento\Framework\Registry $registry, \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory, \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory, \Magento\Payment\Helper\Data $paymentData, \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig, \Magento\Payment\Model\Method\Logger $logger, \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null, \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null, array $data = []
    ) {
        $this->_urlBuilder = $urlBuilder;
        $this->_exception = $exception;
        $this->_transactionRepository = $transactionRepository;
        $this->_transactionBuilder = $transactionBuilder;
        $this->_orderFactory = $orderFactory;
        $this->_storeManager = $storeManager;
        $this->_code = static::METHOD_CODE;
        parent::__construct(
                $context, $registry, $extensionFactory, $customAttributeFactory, $paymentData, $scopeConfig, $logger, $resource, $resourceCollection, $data
        );
    }

    /**
     * Instantiate state and set it to state object.
     *
     * @param string                        $paymentAction
     * @param \Magento\Framework\DataObject $stateObject
     */
    public function initialize($paymentAction, $stateObject) {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);

        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
    }

    public function getClient() {
        $apiKey = $this->getConfigData('api_key');
        if ($apiKey == null) {
            $this->_exception->create(
                    ['phrase' => __('PayLike API keys not configured.')]
            );
        }

        return $apiKey;
    }

    public function assignData(\Magento\Framework\DataObject $data) {

        parent::assignData($data);
        $infoInstance = $this->getInfoInstance();
        $infoInstance->setAdditionalInformation('paylike_txn_id', $data->getPaylikeTxnId());                
        return $this;
    }

    public function getCheckoutUrl($order, $storeId = null) {
        $orderId = $order->getIncrementId();

        $params = array(
            'key' => $apiKey = $this->getConfigData('api_key'),
            'amount' => $order->getTotalDue() * 100,
            'currency' => $order->getBaseCurrencyCode(),
            'reference' => 'Payment for Order# ' . $orderId,
            'redirect' => $this->getReturnUrl($storeId),
        );

        try {
            $code = $this->_prepareParams($params);
        } catch (Exception $e) {
            $message = print_r($e, true);
            $this->_debug("PayLike: Error generating checkout code $message");
            $this->_exception->create(
                    ['phrase' => __('There was an error redirecting you to PayLike. Please select a different payment method.')]
            );
        }


        return 'https://pos.paylike.io/?' . $code;
    }

    public function getOrderPlaceRedirectUrl($storeId = null) {
        return $this->_getUrl('paylikeio/start', $storeId);
    }

    private function _prepareParams($params) {
        return http_build_query($params);
    }

    /**
     * Get return URL.
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getReturnUrl($storeId = null) {
        return $this->_getUrl('paylikeio/api/callback', $storeId);
    }

    /**
     * Get return URL.
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getSuccessUrl($storeId = null) {
        return $this->_getUrl('paylikeio/checkout/success', $storeId);
    }

    /**
     * Get notify (IPN) URL.
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getNotifyUrl($storeId = null) {
        return $this->_getUrl('paylikeio/ipn/callback', $storeId, false);
    }

    /**
     * Get cancel URL.
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getCancelUrl($storeId = null) {
        return $this->_getUrl('paylikeio/checkout/cancel', $storeId);
    }

    /**
     * Build URL for store.
     *
     * @param string    $path
     * @param int       $storeId
     * @param bool|null $secure
     *
     * @return string
     */
    protected function _getUrl($path, $storeId, $secure = null) {
        $store = $this->_storeManager->getStore($storeId);

        return $this->_urlBuilder->getUrl(
                        $path, ['_store' => $store, '_secure' => $secure === null ? $store->isCurrentlySecure() : $secure]
        );
    }

    //$this->debugData(['request' => $requestData, 'exception' => $e->getMessage(), 'result' => $result]);

    /**
     * Authorize payment
     *
     * @param \Magento\Framework\DataObject|\Magento\Payment\Model\InfoInterface|Payment $payment
     * @param float $amount
     * @return $this
     */
    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount) {
        //return $this->_placeOrder($payment, $amount);
        $this->debugData(['request' => "Authorizaton just called ", 'amount' => $amount]);
        return $this;
    }

    /**
     * Payment capturing
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Validator\Exception
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount) {

        try {
            $order = $payment->getOrder();
            $paylike = new Paylike($this->getConfigData('app_key'));
            $transactionId = $payment->getAdditionalInformation('paylike_txn_id');
            $requestData = ['currency' => $order->getBaseCurrencyCode(), 'amount' => $amount * 100];
            $result = $paylike->transactions->capture($transactionId, $requestData);


            if ($result && $result->transaction->successful == true) {
                $payment
                        ->setTransactionId($transactionId)
                        ->setIsTransactionClosed(1)
                        ->setShouldCloseParentTransaction(1);
            } else {
                $this->_logger->error(__('Paylike Payment Capture error.'));
                throw new \Magento\Framework\Validator\Exception(__('Paylike Payment capture error.'));
            }
        } catch (\Exception $e) {
            $this->debugData(['request' => $requestData, 'exception' => $e->getMessage(), 'result' => $result]);
            $this->_logger->error(__('Payment capturing error.'));
            throw new \Magento\Framework\Validator\Exception(__('Payment capturing error.'));
        }

        return $this;
    }

    /**
     * Payment refund
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Validator\Exception
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount) {

        try {
            $order = $payment->getOrder();
            $paylike = new Paylike($this->getConfigData('app_key'));
            $transactionId = $payment->getParentTransactionId();
            $requestData = ['currency' => $order->getBaseCurrencyCode(), 'amount' => $amount * 100];
            $result = $paylike->transactions->refund($transactionId, $requestData);
            if ($result && $result->transaction->successful == true) {
                $payment
                        ->setTransactionId($transactionId . '-' . \Magento\Sales\Model\Order\Payment\Transaction::TYPE_REFUND)
                        ->setParentTransactionId($transactionId)
                        ->setIsTransactionClosed(1)
                        ->setShouldCloseParentTransaction(1);
            } else {
                $this->_logger->error(__('Paylike Payment refunding error.'));
                throw new \Magento\Framework\Validator\Exception(__('Paylike Payment refunding error.'));
            }
        } catch (\Exception $e) {
            $this->debugData(['request' => $requestData, 'exception' => $e->getMessage(), 'result' => $result]);
            $this->_logger->error(__('Payment capturing error.'));
            throw new \Magento\Framework\Validator\Exception(__('Payment capturing error.'));
        }

        return $this;
    }

}
