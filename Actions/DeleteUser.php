<?php

namespace App\Vito\Plugins\AnonymUz\RabbitMQManagementPlugin\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Models\Server;
use App\ServerFeatures\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeleteUser extends Action
{
    public function __construct(public Server $server) {}

    public function name(): string
    {
        return 'Delete User';
    }

    public function active(): bool
    {
        return false;
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('warning_alert')
                ->alert()
                ->options(['type' => 'warning'])
                ->description('This action will permanently delete the user and all their permissions.'),
            DynamicField::make('username')
                ->text()
                ->label('Username')
                ->description('The username of the user to delete')
                ->required(),
            DynamicField::make('confirm')
                ->checkbox()
                ->label('Confirm Deletion')
                ->description('I understand this action cannot be undone')
                ->required(),
        ]);
    }

    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'username' => 'required|string',
            'confirm' => 'required|accepted',
        ])->validate();

        $username = $request->input('username');

        // Check if RabbitMQ is installed
        $rabbitmq = $this->server->messageQueue();
        if (! $rabbitmq) {
            throw new \Exception('RabbitMQ is not installed on this server');
        }

        // Prevent deletion of the main admin user
        $adminUsername = $rabbitmq->type_data['username'] ?? '';
        if ($username === $adminUsername) {
            throw new \Exception('Cannot delete the main admin user');
        }

        // Delete the user
        $this->server->ssh()->exec(
            "sudo rabbitmqctl delete_user {$username}",
            'delete-user'
        );

        $request->session()->flash('success', "User '{$username}' deleted successfully");
        $request->session()->flash('info', 'All permissions and connections for this user have been removed');
    }
}
