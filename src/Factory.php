<?php

namespace Meng\AsyncSoap\Artax;

use Amp\Artax\Client;
use Amp\Artax\Request;
use Amp\Artax\Response;
use Amp\Deferred;
use Meng\Soap\HttpBinding\HttpBinding;
use Meng\Soap\HttpBinding\RequestBuilder;
use Meng\Soap\Interpreter;

class Factory
{
    public function create(Client $client, $wsdl, array $options = [])
    {
        $deferredHttpBinding = new Deferred;
        if (null === $wsdl) {
            $deferredHttpBinding->succeed(new HttpBinding(new Interpreter($wsdl, $options), new RequestBuilder));
        } else {
            $wsdlRequest = new Request;
            $wsdlRequest->setMethod('GET')->setUri($wsdl);
            $client->request($wsdlRequest)->when(
                function (\Exception $error = null, $response) use ($deferredHttpBinding, $options) {
                    if ($error) {
                        $deferredHttpBinding->fail($error);
                    } else {
                        /** @var Response $response */
                        $wsdl = $response->getBody();
                        $deferredHttpBinding->succeed(
                            new HttpBinding(
                                new Interpreter('data://text/plain;base64,' . base64_encode($wsdl), $options),
                                new RequestBuilder
                            )
                        );
                    }

                }
            );
        }
        return new SoapClient($client, $deferredHttpBinding->promise());
    }
}