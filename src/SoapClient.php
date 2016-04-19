<?php

namespace Meng\AsyncSoap\Artax;

use Amp\Artax\Client;
use Amp\Artax\Request;
use Amp\Artax\Response;
use Amp\Deferred;
use Amp\Promise;
use Meng\AsyncSoap\SoapClientInterface;
use Meng\Soap\HttpBinding\HttpBinding;
use Zend\Diactoros\Response as PsrResponse;
use Zend\Diactoros\Stream;
use function Amp\wait;

class SoapClient implements SoapClientInterface
{
    private $deferredHttpBinding;
    private $client;

    public function __construct(Client $client, Promise $httpBindingPromise)
    {
        $this->client = $client;
        $this->deferredHttpBinding = $httpBindingPromise;
    }

    public function __call($name, $arguments)
    {
        return $this->callAsync($name, $arguments);
    }

    public function call($name, array $arguments, array $options = null, $inputHeaders = null, array &$output_headers = null)
    {
        $promise = $this->callAsync($name, $arguments, $options, $inputHeaders, $output_headers);
        return wait($promise);
    }

    public function callAsync($name, array $arguments, array $options = null, $inputHeaders = null, array &$output_headers = null)
    {
        $deferredResult = new Deferred;
        $this->deferredHttpBinding->when(
            function (\Exception $error = null, $httpBinding) use ($deferredResult, $name, $arguments, $options, $inputHeaders, $output_headers) {
                if ($error) {
                    $deferredResult->fail($error);
                } else {
                    $request = new Request;
                    /** @var HttpBinding $httpBinding */
                    $psrRequest = $httpBinding->request($name, $arguments, $options, $inputHeaders);
                    $request->setMethod($psrRequest->getMethod());
                    $request->setUri($psrRequest->getUri());
                    $request->setAllHeaders($psrRequest->getHeaders());
                    $request->setBody($psrRequest->getBody()->__toString());
                    $this->client->request($request)->when(
                        function (\Exception $error = null, $response) use ($name, $output_headers, $deferredResult, $httpBinding) {
                            if ($error) {
                                $deferredResult->fail($error);
                            } else {
                                $bodyStream = fopen('php://temp', 'r+');
                                /** @var Response $response */
                                fwrite($bodyStream, $response->getBody());
                                fseek($bodyStream, 0);
                                $bodyStream = new Stream($bodyStream);
                                $psrResponse = new PsrResponse($bodyStream, $response->getStatus(), $response->getAllHeaders());
                                try {
                                    $deferredResult->succeed($httpBinding->response($psrResponse, $name, $output_headers));
                                } catch (\Exception $e) {
                                    $deferredResult->fail($e);
                                }

                            }
                        }
                    );
                }
            }
        );

        return $deferredResult->promise();
    }
}