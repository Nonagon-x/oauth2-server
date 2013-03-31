<?php
/**
 * OAuth 2.0 Authorization Server
 *
 * @package     lncd/oauth2
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) 2013 University of Lincoln
 * @license     http://mit-license.org/
 * @link        http://github.com/lncd/oauth2
 */

namespace OAuth2;

use OAuth2\Util\Request;
use OAuth2\Util\SecureKey;
use OAuth2\Storage\SessionInterface;
use OAuth2\Storage\ClientInterface;
use OAuth2\Storage\ScopeInterface;
use OAuth2\Grant\GrantTypeInterface;

/**
 * OAuth 2.0 authorization server class
 */
class AuthServer
{
    /**
     * The delimeter between scopes specified in the scope query string parameter
     *
     * The OAuth 2 specification states it should be a space but that is stupid
     * and everyone excepted Google use a comma instead.
     *
     * @var string
     */
    protected $scopeDelimeter = ',';

    /**
     * The TTL (time to live) of an access token in seconds (default: 3600)
     * @var integer
     */
    protected $expiresIn = 3600;

    /**
     * The registered grant response types
     * @var array
     */
    protected $responseTypes = array();

    /**
     * The client, scope and session storage classes
     * @var array
     */
    protected $storages = array();

    /**
     * The registered grant types
     * @var array
     */
    protected $grantTypes = array();

    /**
     * Require the "scope" parameter to be in checkAuthoriseParams()
     * @var boolean
     */
    protected $requireScopeParam = true;

    /**
     * Default scope to be used if none is provided and requireScopeParam is false
     * @var string
     */
    protected $defaultScope = null;

    /**
     * Require the "state" parameter to be in checkAuthoriseParams()
     * @var boolean
     */
    protected $requireStateParam = false;

    /**
     * The request object
     * @var Util\RequestInterface
     */
    protected $request = null;

    /**
     * Exception error codes
     * @var array
     */
    protected static $exceptionCodes = array(
        0   =>  'invalid_request',
        1   =>  'unauthorized_client',
        2   =>  'access_denied',
        3   =>  'unsupported_response_type',
        4   =>  'invalid_scope',
        5   =>  'server_error',
        6   =>  'temporarily_unavailable',
        7   =>  'unsupported_grant_type',
        8   =>  'invalid_client',
        9   =>  'invalid_grant'
    );

    /**
     * Exception error messages
     * @var array
     */
    protected static $exceptionMessages = array(
        'invalid_request'           =>  'The request is missing a required parameter, includes an invalid parameter value, includes a parameter more than once, or is otherwise malformed. Check the "%s" parameter.',
        'unauthorized_client'       =>  'The client is not authorized to request an access token using this method.',
        'access_denied'             =>  'The resource owner or authorization server denied the request.',
        'unsupported_response_type' =>  'The authorization server does not support obtaining an access token using this method.',
        'invalid_scope'             =>  'The requested scope is invalid, unknown, or malformed. Check the "%s" scope.',
        'server_error'              =>  'The authorization server encountered an unexpected condition which prevented it from fulfilling the request.',
        'temporarily_unavailable'   =>  'The authorization server is currently unable to handle the request due to a temporary overloading or maintenance of the server.',
        'unsupported_grant_type'    =>  'The authorization grant type "%s" is not supported by the authorization server',
        'invalid_client'            =>  'Client authentication failed',
        'invalid_grant'             =>  'The provided authorization grant is invalid, expired, revoked, does not match the redirection URI used in the authorization request, or was issued to another client. Check the "%s" parameter.',
        'invalid_credentials'       =>  'The user credentials were incorrect.',
        'invalid_refresh'           =>  'The refresh token is invalid.',
    );

    /**
     * Exception error HTTP status codes
     * @var array
     *
     * RFC 6749, section 4.1.2.1.:
     * No 503 status code for 'temporarily_unavailable', because
     * "a 503 Service Unavailable HTTP status code cannot be
     * returned to the client via an HTTP redirect"
     */
    protected static $exceptionHttpStatusCodes = array(
        'invalid_request'           =>  400,
        'unauthorized_client'       =>  400,
        'access_denied'             =>  401,
        'unsupported_response_type' =>  400,
        'invalid_scope'             =>  400,
        'server_error'              =>  500,
        'temporarily_unavailable'   =>  400,
        'unsupported_grant_type'    =>  501,
        'invalid_client'            =>  401,
        'invalid_grant'             =>  400,
        'invalid_credentials'       =>  400,
        'invalid_refresh'           =>  400,
    );

