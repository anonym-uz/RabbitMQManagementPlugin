<?php

namespace App\Vito\Plugins\AnonymUz\RabbitMQManagementPlugin\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Models\Server;
use App\ServerFeatures\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateUser extends Action
{
    public function __construct(public Server $server) {}

    public function name(): string
    {
        return 'Create User';
    }

    public function active(): bool
    {
        return true;
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('username')
                ->text()
                ->label('Username')
                ->description('The username for the new RabbitMQ user')
                ->required(),
            DynamicField::make('password')
                ->password()
                ->label('Password')
                ->description('The password for the new user (min 8 characters)')
                ->default(Str::random(16))
                ->required(),
            DynamicField::make('tags')
                ->text()
                ->label('User Tags')
                ->description('Comma-separated tags (e.g., management, administrator)')
                ->default('management'),
            DynamicField::make('vhost')
                ->text()
                ->label('Virtual Host')
                ->description('The virtual host to grant permissions on')
                ->default('/'),
            DynamicField::make('configure_permission')
                ->text()
                ->label('Configure Permission')
                ->description('Regex pattern for configure permission')
                ->default('.*'),
            DynamicField::make('write_permission')
                ->text()
                ->label('Write Permission')
                ->description('Regex pattern for write permission')
                ->default('.*'),
            DynamicField::make('read_permission')
                ->text()
                ->label('Read Permission')
                ->description('Regex pattern for read permission')
                ->default('.*'),
        ]);
    }

    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string|min:8',
            'tags' => 'nullable|string',
            'vhost' => 'required|string',
            'configure_permission' => 'required|string',
            'write_permission' => 'required|string',
            'read_permission' => 'required|string',
        ])->validate();

        $username = $request->input('username');
        $password = $request->input('password');
        $tags = $request->input('tags', 'management');
        $vhost = $request->input('vhost', '/');
        $configurePermission = $request->input('configure_permission', '.*');
        $writePermission = $request->input('write_permission', '.*');
        $readPermission = $request->input('read_permission', '.*');

        // Check if RabbitMQ is installed
        $rabbitmq = $this->server->messageQueue();
        if (!$rabbitmq) {
            throw new \Exception('RabbitMQ is not installed on this server');
        }

        // Create the user
        $this->server->ssh()->exec(
            "sudo rabbitmqctl add_user {$username} {$password}",
            'create-user'
        );

        // Set user tags
        $this->server->ssh()->exec(
            "sudo rabbitmqctl set_user_tags {$username} {$tags}",
            'set-user-tags'
        );

        // Set permissions on the specified vhost
        $this->server->ssh()->exec(
            "sudo rabbitmqctl set_permissions -p {$vhost} {$username} \"{$configurePermission}\" \"{$writePermission}\" \"{$readPermission}\"",
            'set-user-permissions'
        );

        $request->session()->flash('success', "User '{$username}' created successfully with permissions on vhost '{$vhost}'");
    }
}
