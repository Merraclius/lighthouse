<?php declare(strict_types=1);

namespace Nuwave\Lighthouse\Subscriptions\Directives;

use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Support\Contracts\Directive;

/**
 * This directive exists as a placeholder
 *
 * @see \Nuwave\Lighthouse\Schema\Types\GraphQLSubscription
 */
class SharedDirective extends BaseDirective implements Directive
{
    public const NAME = 'shared';

    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
"""
Directive for subscription classes that are shared between multiple subscriptions.
Note! Only applied to the schema without sensitive data.
"""
directive @shared(
  """
  Name which will be used as a postfix for the subscription.
  """
  name: String!
) on FIELD_DEFINITION
GRAPHQL;
    }
}
