<?php declare(strict_types=1);

namespace Nuwave\Lighthouse\Subscriptions;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Nuwave\Lighthouse\Execution\HttpGraphQLContext;
use Nuwave\Lighthouse\GraphQL;
use Nuwave\Lighthouse\Schema\Types\GraphQLSubscription;
use Nuwave\Lighthouse\Subscriptions\Contracts\AuthorizesSubscriptions;
use Nuwave\Lighthouse\Subscriptions\Contracts\BroadcastsSubscriptions;
use Nuwave\Lighthouse\Subscriptions\Contracts\StoresSubscriptions;
use Nuwave\Lighthouse\Subscriptions\Contracts\SubscriptionIterator;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionBroadcaster implements BroadcastsSubscriptions
{
    public function __construct(
        protected GraphQL $graphQL,
        protected AuthorizesSubscriptions $subscriptionAuthorizer,
        protected StoresSubscriptions $subscriptionStorage,
        protected SubscriptionIterator $subscriptionIterator,
        protected BroadcastDriverManager $broadcastDriverManager,
        protected BusDispatcher $busDispatcher,
    ) {}

    public function queueBroadcast(GraphQLSubscription $subscription, string $fieldName, mixed $root): void
    {
        $broadcastSubscriptionJob = new BroadcastSubscriptionJob($subscription, $fieldName, $root);
        $broadcastSubscriptionJob->onQueue(config('lighthouse.subscriptions.broadcasts_queue_name'));

        $this->busDispatcher->dispatch($broadcastSubscriptionJob);
    }

    public function broadcast(GraphQLSubscription $subscription, string $fieldName, mixed $root): void
    {
        if ($root instanceof Collection) {
            $this->broadcastBatch($subscription, $fieldName, $root);

            return;
        }

        $topic = $subscription->decodeTopic($fieldName, $root);

        $subscribers = $this->subscriptionStorage->subscribersByTopic($topic);

        // Batch-load subscriber users before filter/iterate runs so that
        // subscription filters and resolvers can read $context->user
        // without triggering one SELECT per subscriber.
        $this->batchPreloadContextUsers($subscribers);

        $subscribers = $subscribers
            ->filter(static fn (Subscriber $subscriber): bool => $subscription->filter($subscriber, $root));

        $this->subscriptionIterator->process(
            $subscribers,
            function (Subscriber $subscriber) use ($root): void {
                $subscriber->root = $root;

                $result = $this->graphQL->executeParsedQuery(
                    $subscriber->query,
                    $subscriber->context,
                    $subscriber->variables,
                    $subscriber,
                );
                $this->broadcastDriverManager->broadcast($subscriber, $result);
            },
        );
    }

    private function broadcastBatch(GraphQLSubscription $subscription, string $fieldName, Collection $roots): void
    {
        $batch = [];
        $cachedSubscribers = [];

        $roots->each(function ($root) use ($subscription, $fieldName, &$cachedSubscribers, &$batch) {
            $topic = $subscription->decodeTopic($fieldName, $root);

            if (! isset($cachedSubscribers[$topic])) {
                $topicSubscribers = $this->subscriptionStorage
                    ->subscribersByTopic($topic);
                $this->batchPreloadContextUsers($topicSubscribers);
                $cachedSubscribers[$topic] = $topicSubscribers;
            }

            $subscribers = $cachedSubscribers[$topic]
                ->filter(static fn (Subscriber $subscriber): bool => $subscription->filter($subscriber, $root));

            $this->subscriptionIterator->process(
                $subscribers,
                function (Subscriber $subscriber) use ($root, &$batch): void {
                    $subscriber->root = $root;

                    $result = $this->graphQL->executeParsedQuery(
                        $subscriber->query,
                        $subscriber->context,
                        $subscriber->variables,
                        $subscriber,
                    );

                    $batch[] = [
                        'subscriber' => $subscriber,
                        'result' => $result,
                    ];

                    if (count($batch) >= 10) {
                        $this->broadcastDriverManager->broadcastBatch($batch);
                        $batch = [];
                    }
                },
            );
        });

        if (count($batch) > 0) {
            $this->broadcastDriverManager->broadcastBatch($batch);
        }
    }

    public function authorize(Request $request): Response
    {
        return $this->subscriptionAuthorizer->authorize($request)
            ? $this->broadcastDriverManager->authorized($request)
            : $this->broadcastDriverManager->unauthorized($request);
    }

    /**
     * Preload users referenced by subscriber contexts in a single query per
     * (model class, connection) bucket, instead of one SELECT per subscriber
     * when $context->user is read.
     *
     * Works with contexts hydrated by ContextSerializer::unserialize(), which
     * attaches the raw ModelIdentifier to HttpGraphQLContext::$userIdentifier
     * and defers the database lookup to this method.
     *
     * Contexts with array identifiers (queueable collections), missing
     * identifiers, or non-HttpGraphQLContext implementations are left alone —
     * they fall back to whatever resolution the context would normally do on
     * first $context->user() call.
     */
    private function batchPreloadContextUsers(Collection $subscribers): void
    {
        /** @var array<string, array{class: class-string, connection: ?string, ids: array<true>}> */
        $buckets = [];

        foreach ($subscribers as $subscriber) {
            $context = $subscriber->context;
            if (! $context instanceof HttpGraphQLContext) {
                continue;
            }
            if ($context->user !== null) {
                continue; // already resolved somehow
            }
            $identifier = $context->userIdentifier;
            if (! $identifier instanceof ModelIdentifier) {
                continue;
            }
            if (is_array($identifier->id)) {
                continue; // collection identifiers skipped
            }
            if (! is_string($identifier->class) || ! class_exists($identifier->class)) {
                continue;
            }

            $connection = $identifier->connection ?? '';
            $bucketKey = $identifier->class . '|' . $connection;

            if (! isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [
                    'class' => $identifier->class,
                    'connection' => $identifier->connection,
                    'ids' => [],
                ];
            }

            $buckets[$bucketKey]['ids'][$identifier->id] = true;
        }

        if ($buckets === []) {
            return;
        }

        $cache = [];

        foreach ($buckets as $bucketKey => $bucket) {
            $class = $bucket['class'];
            /** @var Model $model */
            $model = new $class();

            if ($bucket['connection'] !== null && $bucket['connection'] !== '') {
                $model->setConnection($bucket['connection']);
            }

            $ids = array_keys($bucket['ids']);

            try {
                $cache[$bucketKey] = $model->newQueryForRestoration($ids)
                    ->get()
                    ->keyBy(static fn (Model $m) => $m->getKey());
            } catch (\Throwable $e) {
                // Fall back to per-subscriber resolution on error — do not
                // take down the broadcast just because preload failed.
                $cache[$bucketKey] = new \Illuminate\Database\Eloquent\Collection();
            }
        }

        foreach ($subscribers as $subscriber) {
            $context = $subscriber->context;
            if (! $context instanceof HttpGraphQLContext) {
                continue;
            }
            if ($context->user !== null) {
                continue;
            }
            $identifier = $context->userIdentifier;
            if (! $identifier instanceof ModelIdentifier) {
                continue;
            }
            if (is_array($identifier->id)) {
                continue;
            }
            if (! is_string($identifier->class) || ! class_exists($identifier->class)) {
                continue;
            }

            $bucketKey = $identifier->class . '|' . ($identifier->connection ?? '');
            $preloaded = $cache[$bucketKey][$identifier->id] ?? null;

            if ($preloaded !== null) {
                if (! empty($identifier->relations)) {
                    $preloaded->load($identifier->relations);
                }

                $context->setUser($preloaded);
            }
        }
    }
}
