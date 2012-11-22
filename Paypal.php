<?php

class Paypal extends CApplicationComponent
{
    public $email;
    public $username;
    public $password;
    public $signature;
    public $appid = 'APP-80W284485P519543T';// APP-80W284485P519543T - global sandbox app id
    
    public $apiurl  = 'https://api-3t.paypal.com/nvp';
    public $svcsUrl = 'https://svcs.paypal.com/%s';
    public $weburl  = 'https://www.paypal.com/webscr';
    
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
     * Default permissions set
     * @var string
     */
    public $permissions;
    
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
        if ( $this->sandbox ) {
            $this->apiurl  = 'https://api-3t.sandbox.paypal.com/nvp';
            $this->svcsUrl = 'https://svcs.sandbox.paypal.com/%s';
            $this->weburl  = 'https://www.sandbox.paypal.com/webscr';
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
    
    /**
     * Checks payment result
     * @param array $params paypal request params
     * @return mixed saving data array or false
     */
    public function checkResult($token)
    {
        $requestData = $this->_loadRequest();
        
        if ($token == @$requestData['token']) {
            $this->_clearRequest();
            return $requestData['data'];
            
        } else {
        	Yii::trace(sprintf('Tokens "%s" and "%s" are not equal', $token, @$requestData['token']), __CLASS__);
            return false;
        }
    }
    
    # Getters #
    
    public function getPayLink($payKey)
    {
        return sprintf($this->apRedirectUrl, $this->weburl, '_ap-payment', $payKey);
    }
    
    # Express checkout #
    
    /**
     * Makes express checkout request
     * @param array $params Array with PayPal API params
     * @link https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_SetExpressCheckout
     * @return string redirect url
     * @throws PaypalHTTPException
     * @throws PaypalResponseException
 	 */
    public function setExpressCheckout(array $params)
    {
        $defParams = array(
            'REQCONFIRMSHIPPING' => 0,
            'NOSHIPPING' => 1,
        );
        $params = array_merge($defParams, $params);
        
        $response = $this->callNVP('SetExpressCheckout', $params);
        
        if ($response['ACK'] == 'Success') {
            $this->_saveRequest($response['TOKEN']);

            $url = sprintf($this->url, $this->weburl, '_express-checkout', $response['TOKEN']);
            return $url;
            
        } else {
            throw new PaypalNVPResponseException($response);
        }
    }
    
    /**
     * Gets express checkout details
     * @param string $token
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
        
        $response = $this->callNVP('GetExpressCheckoutDetails', $params);
        
        if ($response['ACK'] == 'Success') {
            return $response;
            
        } else {
            throw new PaypalNVPResponseException($response);
        }
    }
    
    /**
     * Makes express checkout payment
     * @param array $details Checkout details returned by getExpressCheckoutDetails
     * @link https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_DoExpressCheckoutPayment
     * @return array checkout result
     * @throws PaypalHTTPException
     * @throws PaypalResponseException
 	 */
    public function doExpressCheckoutPayment(array $details)
    {
        $params = $details;
        
        $response = $this->callNVP('DoExpressCheckoutPayment', $params);
        
        if ($response['ACK'] == 'Success') {
            
            // Checking statuses
            $response['success'] = $this->_checkFieldArray(
                $response,
                'PAYMENTINFO_%d_ACK',
                'Success'
            );
            
            return $response;
            
        } else {
            throw new PaypalNVPResponseException($response);
        }
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
     * @link https://www.x.com/developers/paypal/documentation-tools/api/pay-api-operation
     * @return string redirect url
     * @throws PaypalHTTPException
     * @throws PaypalResponseException
 	 */
    public function pay(array $params)
    {
        $defParams = array(
            'requestEnvelope.detailLevel' => 'ReturnAll',
            'requestEnvelope.errorLanguage' => $this->language,
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
            'requestEnvelope.detailLevel' => 'ReturnAll',
            'requestEnvelope.errorLanguage' => $this->language,
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
            'requestEnvelope.detailLevel' => 'ReturnAll',
            'requestEnvelope.errorLanguage' => $this->language,
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
     */
    public function requestPermissions($callback)
    {
        $params = array(
            'requestEnvelope.detailLevel' => 'ReturnAll',
            'requestEnvelope.errorLanguage' => $this->language,
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
     */
    public function getAccessToken($requestToken, $verificationCode)
    {
        $params = array(
            'requestEnvelope.errorLanguage' => $this->language,
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
     */
    public function getEmail()
    {
        $params = array(
            'requestEnvelope.errorLanguage' => $this->language,
            'attributeList.attribute(0)' => 'http://axschema.org/contact/email',
        );
        
        $response = $this->callSVCS('Permissions/GetBasicPersonalData', $params);
        
        return $response['response_personalData(0)_personalDataValue'];
    }
    
    # Invoices #
    
    public function sendInvoice($invoice){
        $url = 'Invoice/CreateAndSendInvoice';
        $params = array(
            "requestEnvelope.errorLanguage"=>"en_US",
            "invoice.merchantEmail"=>$this->email,
            "invoice.payerEmail"=>$invoice->User->email,//$invoice->Restaurant->paypal_email,
            "invoice.currencyCode"=>$this->currencyCode,
            "invoice.itemList.item(0).name"=>"reservation order",
            "invoice.itemList.item(0).date"=>date("Y-m-d\TH:i:sP", strtotime($invoice->event_date)),
            "invoice.itemList.item(0).quantity"=>1,
            "invoice.itemList.item(0).unitPrice"=>$invoice->fee_amount,
            "invoice.paymentTerms"=>"Net10" 
        );        
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
    
    public function cancelInvoice($invoice){
        $url = 'Invoice/CancelInvoice';
        $params = array(
            "requestEnvelope.errorLanguage"=>"en_US",
            "invoiceID"=>$invoice->invoiceID,
            "noteForPayer"=>"Invoice cancelled",
            "sendCopyToMerchant"=>true,
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
    
	public function checkInvoice($invoice){
        $url = 'Invoice/GetInvoiceDetails';
        $params = array(
            "requestEnvelope.errorLanguage"=>"en_US",
            "requestEnvelope.detailLevel"=>"ReturnAll",
            "invoiceID"=>$invoice->invoiceID
        );
                
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
            'VERSION'   => '65.1',
        
            'LOCALECODE' => $this->localeCode,
        );
        $params = array_merge($defParams, $params);
        
        // Generating post string
        $postStr = http_build_query($params);
        
        $responseStr = $this->call($this->apiurl, $postStr);
        
        parse_str($responseStr, $response);
            
        return $response;
    }
    
    public function callSVCS($name, $params = array())
    {
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
        
        if ($response['responseEnvelope_ack'] == 'Success') {
            Yii::trace('Parsed Response: ' . var_export($response, true), __METHOD__);
            
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
            $headers[] = 'X-PAYPAL-DEVICE-IPADDRESS: '   . $_SERVER['REMOTE_ADDR'];
        }
        
        //setting the curl parameters.
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_VERBOSE => 1,
            
            CURLOPT_HEADER => false,
            
            //turning off the server and peer verification
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
        Yii::app()->session[$this->varName] = array(
            'token' => $token,
            'data' => $this->savingData,
        );
    }
    
    /**
     * Loads request data
     * @return array request data
     */
    private function _loadRequest()
    {
        return Yii::app()->session[$this->varName];
    }
    
    /**
     * Clears request data
     */
    private function _clearRequest()
    {
        unset(Yii::app()->session[$this->varName]);
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
