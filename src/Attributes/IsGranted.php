<?php

namespace Modufolio\Appkit\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class IsGranted
{
    /** @var list<string> */
    public readonly array $roles;

    /** @var list<string> */
    public readonly array $methods;

    /**
     * @param string|list<string> $roles   roles or trust-level attributes; holding any one of them satisfies this attribute
     * @param string|list<string> $methods HTTP methods this check applies to; an empty array applies it to every method
     */
    public function __construct(string|array $roles, array|string $methods = [])
    {
        $this->roles = (array) $roles;

        $methods = array_map(strtoupper(...), (array) $methods);

        // A GET rule must cover HEAD too: the router treats HEAD as GET, so
        // leaving it out would let a HEAD request slip past the check.
        if (\in_array('GET', $methods, true) && !\in_array('HEAD', $methods, true)) {
            $methods[] = 'HEAD';
        }

        $this->methods = $methods;
    }
}
