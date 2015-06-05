<?php

namespace Dragooon\Hawk\Server;

use Dragooon\Hawk\Credentials\CallbackCredentialsProvider;
use Dragooon\Hawk\Credentials\CredentialsInterface;
use Dragooon\Hawk\Credentials\CredentialsProviderInterface;
use Dragooon\Hawk\Crypto\Artifacts;
use Dragooon\Hawk\Crypto\Crypto;
use Dragooon\Hawk\Header\Header;
use Dragooon\Hawk\Header\HeaderFactory;
use Dragooon\Hawk\Nonce\CallbackNonceValidator;
use Dragooon\Hawk\Nonce\NonceValidatorInterface;
use Dragooon\Hawk\Time\TimeProviderInterface;

class Server implements ServerInterface
{
    private $crypto;
    private $credentialsProvider;
    private $timeProvider;
    private $nonceValidator;
    private $timestampSkewSec;
    private $localtimeOffsetSec;

    /**
     * @param Crypto $crypto
     * @param CredentialsProviderInterface $credentialsProvider
     * @param TimeProviderInterface $timeProvider
     * @param NonceValidatorInterface $nonceValidator
     * @param int $timestampSkewSec
     * @param int $localtimeOffsetSec
     */
    public function __construct(
        Crypto $crypto,
        $credentialsProvider,
        TimeProviderInterface $timeProvider,
        $nonceValidator,
        $timestampSkewSec,
        $localtimeOffsetSec
    ) {
        if (!$credentialsProvider instanceof CredentialsProviderInterface) {
            if (is_callable($credentialsProvider)) {
                $credentialsProvider = new CallbackCredentialsProvider($credentialsProvider);
            } else {
                throw new \InvalidArgumentException(
                    "Credentials provider must implement CredentialsProviderInterface or must be callable"
                );
            }
        }

        if (!$nonceValidator instanceof NonceValidatorInterface) {
            if (is_callable($nonceValidator)) {
                $nonceValidator = new CallbackNonceValidator($nonceValidator);
            } else {
                throw new \InvalidArgumentException(
                    "Nonce validator must implement NonceValidatorInterface or must be callable"
                );
            }
        }

        $this->crypto = $crypto;
        $this->credentialsProvider = $credentialsProvider;
        $this->timeProvider = $timeProvider;
        $this->nonceValidator = $nonceValidator;
        $this->timestampSkewSec = $timestampSkewSec;
        $this->localtimeOffsetSec = $localtimeOffsetSec;
    }

    /**
     * @param string $method
     * @param string $host
     * @param int $port
     * @param mixed $resource
     * @param string $contentType
     * @param mixed $payload
     * @param mixed $headerObjectOrString
     * @return Response
     * @throws UnauthorizedException
     */
    public function authenticate(
        $method,
        $host,
        $port,
        $resource,
        $contentType = null,
        $payload = null,
        $headerObjectOrString = null
    ) {
        if (null === $headerObjectOrString) {
            throw new UnauthorizedException("Missing Authorization header");
        }

        $header = HeaderFactory::createFromHeaderObjectOrString(
            'Authorization',
            $headerObjectOrString,
            function () {
                throw new UnauthorizedException("Invalid Authorization header");
            }
        );

        // Measure now before any other processing
        $now = $this->timeProvider->createTimestamp() + $this->localtimeOffsetSec;

        $artifacts = new Artifacts(
            $method,
            $host,
            $port,
            $resource,
            $header->attribute('ts'),
            $header->attribute('nonce'),
            $header->attribute('ext'),
            $payload,
            $contentType,
            $header->attribute('hash'),
            $header->attribute('app'),
            $header->attribute('dlg')
        );

        foreach (array('id', 'ts', 'nonce', 'mac') as $requiredAttribute) {
            if (null === $header->attribute($requiredAttribute)) {
                throw new UnauthorizedException('Missing attributes');
            }
        }

        $credentials = $this->credentialsProvider->loadCredentialsById($header->attribute('id'));

        $calculatedMac = $this->crypto->calculateMac('header', $credentials, $artifacts);

        if (!$this->crypto->fixedTimeComparison($calculatedMac, $header->attribute('mac'))) {
            throw new UnauthorizedException('Bad MAC');
        }

        if (null !== $artifacts->payload()) {
            if (null === $artifacts->hash()) {
                // Should this ever happen? Difficult to get a this far if
                // hash is missing as the MAC will probably be wrong anyway.
                throw new UnauthorizedException('Missing required payload hash');
            }

            $calculatedHash = $this->crypto->calculatePayloadHash(
                $artifacts->payload(),
                $credentials->algorithm(),
                $artifacts->contentType()
            );

            if (!$this->crypto->fixedTimeComparison($calculatedHash, $artifacts->hash())) {
                throw new UnauthorizedException('Bad payload hash');
            }
        }

        if (!$this->nonceValidator->validateNonce($artifacts->nonce(), $artifacts->timestamp())) {
            throw new UnauthorizedException('Invalid nonce');
        }

        if (abs($header->attribute('ts') - $now) > $this->timestampSkewSec) {
            $ts = $this->timeProvider->createTimestamp() + $this->localtimeOffsetSec;
            $tsm = $this->crypto->calculateTsMac($ts, $credentials);

            throw new UnauthorizedException('Stale timestamp', array('ts' => $ts, 'tsm' => $tsm));
        }

        return new Response($credentials, $artifacts);
    }

