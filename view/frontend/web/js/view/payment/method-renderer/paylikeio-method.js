/*browser:true*/
/*global define*/
define(
        [
            'ko',
            'jquery',
            'Magento_Checkout/js/view/payment/default',
            'mage/url',
            'Magento_Checkout/js/model/quote',
            'Magento_Checkout/js/action/place-order',
            'Magento_Checkout/js/model/payment/additional-validators',
            'Magento_Ui/js/model/messages'
        ],
        function (
                ko,
                $,
                Component,
                url,
                quote,
                placeOrderAction,
                additionalValidators,
                Messages
                ) {
            'use strict';

            return Component.extend({
                defaults: {
                    template: 'PayLike_Paylike/payment/paylikeio-form',
                    paylikeTxnId: ''
                },
                redirectAfterPlaceOrder: false,
                /**
                 * After place order callback
                 */
                afterPlaceOrder: function () {
                    window.location.replace(url.build('paylikeio/checkout/callback'));
                },
                isPlaceOrderActionAllowed: ko.observable(quote.billingAddress() != null),
                /**
                 * Place order.
                 */
                placeOrder: function (data, event) {
                    console.log("placing order....");
                    var self = this,
                            placeOrder;

                    if (event) {
                        event.preventDefault();
                    }

                    if (this.validate() && additionalValidators.validate()) {
                        this.isPlaceOrderActionAllowed(false);
                        placeOrder = placeOrderAction(this.getData(), this.redirectAfterPlaceOrder, this.messageContainer);

                        $.when(placeOrder).fail(function () {
                            self.isPlaceOrderActionAllowed(true);
                        }).done(this.afterPlaceOrder.bind(this));
                        return true;
                    }
                    return false;
                },
                processPaylike: function () {
                    var self = this;
                    paylikeConfig.amount = quote.totals()['base_grand_total'] * 100;

                    paylike.popup(paylikeConfig, function (err, res) {
                        if (err) {
                            console.log(err);
                            return false;
                        }

                        if (res.transaction.id !== undefined && res.transaction.id !== "") {
                            self.paylikeTxnId = res.transaction.id;                            
                            self.placeOrder();
                        } else {
                            return false;
                        }
                    });
                },
                getData: function () {
                    return {
                        "method": this.item.method,
                        'additional_data': {
                            'paylike_txn_id': this.paylikeTxnId
                        }
                    };

                },
                validate: function () {
                    return true;
                },
            });
        }
);
