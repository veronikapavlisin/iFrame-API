<?php 
/**
 * 
 * Library for communication via XML signed requests/responses.
 *
 * @package   iFrameApiLib
 * @author     Veronika Pavlisin
 * @version    1.62
 * @date       10.04.2019
 */
class iFrameApiLib
{
    // salt for hashing signature and control signature of each request
	private $apiKey = '';
    // API calling URL on side of other party
	private $apiCallUrl = '';
	// whitelabel code
	private $whitelabel = '';
	
    // authorization token, - is used for non-authorized calls
    public $token = '-';
    
    // internal error variable set to 0 by default = no error
    public $error_code = 0;
    // internal error text variable empty by default
    private $error_text = '';
    
    // list of calls that do not require authorization token ( - is used as token in these calls)
    private $offline_calls = array( 'ping', 'get_payout_transactions', 
                                    'get_draw_results', 'draw_finished_notify',
                                    'transaction_ticket_payin', 'get_transaction_details', 
                                    'transaction_ticket_payout', 'transaction_bonus_payout');
    
    // list of calls that require repeated attempts for calling and 4rd party log in case of failure
    private $essential_calls = array(   'draw_finished_notify',
                                        'transaction_ticket_payout', 
                                        'transaction_bonus_payout',
                                        'transaction_ticket_refund',
                                        'transaction_winning_rollback');
    
    
    // string used to generate signature hash - for debugging purposes
    private $signature_string = '';
    
    // internal flag whether call POST parameter is used for sending request
    private $post_call_parameter = true; 
    
