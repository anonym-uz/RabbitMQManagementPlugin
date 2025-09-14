<?php

namespace App\Vito\Plugins\AnonymUz\RabbitMQManagementPlugin\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Models\Server;
use App\ServerFeatures\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EnableManagementUI extends Action
{
    public function __construct(public Server $server) {}

    public function name(): string
    {
        return 'Enable Management UI';
    }

    public function active(): bool
    {
        // Check if management UI is already enabled
        try {
            $result = $this->server->ssh()->exec('sudo rabbitmq-plugins list -e | grep rabbitmq_management');

            return str_contains($result, 'rabbitmq_management');
        } catch (\Exception $e) {
            return false;
        }
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('info_alert')
                ->alert()
                ->options(['type' => 'info'])
                ->description('This will enable the RabbitMQ Management UI web interface on port 15672.'),
            DynamicField::make('bind_address')
                ->text()
                ->label('Bind Address')
                ->description('IP address to bind the management interface to')
                ->default('0.0.0.0'),
            DynamicField::make('create_admin_user')
                ->checkbox()
                ->label('Create Admin User')
                ->description('Create a new admin user for the management UI')
                ->default(true),
            DynamicField::make('admin_username')
                ->text()
                ->label('Admin Username')
                ->description('Username for the admin user')
                ->default('admin'),
            DynamicField::make('admin_password')
                ->password()
                ->label('Admin Password')
                ->description('Password for the admin user (leave empty to generate)')
                ->default(Str::random(16)),
        ]);
    }

    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'bind_address' => 'required|ip',
            'create_admin_user' => 'boolean',
            'admin_username' => 'required_if:create_admin_user,true|string',
            'admin_password' => 'nullable|string|min:8',
        ])->validate();

        $bindAddress = $request->input('bind_address', '0.0.0.0');
        $createAdmin = $request->input('create_admin_user', true);
        $adminUsername = $request->input('admin_username', 'admin');
        $adminPassword = $request->input('admin_password') ?: Str::random(16);

        // Check if RabbitMQ is installed
        $rabbitmq = $this->server->messageQueue();
        if (! $rabbitmq) {
            throw new \Exception('RabbitMQ is not installed on this server');
        }

        // Enable the management plugin
        $this->server->ssh()->exec(
            'sudo rabbitmq-plugins enable rabbitmq_management',
            'enable-rabbitmq-management'
        );

        // Configure the management interface bind address
        $config = <<<EOT
listeners.tcp.default = 5672
management.tcp.port = 15672
management.tcp.ip = {$bindAddress}
EOT;

        $this->server->ssh()->exec(
            "echo '{$config}' | sudo tee /etc/rabbitmq/rabbitmq.conf",
            'configure-rabbitmq-management'
        );

        // Create admin user if requested
        if ($createAdmin) {
            $this->server->ssh()->exec(
                "sudo rabbitmqctl add_user {$adminUsername} {$adminPassword}",
                'create-admin-user'
            );

            $this->server->ssh()->exec(
                "sudo rabbitmqctl set_user_tags {$adminUsername} administrator",
                'set-admin-tags'
            );

            $this->server->ssh()->exec(
                "sudo rabbitmqctl set_permissions -p / {$adminUsername} \".*\" \".*\" \".*\"",
                'set-admin-permissions'
            );

            $request->session()->flash('info', "Admin user '{$adminUsername}' created with password: {$adminPassword}");
        }

        // Restart RabbitMQ to apply changes
        $this->server->ssh()->exec(
            'sudo systemctl restart rabbitmq-server',
            'restart-rabbitmq'
        );

        // Open firewall port if UFW is enabled
        try {
            $this->server->ssh()->exec(
                'sudo ufw allow 15672/tcp',
                'open-management-port'
            );
        } catch (\Exception $e) {
            // UFW might not be enabled, that's okay
        }

        $request->session()->flash('success', 'RabbitMQ Management UI enabled on port 15672');
        $request->session()->flash('info', "Access the UI at: http://{$this->server->ip}:15672");
    }
}
