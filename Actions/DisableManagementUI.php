<?php

namespace App\Vito\Plugins\AnonymUz\RabbitMQManagementPlugin\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Models\Server;
use App\ServerFeatures\Action;
use Illuminate\Http\Request;

class DisableManagementUI extends Action
{
    public function __construct(public Server $server) {}

    public function name(): string
    {
        return 'Disable Management UI';
    }

    public function active(): bool
    {
        return true;
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('warning_alert')
                ->alert()
                ->options(['type' => 'warning'])
                ->description('This will disable the RabbitMQ Management UI web interface on port 15672.'),
        ]);
    }

    public function handle(Request $request): void
    {
        // Check if RabbitMQ is installed
        $rabbitmq = $this->server->messageQueue();
        if (!$rabbitmq) {
            throw new \Exception('RabbitMQ is not installed on this server');
        }

        // Disable the management plugin
        $this->server->ssh()->exec(
            'sudo rabbitmq-plugins disable rabbitmq_management',
            'disable-rabbitmq-management'
        );

        // Remove firewall rule if it exists
        try {
            $this->server->ssh()->exec(
                'sudo ufw delete allow 15672/tcp',
                'close-management-port'
            );
        } catch (\Exception $e) {
            // UFW might not be enabled or rule might not exist
        }

        // Restart RabbitMQ to apply changes
        $this->server->ssh()->exec(
            'sudo systemctl restart rabbitmq-server',
            'restart-rabbitmq'
        );

        $request->session()->flash('success', 'RabbitMQ Management UI has been disabled');
    }
}
