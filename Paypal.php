<?php

class Paypal extends CApplicationComponent
{
    # Paypal user credentials #
    
    public $email;
    public $username;
    public $password;
    public $signature;
    public $appid = 'APP-80W284485P519543T';// APP-80W284485P519543T - global sandbox app id
    
    public $apiurl  = null;
    public $svcsUrl = null;
    public $weburl  = null;
    
    # Paypal default settings #
    
    /**
     * Default language
     * @var string
     */
    public $language = 'en_US';
    /**
     * Default currency code
     * @var string
     */
    public $currencyCode = 'USD';
    /**
     * Default locale code
     * @var string
     */
    public $localeCode = 'US';
    /**
     * Default country code
     * @var string
     */
    public $countryCode = null;
    
    /**
     * Default permissions set
     * @var string
     */
    public $permissions;
    
    public $cancelUrl = null;
    
    /**
     * Sandbox mode
     * @var boolean
     */
    public $sandbox = false;
    
    /**
     * Paypal token user flash name
     * @var string
     */
    public $varName = 'paypal';
    
    /**
     * @var array array to save payment id or something else
     */
    public $savingData = array();
    
    private $url = '%s?cmd=%s&token=%s';
    private $apRedirectUrl = '%s?cmd=%s&paykey=%s';

    public function init()
    {
        // Set up domains
        $domain = ($this->sandbox ? 'sandbox.' : '') . 'paypal.com';
        if ($this->apiurl === null)
            $this->apiurl = "https://api-3t.$domain/nvp";
        if ($this->svcsUrl === null)
            $this->svcsUrl = "https://svcs.$domain/%s";
        if ($this->weburl === null) {
            $country = isset($this->countryCode) ? $this->countryCode . '/' : '';
            $this->weburl = "https://www.$domain/{$country}webscr";
        }
        
        if ($this->cancelUrl === null && Yii::app()->hasComponent('request')) {
            $this->cancelUrl = Yii::app()->request->getBaseUrl(true);
        }
    }
    
    public function setCredentials($credentials, $liveOnly = true)
    {
        if ( !$liveOnly || !$this->sandbox ) {
            $this->username  = $credentials['username'];
            $this->password  = $credentials['password'];
            $this->signature = $credentials['signature'];
        }
    }
    
    # Getters #
    
    public function getPayLink($payKey)
    {
        return sprintf($this->apRedirectUrl, $this->weburl, '_ap-payment', $payKey);
    }
    
    # Express Checkout #
    
    /**
     * Makes express checkout request
     * @param array $params Array with PayPal API params
     * @link https://www.x.com/developers/paypal/documentation-tools/express-checkout/gs_expresscheckout
     * @link https://www.x.com/developers/paypal/documentation-tools/api/setexpresscheckout-api-operation-nvp
     * @return string redirect url
     * @throws PaypalHTTPException
     * @throws PaypalResponseException
 	 */
    public function setExpressCheckout(array $params)
    {
        $params += array(
            'METHOD' => 'SetExpressCheckout',
            'ALLOWNOTE' => 0,
            'REQCONFIRMSHIPPING' => 0,
            'NOSHIPPING' => 1,
            'CANCELURL' => $this->cancelUrl,
        );
        
        $response = $this->callNVP('SetExpressCheckout', $params);
        
        $this->_saveRequest($response['TOKEN']);

        $url = sprintf($this->url, $this->weburl, '_express-checkout', $response['TOKEN']);
        return $url;
    }
    
    /**
     * Gets express checkout details
     * @param string $token
     * @link https://www.x.com/developers/paypal/documentation-tools/express-checkout/gs_expresscheckout
     * @link https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_GetExpressCheckoutDetails
     * @return array checkout details
     * @throws PaypalHTTPException
     * @throws PaypalResponseException
 	 */
    public function getExpressCheckoutDetails($token)
    {
        $params = array(
            'TOKEN' => $token,
        );
        
        return $this->callNVP('GetExpressCheckoutDetails', $params);
    }
    