    /**
     * Get all headers that have to be send with the error response
     *
     * @param  string $error The error message key
     * @return array         Array with header values
     */
    public static function getExceptionHttpHeaders($error)
    {
        $headers = array();
        switch (self::$exceptionHttpStatusCodes[$error]) {
            case 401:
                $headers[] = 'HTTP/1.1 401 Unauthorized';
                break;
            case 500:
                $headers[] = 'HTTP/1.1 500 Internal Server Error';
                break;
            case 501:
                $headers[] = 'HTTP/1.1 501 Not Implemented';
                break;
            case 400:
            default:
                $headers[] = 'HTTP/1.1 400 Bad Request';
        }

        // Add "WWW-Authenticate" header
        //
        // RFC 6749, section 5.2.:
        // "If the client attempted to authenticate via the 'Authorization'
        // request header field, the authorization server MUST
        // respond with an HTTP 401 (Unauthorized) status code and
        // include the "WWW-Authenticate" response header field
        // matching the authentication scheme used by the client.
        // @codeCoverageIgnoreStart
        if ($error === 'invalid_client') {
            $authScheme = null;
            $request = new Request();
            if ($request->server('PHP_AUTH_USER') !== null) {
                $authScheme = 'Basic';
            } else {
                $authHeader = $request->header('Authorization');
                if ($authHeader !== null) {
                    if (strpos($authHeader, 'Bearer') === 0) {
                        $authScheme = 'Bearer';
                    } elseif (strpos($authHeader, 'Basic') === 0) {
                        $authScheme = 'Basic';
                    }
                }
            }
            if ($authScheme !== null) {
                $headers[] = 'WWW-Authenticate: '.$authScheme.' realm=""';
            }
        }
        // @codeCoverageIgnoreEnd

        return $headers;
    }

    /**
     * Get an exception message
     *
     * @param  string $error The error message key
     * @return string        The error message
     */
    public static function getExceptionMessage($error = '')
    {
        return self::$exceptionMessages[$error];
    }

    /**
     * Get an exception code
     *
     * @param  integer $code The exception code
     * @return string        The exception code type
     */
    public static function getExceptionType($code = 0)
    {
        return self::$exceptionCodes[$code];
    }

    /**
     * Create a new OAuth2 authorization server
     *
     * @param ClientInterface  $client  A class which inherits from Storage/ClientInterface
     * @param SessionInterface $session A class which inherits from Storage/SessionInterface
     * @param ScopeInterface   $scope   A class which inherits from Storage/ScopeInterface
     */
    public function __construct(ClientInterface $client, SessionInterface $session, ScopeInterface $scope)
    {
        $this->storages = array(
            'client'    =>  $client,
            'session'   =>  $session,
            'scope' =>  $scope
        );
    }

    /**
     * Enable support for a grant
     * @param GrantTypeInterface $grantType  A grant class which conforms to Interface/GrantTypeInterface
     * @param null|string        $identifier An identifier for the grant (autodetected if not passed)
     */
    public function addGrantType(GrantTypeInterface $grantType, $identifier = null)
    {
        if (is_null($identifier)) {
            $identifier = $grantType->getIdentifier();
        }
        $this->grantTypes[$identifier] = $grantType;

        if ( ! is_null($grantType->getResponseType())) {
            $this->responseTypes[] = $grantType->getResponseType();
        }
    }

    /**
     * Check if a grant type has been enabled
     * @param  string  $identifier The grant type identifier
     * @return boolean             Returns "true" if enabled, "false" if not
     */
    public function hasGrantType($identifier)
    {
        return (array_key_exists($identifier, $this->grantTypes));
    }

    public function getResponseTypes()
    {
        die(var_dump($this->responseTypes));
        return $this->responseTypes;
    }

    /**
     * Require the "scope" paremter in checkAuthoriseParams()
     * @param  boolean $require
     * @return void
     */
    public function requireScopeParam($require = true)
    {
        $this->requireScopeParam = $require;
    }