    /**
    * 
    * iFrameApiLib constructor
    *
    * @access   public
    * @param 	string 	$key  API key specific for each partner
    * @param 	string 	$callUrl  API call URL on side of other partner
    * 
    * @return
    */
    function __construct($key, $callUrl = '', $whitelabel = '')
    {
        $this->apiKey = $key;
        $this->apiCallUrl = $callUrl;
        $this->whitelabel = $whitelabel;
    }
    
    
    /**
    * 
    * Initializes api object before each call
    * 
    * @access   public
    * @param    string  $token  valid token for next call
    * 
    * @return true
    */
    public function init ($token = "")
    {
        // setting token
        if (trim($token) != "")
        {
            $this->token = $token;
        }
        
        // resets internal error variables
        $this->error_code = 0;
        $this->error_text = '';
        
        return true;
    }
    
    
    /**
    *
    * Updates settings of api object
    *
    * @access   public
    * @param    array  $settings  array of settings
    *
    * @return true
    */
    public function set ($settings = array())
    {
        if (!empty($settings))
        {
            foreach ($settings as $key => $value)
            {
                switch ($key)
                {
                    case 'post_call_parameter': 
                        $this->post_call_parameter = ($value);
                        // true - request is POST-ed as urlencoded value of call parameter;
                        // false - request is POST-ed as xml/text string
                        break;
                	default:
                	    break;
                }
            }
        }
        
        return true;
    }
    
    
    /**
    * 
    * Generating and sending/returning XML API call (with parameters) to other party (defined by iFrameApiLib->apiCallUrl)
    *
    * @access   public
    * @param    string 	$call  name of API call
    * @param    array 	$params  associative array of input parameters (transformed 1:1 to params subtag of XML)
    * @param    boolean $skipPostCall  if true, POST call is skipped and XML string is returned, otherwise POST is processed and usable output is returned 
    * @param    boolean  $debugMode  debugging flag
    * @param    array 	$param_types  associative array specifying special type of any of parameters defined in params array
    * @param    array 	$param_attributes  associative array specifying attributes of any of parameters defined in params array
    * 
    * @return   mixed response as array of call and returning parameters or XML string (based on noCallFlag parameter
    */
    public function apiCall($call, $params = array(), $skipPostCall = false, $debugMode = false, $param_types = array(), $param_attributes = array())
    {
        // create XML string of request
    	$xml_string = $this->createRequest($call, $params, false, $param_types, $param_attributes);
        
    	$logging = true;
    	
    	// return encoded string if POST call shall be skipped
    	if ($skipPostCall)
    	{
    	    return http_build_query(array('call' => $xml_string));
    	}
        
        // prepare parameters for POST request
        if ($this->post_call_parameter)
        {
            $body = http_build_query(array('call' => urlencode($xml_string)));
        }
        else
        {
            $body = $xml_string;
        }
        
        // send request via POST to apiCallUrl
    	$opts = array(
            'http' => array(
                'header'  => ($this->post_call_parameter ? "Content-type: application/x-www-form-urlencoded\r\n" : "Content-type: text/xml\r\n"),
                'method'  => 'POST',
                'content' => $body,
                'ignore_errors' => true,
            ),
        );

        $context  = stream_context_create($opts);
        
        $retry = false;

        // repeated attempts for connection in case of essential call
        if (1 && in_array($call, $this->essential_calls))
    	{
    	    // 3 attempts
    	    $retry = 5;
            while($retry--)
            {
                $res_string = @file_get_contents($this->apiCallUrl, false, $context);
                if ($res_string)
                    break;
                else
                    sleep(5);
                
                if (!$res_string)
                {
                    $log_entry = array("ESSENTIAL: ".$call);
                    $log_entry[] = json_encode($params);
                    $log_entry[] = $xml_string;
                    $log_entry[] = $res_string;
                    
                    /* LOGS of calls */
                    if ($this->whitelabel != '')
                    {
                        $_tmp = explode('_', $this->whitelabel);
                        registry('log')->store($_tmp[0], 'outgoing', $log_entry, v($_tmp[1], '') == 'dev' ? true : false);
                        /* LOGS of calls */
                    }
                }
            }
    	}
    	else
    	{
    	    $res_string = @file_get_contents($this->apiCallUrl, false, $context);
    	}
    	
        if (!$res_string)
        {
            $log_entry = array($call);
            $log_entry[] = json_encode($params);
            $log_entry[] = $xml_string;
            $log_entry[] = $res_string;
            
            if ($this->whitelabel != '')
            {
                /* LOGS of calls */
                $_tmp = explode('_', $this->whitelabel);
                registry('log')->store($_tmp[0], 'outgoing', $log_entry, v($_tmp[1], '') == 'dev' ? true : false);
                /* LOGS of calls */
            }
            
            return array('error_code' => 99, 'error_text' => 'API Connection not available');
        }
        
        $start_index = strpos($res_string, '<?xml version="1.0" encoding="UTF-8"?>');
        if ($start_index !== FALSE && $start_index > 0 )
        {
            $res_string = substr($res_string, $start_index);
        }
        
        $ret_received = $this->apiReceive($res_string, $debugMode);
        
        if (1 || $logging || $ret_received['error_code'] != 0)
        {
            $log_entry = array($call);
            if ($retry !== FALSE && $retry < 4)
            {
                $log_entry[] = "RETRY:".(5-$retry);
            }
            $log_entry[] = json_encode($params);
            #$log_entry[] = $xml_string;
            #$log_entry[] = $res_string;
            $_ret_received = $ret_received;
            if (isset($_ret_received['params']['debit_key']))
            {
                $_ret_received['params']['debit_key'] = 'XxXxX';
            }
            $log_entry[] = json_encode($_ret_received);
            
            if ($this->whitelabel != '')
            {
                /* LOGS of calls */
                $_tmp = explode('_', $this->whitelabel);
                registry('log')->store($_tmp[0], 'outgoing', $log_entry, v($_tmp[1], '') == 'dev' ? true : false);
                /* LOGS of calls */
            }
        }
        
        // returning output of returned XML string processing
        return $ret_received;
    }
    
    
    /**
    * 
    * Returns XML string as call's response
    *
    * @access   public
    * @param    string 	$call  name of API call
    * @param    array 	$params  associative array of input parameters (transformed 1:1 to params subtag of XML)
    * @param    array 	$param_types  associative array specifying special type of any of parameters defined in params array
    * @param    array 	$param_attributes  associative array specifying attributes of any of parameters defined in params array
    * 
    * @return   string 	response as XML string
    */
    public function apiRespond($call, $params = array(), $param_types = array(), $param_attributes = array())
    {
    	return $this->createRequest($call, $params, true, $param_types, $param_attributes);
    }
    
    
    /**
    * 
    * Validates and transforms XML string to usable array 
    *
    * @access   public
    * @param    string 	$response_string  XML string returned as response from other party
    * @param    boolean  $debugMode  debugging flag
    * 
    * @return   string 	response as XML string
    */
    public function apiReceive($response_string, $debugMode = false)
    {
        $error = '';
        if (trim($response_string) == '')
        {
            $error = 'empty string';
        }
        else if (strpos(strtolower($response_string), '<html>') !== FALSE)
        {
            $error = 'HTML string: '.$response_string;
        }
        else
        {
            // transform XML string to XML object
            $oXml = @simplexml_load_string($response_string);
            
            // extract call string
            $call = $oXml ? (string)$oXml->call : '';
            
            if (trim($call) == '')
            {
                $error = 'non-XML string: '.$response_string;
            }
        }
        
    	if ($error != '')
    	{
    	    return array('error_code' => 99, 'error_text' => 'API Connection not available - '.$error);
    	}
    	
    	// extract parameters from XML object to array
    	$params = array();
    	if ($oXml && $oXml->params->children())
        {
            foreach ($oXml->params->children() as $param => $value)
            {
                // if value cannot be converted to string (XML object)
                if (!(string) $value)
                {
                    // convert all params to string
                    $xml_param_string = $oXml->params->asXML();
                    if ($xml_param_string)
                    {
                        // strip out string for this paramater
                        $xml_param_string = substr($xml_param_string, 0, strpos($xml_param_string, '</'.$param.'>'));
                        $xml_param_string = substr($xml_param_string, strpos($xml_param_string, '<'.$param.'>')+strlen('<'.$param.'>'));
                        $params[(string)$param] = $xml_param_string;
                    }
                }
                else
                {
                    $params[(string)$param] = (string) $value;
                }
            }
        }
    	
        // output array filled with call and parameters array
        $res = array();
        $res['call'] = $call;
        $res['params'] = $params;
        
    	if ($this->validate($oXml, $response_string))
    	{
    	    // if XML is valid, response's error_code and text is returned
    		$res['error_code'] = (integer) $oXml->error_code;
            $res['error_text'] = (string) $oXml->error_text;
        }
        else
        {
    	    // if XML is invalid, internally set error_code and text are returned in output array
        	$res['error_code'] = $this->error_code;
        	$res['error_text'] = $this->error_text;
        }
        
        // output array contains: call, parameters, 
        return $res;
    }
    
    
    /**
    * 
    * Returns token or '-' according to used call
    *
    * @access   private
    * @param 	string 	$call  name of API call
    * 
    * @return 	string 	token
    */
    private function getTokenForCall ($call)
    {
        // if call is among offline calls (authorization token is not needed)
        // - is returned as token
    	if (in_array($call, $this->offline_calls))
    	{
    		return '-';
    	}
    	// otherwise return current token
    	return $this->token;
    }
    
    
    /**
    *
    * Creates XML string of request or response based on call name and array of parameters
    *
    * @access   private
    * @param 	string  $call  name of API call
    * @param    array   $params  associative array of input parameters (transformed 1:1 to params subtag of XML)
    * @param    boolean $response_flag  if true, response XML will be generated, by default request XML is generated 
    * @param    array 	$param_types  associative array specifying special type of any of parameters defined in params array
    * @param    array 	$param_attributes  associative array specifying attributes for any of parameters defined in params array
    *
    * @return 	string 	XML string of request/response
    */
    private function createRequest ($call, $params = array(), $response_flag = false, $param_types = array(), $param_attributes = array())
    {

        // creating root XML object
        $oXml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><root/>');
        // adding call subtag
        $oXml->addChild('call', $call);
        // adding token subtag
        $oXml->addChild('token', $this->getTokenForCall($call));
        // adding success, error_code and error_text subtags in case response is generated 
        if ($response_flag)
        {
            $oXml->addChild('success', $this->error_code > 0 ? 0 : 1);
            $oXml->addChild('error_code', $this->error_code);
            $oXml->addChild('error_text', $this->error_text);
        }
        // adding params subtag
        $params_tag = $oXml->addChild('params');
        // adding subtags for each of items from input parameters array
        if (!empty($params))
        {
            foreach ($params as $param => $value)
            {
                if (isset($param_types[$param]) && $param_types[$param] == 'xml')
                {
                    $xml_param_tag = $params_tag->addChild($param);
                    if (isset($param_attributes[$param]))
                        foreach ($param_attributes[$param] as $_attribute => $_attribute_value)
                            $xml_param_tag->addAttribute($_attribute, $_attribute_value);
                }
                else
                {
                    $param_tag = $params_tag->addChild($param, $value);
                    if (isset($param_attributes[$param]))
                        foreach ($param_attributes[$param] as $_attribute => $_attribute_value)
                            $param_tag->addAttribute($_attribute, $_attribute_value);
                }
            }
        }
        // adding time
        $oXml->addChild('time',time());
        // adding signature
        $oXml->addChild('signature', $this->getSignature($oXml));
        
        // returning XML string generated from XML object
        $output_xml = $oXml->asXML();
        
        // replacing xml parameters with their values (direct xml strings)
        if (!empty($param_types))
            foreach ($param_types as $param => $param_type)
                if ($param_type == 'xml')
                {
                    $output_xml = str_replace('<'.$param.'/>', '<'.$param.'>'.$params[$param].'</'.$param.'>', $output_xml);
                }
        return $output_xml;
    }
    
    
    /**
    *
    * Generates md5 signature string from XML object 
    *
    * @access   private
    * @param 	object  $oXml  SimpleXMLElement object
    * @param 	boolean  $debugMode  debugging flag
    *
    * @return 	string 	md5 signature of XML
    */
    private function getSignature ($oXml, $debugMode = false)
    {
        // creating string of parameters and values as basis for hashed signature
        // adding call name
    	$xml_string = 'call'.$oXml->call;
        // adding token
    	$xml_string .= 'token'.$oXml->token;
        // adding other parameters only if signature for response is generated 
    	if (isset($oXml->success))
        {
            // adding success
        	$xml_string .= 'success'.(string)$oXml->success;
            // adding error_code
        	$xml_string .= 'error_code'.(string)$oXml->error_code;
        	// adding error_text
        	$xml_string .= 'error_text'.(string)$oXml->error_text;
        }
        // adding parameters from params subtag
        if ($oXml->params)
            foreach ($oXml->params->children() as $param => $value)
            {
                // value is not used in case of nested XML structure
                if (substr($value,0,1) == '<' && substr($value,-1) == '>')
                {
                    $xml_string .= $param;
                }
                else
                {
                    $xml_string .= $param.$value;
                }
            }
        // adding time
        $xml_string .= 'time'.$oXml->time;
        
        // adding partner's key to encrypt
        $xml_string .= $this->apiKey;
        
        $this->signature_string = $xml_string;
        
        if ($debugMode)
            return $xml_string;
        
        return md5($xml_string);
    }
    
    
    /**
    *
    * Returns string that was used for signature 
    *
    * @access   public
    * @return 	string of parameters of XML request
    */
    public function getSignatureString ()
    {
        return $this->signature_string;
    }
    
    
    /**
    *
    * Validates XML
    *
    * @access   private
    * @param 	object  $oXml  SimpleXMLElement object
    * @param 	string  $debugObject  response string for debugging purposes
    *
    * @return 	boolean true if XML is valid, false otherwise (internal error variables will be set)
    */
    private function validate ($oXml, $debugObject = null)
    {
        // checking if signature and control signature match
    	if ((string) $oXml->signature !== $this->getSignature($oXml))
    	{
            $this->setError(1);
            $this->error_text .= " ".((string) $oXml->signature).' instead of '.$this->getSignature($oXml);
            return false;
    	}
    	
    	// checking if not more than 60 seconds passed since request was sent
    	if (time() - (int) $oXml->time > 6000)
    	{
    		$this->setError(2);
    		return false;
    	}
    	
    	// XML is valid
    	return true;
    }
    
    
    /**
    *
    * Sets internal error variables to success - alias for setError(0)
    *
    * @access   public
    * @return 	0
    */
    public function setSuccess ()
    {
        return $this->setError(0);
    }
    
    
    /**
    *
    * Resets internal error variables
    *
    * @access   public
    * @param    integer  $error_code  internal error code
    * @param    array    $replaces  array of replaces for error_text variable in form: old_string => new_string
    * @param    array    $forceText  forced error text that will be used instead of text defined by error_code
    *
    * @return 	integer new error_code
    */
    public function setError ($error_code = 0, $replaces = array(), $forceText = '')
    {
        // set internal error variable
    	$this->error_code = $error_code;
        
        // set internal error text variable based on error code
    	switch ($this->error_code)
        {
        	// if default value 0 is set, there is no error
        	case 0:  $this->error_text = ''; break;
            
        	case 1:  $this->error_text = 'XML is signed with invalid signature'; break;
            case 2:  $this->error_text = 'Request has expired'; break;
            case 5:  $this->error_text = 'Unknown call'; break;
            case 6:  $this->error_text = 'Invalid parameter %parameter% used'; break;
            case 7:  $this->error_text = 'Unknown value for parameter %parameter% used'; break;
            case 8:  $this->error_text = 'Unknown token %token% used'; break;
            case 9:  $this->error_text = 'Expired token used'; break;
            case 10: $this->error_text = 'System is not available'; break;
            case 11: $this->error_text = 'System under maintenance'; break;
            case 12: $this->error_text = 'System overloaded'; break;
            case 15: $this->error_text = 'Unauthorized player ID %player_id%'; break;
            case 16: $this->error_text = 'Unknown player ID %player_id%'; break;
            case 17: $this->error_text = 'Insufficient funds on player account'; break;
            case 18: $this->error_text = 'Unauthorized payment rejected'; break;
            
            case 99: $this->error_text = 'General error: %error_msg%'; break;
        }
        
        // if supplied, apply all replaces to error_text string (e.g. %parameter% or %token%) 
        if (!empty($replaces))
        {
        	$this->error_text = str_replace(array_keys($replaces), array_values($replaces), ($forceText != '' ? $forceText : $this->error_text));
        }
        else if ($forceText != '')
        {
            $this->error_text = $forceText;
        }
        
        return $this->error_code;
    }
    
}
?>