    /**
     * Makes express checkout payment
     * @param string|array $token payment token
     * This paramener may be array - checkout details returned by getExpressCheckoutDetails
     * @link https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_DoExpressCheckoutPayment
     * @link https://www.x.com/developers/paypal/documentation-tools/express-checkout/gs_expresscheckout Express Checkout API Getting Started Guide
     * @link https://www.x.com/developers/paypal/documentation-tools/api/doexpresscheckoutpayment-api-operation-nvp DoExpressCheckoutPayment API Operation
     * @return array payment response
     * This function adds some additional parameters to response array:
     * success (array) - true if all payments were successful.
     * details (array) - getExpressCheckoutDetails result
     * @throws PaypalHTTPException
     * @throws PaypalResponseException
 	 */
    public function doExpressCheckoutPayment($token)
    {
        if (is_string($token))
            $details = $this->getExpressCheckoutDetails($token);
        else
            $details = $token;
        
        $response = $this->callNVP('DoExpressCheckoutPayment', $details);
        
        // Checking statuses
        $response['success'] = $this->_checkFieldArray(
            $response,
            'PAYMENTINFO_%d_ACK',
            'Success'
        );
        
        $response['details'] = $details;

        return $response;
    }
    
    /**
     * Checks payment result token
     * @param array $params paypal request params
     * @return mixed saved data array or false
     * @throws PaypalHTTPException
     * @throws PaypalResponseException
     */
    public function checkExpressCheckoutToken($token)
    {
        $requestData = $this->_loadRequest($token);
        
        if ($requestData !== null) {
            Yii::trace(sprintf('Request with token %s found', $token), __CLASS__);
            $this->_clearRequest($token);
            return $requestData;
            
        } else {
        	Yii::trace(sprintf('Request with token %s not found', $token), __CLASS__);
            return false;
        }
    }
    
    /**
     * This function makes full path to finish [express checkout] payment.
     * 1. Checks for "token" ans "PayerID" GET parameters (Paypal adds them to successUrl).
     * 2. Compares token with token saved to session
     * 3. Calls "DoExpressCheckoutPayment" to finish payment
     * 
     * @return array|boolean payment result or false
     * When this method returns "false" - you can be sure that payment didn't started.
     * When this method returns array, you need to check element 'success' (boolean) to ensure payment was successful.
     * Result array has element "details" with "GetExpressCheckoutDetails" result.
     */
    public function finishExpressCheckoutPayment()
    {
        if (isset($_GET['token'], $_GET['PayerID'])) {
            $token   = $_GET['token'];
            $session = $this->checkExpressCheckoutToken($token);
            if ($session !== false) {
                $result = $this->doExpressCheckoutPayment($token);
                $result['session'] = $session;
                return $result;
            }
        }
        
        return false;
    }
    
    # Express Checkout - Recurring Payments #
    
    /**
     * @link https://www.x.com/developers/paypal/documentation-tools/express-checkout/integration-guide/ECRecurringPayments
     */
    
    /**
     * Calls CreateRecurringPaymentsProfile API operation that creates a recurring payments profile.
     * @param array $params request params
     * @return array payment response
     * @link https://cms.paypal.com/uk/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_CreateRecurringPayments CreateRecurringPaymentsProfile API Operation
     * @link https://www.x.com/developers/paypal/documentation-tools/api/createrecurringpaymentsprofile-api-operation-nvp CreateRecurringPaymentsProfile API Operation (NVP)
     * @throws PaypalHTTPException
     * @throws PaypalResponseException
 	 */
    public function createRecurringPaymentsProfile($params)
    {
        $params += array(
            'METHOD'       => 'CreateRecurringPaymentsProfile',
            'CURRENCYCODE' => $this->currencyCode,
            'CANCELURL'    => $this->cancelUrl,
        );
        
        $response = $this->callNVP('DoExpressCheckoutPayment', $params);
        
        return $response;
    }
    
    /**
     * Calls GetRecurringPaymentsProfileDetails API operation that obtains information about a recurring payments profile. 
     * @param string $profileId Paypal recurring payment profile id.
     * @return array payment profile details
     * @link https://www.x.com/developers/paypal/documentation-tools/api/getrecurringpaymentsprofiledetails-api-operation-nvp GetRecurringPaymentsProfileDetails API Operation (NVP)
     * @throws PaypalHTTPException
     * @throws PaypalResponseException
 	 */
    public function getRecurringPaymentsProfileDetails($profileId)
    {
        $params = array(
            'METHOD'    => 'GetRecurringPaymentsProfileDetails',
            'PROFILEID' => $profileId,
        );
        
        $details = $this->callNVP('GetRecurringPaymentsProfileDetails', $params);
        
        return $details;
    }
    
    # Adaptive payments #
    
