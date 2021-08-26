<?php

namespace TochkaApi;

use TochkaApi\Auth\AccessToken;
use TochkaApi\Exceptions\ModelNotFoundException;
use TochkaApi\Exceptions\TochkaApiClientException;
use TochkaApi\HttpAdapters\HttpClientInterface;
use TochkaApi\Models\BaseModel;
use TochkaApi\Utilities\TochkaPermissionsJWT;


/**
 * @method  \TochkaApi\Models\Balance balance($id = null)
 * @method  \TochkaApi\Models\Statement statement($id = null)
 * @method  \TochkaApi\Models\Account account($id = null)
 * @method  \TochkaApi\Models\Card card($id = null, $customerCode = null)
 * @method  \TochkaApi\Models\Customer customer($id = null)
 * @method  \TochkaApi\Models\Payment payment($id = null)
 * @method  \TochkaApi\Models\Consent consent($id = null)
 * @method  \TochkaApi\Models\Custom custom($id = null)
 */

final class Client
{
    /**
     * @var string SDK_VERSION
     */
    const SDK_VERSION = "1.0";

    /**
     * @var string HOST
     */
    const HOST = "https://enter.tochka.com";

    /**
     * @var HttpClientInterface $adapter
     */
    protected $adapter;

    /**
     * @var string $client_id
     */
    protected $client_id;

    /**
     * @var string $client_secret
     */
    protected $client_secret;

    /**
     * @var string $redirect_uri
     */
    protected $redirect_uri;

    /**
     * @var AccessToken
     */
    protected $access_token;

    /**
     * @var string $scopes
     */
    protected $scopes = "accounts customers statements cards sbp payments balances";

    /**
     * @var array $permissions
     */
    protected $permissions = [
        "ReadAccountsBasic",
        "ReadAccountsDetail",
        "ReadBalances",
        "ReadStatements",
        "ReadTransactionsBasic",
        "ReadTransactionsCredits",
        "ReadTransactionsDebits",
        "ReadTransactionsDetail",
        "ReadCustomerData",
        "ReadSBPData",
        "EditSBPData",
        "ReadCardData",
        "EditCardData",
        "EditCardState",
        "ReadCardLimits",
        "EditCardLimits",
        "CreatePaymentForSign",
        "CreatePaymentOrder"
    ];

    /**
     * TochkaApi constructor.
     * @param string $client_id
     * @param string $client_secret
     * @param string $redirect_uri
     * @param HttpClientInterface $adapter
     */
    public function __construct($client_id, $client_secret, $redirect_uri, HttpClientInterface $adapter)
    {
        $this->setClientId($client_id);
        $this->setClientSecret($client_secret);
        $this->setRedirectUri($redirect_uri);

        $this->setAdapter($adapter);
    }

    /**
     * @param string|AccessToken $access_token
     * @param int $expires_in
     */
    public function setAccessToken($access_token, $expires_in = 0, $refresh_token = "")
    {
        $this->access_token = $access_token instanceof AccessToken ? $access_token : new AccessToken($access_token, $expires_in, $refresh_token);
    }

    /**
     * @return AccessToken
     */
    public function getAccessToken()
    {
        return $this->access_token instanceof AccessToken ? $this->access_token : new AccessToken("");
    }

    /**
     * @return string
     */
    public function getRedirectUri()
    {
        return $this->redirect_uri;
    }

    /**
     * @param string $redirect_uri
     */
    public function setRedirectUri($redirect_uri)
    {
        $this->redirect_uri = $redirect_uri;
    }

    /**
     * @return string
     */
    public function getScopes()
    {
        return $this->scopes;
    }

    /**
     * @param string $scopes
     */
    public function setScopes($scopes)
    {
        $this->scopes = $scopes;
    }

    /**
     * @return array
     */
    public function getPermissions()
    {
        return $this->permissions;
    }

    /**
     * @param array $permissions
     */
    public function setPermissions($permissions)
    {
        $this->permissions = $permissions;
    }


    /**
     * @return string
     */
    public function getTokenUrl()
    {
        return static::HOST . "/connect/token";
    }

