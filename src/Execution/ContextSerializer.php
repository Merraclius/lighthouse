<?php declare(strict_types=1);

namespace Nuwave\Lighthouse\Execution;

use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Illuminate\Support\Arr;
use Nuwave\Lighthouse\Support\Contracts\CreatesContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Nuwave\Lighthouse\Support\Contracts\SerializesContext;

class ContextSerializer implements SerializesContext
{
    use SerializesAndRestoresModelIdentifiers;

    public function __construct(
        protected CreatesContext $createsContext,
    ) {}

    public function serialize(GraphQLContext $context): string
    {
        $request = $context->request();

        return serialize([
            'request' => $request
                ? [
                    'query' => $request->query->all(),
                    'request' => $request->request->all(),
                    'attributes' => $request->attributes->all(),
                    'cookies' => [],
                    'files' => [],
                    'server' => Arr::except($request->server->all(), ['HTTP_AUTHORIZATION']),
                    'content' => $request->getContent(),
                ]
                : null,
            'user' => $this->getSerializedPropertyValue($context->user()),
        ]);
    }

    public function unserialize(string $context): GraphQLContext
    {
        [
            'request' => $rawRequest,
            'user' => $rawUser
            // @phpstan-ignore theCodingMachineSafe.function (Safe\unserialize is not available in thecodingmachine/safe ^1 and ^2)
        ] = unserialize($context);

        if ($rawRequest) {
            $request = new Request(
                $rawRequest['query'],
                $rawRequest['request'],
                $rawRequest['attributes'],
                $rawRequest['cookies'],
                $rawRequest['files'],
                $rawRequest['server'],
                $rawRequest['content'],
            );
            // Intentionally do NOT wire a user resolver that triggers
            // getRestoredPropertyValue() here. Doing so causes
            // HttpGraphQLContext::__construct to fire one SELECT on users
            // per subscriber during subscribersByTopic unserialize — an N+1
            // when broadcasting to many subscribers. The identifier is
            // attached to the resulting context below so that
            // SubscriptionBroadcaster can batch-load users in a single query.
        } else {
            $request = null;
        }

        $context = $this->createsContext->generate($request);

        if ($rawUser instanceof ModelIdentifier && $context instanceof HttpGraphQLContext) {
            $context->userIdentifier = $rawUser;
        }

        return $context;
    }
}
