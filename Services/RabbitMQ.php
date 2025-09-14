<?php

namespace App\Vito\Plugins\AnonymUz\RabbitMQManagementPlugin\Services;

use App\Services\AbstractService;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RabbitMQ extends AbstractService
{
    public static function id(): string
    {
        return 'rabbitmq';
    }

    public static function type(): string
    {
        return 'message_queue';
    }

    public function unit(): string
    {
        return 'rabbitmq-server';
    }

    public function version(): string
    {
        try {
            $version = $this->service->server->ssh()->exec(
                'sudo rabbitmqctl version | grep -oE \'[0-9]+\.[0-9]+\.[0-9]+\' | head -n 1'
            );
            return trim($version);
        } catch (\Exception $e) {
            return 'latest';
        }
    }

    public function creationRules(array $input): array
    {
        return [
            'type' => [
                function (string $attribute, mixed $value, Closure $fail): void {
                    $existingRabbitMQ = $this->service->server->services()
                        ->where('type', 'message_queue')
                        ->where('name', 'rabbitmq')
                        ->first();
                    if ($existingRabbitMQ) {
                        $fail('RabbitMQ is already installed on this server.');
                    }
                },
            ],
            'version' => [
                'required',
                Rule::in(['latest']),
            ],
        ];
    }

    public function creationData(array $input): array
    {
        return [
            'username' => 'admin',
            'password' => Str::random(16),
            'port' => 5672,
            'management_port' => 15672,
        ];
    }

    public function data(): array
    {
        return [
            'username' => $this->service->type_data['username'] ?? 'admin',
            'password' => $this->service->type_data['password'] ?? '',
            'port' => $this->service->type_data['port'] ?? 5672,
            'management_port' => $this->service->type_data['management_port'] ?? 15672,
        ];
    }

    public function install(): void
    {
        // Install Erlang (RabbitMQ dependency)
        $this->service->server->ssh()->exec(
            'curl -1sLf "https://packagecloud.io/rabbitmq/erlang/gpgkey" | sudo apt-key add -',
            'add-erlang-key'
        );

        $this->service->server->ssh()->exec(
            'sudo tee /etc/apt/sources.list.d/rabbitmq-erlang.list <<EOF
deb https://packagecloud.io/rabbitmq/erlang/ubuntu/ $(lsb_release -cs) main
EOF',
            'add-erlang-repo'
        );

        // Install RabbitMQ repository
        $this->service->server->ssh()->exec(
            'curl -1sLf "https://packagecloud.io/rabbitmq/rabbitmq-server/gpgkey" | sudo apt-key add -',
            'add-rabbitmq-key'
        );

        $this->service->server->ssh()->exec(
            'sudo tee /etc/apt/sources.list.d/rabbitmq.list <<EOF
deb https://packagecloud.io/rabbitmq/rabbitmq-server/ubuntu/ $(lsb_release -cs) main
EOF',
            'add-rabbitmq-repo'
        );

        // Update and install
        $this->service->server->ssh()->exec(
            'sudo apt-get update',
            'update-apt'
        );

        $this->service->server->ssh()->exec(
            'sudo apt-get install -y erlang-base erlang-asn1 erlang-crypto erlang-eldap erlang-ftp erlang-inets erlang-mnesia erlang-os-mon erlang-parsetools erlang-public-key erlang-runtime-tools erlang-snmp erlang-ssl erlang-syntax-tools erlang-tftp erlang-tools erlang-xmerl',
            'install-erlang'
        );

        $this->service->server->ssh()->exec(
            'sudo apt-get install -y rabbitmq-server',
            'install-rabbitmq'
        );

        // Enable and start RabbitMQ
        $this->service->server->ssh()->exec(
            'sudo systemctl enable rabbitmq-server',
            'enable-rabbitmq'
        );

        $this->service->server->ssh()->exec(
            'sudo systemctl start rabbitmq-server',
            'start-rabbitmq'
        );

        // Create admin user with generated password
        $username = $this->service->type_data['username'] ?? 'admin';
        $password = $this->service->type_data['password'] ?? Str::random(16);

        // Remove default guest user for security
        try {
            $this->service->server->ssh()->exec(
                'sudo rabbitmqctl delete_user guest',
                'delete-guest-user'
            );
        } catch (\Exception $e) {
            // Guest user might not exist
        }

        // Create admin user
        $this->service->server->ssh()->exec(
            "sudo rabbitmqctl add_user {$username} {$password}",
            'create-admin-user'
        );

        $this->service->server->ssh()->exec(
            "sudo rabbitmqctl set_user_tags {$username} administrator",
            'set-admin-tags'
        );

        $this->service->server->ssh()->exec(
            "sudo rabbitmqctl set_permissions -p / {$username} \".*\" \".*\" \".*\"",
            'set-admin-permissions'
        );

        // Update service with credentials
        $this->service->type_data = [
            'username' => $username,
            'password' => $password,
            'port' => 5672,
            'management_port' => 15672,
        ];
        $this->service->save();
    }

    public function uninstall(): void
    {
        // Stop and disable service
        $this->service->server->ssh()->exec(
            'sudo systemctl stop rabbitmq-server',
            'stop-rabbitmq'
        );

        $this->service->server->ssh()->exec(
            'sudo systemctl disable rabbitmq-server',
            'disable-rabbitmq'
        );

        // Uninstall packages
        $this->service->server->ssh()->exec(
            'sudo apt-get remove -y rabbitmq-server',
            'remove-rabbitmq'
        );

        $this->service->server->ssh()->exec(
            'sudo apt-get autoremove -y',
            'autoremove-packages'
        );

        // Remove configuration
        $this->service->server->ssh()->exec(
            'sudo rm -rf /etc/rabbitmq /var/lib/rabbitmq',
            'remove-rabbitmq-config'
        );
    }

    public function restart(): void
    {
        $this->service->server->ssh()->exec(
            'sudo systemctl restart rabbitmq-server',
            'restart-rabbitmq'
        );
    }

    public function stop(): void
    {
        $this->service->server->ssh()->exec(
            'sudo systemctl stop rabbitmq-server',
            'stop-rabbitmq'
        );
    }

    public function start(): void
    {
        $this->service->server->ssh()->exec(
            'sudo systemctl start rabbitmq-server',
            'start-rabbitmq'
        );
    }

    public function status(): string
    {
        try {
            $result = $this->service->server->ssh()->exec(
                'sudo systemctl is-active rabbitmq-server'
            );

            return trim($result) === 'active' ? 'running' : 'stopped';
        } catch (\Exception $e) {
            return 'stopped';
        }
    }

    public function isInstalled(): bool
    {
        try {
            $result = $this->service->server->ssh()->exec(
                'which rabbitmqctl'
            );

            return !empty(trim($result));
        } catch (\Exception $e) {
            return false;
        }
    }
}