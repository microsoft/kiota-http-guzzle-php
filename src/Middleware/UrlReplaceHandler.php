<?php

namespace Microsoft\Kiota\Http\Middleware;

use GuzzleHttp\Promise\PromiseInterface;
use Microsoft\Kiota\Abstractions\RequestOption;
use Microsoft\Kiota\Http\Middleware\Options\UrlReplaceOption;
use Psr\Http\Message\RequestInterface;

class UrlReplaceHandler
{
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
     * @param array<string, RequestOption> $options
     * @return mixed
     */

    public function __invoke(RequestInterface $request, array $options)
    {
        if (array_key_exists(UrlReplaceOption::class, $options) &&
            $options[UrlReplaceOption::class] instanceof UrlReplaceOption) {
            $this->urlReplaceOptions = $options[UrlReplaceOption::class];
        }
        $fn = $this->nextHandler;
        if (!$this->getUrlReplaceOptions()->isEnabled() ||
            empty($this->getUrlReplaceOptions()->getReplacementPairs())) {
            return $fn($request, $options);
        }
        $request = $this->replaceUrlSegment($request);
        return $fn($request, $options);
    }

    private function replaceUrlSegment(RequestInterface $request): RequestInterface
    {
        $newUri = $request->getUri();
        $path = $newUri->getPath();
        foreach ($this->getUrlReplaceOptions()->getReplacementPairs() as $key => $replacementValue) {
            $result = preg_replace('/'.urlencode($key).'/', urlencode($replacementValue), urlencode($path), 1);

            if (is_string($result)) {
                $newUri = $newUri->withPath(urldecode($result));
            }
            $path = $newUri->getPath();
        }
        return $request->withUri($newUri);
    }
}
