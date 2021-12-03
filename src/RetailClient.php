<?php

namespace LightspeedHQ\Retail;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

/**
* RetailClient is an extension of the Guzzle HTTP client for Lightspeed Retail.
*
* Middleware is added to the client to handle the access token refresh, rate
* limiting and retry in case of a temporary connection error.
*/
class RetailClient extends Client
{
    const HOST = "https://api.lightspeedapp.com/";

    public $account_id;
    private $refresh_token;
    private $client_id;
    private $client_secret;
    private $access_token;
    public $last_req_time;
    private $bucket = [
        'level' => 0,
        'size' => 60,
        'available' => 60,
        'drip' => 1
    ];

    /**
     * The constructor takes the account ID and a refresh token and OAuth
     * client credentials. New access tokens will be requested as needed.
     *
     * @param string $account_id The Lightspeed Retail account ID
     * @param string $refresh_token A refresh token for that account.
     * @param string $client_id The OAuth client ID.
     * @param string $client_secret The OAuth client secret.
     */
    public function __construct($account_id, $refresh_token, $client_id, $client_secret)
    {
        $this->account_id = $account_id;
        $this->refresh_token = $refresh_token;
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;

        parent::__construct([
            'base_uri' => self::HOST . 'API/Account/' . $account_id . "/",
            'headers' => [
                'Accept' => 'application/json'
            ],
            'handler' => $this->createHandlerStack()
        ]);
    }

    /**
    * Builds the HandlerStack for use by the Guzzle Client.
    *
    * It uses the default stack, plus:
    *
    * - A retry Handler defined by retryDecider() and retryDelay().
    * - A request mapper that adds the current access token to each request.
    * - A request mapper to run checkBucket() before each request.
    * - A response mapper to get the new bucket level after each response.
    */
    protected function createHandlerStack()
    {
        $stack = HandlerStack::create(new CurlHandler());
        // RetryMiddleware handles errors (including token refresh)
        $stack->push(Middleware::retry($this->retryDecider(), $this->retryDelay()));
        // Add Authorization header with current access token
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            return $request->withHeader('Authorization', 'Bearer ' . $this->access_token);
        }));
        // Check bucket before sending
        $stack->push(Middleware::mapRequest($this->checkBucket()));
        // After response, get bucket state
        $stack->push(Middleware::mapResponse($this->getBucket()));
        return $stack;
    }

    /**
    * A middleware method to decide when to retry requests.
    *
    * This will run even for sucessful requests. We want to retry up to 5 times
    * on connection errors (which can sometimes come back as 502, 503 or 504
    * HTTP errors) and 429 Too Many Requests errors.
    * For 401 Unautorized responses, we refresh the access token and retry once.
    *
    * @return callable
    */
    protected function retryDecider()
    {
        return function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
            RequestException $exception = null
        ) {
            // Limit the number of retries to 5
            if ($retries >= 5) {
                return false;
            }

            $should_retry = false;
            $refresh = false;
            $log_message = null;

            // Retry connection exceptions
            if ($exception instanceof ConnectException) {
                $should_retry = true;
                $log_message = 'Connection Error: ' . $exception->getMessage();
            }

            if ($response) {
                $code = $response->getStatusCode();
                if ($code >= 400) {
                    $response_json = json_encode(json_decode($response->getBody()), JSON_PRETTY_PRINT);
                    $log_message = 'HTTP Error ' . $code . ":\n" . $response_json;
                    // 429, 502, 503, 504: try again
                    if (in_array($code, [429, 502, 503, 504])) {
                        $should_retry = true;
                    }
                    // 401: Refresh access token and try again once
                    elseif ($code == 401 && $retries <= 1) {
                        $refresh = true;
                        $should_retry = true;
                    }
                }
            }
            if ($log_message) {
                error_log($log_message, 0);
            }
            if ($refresh) {
                error_log("Refreshing Access Token…");
                $this->refreshToken();
            }
            if (($should_retry) && ($retries > 0)) {
                    error_log('Retry ' . $retries . '…', 0);
            }
            return $should_retry;
        };
    }

    /**
    * A middleware method to decide how long to wait before retrying.
    *
    * For 401 and 429 errors, we don't wait.
    * For connection errors we wait 1 second before the first retry, 2 seconds
    * before the second, and so on.
    *
    * @return callable
    */
    protected function retryDelay()
    {
        return function ($numberOfRetries, ResponseInterface $response = null) {
            // No delay for 401 or 429 responses
            return (($response) && in_array($response->getStatusCode(), [401, 429])) ? 0 : 1000 * $numberOfRetries;
        };
    }

    /**
    * A method to refresh the access token.
    */
    protected function refreshToken()
    {
        $response = $this->post('https://cloud.lightspeedapp.com/oauth/access_token.php', [
            'multipart' => [
                ['name' => 'client_id', 'contents' => $this->client_id],
                ['name' => 'client_secret', 'contents' => $this->client_secret],
                ['name' => 'refresh_token', 'contents' => $this->refresh_token],
                ['name' => 'grant_type', 'contents' => 'refresh_token'],
            ]
        ]);
        if ($token = json_decode($response->getBody(), true)['access_token']) {
            $this->access_token = $token;
            error_log('New Access Token: '. substr($token, 0, 8) . '************', 0);
        }
    }

    /**
    * A middleware method to check the bucket state before each request.
    *
    * GET requests cost 1 point; PUT, POST and DELETE cost 10. If this request
    * will push us over the limit, we wait until there's enough room before
    * sending it.
    * Takes into account the time passed since the last request.
    *
    * @return callable
    */
    protected function checkBucket()
    {
        return function (RequestInterface $request) {
            $overflow = (strtolower($request->getMethod()) == 'get' ? 1 : 10) - $this->bucket['available'];
            if ($overflow > 0) {
                if (($sleep_time = $overflow / $this->bucket['drip']) > (time() - $this->last_req_time)) {
                    $sleep_microseconds = ceil($sleep_time * 1000000);
                    error_log('Notice: Rate limit reached, sleeping ' . $sleep_microseconds / 1000000 . ' seconds.', 0);
                    usleep($sleep_microseconds);
                }
            }
            return $request;
        };
    }

    /**
    * A middleware method to read the bucket state from each reponse.
    *
    * The bucket level and size are parsed from the X-LS-API-Bucket-Level
    * header. The drip rate is calculated as the bucket size divided by 60.
    * The time is also saved so we know how much time has passed.
    *
    * @return callable
    */
    protected function getBucket()
    {
        return function (ResponseInterface $response) {
            if (count($bucket_header = $response->getHeader('X-LS-API-Bucket-Level')) > 0) {
                $bucket = explode('/', $bucket_header[0]);
                $this->bucket = [
                    'level' => $bucket[0],
                    'size' => $bucket[1],
                    'available' => $bucket[1] - $bucket[0],
                    'drip' => $response->getHeader('X-LS-API-Drip-Rate')
                ];
            }
            $this->last_req_time = time();
            return $response;
        };
    }
}
