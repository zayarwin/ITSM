<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Services\AnthropicClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class InvestigateIncident implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(public int $incidentId)
    {
    }

    public function handle(AnthropicClient $anthropic): void
    {
        $incident = Incident::with('device')->find($this->incidentId);

        if (! $incident) {
            return;
        }

        $incident->update(['status' => 'analyzing']);

        try {
            $diagnostics = $this->gatherDiagnostics($incident);
            $incident->update(['diagnostic_context' => $diagnostics]);

            $analysis = $anthropic->analyze(
                $this->systemPrompt(),
                $this->userPrompt($incident, $diagnostics)
            );

            $incident->update([
                'ai_analysis' => $analysis,
                'status' => 'analyzed',
            ]);
        } catch (Throwable $e) {
            Log::error('InvestigateIncident failed', ['incident_id' => $incident->id, 'error' => $e->getMessage()]);
            $incident->update([
                'ai_analysis' => 'Automated investigation failed: '.$e->getMessage(),
                'status' => 'failed',
            ]);
        }
    }

    // Extra, facility-specific commands layered on top of the general baseline below.
    // Unrecognized facilities (hardware, security, environment, etc.) just get the
    // baseline — the AI can still interpret the raw syslog message without them.
    private function extraCommandsFor(string $facility): array
    {
        return match (strtoupper($facility)) {
            'OSPF' => [
                'show ip ospf neighbor',
                'show ip ospf interface brief',
                'show run | section router ospf',
            ],
            'BGP' => [
                'show ip bgp summary',
                'show run | section router bgp',
            ],
            'LINEPROTO', 'LINK' => [
                'show interfaces',
            ],
            default => [],
        };
    }

    private function gatherDiagnostics(Incident $incident): string
    {
        $device = $incident->device;
        $middlewareUrl = rtrim(env('MIDDLEWARE_URL', 'http://127.0.0.1:8001'), '/');

        // "show logging" is always useful general context regardless of facility. No output
        // filter here — piping "show logging" errored out on this IOS build during testing.
        $commands = array_merge(
            ['show ip interface brief', 'show logging'],
            $this->extraCommandsFor($incident->facility)
        );

        try {
            // Telnet, not SSH: these lab devices only have Telnet configured (no SSH server),
            // unlike the SSH-based /run-command endpoint used elsewhere in the app.
            $response = Http::timeout(60)->post("{$middlewareUrl}/telnet/run-commands", [
                'host' => $device->ip_address,
                'username' => $device->username,
                'password' => $device->password,
                'commands' => $commands,
            ]);

            if (! $response->successful()) {
                return 'Failed to collect diagnostics over Telnet: '.$response->body();
            }

            $results = $response->json('results', []);
        } catch (Throwable $e) {
            return 'Failed to collect diagnostics over Telnet: '.$e->getMessage();
        }

        $sections = [];
        foreach ($commands as $command) {
            $output = $results[$command] ?? '(no output)';
            $sections[] = "\$ {$command}\n{$output}";
        }

        return implode("\n\n", $sections);
    }

    private function systemPrompt(): string
    {
        return <<<'EOT'
You are a senior network engineer investigating an alert raised from a Cisco IOS device's
syslog output. It could be a routing protocol change (OSPF/BGP), an interface flap, a
hardware/environmental warning, a security/authentication event, or anything else IOS
considered significant — the facility and mnemonic tell you what kind of message it is, but
you should read the raw message itself to understand exactly what happened, since you are
not limited to a fixed set of known message types. You are given that raw syslog message
plus live diagnostic output pulled from the device (when it was reachable).

Respond with a concise incident report in plain text (no markdown headers) covering:
1. What happened (one sentence, in plain language, based on the actual message).
2. Likely root cause, reasoned from the diagnostic output provided.
3. Concrete recommended next steps or commands to run/verify.

Be direct and specific. If the diagnostic output doesn't clearly show a root cause, say so
and list the most likely candidates instead of guessing with false confidence.
EOT;
    }

    private function userPrompt(Incident $incident, string $diagnostics): string
    {
        $device = $incident->device;
        $stateLine = $incident->new_state
            ? "State change: {$incident->previous_state} -> {$incident->new_state}"
            : 'State change: not applicable to this message type';

        return <<<EOT
Device: {$device->hostname} ({$device->ip_address}), model {$device->model}
Facility: {$incident->facility}
Severity: {$incident->severity}
Mnemonic: {$incident->mnemonic}
{$stateLine}
Detected at: {$incident->detected_at}

Raw syslog message:
{$incident->raw_message}

Diagnostic output collected from the device immediately after detection:
{$diagnostics}
EOT;
    }
}