    /**
     * Is the scope parameter required?
     * @return bool
     */
    public function scopeParamRequired()
    {
        return $this->requireScopeParam;
    }

    /**
     * Default scope to be used if none is provided and requireScopeParam is false
     * @var string
     */
    public function setDefaultScope($default = null)
    {
        $this->defaultScope = $default;
    }

    /**
     * Default scope to be used if none is provided and requireScopeParam is false
     * @return string|null
     */
    public function getDefaultScope()
    {
        return $this->defaultScope;
    }

    /**
     * Require the "state" paremter in checkAuthoriseParams()
     * @param  boolean $require
     * @return void
     */
    public function requireStateParam($require = false)
    {
        $this->requireStateParam = $require;
    }

    /**
     * Get the scope delimeter
     *
     * @return string The scope delimiter (default: ",")
     */
    public function getScopeDelimeter()
    {
        return $this->scopeDelimeter;
    }

    /**
     * Set the scope delimiter
     *
     * @param string $scopeDelimeter
     */
    public function setScopeDelimeter($scopeDelimeter)
    {
        $this->scopeDelimeter = $scopeDelimeter;
    }

    /**
     * Get the TTL for an access token
     * @return int The TTL
     */
    public function getExpiresIn()
    {
        return $this->expiresIn;
    }

    /**
     * Set the TTL for an access token
     * @param int $expiresIn The new TTL
     */
    public function setExpiresIn($expiresIn)
    {
        $this->expiresIn = $expiresIn;
    }

    /**
     * Sets the Request Object
     *
     * @param Util\RequestInterface The Request Object
     */
    public function setRequest(Util\RequestInterface $request)
    {
        $this->request = $request;
    }

    /**
     * Gets the Request object.  It will create one from the globals if one is not set.
     *
     * @return Util\RequestInterface
     */
    public function getRequest()
    {
        if ($this->request === null) {
            // @codeCoverageIgnoreStart
            $this->request = Request::buildFromGlobals();

        }
        // @codeCoverageIgnoreEnd

        return $this->request;
    }

    /**
     * Return a storage class
     * @param  string $obj The class required
     * @return Storage\ClientInterface|Storage\ScopeInterface|Storage\SessionInterface
     */
    public function getStorage($obj)
    {
        return $this->storages[$obj];
    }

    /**
     * Issue an access token
     *
     * @param  array $inputParams Optional array of parsed $_POST keys
     * @return array             Authorise request parameters
     */
    public function issueAccessToken($inputParams = array())
    {
        $grantType = $this->getParam('grant_type', 'post', $inputParams);

        if (is_null($grantType)) {
            throw new Exception\ClientException(sprintf(self::$exceptionMessages['invalid_request'], 'grant_type'), 0);
        }

        // Ensure grant type is one that is recognised and is enabled
        if ( ! in_array($grantType, array_keys($this->grantTypes))) {
            throw new Exception\ClientException(sprintf(self::$exceptionMessages['unsupported_grant_type'], $grantType), 7);
        }

        // Complete the flow
        return $this->getGrantType($grantType)->completeFlow($inputParams);
    }

    /**
     * Return a grant type class
     * @param  string $grantType The grant type identifer
     * @return class
     */
    public function getGrantType($grantType)
    {
        return $this->grantTypes[$grantType];
    }

    /**
     * Get a parameter from passed input parameters or the Request class
     * @param  string|array $param Required parameter
     * @param  string $method      Get/put/post/delete
     * @param  array  $inputParams Passed input parameters
     * @return mixed               'Null' if parameter is missing
     */
    public function getParam($param = '', $method = 'get', $inputParams = array(), $default = null)
    {
        if (is_string($param)) {
            if (isset($inputParams[$param])) {
                return $inputParams[$param];
            } elseif ($param === 'client_id' && ! is_null($client_id = $this->getRequest()->server('PHP_AUTH_USER'))) {
                return $client_id;
            } elseif ($param === 'client_secret' && ! is_null($client_secret = $this->getRequest()->server('PHP_AUTH_PW'))) {
                return $client_secret;
            } else {
                return $this->getRequest()->{$method}($param, $default);
            }
        } else {
            $response = array();
            foreach ($param as $p) {
                $response[$p] = $this->getParam($p, $method, $inputParams);
            }
            return $response;
        }
    }

}
