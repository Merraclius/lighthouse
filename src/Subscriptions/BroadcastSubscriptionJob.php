<?php declare(strict_types=1);

namespace Nuwave\Lighthouse\Subscriptions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Nuwave\Lighthouse\Schema\Types\GraphQLSubscription;
use Nuwave\Lighthouse\Subscriptions\Contracts\BroadcastsSubscriptions;

class BroadcastSubscriptionJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        /**
         * The subscription field that was requested.
         */
        public GraphQLSubscription $subscription,
        /**
         * The name of the field.
         */
        public string $fieldName,
        /**
         * The root element to be passed when resolving the subscription.
         */
        public mixed $root,
    ) {}

    public function handle(BroadcastsSubscriptions $broadcaster): void
    {
        Log::info("BroadcastSubscriptionJob", ['time' => microtime(true)]);
        $broadcaster->broadcast($this->subscription, $this->fieldName, $this->root);
    }
}