    /**
     * Performs a payment from system account 
     * @param array $params payment params
     * @return string payment key
     * @throws PaypalException
     * @see pay
     */
    public function systemPay(array $params)
    {
    	$params['sender.useCredentials'] = 'true';
    	
    	$response = $this->pay($params);
    	
    	if ($response['paymentExecStatus'] == 'COMPLETED') {
    		return $response['payKey'];
    		
    	} else {
    		throw new PaypalException('Payment not completed');
    	}
    }
    
    /**
     * Creates a payment and returns redirect url 
     * @param array $params payment params
     * @return string payment key
     * @throws PaypalException
     * @see pay
     */
    public function payUrl(array $params)
    {
        $response = $this->pay($params);
    	
    	if ($response['paymentExecStatus'] == 'CREATED') {
    	    $this->_saveRequest($response['payKey']);
    	    
    		return array(
                'payKey' => $response['payKey'],
                'url' => $this->getPayLink($response['payKey']),
            );
    		
    	} else {
    		throw new PaypalException(string('Payment status is %s (expecting "CREATED")', $response['paymentExecStatus']));
    	}
    }
    
    /**
     * Makes payment request
     * @param array $params Array with PayPal API params
     * @link https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_APPayAPI
     * @link https://www.x.com/developers/paypal/documentation-tools/adaptive-payments/gs_AdaptivePayments Adaptive Payments API: Getting Started
     * @link https://www.x.com/developers/paypal/documentation-tools/adaptive-payments/integration-guide/APIntro Introducing Adaptive Payments
     * @link https://www.x.com/developers/paypal/documentation-tools/api/pay-api-operation
     * @return string redirect url
     * @throws PaypalHTTPException
     * @throws PaypalResponseException
 	 */
    public function pay(array $params)
    {
        $defParams = array(
            'currencyCode' => $this->currencyCode,
        	'actionType' => 'PAY',
        );
        $params = array_merge($defParams, $params);
        
        $response = $this->callSVCS('AdaptivePayments/Pay', $params);
        
        return $response;
    }
    
    /**
     * Returns payment details
     * @param string $payKey payment key
     * @return array PayPal response as array
     * @link https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_APPaymentDetails
     */
    public function paymentDetails($payKey)
    {
        $params = array(
        	'currencyCode' => $this->currencyCode,
        	'payKey' => $payKey,
        );
        
        $response = $this->callSVCS('AdaptivePayments/PaymentDetails', $params);
        
        return $response;
    }
    
    /**
     * Refunds payment
     * @param string $payKey payment key
     * @return array PayPal response as array
     * @link https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_APRefund
     */
    public function refund($payKey)
    {
        $params = array(
            'currencyCode' => $this->currencyCode,
            'payKey' => $payKey,
        );
        
        $response = $this->callSVCS('AdaptivePayments/Refund', $params);
        
        // Checking statuses
        $response['success'] = $this->_checkFieldArray(
            $response,
            'refundInfoList_refundInfo(%d)_refundStatus',
            array('REFUNDED', 'REFUNDED_PENDING')
        );
        
        return $response;
    }
    
    # API Permissions #
    
    /**
     * Request permissions api
     * @param string $callback
     * @return string redirect url
     * @link https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_PermissionsRequestPermissionsAPI
     * @link https://www.x.com/developers/paypal/documentation-tools/api/requestpermissions-api-operation RequestPermissions API Operation
     */
    public function requestPermissions($callback)
    {
        $params = array(
        	'scope' => $this->permissions,
        	'callback' => $callback,
        );
        
        $response = $this->callSVCS('Permissions/RequestPermissions', $params);
        
        $url = sprintf('%s?cmd=%s&%s=%s', $this->weburl, '_grant-permission', 'request_token', $response['token']);
        
        return $url;
    }
    
    /**
     * GetAccessToken permissions api
     * @param string $requestToken field "request_token" from the callback query
     * @param string $verificationCode field "verification_code" from the callback query
     * @return array paypal responce
     * @link https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_PermissionsGetAccessTokenAPI
     * @link https://www.x.com/developers/paypal/documentation-tools/api/getaccesstoken-api-operation GetAccessToken API Operation
     */
    public function getAccessToken($requestToken, $verificationCode)
    {
        $params = array(
        	'token' => $requestToken,
        	'verifier' => $verificationCode,
        );
        
        $response = $this->callSVCS('Permissions/GetAccessToken', $params);
        
        $scopeArray = $this->_getFieldArray($response, 'scope(%d)');
        $response['scope'] = implode(',', $scopeArray);
        
        return $response;
    }
    
