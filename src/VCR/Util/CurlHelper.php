<?php

namespace VCR\Util;

use VCR\Request;
use VCR\Response;

/**
* cURL helper class.
*/
class CurlHelper
{
    /**
     * @var array List of cURL info constants.
     */
    private static $curlInfoList = array(
        //"certinfo"?
        CURLINFO_HTTP_CODE => 'http_code',
        CURLINFO_EFFECTIVE_URL => 'url',
        CURLINFO_FILETIME,
        CURLINFO_TOTAL_TIME => 'total_time',
        CURLINFO_NAMELOOKUP_TIME => 'namelookup_time',
        CURLINFO_CONNECT_TIME => 'connect_time',
        CURLINFO_PRETRANSFER_TIME => 'pretransfer_time',
        CURLINFO_STARTTRANSFER_TIME => 'starttransfer_time',
        CURLINFO_REDIRECT_COUNT => 'redirect_count',
        CURLINFO_REDIRECT_TIME => 'redirect_time',
        CURLINFO_SIZE_UPLOAD => 'size_upload',
        CURLINFO_SIZE_DOWNLOAD => 'size_download',
        CURLINFO_SPEED_DOWNLOAD => 'speed_download',
        CURLINFO_SPEED_UPLOAD => 'speed_upload',
        CURLINFO_HEADER_SIZE => 'header_size',
        CURLINFO_HEADER_OUT => 'request_header',
        CURLINFO_FILETIME => 'filetime',
        CURLINFO_REQUEST_SIZE => 'request_size',
        CURLINFO_SSL_VERIFYRESULT => 'ssl_verify_result',
        CURLINFO_CONTENT_LENGTH_DOWNLOAD => 'download_content_length',
        CURLINFO_CONTENT_LENGTH_UPLOAD => 'upload_content_length',
        CURLINFO_CONTENT_TYPE => 'content_type'
    );

    /**
     * Outputs a response depending on the set cURL option.
     *
     * The response body can be written to a file, returned, echoed or
     * passed to a custom function.
     *
     * The response header might be passed to a custom function.
     *
     * @param  Response $response    Response which contains the response body.
     * @param  array    $curlOptions cURL options which are not stored within the Response.
     * @param  resource $ch          cURL handle to add headers if needed.
     *
     * @return null|string
     */
    public static function handleOutput(Response $response, array $curlOptions, $ch)
    {
        if (isset($curlOptions[CURLOPT_HEADERFUNCTION])) {
            $headers = explode("\n", $response->getRawHeaders());
            foreach($headers as $header) {
                call_user_func($curlOptions[CURLOPT_HEADERFUNCTION], $ch, $header);
            }
        }

        $body = (string) $response->getBody(true);

        if (!empty($curlOptions[CURLOPT_HEADER])) {
            $body = $response->getRawHeaders() . $body;
        }

        if (isset($curlOptions[CURLOPT_WRITEFUNCTION])) {
            call_user_func($curlOptions[CURLOPT_WRITEFUNCTION], $ch, $body);
        } elseif (isset($curlOptions[CURLOPT_RETURNTRANSFER]) && $curlOptions[CURLOPT_RETURNTRANSFER] == true) {
            return $body;
        } elseif (isset($curlOptions[CURLOPT_FILE])) {
            $fp = $curlOptions[CURLOPT_FILE];
            fwrite($fp, $body);
            fflush($fp);
        } else {
            echo $body;
        }
    }

    /**
     * Returns a cURL option from a Response.
     *
     * @param  Response $response Response to get cURL option from.
     * @param  integer $option cURL option to get.
     *
     * @throws \BadMethodCallException
     * @return mixed Value of the cURL option.
     */
    public static function getCurlOptionFromResponse(Response $response, $option = 0)
    {
        switch ($option) {
            case 0: // 0 == array of all curl options
                $info = array();
                foreach (self::$curlInfoList as $option => $key) {
                   $info[$key] = $response->getInfo($option);
                }
                break;
            case CURLINFO_HTTP_CODE:
                $info = $response->getStatusCode();
                break;
            case CURLINFO_SIZE_DOWNLOAD:
                $info = $response->getHeader('Content-Length');
                break;
            default:
                $info = $response->getInfo($option);
                break;
        }

        if (!is_null($info)) {
            return $info;
        }

        $constants = get_defined_constants(true);
        $constantNames = array_flip($constants['curl']);
        throw new \BadMethodCallException("Not implemented: {$constantNames[$option]} ({$option}) ");
    }

    /**
     * Sets a cURL option on a Request.
     *
     * @param Request  $request Request to set cURL option to.
     * @param integer  $option  cURL option to set.
     * @param mixed    $value   Value of the cURL option.
     * @param resource $curlHandle cURL handle where this option is set on (optional).
     */
    public static function setCurlOptionOnRequest(Request $request, $option, $value, $curlHandle = null)
    {
        switch ($option) {
            case CURLOPT_URL:
                $request->setUrl($value);
                break;
            case CURLOPT_FOLLOWLOCATION:
                $request->getParams()->set('redirect.disable', !$value);
                break;
            case CURLOPT_MAXREDIRS:
                $request->getParams()->set('redirect.max', $value);
                break;
            case CURLOPT_CUSTOMREQUEST:
                $request->setMethod($value);
                break;
            case CURLOPT_POST:
                if ($value == true) {
                    $request->setMethod('POST');
                }
                break;
            case CURLOPT_POSTFIELDS:
                // todo: check for file @
                if (is_array($value)) {
                    foreach ($value as $name => $fieldValue) {
                        $request->setPostField($name, $fieldValue);
                    }

                    if (count($value) == 0) {
                        $request->removeHeader('Content-Type');
                    }
                } else {
                    $request->setBody($value);
                }
                break;
            case CURLOPT_HTTPHEADER:
                foreach ($value as $header) {
                    $headerParts = explode(': ', $header, 2);
                    if (isset($headerParts[1])) {
                        $request->setHeader($headerParts[0], $headerParts[1]);
                    }
                }
                break;
            case CURLOPT_HEADER:
            case CURLOPT_WRITEFUNCTION:
            case CURLOPT_HEADERFUNCTION:
                // Ignore writer and header functions.
                // These options are stored and will be handled later in handleOutput().
                break;
            case CURLOPT_FILE:
                $request->setBody($value);
                break;
            case CURLOPT_READFUNCTION:
                // Guzzle provides a callback to let curl read the body string.
                // To get the body, this callback is called manually.
                if (is_null($value)) {
                    $request->getCurlOptions()->set($option, $value);
                    break;
                }
                $bodySize = $request->getCurlOptions()->get(CURLOPT_INFILESIZE);
                Assertion::notEmpty($bodySize, "To set a CURLOPT_READFUNCTION, CURLOPT_INFILESIZE must be set.");
                $body = call_user_func_array($value, array($curlHandle, fopen('php://memory', 'r'), $bodySize));
                $request->setBody($body);
                break;
            default:
                $request->getCurlOptions()->set($option, $value);
                break;
        }
    }
}
