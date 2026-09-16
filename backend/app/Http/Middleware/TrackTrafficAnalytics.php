<?php

namespace App\Http\Middleware;

use App\Services\Analytics\TrafficAnalyticsIdentity;
use App\Services\Analytics\TrafficEventRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackTrafficAnalytics
{
    public function __construct(
        private readonly TrafficAnalyticsIdentity $identity,
        private readonly TrafficEventRecorder $recorder
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        if (
            ! $this->identity
                ->hasConsent(
                    $request
                )
        ) {
            return $next(
                $request
            );
        }

        $visitorUuid =
            $this->identity
                ->resolveVisitor(
                    $request
                );

        $sessionUuid =
            $this->identity
                ->resolveSession(
                    $request
                );

        $response =
            $next(
                $request
            );

        $this->recorder
            ->recordPageView(
                request:
                    $request,

                response:
                    $response,

                visitorUuid:
                    $visitorUuid,

                sessionUuid:
                    $sessionUuid
            );

        return $response;
    }
}
