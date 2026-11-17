<?php

namespace App\Http\Controllers;

use App\Actions\ApplyGenerationState;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Receives Pixelforge events (signature already verified by middleware).
 *
 * The event is recorded and applied in one transaction: if applying fails we
 * answer 5xx, the insert rolls back, and Pixelforge's retry is processed
 * normally. A repeat of an event that was applied is acknowledged and skipped.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, ApplyGenerationState $state): JsonResponse
    {
        $event = json_decode($request->getContent(), true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response()->json(['error' => 'Malformed event.'], 400);
        }

        $object = $event['data']['object'] ?? null;

        $result = DB::transaction(function () use ($event, $object, $request, $state) {
            $now = now();

            $inserted = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'pixelforge_generation_id' => $object['id'] ?? null,
                'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt') ?: null,
                'payload' => json_encode($event),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                return 'duplicate';
            }

            if (str_starts_with($event['type'], 'generation.') && is_array($object)) {
                $state->apply($object);
            }

            WebhookEvent::query()->where('event_id', $event['id'])->update(['processed_at' => $now]);

            return 'processed';
        });

        return response()->json(['status' => $result]);
    }
}
