# Yii Paypal

Paypal API Client for Yii framework.

*Warning!* The code should be heavily refactored so updates can have backward incompatibility.

## Supported API's

* Express checkout
    * Express checkout - Recurring payments ([CreateRecurringPaymentsProfile](https://www.x.com/developers/paypal/documentation-tools/api/createrecurringpaymentsprofile-api-operation-nvp), [GetRecurringPaymentsProfileDetails](https://www.x.com/developers/paypal/documentation-tools/api/getrecurringpaymentsprofiledetails-api-operation-nvp), [ManageRecurringPaymentsProfileStatus](https://www.x.com/developers/paypal/documentation-tools/api/managerecurringpaymentsprofilestatus-api-operation-nvp))
* Adaptive payments (+ refunds)
* API permissions
* Invoices

## How to perform Express Checkout

Express Checkout is a simplest Paypal payment tool. 

1. Execute payment by calling ``$paymentUrl = setExpressCheckout(array $params)`` function. You need to pass [request parameters](https://developer.paypal.com/webapps/developer/docs/classic/api/merchant/SetExpressCheckout_API_Operation_NVP/) to it.
2. Redirect user to ``$paymentUrl``.
3. Check payment result by calling ``$result = finishExpressCheckoutPayment()``. If ``$result`` is not false and ``$result['success']`` is true, payment is sent.