    /**
     * GetBasicPersonalData API Operation
     * @return array paypal email
     * @link https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_PermissionsGetBasicPersonalDataAPI
     * @link https://www.x.com/developers/paypal/documentation-tools/api/getbasicpersonaldata-api-operation
     */
    public function getEmail()
    {
        $params = array(
            'attributeList.attribute(0)' => 'http://axschema.org/contact/email',
        );
        
        $response = $this->callSVCS('Permissions/GetBasicPersonalData', $params);
        
        return $response['response_personalData(0)_personalDataValue'];
    }
    
    # Invoices #
    
    public function sendInvoice($params)
    {
        $url = 'Invoice/CreateAndSendInvoice';

        // Generating post string
        //$postStr = http_build_query($params);
        $response = $this->callSVCS($url, $params);
        //parse_str($responseStr, $response);
        if ($response['responseEnvelope_ack'] == 'Success') {
            return $response['invoiceID'];
        }else{
            return 'Failure';
        }
    }
    
    public function cancelInvoice($params)
    {
        $url = 'Invoice/CancelInvoice';
        $params += array(
            'sendCopyToMerchant' => true,
        );
                
        // Generating post string
        //$postStr = http_build_query($params);
        $response = $this->callSVCS($url, $params);
        //parse_str($responseStr, $response);
        if ($response['responseEnvelope_ack'] == 'Success') {
            return 'Success';
        }else{
            return 'Failure';
        }
    }
    
	public function checkInvoice($params)
    {
        $url = 'Invoice/GetInvoiceDetails';
                
        // Generating post string
        //$postStr = http_build_query($params);
        $response = $this->callSVCS($url, $params);
        //parse_str($responseStr, $response);
        if ($response['responseEnvelope_ack'] == 'Success' and $response['invoiceDetails_status'] == 'Paid') {
            return 'Paid';
        }else{
            return 'Not paid';
        }
    }
    
    # Calls #
    
    public function callNVP($methodName, $params = array())
    {
        $defParams = array(
            'METHOD'    => $methodName,
            'USER'      => $this->username,
            'PWD'       => $this->password,
            'SIGNATURE' => $this->signature,
			//'TOKEN'     => null,
            'VERSION'   => '86',
        
            'LOCALECODE' => $this->localeCode,
        );
        $params = array_merge($defParams, $params);
        
        // Generating post string
        $postStr = http_build_query($params);
        
        $responseStr = $this->call($this->apiurl, $postStr);
        
        parse_str($responseStr, $response);
        
        if ($response['ACK'] == 'Success') {
            return $response;
            
        } else {
            throw new PaypalNVPResponseException($response);
        }
    }
    
    public function callSVCS($name, $params = array())
    {
        $defParams = array(
            'requestEnvelope.detailLevel' => 'ReturnAll',
            'requestEnvelope.errorLanguage' => $this->language,
        );
        $params = array_merge($defParams, $params);

        //$postStr = http_build_query($params);
        
        $postParams = array();
        foreach ($params as $key => $val) {
            $postParams[] = $key . '=' . urlencode($val);
        }
        $postStr = implode('&', $postParams);
        
        $url = sprintf($this->svcsUrl, $name);
        
        $responseStr = $this->call($url, $postStr);
        
        if (strpos($responseStr, '&') === false)
            throw new PaypalResponseException(array($responseStr), $responseStr);
        
        parse_str($responseStr, $response);
        
        Yii::trace('Parsed Response: ' . var_export($response, true), __METHOD__);
        
        if ($response['responseEnvelope_ack'] == 'Success') {
            return $response;
            
        } else {
            throw new PaypalSVCSResponseException($response);
        }
    }
    
    public function call($url, $postStr)
    {
        Yii::trace('Calling ' . $url . "\n" . 'POST ' . $postStr, __CLASS__);
        
        $headers = array(
            'X-PAYPAL-SECURITY-USERID: '    . $this->username,
            'X-PAYPAL-SECURITY-PASSWORD: '  . $this->password,
            'X-PAYPAL-SECURITY-SIGNATURE: ' . $this->signature,
            'X-PAYPAL-REQUEST-DATA-FORMAT: '  . 'NV',
            'X-PAYPAL-RESPONSE-DATA-FORMAT: ' . 'NV',
            'X-PAYPAL-APPLICATION-ID: ' . $this->appid,
            //'X-PAYPAL-SERVICE-VERSION: 1.3.0',
        );
        
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $headers[] = 'X-PAYPAL-DEVICE-IPADDRESS: ' . $_SERVER['REMOTE_ADDR'];
        }
        
