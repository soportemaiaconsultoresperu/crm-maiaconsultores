<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileGoogleCalendarChannel;
use App\Models\GoogleCalendarChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class GoogleCalendarWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $channelId = (string) $request->headers->get('X-Goog-Channel-ID', '');
        $resourceId = (string) $request->headers->get('X-Goog-Resource-ID', '');
        $messageNumber = (string) $request->headers->get('X-Goog-Message-Number', '');
        $token = (string) $request->headers->get('X-Goog-Channel-Token', '');

        if ($channelId === '' || $resourceId === '' || $messageNumber === '') {
            return response()->json(['message' => 'Missing Google Calendar channel headers.'], Response::HTTP_BAD_REQUEST);
        }

        if (! ctype_digit($messageNumber)) {
            return response()->json(['message' => 'Invalid Google Calendar message number.'], Response::HTTP_BAD_REQUEST);
        }

        $result = DB::transaction(function () use ($channelId, $resourceId, $messageNumber, $token): array {
            /** @var GoogleCalendarChannel|null $channel */
            $channel = GoogleCalendarChannel::query()
                ->where('channel_id', $channelId)
                ->lockForUpdate()
                ->first();

            if (! $channel instanceof GoogleCalendarChannel) {
                return ['status' => Response::HTTP_NOT_FOUND, 'queued' => false, 'channel' => null];
            }

            if ($channel->resource_id !== $resourceId) {
                return ['status' => Response::HTTP_FORBIDDEN, 'queued' => false, 'channel' => null];
            }

            if (! $channel->hasToken($token)) {
                return ['status' => Response::HTTP_FORBIDDEN, 'queued' => false, 'channel' => null];
            }

            if ($channel->isStaleMessage($messageNumber)) {
                return ['status' => Response::HTTP_NO_CONTENT, 'queued' => false, 'channel' => null];
            }

            $channel->forceFill([
                'last_message_number' => $messageNumber,
                'last_received_at' => now(),
                'status' => GoogleCalendarChannel::STATUS_ACTIVE,
                'error_class' => null,
                'error_message' => null,
            ])->save();

            return ['status' => Response::HTTP_ACCEPTED, 'queued' => true, 'channel' => $channel->getKey()];
        });

        if ($result['queued'] === true && $result['channel'] !== null) {
            ReconcileGoogleCalendarChannel::dispatch((int) $result['channel'])->afterCommit();
        }

        return response()->json([], (int) $result['status']);
    }
}
