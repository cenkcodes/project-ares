<?php

namespace App\Http\Controllers;

use App\Services\Analytics\TrafficAnalyticsIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class PrivacyConsentController extends Controller
{
    public function store(
        Request $request,
        TrafficAnalyticsIdentity $trafficIdentity
    ): JsonResponse {
        $validated =
            $request->validate([
                'version' => [
                    'required',
                    'string',
                    'max:50',
                ],

                'analytics' => [
                    'required',
                    'boolean',
                ],

                'advertising' => [
                    'required',
                    'boolean',
                ],

                'action' => [
                    'required',
                    'string',
                    'in:accept_all,reject_optional,save_preferences',
                ],
            ]);

        $record = [
            'recorded_at' =>
                now()->toIso8601String(),

            'version' =>
                $validated['version'],

            'necessary' =>
                true,

            'analytics' =>
                (bool)
                $validated['analytics'],

            'advertising' =>
                (bool)
                $validated['advertising'],

            'action' =>
                $validated['action'],

            'user_id' =>
                $request
                    ->user()
                    ?->getAuthIdentifier(),

            'ip_hash' =>
                hash_hmac(
                    'sha256',
                    (string)
                    (
                        $request->ip()
                        ?: ''
                    ),
                    (string)
                    config('app.key')
                ),

            'user_agent_hash' =>
                hash_hmac(
                    'sha256',
                    substr(
                        (string)
                        $request->userAgent(),
                        0,
                        2000
                    ),
                    (string)
                    config('app.key')
                ),
        ];

        $directory =
            storage_path(
                'app/private/compliance'
            );

        File::ensureDirectoryExists(
            $directory,
            0700,
            true
        );

        $file =
            $directory .
            '/consent-' .
            now()->format(
                'Y-m'
            ) .
            '.jsonl';

        file_put_contents(
            $file,
            json_encode(
                $record,
                JSON_UNESCAPED_SLASHES
            ) .
            PHP_EOL,
            FILE_APPEND |
            LOCK_EX
        );

        /*
         * Optional analytics identifiers are
         * removed immediately when analytics
         * consent is rejected or withdrawn.
         */
        if (
            ! (bool)
            $validated['analytics']
        ) {
            $trafficIdentity
                ->queueForget();
        }

        return response()->json([
            'ok' =>
                true,
        ]);
    }
}
