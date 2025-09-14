<?php

namespace App\Vito\Plugins\AnonymUz\RabbitMQManagementPlugin\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Models\Server;
use App\ServerFeatures\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CreateVirtualHost extends Action
{
    public function __construct(public Server $server) {}

    public function name(): string
    {
        return 'Create Virtual Host';
    }

    public function active(): bool
    {
        return false;
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('vhost_name')
                ->text()
                ->label('Virtual Host Name')
                ->description('The name of the virtual host to create')
                ->required(),
            DynamicField::make('description')
                ->text()
                ->label('Description')
                ->description('Optional description for the virtual host'),
            DynamicField::make('tags')
                ->text()
                ->label('Tags')
                ->description('Optional tags for the virtual host'),
            DynamicField::make('grant_permissions')
                ->checkbox()
                ->label('Grant Admin Permissions')
                ->description('Grant full permissions to the admin user on this vhost')
                ->default(true),
        ]);
    }

    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'vhost_name' => 'required|string',
            'description' => 'nullable|string',
            'tags' => 'nullable|string',
            'grant_permissions' => 'boolean',
        ])->validate();

        $vhostName = $request->input('vhost_name');
        $description = $request->input('description', '');
        $tags = $request->input('tags', '');
        $grantPermissions = $request->input('grant_permissions', true);

        // Check if RabbitMQ is installed
        $rabbitmq = $this->server->messageQueue();
        if (!$rabbitmq) {
            throw new \Exception('RabbitMQ is not installed on this server');
        }

        // Create the virtual host
        $this->server->ssh()->exec(
            "sudo rabbitmqctl add_vhost {$vhostName}",
            'create-vhost'
        );

        // Set description if provided
        if (!empty($description)) {
            $this->server->ssh()->exec(
                "sudo rabbitmqctl set_vhost_metadata {$vhostName} description \"{$description}\"",
                'set-vhost-description'
            );
        }

        // Set tags if provided
        if (!empty($tags)) {
            $this->server->ssh()->exec(
                "sudo rabbitmqctl set_vhost_metadata {$vhostName} tags \"{$tags}\"",
                'set-vhost-tags'
            );
        }

        // Grant permissions to admin user if requested
        if ($grantPermissions) {
            // Get the admin username from RabbitMQ service data
            $adminUsername = $rabbitmq->type_data['username'] ?? 'guest';

            $this->server->ssh()->exec(
                "sudo rabbitmqctl set_permissions -p {$vhostName} {$adminUsername} \".*\" \".*\" \".*\"",
                'grant-admin-permissions'
            );

            $request->session()->flash('info', "Granted full permissions to user '{$adminUsername}' on vhost '{$vhostName}'");
        }

        $request->session()->flash('success', "Virtual host '{$vhostName}' created successfully");
    }
}