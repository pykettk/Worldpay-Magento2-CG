<?php
/**
 * @copyright 2017 Sapient
 */
namespace Sapient\Worldpay\Model;

use Exception;

/**
 * Used for processing the Request
 */
class Request
{
    /**
     * @var \Magento\Framework\HTTP\Client\Curl
     */
    protected $_request;

    /**
     * @var \Sapient\Worldpay\Logger\WorldpayLogger
     */
    protected $_logger;
    /**
     * @var CURL_POST
     */
    public const CURL_POST = true;
    /**
     * @var CURL_RETURNTRANSFER
     */
    public const CURL_RETURNTRANSFER = true;
    /**
     * @var CURL_NOPROGRESS
     */
    public const CURL_NOPROGRESS = false;
    /**
     * @var CURL_TIMEOUT
     */
    public const CURL_TIMEOUT = 300;
    /**
     * @var CURL_VERBOSE
     */
    public const CURL_VERBOSE = true;
    /**
     * @var SUCCESS
     */
    public const SUCCESS = 200;

    /**
     * Constructor
     *
     * @param \Sapient\Worldpay\Logger\WorldpayLogger $wplogger
     * @param \Sapient\Worldpay\Helper\Data $helper
     * @param \Magento\Framework\HTTP\Client\Curl $curl
     */
    public function __construct(
        \Sapient\Worldpay\Logger\WorldpayLogger $wplogger,
        \Sapient\Worldpay\Helper\Data $helper,
        \Magento\Framework\HTTP\Client\Curl $curl
    ) {
        $this->_wplogger = $wplogger;
        $this->helper = $helper;
        $this->curl = $curl;
    }
    
     /**
      * Process the request
      *
      * @param string $quote
      * @param string $username
      * @param string $password
      * @return SimpleXMLElement body
      * @throws Exception
      */
    public function sendRequest($quote, $username, $password)
    {
        $request = $this->_getRequest();
        $logger = $this->_wplogger;
        $url = $this->_getUrl();

        $logger->info('Setting destination URL: '. $url);
        $logger->info('Initialising request');
        $request->setOption(CURLOPT_POST, self::CURL_POST);
        $request->setOption(CURLOPT_RETURNTRANSFER, self::CURL_RETURNTRANSFER);
        $request->setOption(CURLOPT_NOPROGRESS, self::CURL_NOPROGRESS);
        //$request->setOption(CURLOPT_TIMEOUT, self::CURL_TIMEOUT);
        $request->setOption(CURLOPT_VERBOSE, self::CURL_VERBOSE);
        /*SSL verification false*/
        $request->setOption(CURLOPT_SSL_VERIFYHOST, false);
        $request->setOption(CURLOPT_SSL_VERIFYPEER, false);
        //$request->setOption(CURLOPT_POSTFIELDS, $quote->saveXML());
        $request->setOption(CURLOPT_USERPWD, $username.':'.$password);
        // Cookie Set to 2nd 3DS request only.
        $cookie = $this->helper->getWorldpayAuthCookie();
        if ($this->helper->isThreeDSRequest() && $cookie != "") { // Check is 3DS request
            $cookie = $cookie.';SameSite=None; Secure';
            $request->setOption(CURLOPT_COOKIE, $cookie);
        }
        if ($this->helper->isDynamic3DS2Enabled() && $cookie != "") { // Check is 3DS2 request
            $request->setOption(CURLOPT_COOKIE, $cookie);
        }
        //$request->setOption(CURLOPT_HEADER, true);
        //$request->setOption(CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
        $request->addCookie(CURLOPT_COOKIE, $cookie);
        $request->setTimeout(self::CURL_TIMEOUT);
        $request->setHeaders([
            'Content-Type'=> 'text/xml',
            'Expect'=>''
        ]);
        $logger->info('Sending XML as: ' . $this->_getObfuscatedXmlLog($quote));

       //$request->setOption(CURLINFO_HEADER_OUT, true);

        //$result = $request->execute();
        $request->setOption(CURLOPT_HEADER, 1);
        $request->setOption(CURLINFO_HEADER_OUT, 1);
        $request->post($url, $quote->saveXML());

        $result = $request->getBody();

        // logging Headder for 3DS request to check Cookie.
        if ($this->helper->isThreeDSRequest()) {
            //$information = $request->getInfo(CURLINFO_HEADER_OUT);
            $information = $request->getHeaders();
            $logger->info("**REQUEST HEADER START**");
            $logger->info(json_encode($information));
            $logger->info("**REQUEST HEADER ENDS**");
        }

        if (!$result || ($request->getStatus() != self::SUCCESS)) {
            $logger->info('Request could not be sent.');
            $logger->info($result);
            $logger->info('########### END OF REQUEST - FAILURE WHILST TRYING TO SEND REQUEST ###########');
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Worldpay api service not available')
            );
        }
        $logger->info('Request successfully sent');
        $logger->info($result);

        // extract headers
        $bits = explode("\r\n\r\n", $result);
        $body = array_pop($bits);
        $headers = implode("\r\n\r\n", $bits);

        // Extracting Cookie from Response Header.
        if (preg_match("/set-cookie: (.+?)([\r\n]|$)/", $headers, $match)) {
            // Keep a hold of the cookie returned incase we need to send a
            // second order message after 3dSecure check
                $logger->info('Cookie Get: ' . $match[1]);
                $this->helper->setWorldpayAuthCookie($match[1]);
        }
        // if we get Set-Cookie instead of set-cookie
        if (preg_match("/Set-Cookie: (.+?)([\r\n]|$)/", $headers, $match)) {
            // Keep a hold of the cookie returned incase we need to send a
            // second order message after 3dSecure check
            $logger->info('Cookie Get: ' . $match[1]);
            $this->helper->setWorldpayAuthCookie($match[1]);
        }
        return $body;
    }

    /**
     * Censors sensitive data before outputting to the log file
     *
     * @param string $quote
     */
    protected function _getObfuscatedXmlLog($quote)
    {
        $elems = ['cardNumber', 'cvc'];
        $_xml  = clone($quote);

        foreach ($elems as $_e) {
            foreach ($_xml->getElementsByTagName($_e) as $_node) {
                $_node->nodeValue = str_repeat('X', strlen($_node->nodeValue));
            }
        }

        return $_xml->saveXML();
    }

    /**
     * Get URL of merchant site based on environment mode
     */
    private function _getUrl()
    {
        if ($this->helper->getEnvironmentMode()=='Live Mode') {
            return $this->helper->getLiveUrl();
        }
        return $this->helper->getTestUrl();
    }

    /**
     * Get request
     *
     * @return object
     */
    private function _getRequest()
    {
        if ($this->_request === null) {
            $this->_request = $this->curl;
        }

        return $this->_request;
    }
}
