<x-filament-panels::page>
    <div
        class="space-y-6"
        wire:poll.5s="refreshStatus"
    >
        <form
            wire:submit="startJob"
            class="space-y-6"
        >
            {{ $this->form }}

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button
                    type="submit"
                    icon="heroicon-o-play"
                    :disabled="$activeJob !== null"
                >
                    Start Job
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-arrow-path"
                    wire:click="refreshStatus"
                >
                    Refresh
                </x-filament::button>

                @if($activeJob !== null)
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        A job is active. New jobs are blocked until it finishes. The server process continues even if this page is closed.
                    </span>
                @endif
            </div>
        </form>

        @if($latestJob !== null)
            @php
                $progressTotal = max(1, (int) $latestJob->progress_total);
                $progressCurrent = min(
                    $progressTotal,
                    max(0, (int) $latestJob->progress_current)
                );
                $progressPercent = (int) round(
                    ($progressCurrent / $progressTotal) * 100
                );
                $statusClass = match ($latestJob->status) {
                    'pass' => 'xrv-status-pass',
                    'fail' => 'xrv-status-fail',
                    default => 'xrv-status-running',
                };
            @endphp

            <section class="xrv-import-card">
                <div class="xrv-import-header">
                    <div>
                        <div class="xrv-kicker">CURRENT / LATEST JOB</div>
                        <h2>
                            #{{ $latestJob->id }} ·
                            {{ strtoupper($latestJob->source) }} ·
                            {{ $latestJob->category_slug }}
                        </h2>
                    </div>

                    <span class="xrv-status {{ $statusClass }}">
                        {{ strtoupper($latestJob->status) }}
                    </span>
                </div>

                <div class="xrv-meta-grid">
                    <div>
                        <span>Mode</span>
                        <strong>{{ $latestJob->mode }}</strong>
                    </div>
                    <div>
                        <span>Stage</span>
                        <strong>{{ $latestJob->stage }}</strong>
                    </div>
                    <div>
                        <span>Minimum</span>
                        <strong>{{ number_format($latestJob->minimum_count) }}</strong>
                    </div>
                    <div>
                        <span>Target</span>
                        <strong>{{ number_format($latestJob->target_count) }}</strong>
                    </div>
                    <div>
                        <span>Max Pages</span>
                        <strong>{{ number_format($latestJob->max_pages) }}</strong>
                    </div>
                    <div>
                        <span>PID</span>
                        <strong>{{ $latestJob->process_id ?: '—' }}</strong>
                    </div>
                </div>

                <div class="xrv-progress-wrap">
                    <div class="xrv-progress-label">
                        <span>
                            {{ number_format($progressCurrent) }} /
                            {{ number_format($progressTotal) }}
                        </span>
                        <span>{{ $progressPercent }}%</span>
                    </div>
                    <div class="xrv-progress-track">
                        <div
                            class="xrv-progress-bar"
                            style="width: {{ $progressPercent }}%"
                        ></div>
                    </div>
                </div>

                @if($latestJob->message)
                    <div class="xrv-message">
                        {{ $latestJob->message }}
                    </div>
                @endif

                @if(is_array($latestJob->result_summary) && $latestJob->result_summary !== [])
                    <div class="xrv-summary-grid">
                        @foreach($latestJob->result_summary as $key => $value)
                            <div>
                                <span>{{ str_replace('_', ' ', $key) }}</span>
                                <strong>
                                    {{ is_numeric($value)
                                        ? number_format((int) $value)
                                        : $value }}
                                </strong>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="xrv-import-card">
                <div class="xrv-import-header">
                    <div>
                        <div class="xrv-kicker">LIVE LOG</div>
                        <h2>Latest output</h2>
                    </div>
                </div>

                <pre class="xrv-log">{{ $logTail !== '' ? $logTail : 'Waiting for process output...' }}</pre>
            </section>
        @endif

        <section class="xrv-import-card">
            <div class="xrv-import-header">
                <div>
                    <div class="xrv-kicker">HISTORY</div>
                    <h2>Recent jobs</h2>
                </div>
            </div>

            <div class="xrv-table-wrap">
                <table class="xrv-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Source</th>
                            <th>Category</th>
                            <th>Mode</th>
                            <th>Status</th>
                            <th>Stage</th>
                            <th>Minimum</th>
                            <th>Target</th>
                            <th>Started</th>
                            <th>Finished</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentJobs as $job)
                            <tr>
                                <td>#{{ $job->id }}</td>
                                <td>{{ strtoupper($job->source) }}</td>
                                <td>{{ $job->category_slug }}</td>
                                <td>{{ $job->mode }}</td>
                                <td>{{ strtoupper($job->status) }}</td>
                                <td>{{ $job->stage }}</td>
                                <td>{{ number_format($job->minimum_count) }}</td>
                                <td>{{ number_format($job->target_count) }}</td>
                                <td>
                                    {{ $job->started_at
                                        ? $job->started_at->format('Y-m-d H:i:s')
                                        : '—' }}
                                </td>
                                <td>
                                    {{ $job->finished_at
                                        ? $job->finished_at->format('Y-m-d H:i:s')
                                        : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10">No Video Import Center jobs yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <style>
        .xrv-import-card {
            display: grid;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid rgba(128, 128, 128, 0.22);
            border-radius: 0.85rem;
            background: rgba(128, 128, 128, 0.04);
        }

        .xrv-import-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .xrv-import-header h2 {
            margin: 0.15rem 0 0;
            font-size: 1rem;
            font-weight: 700;
        }

        .xrv-kicker {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            opacity: 0.65;
        }

        .xrv-status {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.6rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 800;
            border: 1px solid rgba(128, 128, 128, 0.28);
        }

        .xrv-status-pass {
            border-color: rgba(34, 197, 94, 0.55);
            background: rgba(34, 197, 94, 0.1);
        }

        .xrv-status-fail {
            border-color: rgba(239, 68, 68, 0.55);
            background: rgba(239, 68, 68, 0.1);
        }

        .xrv-status-running {
            border-color: rgba(245, 158, 11, 0.55);
            background: rgba(245, 158, 11, 0.1);
        }

        .xrv-meta-grid,
        .xrv-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.75rem;
        }

        .xrv-meta-grid > div,
        .xrv-summary-grid > div {
            display: grid;
            gap: 0.2rem;
            padding: 0.75rem;
            border-radius: 0.65rem;
            background: rgba(128, 128, 128, 0.06);
        }

        .xrv-meta-grid span,
        .xrv-summary-grid span {
            font-size: 0.7rem;
            text-transform: uppercase;
            opacity: 0.65;
        }

        .xrv-meta-grid strong,
        .xrv-summary-grid strong {
            font-size: 0.88rem;
            overflow-wrap: anywhere;
        }

        .xrv-progress-wrap {
            display: grid;
            gap: 0.35rem;
        }

        .xrv-progress-label {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .xrv-progress-track {
            height: 0.65rem;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(128, 128, 128, 0.18);
        }

        .xrv-progress-bar {
            height: 100%;
            border-radius: inherit;
            background: rgb(245, 158, 11);
            transition: width 0.25s ease;
        }

        .xrv-message {
            padding: 0.75rem;
            border-radius: 0.65rem;
            background: rgba(245, 158, 11, 0.07);
            font-size: 0.82rem;
            overflow-wrap: anywhere;
        }

        .xrv-log {
            max-height: 28rem;
            overflow: auto;
            margin: 0;
            padding: 0.9rem;
            border-radius: 0.7rem;
            background: rgba(0, 0, 0, 0.88);
            color: #f3f4f6;
            font-size: 0.75rem;
            line-height: 1.5;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .xrv-table-wrap {
            overflow-x: auto;
            border: 1px solid rgba(128, 128, 128, 0.18);
            border-radius: 0.7rem;
        }

        .xrv-table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
            font-size: 0.78rem;
        }

        .xrv-table th,
        .xrv-table td {
            padding: 0.65rem 0.75rem;
            text-align: left;
            border-bottom: 1px solid rgba(128, 128, 128, 0.13);
            white-space: nowrap;
        }

        .xrv-table th {
            font-size: 0.68rem;
            text-transform: uppercase;
            opacity: 0.68;
        }

        .xrv-table tbody tr:last-child td {
            border-bottom: 0;
        }
    </style>
</x-filament-panels::page>
