<?php

namespace Meng\AsyncSoap\Artax;

use Amp\Artax\Client;
use Amp\Artax\Request;
use Amp\Artax\Response;
use Amp\Deferred;
use Amp\Promise;
use GuzzleHttp\Promise\Promise as GuzzlePromise;
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

    public function call($name, array $arguments, array $options = null, $inputHeaders = null, array &$outputHeaders = null)
    {
        $promise = $this->callAsync($name, $arguments, $options, $inputHeaders, $outputHeaders);
        return $promise->wait();
    }

    public function callAsync($name, array $arguments, array $options = null, $inputHeaders = null, array &$outputHeaders = null)
    {
        $deferredResult = new Deferred;
        $this->deferredHttpBinding->when(
            function (\Exception $error = null, $httpBinding) use ($deferredResult, $name, $arguments, $options, $inputHeaders, &$outputHeaders) {
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
                    $psrRequest->getBody()->close();

                    $this->client->request($request)->when(
                        function (\Exception $error = null, $response) use ($name, &$outputHeaders, $deferredResult, $httpBinding) {
                            if ($error) {
                                $deferredResult->fail($error);
                            } else {
                                $bodyStream = new Stream('php://temp', 'r+');
                                /** @var Response $response */
                                $bodyStream->write($response->getBody());
                                $bodyStream->rewind();
                                $psrResponse = new PsrResponse($bodyStream, $response->getStatus(), $response->getAllHeaders());

                                try {
                                    $deferredResult->succeed($httpBinding->response($psrResponse, $name, $outputHeaders));
                                } catch (\Exception $e) {
                                    $deferredResult->fail($e);
                                } finally {
                                    $psrResponse->getBody()->close();
                                }

                            }
                        }
                    );
                }
            }
        );

        return $this->convertPromise($deferredResult->promise());
    }

    /**
     * Amp promise interface does not conform to https://promisesaplus.com, so it should be converted
     * to a Guzzle promise in order to fulfill the return type of  SoapClientInterface::callAsync.
     * @param Promise $ampPromise
     * @return GuzzlePromise
     */
    private function convertPromise(Promise $ampPromise)
    {
        $guzzlePromise = new GuzzlePromise(
            function () use ($ampPromise) {
                wait($ampPromise);
            }
        );
        $ampPromise->when(function (\Exception $error = null, $value) use ($guzzlePromise) {
            if ($error) {
                $guzzlePromise->reject($error);
            } else {
                $guzzlePromise->resolve($value);
            }
        });
        return $guzzlePromise;
    }
}
