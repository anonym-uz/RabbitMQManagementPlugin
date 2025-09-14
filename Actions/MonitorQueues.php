<?php

namespace App\Vito\Plugins\AnonymUz\RabbitMQManagementPlugin\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Models\Server;
use App\ServerFeatures\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MonitorQueues extends Action
{
    public function __construct(public Server $server) {}

    public function name(): string
    {
        return 'Monitor Queues';
    }

    public function active(): bool
    {
        return false;
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('vhost')
                ->text()
                ->label('Virtual Host')
                ->description('The virtual host to monitor')
                ->default('/'),
            DynamicField::make('include_message_stats')
                ->checkbox()
                ->label('Include Message Statistics')
                ->description('Show ready and unacknowledged message counts')
                ->default(true),
            DynamicField::make('include_consumer_info')
                ->checkbox()
                ->label('Include Consumer Information')
                ->description('Show details about connected consumers')
                ->default(true),
        ]);
    }

    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'vhost' => 'required|string',
            'include_message_stats' => 'boolean',
            'include_consumer_info' => 'boolean',
        ])->validate();

        $vhost = $request->input('vhost', '/');
        $includeMessageStats = $request->input('include_message_stats', true);
        $includeConsumerInfo = $request->input('include_consumer_info', true);

        // Check if RabbitMQ is installed
        $rabbitmq = $this->server->messageQueue();
        if (!$rabbitmq) {
            throw new \Exception('RabbitMQ is not installed on this server');
        }

        // Get list of queues
        $queuesOutput = $this->server->ssh()->exec(
            "sudo rabbitmqctl list_queues -p {$vhost} name messages consumers memory state",
            'list-queues'
        );

        $queueInfo = [];
        $lines = explode("\n", trim($queuesOutput));
        array_shift($lines); // Remove header line

        if (empty($lines)) {
            $request->session()->flash('info', "No queues found in vhost '{$vhost}'");
            return;
        }

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 5) {
                $queueName = $parts[0];
                $messages = $parts[1];
                $consumers = $parts[2];
                $memory = $this->formatBytes((int)$parts[3]);
                $state = $parts[4];

                $queueData = [
                    'name' => $queueName,
                    'messages' => $messages,
                    'consumers' => $consumers,
                    'memory' => $memory,
                    'state' => $state,
                ];

                // Get detailed message statistics if requested
                if ($includeMessageStats && $messages > 0) {
                    $statsOutput = $this->server->ssh()->exec(
                        "sudo rabbitmqctl list_queues -p {$vhost} name messages_ready messages_unacknowledged | grep '^{$queueName}'",
                        'get-message-stats'
                    );

                    if (!empty($statsOutput)) {
                        $statsParts = preg_split('/\s+/', trim($statsOutput));
                        if (count($statsParts) >= 3) {
                            $queueData['ready'] = $statsParts[1];
                            $queueData['unacknowledged'] = $statsParts[2];
                        }
                    }
                }

                // Get consumer information if requested
                if ($includeConsumerInfo && $consumers > 0) {
                    $consumerOutput = $this->server->ssh()->exec(
                        "sudo rabbitmqctl list_consumers -p {$vhost} | grep '^{$queueName}'",
                        'get-consumer-info'
                    );

                    if (!empty($consumerOutput)) {
                        $queueData['consumer_details'] = trim($consumerOutput);
                    }
                }

                $queueInfo[] = $queueData;
            }
        }

        // Get overview statistics
        $overviewOutput = $this->server->ssh()->exec(
            "sudo rabbitmqctl list_vhosts name messages",
            'get-vhost-overview'
        );

        $totalMessages = '0';
        $overviewLines = explode("\n", trim($overviewOutput));
        foreach ($overviewLines as $overviewLine) {
            if (strpos($overviewLine, $vhost) !== false) {
                $parts = preg_split('/\s+/', trim($overviewLine));
                if (count($parts) >= 2) {
                    $totalMessages = $parts[1] ?? '0';
                }
                break;
            }
        }

        // Get node health status
        $nodeStats = $this->server->ssh()->exec(
            "sudo rabbitmqctl node_health_check",
            'node-health-check'
        );

        $healthStatus = strpos($nodeStats, 'Health check passed') !== false ? 'PASSED' : 'CHECK LOGS';

        // Store results in session for display
        $request->session()->flash('queue_monitor_results', [
            'vhost' => $vhost,
            'total_messages' => $totalMessages,
            'health_status' => $healthStatus,
            'queues' => $queueInfo,
        ]);

        $request->session()->flash('success', "Queue monitoring completed for vhost '{$vhost}'");
        $request->session()->flash('info', "Total messages: {$totalMessages} | Health check: {$healthStatus}");
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}