    /**
     * @param CredentialsInterface $credentials
     * @param Artifacts $artifacts
     * @param array $options
     * @return Header
     */
    public function createHeader(CredentialsInterface $credentials, Artifacts $artifacts, array $options = array())
    {
        if (isset($options['payload'])) {
            $payload = $options['payload'];
            $contentType = !empty($options['content_type']) ? $options['content_type'] : '';
            $hash = $this->crypto->calculatePayloadHash($payload, $credentials->algorithm(), $contentType);
        } else {
            $payload = null;
            $contentType = null;
            $hash = null;
        }

        $ext = isset($options['ext']) ? $options['ext'] : null;

        $responseArtifacts = new Artifacts(
            $artifacts->method(),
            $artifacts->host(),
            $artifacts->port(),
            $artifacts->resource(),
            $artifacts->timestamp(),
            $artifacts->nonce(),
            $ext,
            $payload,
            $contentType,
            $hash,
            $artifacts->app(),
            $artifacts->dlg()
        );

        $attributes = array(
            'mac' => $this->crypto->calculateMac('response', $credentials, $responseArtifacts),
        );

        if ($hash !== null) {
            $attributes['hash'] = $hash;
        }

        if ($ext) {
            $attributes['ext'] = $ext;
        }

        return HeaderFactory::create('Server-Authorization', $attributes);
    }

    /**
     * @param CredentialsInterface $credentials
     * @param string $payload
     * @param string $contentType
     * @param string $hash
     * @return bool
     */
    public function authenticatePayload(
        CredentialsInterface $credentials,
        $payload,
        $contentType,
        $hash
    ) {
        $calculatedHash = $this->crypto->calculatePayloadHash($payload, $credentials->algorithm(), $contentType);

        return $this->crypto->fixedTimeComparison($calculatedHash, $hash);
    }

    /**
     * @param string $host
     * @param int $port
     * @param string $resource
     * @return Response
     * @throws UnauthorizedException
     */
    public function authenticateBewit(
        $host,
        $port,
        $resource
    ) {
        // Measure now before any other processing
        $now = $this->timeProvider->createTimestamp() + $this->localtimeOffsetSec;

        if (!preg_match(
            '/^(\/.*)([\?&])bewit\=([^&$]*)(?:&(.+))?$/',
            $resource,
            $resourceParts
        )) {
            // TODO: Should this do something else?
            throw new UnauthorizedException('Malformed resource or does not contan bewit');
        }

        $bewit = base64_decode(str_replace(
            array('-', '_', '', ''),
            array('+', '/', '=', "\n"),
            $resourceParts[3]
        ));

        list ($id, $exp, $mac, $ext) = explode('\\', $bewit);

        if ($exp < $now) {
            throw new UnauthorizedException('Access expired');
        }

        $resource = $resourceParts[1];
        if (isset($resourceParts[4])) {
            $resource .= $resourceParts[2].$resourceParts[4];
        }

        $artifacts = new Artifacts(
            'GET',
            $host,
            $port,
            $resource,
            $exp,
            '',
            $ext
        );

        $credentials = $this->credentialsProvider->loadCredentialsById($id);

        $calculatedMac = $this->crypto->calculateMac(
            'bewit',
            $credentials,
            $artifacts
        );

        if (!$this->crypto->fixedTimeComparison($calculatedMac, $mac)) {
            throw new UnauthorizedException('Bad MAC');
        }

        return new Response($credentials, $artifacts);
    }
}
