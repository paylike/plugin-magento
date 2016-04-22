<?php

/**
 * Attribution Notice: Based on the Paypal payment module included with Magento 2.
 *
 * @copyright  Copyright (c) 2015 Magento
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace PayLike\Paylike\Controller\Checkout;

use Magento\Framework\App\Action\Action as AppAction;

class Callback extends AppAction {

    /**
     * @var \PayLike\Paylike\Model\PaymentMethod
     */
    protected $_paymentMethod;

    /**
     * @var \PayLike\Wallet\Resource\Notification
     */
    protected $_notification;

    /**
     * @var \PayLike\Wallet\Resource\Order
     */
    protected $_paylike_order;

    /**
     * @var \Magento\Sales\Model\Order
     */
    protected $_order;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    protected $_orderSender;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;
    protected $transactionFactory;
    protected $_objectManager;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param \PayLike\Paylike\Model\PaymentMethod $paymentMethod
     * @param Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
     * @param  \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
    \Magento\Framework\App\Action\Context $context, \Magento\Framework\ObjectManagerInterface $objectManager, \PayLike\Paylike\Model\PaymentMethod $paymentMethod, \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender, \Magento\Checkout\Model\Session $checkoutSession, \Psr\Log\LoggerInterface $logger, \Magento\Framework\DB\TransactionFactory $transactionFactory
    ) {
        $this->_paymentMethod = $paymentMethod;
        $this->_objectManager = $objectManager;
        //$this->_client = $this->_paymentMethod->getClient();
        $this->transactionFactory = $transactionFactory;
        $this->_orderSender = $orderSender;
        $this->_checkoutSession = $checkoutSession;
        $this->_logger = $logger;

        parent::__construct($context);
    }

    public function execute() {


        try {
            $this->_loadOrder();
            $this->_registerPaymentCapture();
            $this->_redirect('checkout/onepage/success');
        } catch (\Exception $e) {
            $this->_logger->addError("PayLike: error processing callback");
            $this->_logger->addError($e->getMessage());
            $this->_redirect('checkout/cart/');
        }
    }

    protected function _registerPaymentCapture() {

        $order = $this->_order;
        //echo $this->_order->getId(); exit;
        if ($order->getPayment()->getMethod() == $this->_paymentMethod->getCode()) {
            $qtys = [];
            foreach ($order->getAllItems() as $orderItem) {
                $qtys[$orderItem->getId()] = $orderItem->getQty();
            }

            $invoice = $order->prepareInvoice($qtys);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
            $invoice->register();
            $transaction = $this->transactionFactory->create();
            $transaction->addObject($invoice)
                    ->addObject($invoice->getOrder())
                    ->save();

            if ($invoice && !$this->_order->getEmailSent()) {
                $this->_orderSender->send($this->_order);
                $this->_order->addStatusHistoryComment(
                        __('You notified customer about invoice #%1.', $invoice->getIncrementId())
                )->setIsCustomerNotified(
                        true
                )->save();
            }
        }
    }

    protected function _loadOrder() {
        $session = $this->getCheckout();
        $order_id = $session->getLastOrderId();        
        $this->_order = $this->_objectManager->create('Magento\Sales\Model\Order')->load($order_id);

        if (!$this->_order && $this->_order->getId()) {
            throw new Exception('Could not find Magento order with id $order_id');
        }
    }

    /**
     * Get frontend checkout session object
     *
     * @return \Magento\Checkout\Model\Session
     * @codeCoverageIgnore
     */
    protected function getCheckout() {
        return $this->_checkoutSession;
    }

    protected function _redirect($path, $arguments = []) {
        $this->_redirect->redirect($this->getResponse(), $path, $arguments);
        return $this->getResponse();
    }

    /**
     * Generate an "IPN" comment with additional explanation.
     * Returns the generated comment or order status history object.
     *
     * @param string $comment
     * @param bool $addToHistory
     *
     * @return string|\Magento\Sales\Model\Order\Status\History
     */
    protected function _createIpnComment($comment = '', $addToHistory = false) {
        $message = __('IPN "%1"', $this->_notification->getType());
        if ($comment) {
            $message .= ' ' . $comment;
        }
        if ($addToHistory) {
            $message = $this->_order->addStatusHistoryComment($message);
            $message->setIsCustomerNotified(null);
        }

        return $message;
    }

}
