<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\TypeSafeChoiceAnswer;
use App\Exceptions\TypeSafe\TypeSafeException;

/**
 * The single seam through which the app talks to TypeSafe System One (Jev).
 *
 * Every call sends the given state to a third party, so callers own data
 * minimisation: pass only what the question needs.
 */
interface TypeSafeServiceContract
{
    /**
     * Ask one Choice question about $state and return the answer.
     *
     * @param  array<string, mixed>  $state  JSON-serialisable state the model evaluates
     * @param  array<string, string>  $options  option id => description shown to the model (2–255 entries)
     *
     * @throws TypeSafeException on transport or API failure (after bounded 429/529 retries),
     *                           or a 2xx response without a well-formed choice answer
     */
    public function choose(array $state, string $instructions, array $options): TypeSafeChoiceAnswer;
}
