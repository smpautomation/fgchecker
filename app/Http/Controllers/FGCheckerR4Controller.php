<?php

namespace App\Http\Controllers;

use App\Models\TabletRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FGCheckerR4Controller extends Controller
{
    /** Poll interval, in seconds. */
    protected const POLL_SECONDS = 1;

    /**
     * How many poll iterations before the stream closes on its own and lets
     * the browser's native EventSource reconnect. Keeps a single PHP-FPM
     * worker from being tied up indefinitely per connected tablet.
     */
    protected const MAX_ITERATIONS = 2; // ~5 minutes at 1s polls

    /**
     * Send a heartbeat comment this often (in poll iterations) even when no
     * new row arrives. Two reasons this matters:
     *   1. connection_aborted() is only updated by PHP when it attempts an
     *      output write — without periodic output, a disconnected client
     *      goes unnoticed until the loop's natural end (up to ~5 minutes).
     *   2. Reverse proxies (nginx's default proxy_read_timeout /
     *      fastcgi_read_timeout, often 60s) will silently kill a connection
     *      that's been silent that long.
     */
    protected const HEARTBEAT_EVERY = 15;

    /**
     * GET /r4-status-stream?tablet_id=...
     * Streams new r4_temp rows (light status) for the r4_name registered to
     * this tablet (TabletRecord.tablet_id -> r4_name -> r4_temp.r4_name).
     *
     * Uses the standard SSE "id:" field so the browser automatically sends
     * Last-Event-ID on reconnect — we resume from there rather than replaying
     * history or requiring a custom query param.
     */
    public function stream(Request $request): StreamedResponse
    {
        $tabletId = $request->query('tablet_id');
        $tablet = $tabletId ? TabletRecord::where('tablet_id', $tabletId)->first() : null;
        $r4Name = $tablet?->r4_name;

        $lastId = $this->resolveLastId($request);

        return response()->stream(function () use ($r4Name, $lastId) {
            // Disable any output buffering so events reach the client immediately.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            // Defensive: sleep() doesn't count against max_execution_time on
            // standard PHP-FPM, but this guards environments where it does.
            set_time_limit(0);

            if (! $r4Name) {
                $this->emit(0, ['error' => 'This tablet is not linked to an r4_name. Contact PIC.']);
                return;
            }

            $currentId = $lastId;

            for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
                if (connection_aborted()) {
                    break;
                }

                $rows = DB::table('r4_temp')
                    ->where('r4_name', $r4Name)
                    ->where('id', '>', $currentId)
                    ->orderBy('id')
                    ->get();

                if ($rows->isEmpty()) {
                    // No new data — still write *something* periodically so a
                    // dropped client is detected promptly and proxies don't
                    // time out the idle connection.
                    if ($i % self::HEARTBEAT_EVERY === 0) {
                        $this->heartbeat();
                    }
                } else {
                    foreach ($rows as $row) {
                        $this->emit($row->id, [
                            'light'    => strtoupper((string) $row->light),
                            'datetime' => $row->DateTime,
                        ]);
                        $currentId = $row->id;
                    }
                }

                sleep(self::POLL_SECONDS);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no', // disable nginx proxy buffering
            'Connection'        => 'keep-alive',
        ]);
    }

    /**
     * Resumes from the browser's Last-Event-ID header on reconnect. On a
     * fresh connection (no header), starts from the current max id so we
     * only stream *new* readings rather than replaying history.
     */
    protected function resolveLastId(Request $request): int
    {
        $lastEventId = $request->header('Last-Event-ID');
        if ($lastEventId !== null && is_numeric($lastEventId)) {
            return (int) $lastEventId;
        }

        return (int) (DB::table('r4_temp')->max('id') ?? 0);
    }

    protected function emit(int $id, array $data): void
    {
        echo "id: {$id}\n";
        echo 'data: ' . json_encode($data) . "\n\n";
        flush();
    }

    /**
     * SSE comment line (starts with ":") — valid per spec, ignored by
     * EventSource's onmessage, but still a real write PHP/proxies see.
     */
    protected function heartbeat(): void
    {
        echo ": heartbeat\n\n";
        flush();
    }
}