    /**
     * @param string $jwt
     * @return string
     */
    public function generateAuthorizeUrl($consent_id,$scope,$state)
    {
        $data = [
            "client_id" => $this->getClientId(),
            "redirect_uri" => $this->getRedirectUri(),
            "consent_id" => $consent_id,
            "scope" => $scope,
            "response_type" => "code",
            "state" => $state,
        ];

        return static::HOST . "/connect/authorize?" . http_build_query($data);
    }

    /**
     * @return HttpClientInterface
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * @param HttpClientInterface $adapter
     */
    protected function setAdapter(HttpClientInterface $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @return string
     */
    protected function getClientId()
    {
        return $this->client_id;
    }

    /**
     * @param string $client_id
     */
    protected function setClientId($client_id)
    {
        $this->client_id = $client_id;
    }

    /**
     * @return string
     */
    protected function getClientSecret()
    {
        return $this->client_secret;
    }

    /**
     * @param mixed $client_secret
     */
    protected function setClientSecret($client_secret)
    {
        $this->client_secret = $client_secret;
    }

    /**
     *
     * @param string $state
     * @return string
     * @throws TochkaApiClientException
     */
    public function authorize($state=null)
    {
        $data = [
            "client_id" => $this->getClientId(),
            "client_secret" => $this->getClientSecret(),
            "grant_type" => "client_credentials",
            "scope" => $this->getScopes(),
            "state" => $state,
        ];

        try {
            $token = $this->getTokenRequest($data);
            $response = $this->getPermissionsRequest($token);
        } catch (\Exception $e) {
            throw new TochkaApiClientException($e->getMessage());
        }

        return $this->generateAuthorizeUrl($response['Data']['consentId'],$this->getScopes(),$state);
    }

    /**
     * @param $code
     * @return AccessToken
     * @throws TochkaApiClientException
     */
    public function token($code)
    {
        $data = [
            "client_id" => $this->getClientId(),
            "client_secret" => $this->getClientSecret(),
            "grant_type" => "authorization_code",
            "scope" => $this->getScopes(),
            "code" => $code,
            "redirect_uri" => $this->getRedirectUri(),
        ];

        return $this->getTokenRequest($data);
    }

    /**
     * @param $refreshToken
     * @return AccessToken
     * @throws TochkaApiClientException
     */
    public function refreshToken($refreshToken)
    {
        $data = [
            "client_id" => $this->getClientId(),
            "client_secret" => $this->getClientSecret(),
            "grant_type" => "refresh_token",
            "refresh_token" => $refreshToken,
        ];

        return $this->getTokenRequest($data);
    }

    /**
     * @param $data
     * @return AccessToken
     * @throws TochkaApiClientException
     */
    protected function getTokenRequest($data)
    {
        $headers = [
            "Content-Type: application/x-www-form-urlencoded",
        ];

        $response = $this->getAdapter()->post($this->getTokenUrl(), $data, $headers)->getArray();

        if(!isset($response["access_token"])) {
            throw new TochkaApiClientException("Access token error");
        }

        $token = new AccessToken($response["access_token"], $response["expires_in"], (isset($response["refresh_token"]) ? $response["refresh_token"] : ""), $response["token_type"]);

        return $token;
    }

    /**
     * @param AccessToken $token
     * @return array
     * @throws TochkaApiClientException
     */
    protected function getPermissionsRequest(AccessToken $token)
    {
        $data = [
            "Data" => [
                "permissions" => $this->getPermissions(),
            ]
        ];

        $response = (new Api($token, $this->getAdapter()))->permissionsRequest($data);

        if(!isset($response["Data"]["consentId"])) {
            throw new TochkaApiClientException("Create consents error");
        }

        return $response;
    }

    /**
     * @param $name
     * @param $arguments
     * @return BaseModel
     * @throws ModelNotFoundException
     */
    public function __call($name, $arguments)
    {
        $className = '\\TochkaApi\\Models\\' . ucfirst($name);

        if (!class_exists($className)) {
            throw new ModelNotFoundException($name);
        }

        $model = new $className(new Api($this->getAccessToken(), $this->getAdapter()), $arguments[0] ?? null, $arguments[1] ?? null);

        return $model;
    }
}