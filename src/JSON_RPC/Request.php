<?php
/**
 * This file is a part of "furqansiddiqui/http-client" package.
 * https://github.com/furqansiddiqui/http-client
 *
 * Copyright (c) 2019 Furqan A. Siddiqui <hello@furqansiddiqui.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code or visit following link:
 * https://github.com/furqansiddiqui/http-client/blob/master/LICENSE.md
 */

declare(strict_types=1);

namespace HttpClient\JSON_RPC;

use HttpClient\Exception\HttpClientException;
use HttpClient\Exception\JSON_RPC_RequestException;
use HttpClient\Exception\JSON_RPC_ResponseException;
use HttpClient\HttpClient;
use HttpClient\HttpStatusCodes;
use HttpClient\JSON_RPC;

/**
 * Class Request
 * @package HttpClient\JSON_RPC
 */
class Request
{
    /** @var JSON_RPC */
    private $_client;
    /** @var bool */
    private $_validateParams;
    /** @var string */
    private $_httpMethod;
    /** @var string */
    private $_endpoint;
    /** @var null|int */
    private $_expectedHttpStatusCode;

    /** @var null|string */
    private $id;
    /** @var null|string */
    private $method;
    /** @var null|array */
    private $params;

    /**
     * Request constructor.
     * @param JSON_RPC $client
     * @param string $endpoint
     * @param string $httpMethod
     * @throws JSON_RPC_RequestException
     */
    public function __construct(JSON_RPC $client, string $endpoint, string $httpMethod = 'POST')
    {
        // JSON RPC client instance and request config
        $this->_client = $client;
        $this->_validateParams = true;

        // HTTP Request Method
        $httpMethod = strtoupper($httpMethod);
        if (!in_array($httpMethod, HttpClient::REQUEST_METHODS)) {
            throw new JSON_RPC_RequestException('Bad HTTP request method');
        }

        $this->_httpMethod = $httpMethod;


        // Endpoint
        $endpoint = "/" . trim($endpoint, "/");
        if (!preg_match('/^(\/[\w\-\.]+)+$/', $endpoint)) {
            throw new JSON_RPC_RequestException('Invalid API endpoint');
        }

        $this->_endpoint = $endpoint;
    }

    /**
     * @param bool $validate
     * @return Request
     */
    public function validateParams(bool $validate): self
    {
        $this->_validateParams = $validate;
        return $this;
    }

    /**
     * @param int $expected
     * @return Request
     */
    public function successHttpStatusCode(int $expected): self
    {
        $this->_expectedHttpStatusCode = $expected;
        return $this;
    }

    /**
     * @param $id
     * @return Request
     * @throws JSON_RPC_RequestException
     */
    public function id($id): self
    {
        if (!is_string($id) && !is_int($id)) {
            throw new JSON_RPC_RequestException('Request param "id" must be of type String/Integer');
        } elseif (!$id) {
            throw new JSON_RPC_RequestException('Request param "id" must have a value');
        }

        $this->id = $id;
        return $this;
    }

    /**
     * @param string $method
     * @return Request
     * @throws JSON_RPC_RequestException
     */
    public function method(string $method): self
    {
        if (!preg_match('/^[\w]+$/', $method)) {
            throw new JSON_RPC_RequestException('Invalid value for request param "method"');
        }

        $this->method = $method;
        return $this;
    }

    /**
     * @param array $params
     * @return Request
     * @throws JSON_RPC_RequestException
     */
    public function params(array $params): self
    {
        if ($this->_validateParams) {
            $this->requestParamsValidation($params);
        }

        $this->params = $params;
        return $this;
    }

    /**
     * @return Response
     * @throws HttpClientException
     * @throws JSON_RPC_RequestException
     * @throws JSON_RPC_ResponseException
     */
    public function send(): Response
    {
        $payload = [
            "jsonrpc" => $this->_client->specification,
            "method" => $this->method,
            "params" => $this->params ?? []
        ];

        if ($this->id) {
            $payload["id"] = $this->id;
        }

        $req = new \HttpClient\Request($this->_httpMethod, $this->_client->url . $this->_endpoint);
        $req->payload($payload, true); // Send as JSON

        // Set Authentication and SSL/TLS config
        call_user_func_array([$this->_client, "prepare_req_objs"], [$req]);

        try {
            $res = $req->send();
        } catch (HttpClientException $e) {
            throw new JSON_RPC_RequestException($e->getMessage(), $e->getCode());
        }

        // Expected Success HTTP Status Code
        $badStatusCode = false;
        $responseStatusCode = $res->code();
        $expectedStatusCode = $this->_expectedHttpStatusCode;
        if ($expectedStatusCode) {
            if ($expectedStatusCode !== $responseStatusCode) {
                $badStatusCode = true;
            }
        } else {
            $badStatusCode = true;
            $expectedStatusCode = "2xx";
            if ($responseStatusCode >= 200 && $responseStatusCode <= 226) {
                $badStatusCode = false;
            }
        }

        if ($badStatusCode) {
            $responseStatusCodeMessage = HttpStatusCodes::MESSAGES[$responseStatusCode] ?? null;
            if ($responseStatusCodeMessage) {
                $responseStatusCodeMessage = " (" . $responseStatusCodeMessage . ")";
            }

            throw new JSON_RPC_ResponseException(
                sprintf('HTTP status %d%s, expected %s', $responseStatusCode, $responseStatusCodeMessage, $expectedStatusCode)
            );
        }

        return new Response($this->_client, $res, $this->id);
    }


    /**
     * @param array $obj
     * @throws JSON_RPC_RequestException
     */
    private function requestParamsValidation(array $obj): void
    {
        $spec = $this->_client->specification;
        foreach ($obj as $i => $value) {
            if ($spec !== "2.0") {
                if (!is_int($i)) {
                    throw new JSON_RPC_RequestException(
                        sprintf('JSON RPC %s does not accept named params', $spec)
                    );
                }
            }

            if (is_scalar($value) || is_null($value)) {
                continue; // Scalar values are OK
            }

            if (is_array($value)) {
                $this->requestParamsValidation($value);
                continue;
            }

            throw new JSON_RPC_RequestException(
                sprintf('Request params contain a value of type "%s"', gettype($value))
            );
        }
    }
}