        //setting the curl parameters.
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_VERBOSE => 1,
            
            CURLOPT_HEADER => false,
            
            // Turning off the server and peer verification
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POST => 1,
            
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $postStr,
        ));
        
        // Getting response from server
        $responseStr = curl_exec($ch);
    
        if (curl_errno($ch)) {
            throw new PaypalHTTPException();
            
        } else {
            //closing the curl
            curl_close($ch);
            
            Yii::trace('Response: ' . $responseStr, __METHOD__);
            
            return $responseStr;
        }
    }
    
    
    /**
     * Groups messages in response to array
     * @param array $response
     * @return array 
     */
    public function groupResponse($response)
    {
        $pattern = '~([^\(.]+)(\((\d*)\))?~';
        
        $grouped = array();
        
        // Pointers magic
        
        foreach ($response as $key => $val) {
            // Splitting path to parts
            preg_match_all($pattern, $key, $matches, PREG_SET_ORDER);
            
            // "error(0).message" will be splitted to [{error,0}, {message}]
            
            $group = &$grouped;
            foreach ($matches as $match) {
                $name  =  $match[1];
                $index = @$match[3];
                
                if ( !isset($group[$name]) ) {
                    if ( isset($index) ) {
                        $group[$name] = array($index => array());
                        
                    } else {
                        $group[$name] = array();
                    }
                }
                
                $group = &$group[$name]; // Entering string index
                if ( isset($index) )
                    $group = &$group[$index]; // Entering int index
            }
            
            $group = $val;
        }
        
        return $grouped;
    }
    
    
    # Private methods #
    
    /**
     * Returns field array
     * @param array $data responce array
     * @param string $tpl sprintf template to generate field names
     * @return array
     */
    private function _getFieldArray($data, $tpl)
    {
        $resp = array();
        
        $i = 0;
        while(isset($data[sprintf($tpl, $i)])) {
            $resp[] = $data[sprintf($tpl, $i)];
            ++$i;
        }
        
        return $resp;
    }
    
    /**
     * 
     * @param array $data responce array
     * @param string $tpl sprintf template to generate field names
     * @param array $valid value variants
     * @return boolean
     */
    private function _checkFieldArray($data, $tpl, $valid)
    {
        if (!is_array($valid))
            $valid = array($valid);
        
        $fields = $this->_getFieldArray($data, $tpl);

        foreach ($fields as $field) {
            if (!in_array($field, $valid)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Saves request data
     * @param string $token
     */
    private function _saveRequest($token)
    {
        $requests = Yii::app()->session[$this->varName];
        $requests[$token] = $this->savingData;
        Yii::app()->session[$this->varName] = $requests;
    }
    
    /**
     * Loads request data
     * @param string $token request token
     * @return array request data
     */
    private function _loadRequest($token)
    {
        if (isset(Yii::app()->session[$this->varName][$token]))
            return Yii::app()->session[$this->varName][$token];
        else
            return null;
    }
    
    /**
     * Clears request data
     * @param string $token request token
     */
    private function _clearRequest($token)
    {
        $requests = Yii::app()->session[$this->varName];
        if (isset($requests[$token])) {
            unset($requests[$token]);
            if (empty($requests))
                unset(Yii::app()->session[$this->varName]);
            else
                Yii::app()->session[$this->varName] = $requests;
            
            return true;
            
        } else
            return false;
    }
    
}

# Exceptions #

class PaypalException extends CException {}

class PaypalHTTPException extends PaypalException {}

/**
 * This exception is trown when PayPal returns failure response
 */
class PaypalResponseException extends PaypalException
{
    public $response;
    
    public function __construct(array $response, $msg)
    {
        $this->response = $response;
        parent::__construct($msg);
    }
}

class PaypalNVPResponseException extends PaypalResponseException
{
    public function __construct(array $response)
    {
        parent::__construct($response, $response['L_LONGMESSAGE0']);
    }
}

class PaypalSVCSResponseException extends PaypalResponseException
{
    public function __construct(array $response)
    {
        parent::__construct($response, $response['error(0)_message']);
    }
}
