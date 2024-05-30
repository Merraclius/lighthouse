<?php declare(strict_types=1);

namespace Nuwave\Lighthouse\Subscriptions;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        protected BroadcastManager $broadcastManager,
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

        $subscribers = $this->subscriptionStorage
            ->subscribersByTopic($topic)
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
                $this->broadcastManager->broadcast($subscriber, $result);
            },
        );
    }

    private function broadcastBatch(GraphQLSubscription $subscription, string $fieldName, Collection $roots): void
    {
        $batch = [];

        $roots->each(function($root) use ($subscription, $fieldName, &$batch) {
            $topic = $subscription->decodeTopic($fieldName, $root);

            $subscribers = $this->subscriptionStorage
                ->subscribersByTopic($topic)
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
                        $this->broadcastManager->broadcastBatch($batch);
                        $batch = [];
                    }
                },
            );
        });

        if (count($batch) > 0) {
            $this->broadcastManager->broadcastBatch($batch);
        }
    }

    public function authorize(Request $request): Response
    {
        return $this->subscriptionAuthorizer->authorize($request)
            ? $this->broadcastManager->authorized($request)
            : $this->broadcastManager->unauthorized($request);
    }
}
