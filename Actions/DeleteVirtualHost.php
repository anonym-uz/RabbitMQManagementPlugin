<?php

namespace App\Vito\Plugins\AnonymUz\RabbitMQManagementPlugin\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Models\Server;
use App\ServerFeatures\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeleteVirtualHost extends Action
{
    public function __construct(public Server $server) {}

    public function name(): string
    {
        return 'Delete Virtual Host';
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
                ->options(['type' => 'danger'])
                ->description('This action will permanently delete the virtual host and ALL queues, exchanges, and bindings within it!'),
            DynamicField::make('vhost_name')
                ->text()
                ->label('Virtual Host Name')
                ->description('The name of the virtual host to delete')
                ->required(),
            DynamicField::make('confirm')
                ->checkbox()
                ->label('Confirm Deletion')
                ->description('I understand all data in this vhost will be permanently deleted')
                ->required(),
        ]);
    }

    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'vhost_name' => 'required|string',
            'confirm' => 'required|accepted',
        ])->validate();

        $vhostName = $request->input('vhost_name');

        // Prevent deletion of default vhost
        if ($vhostName === '/') {
            throw new \Exception('Cannot delete the default virtual host');
        }

        // Check if RabbitMQ is installed
        $rabbitmq = $this->server->messageQueue();
        if (!$rabbitmq) {
            throw new \Exception('RabbitMQ is not installed on this server');
        }

        // Delete the virtual host
        $this->server->ssh()->exec(
            "sudo rabbitmqctl delete_vhost {$vhostName}",
            'delete-vhost'
        );

        $request->session()->flash('success', "Virtual host '{$vhostName}' deleted successfully");
        $request->session()->flash('warning', 'All queues, exchanges, and bindings in this vhost have been removed');
    }
}
