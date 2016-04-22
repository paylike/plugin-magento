<?php

namespace PayLike\Paylike\Block;

class Config extends \Magento\Framework\View\Element\Template {

    protected $_cart;
    protected $scopeConfig;

    public function __construct(
    \Magento\Framework\View\Element\Template\Context $context, \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig, \Magento\Checkout\Model\Cart $cart
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->_cart = $cart;
        parent::__construct($context);
    }

    protected function _getQuote() {
        return $this->_cart->getQuote();
    }

    public function getStoreName() {
        return $this->scopeConfig->getValue(
                        'general/store_information/name', \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    public function getPublicApiKey() {

        return $this->scopeConfig->getValue(
                        'payment/paylikeio/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    public function getConfigJSON() {

        $quote = $this->_getQuote();
        $config = array(
            "title" => $this->getStoreName(),
            'description' => '',
            'currency' => $quote->getBaseCurrencyCode(),
            'amount' => $quote->getBaseGrandTotal() * 100,
        );

        $fields = array(
            array(
                "name" => "email",
                "type" => "email",
                "placeholder" => "user@example.com",
                "required" => true,
                'value' => $quote->getCustomerEmail(),
            ),
            'note'
        );

        $config['fields'] = $fields;

        $description = '';
        $products = array();
        foreach ($quote->getAllItems() as $item) {
            $product = array(
                'Name' => $item->getName(),
                'SKU' => $item->getSku(),
                "quantity" => $item->getQty(),
            );

            $description .= " " . $item->getQty() . "X " . $item->getName() . ' &';
            $products[] = $product;
        }

        $config['custom']['products'] = $products;
        $config['description'] = trim($description, "&");

        return json_encode($config);
    }

}
