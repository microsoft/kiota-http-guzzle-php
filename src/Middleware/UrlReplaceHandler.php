<?php

namespace Microsoft\Kiota\Http\Middleware;

use GuzzleHttp\Promise\PromiseInterface;
use Microsoft\Kiota\Abstractions\RequestOption;
use Microsoft\Kiota\Http\Middleware\Options\UrlReplaceOption;
use Psr\Http\Message\RequestInterface;

class UrlReplaceHandler
{
    public const HANDLER_NAME = 'kiotaUrlReplaceHandler';

    private UrlReplaceOption $urlReplaceOptions;

    /**
     * @var callable(RequestInterface, array<string,mixed>): PromiseInterface $nextHandler
     */
    private $nextHandler;

    /**
     * @param callable(RequestInterface, array<string,mixed>): PromiseInterface $nextHandler
     * @param UrlReplaceOption $urlReplaceOption
     */
    public function __construct(callable $nextHandler, UrlReplaceOption $urlReplaceOption)
    {
        $this->nextHandler = $nextHandler;
        $this->urlReplaceOptions = $urlReplaceOption;
    }

    /**
     * @return UrlReplaceOption
     */
    public function getUrlReplaceOptions(): UrlReplaceOption
    {
        return $this->urlReplaceOptions;
    }

    /**
     * @param RequestInterface $request
     * @param array<string, mixed> $options
     * @return mixed
     */

    public function __invoke(RequestInterface $request, array $options)
    {
        $urlReplaceOptions = $this->urlReplaceOptions;
        if (array_key_exists(UrlReplaceOption::class, $options) &&
            $options[UrlReplaceOption::class] instanceof UrlReplaceOption) {
            $urlReplaceOptions = $options[UrlReplaceOption::class];
        }
        $fn = $this->nextHandler;
        if (!$urlReplaceOptions->isEnabled() ||
            empty($urlReplaceOptions->getReplacementPairs())) {
            return $fn($request, $options);
        }
        $request = $this->replaceUrlSegment($request, $urlReplaceOptions);
        return $fn($request, $options);
    }

    private function replaceUrlSegment(RequestInterface $request, UrlReplaceOption $urlReplaceOption): RequestInterface
    {
        $newUri = $request->getUri();
        $path = $newUri->getPath();
        foreach ($urlReplaceOption->getReplacementPairs() as $key => $replacementValue) {
            $result = preg_replace('/'.urlencode($key).'/', urlencode($replacementValue), urlencode($path), 1);

            if (is_string($result)) {
                $newUri = $newUri->withPath(urldecode($result));
            }
            $path = $newUri->getPath();
        }
        return $request->withUri($newUri);
    }
}
