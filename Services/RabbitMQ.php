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
        $ssh = $this->service->server->ssh();

        // Prereqs
        $ssh->exec('sudo apt-get update -y', 'apt-update');
        $ssh->exec('sudo apt-get install -y curl gnupg apt-transport-https lsb-release', 'install-prereqs');

        // Create keyring dir & add Team RabbitMQ signing key (no apt-key)
        $ssh->exec('sudo install -d -m 0755 /usr/share/keyrings', 'mkdir-keyrings');
        $ssh->exec(
            'curl -1sLf "https://keys.openpgp.org/vks/v1/by-fingerprint/0A9AF2115F4687BD29803A206B73A36E6026DFCA" ' .
            '| sudo gpg --dearmor | sudo tee /usr/share/keyrings/com.rabbitmq.team.gpg > /dev/null',
            'add-rabbitmq-signing-key'
        );

        // Add RabbitMQ + Erlang repos from Team RabbitMQ (deb1/deb2) with signed-by
        $ssh->exec(
        // pick ubuntu/debian automatically; use codename from lsb_release
            'sudo bash -lc \'dist=$(lsb_release -si | tr "[:upper:]" "[:lower:]"); ' .
            'codename=$(lsb_release -sc); ' .
            'sudo tee /etc/apt/sources.list.d/rabbitmq.list >/dev/null <<EOF
deb [signed-by=/usr/share/keyrings/com.rabbitmq.team.gpg] https://deb1.rabbitmq.com/rabbitmq-erlang/${dist}/${codename} ${codename} main
deb [signed-by=/usr/share/keyrings/com.rabbitmq.team.gpg] https://deb2.rabbitmq.com/rabbitmq-erlang/${dist}/${codename} ${codename} main
deb [signed-by=/usr/share/keyrings/com.rabbitmq.team.gpg] https://deb1.rabbitmq.com/rabbitmq-server/${dist}/${codename} ${codename} main
deb [signed-by=/usr/share/keyrings/com.rabbitmq.team.gpg] https://deb2.rabbitmq.com/rabbitmq-server/${dist}/${codename} ${codename} main
EOF\'',
            'add-rmq-repos'
        );

        // Update & install Erlang + RabbitMQ
        $ssh->exec('sudo apt-get update -y', 'update-apt');
        $ssh->exec(
            'sudo apt-get install -y erlang-base erlang-asn1 erlang-crypto erlang-eldap erlang-ftp erlang-inets ' .
            'erlang-mnesia erlang-os-mon erlang-parsetools erlang-public-key erlang-runtime-tools erlang-snmp ' .
            'erlang-ssl erlang-syntax-tools erlang-tftp erlang-tools erlang-xmerl',
            'install-erlang'
        );
        $ssh->exec('sudo apt-get install -y rabbitmq-server --fix-missing', 'install-rabbitmq');

        // Enable & start service, wait until it's fully up
        $ssh->exec('sudo systemctl enable --now rabbitmq-server', 'enable-start-rabbitmq');
        $ssh->exec('sudo rabbitmqctl await_startup -t 60', 'await-startup');

        // Enable Management plugin for :15672
        $ssh->exec('sudo rabbitmq-plugins enable rabbitmq_management', 'enable-management');

        // Credentials
        $username = $this->service->type_data['username'] ?? 'admin';
        $password = $this->service->type_data['password'] ?? \Illuminate\Support\Str::random(16);
        $u = escapeshellarg($username);
        $p = escapeshellarg($password);

        // Remove default 'guest' user (ignore if missing)
        try {
            $ssh->exec('sudo rabbitmqctl delete_user guest', 'delete-guest-user');
        } catch (\Exception $e) {}

        // Create admin user; if it exists, just reset the password
        try {
            $ssh->exec("sudo rabbitmqctl add_user {$u} {$p}", 'create-admin-user');
        } catch (\Exception $e) {
            $ssh->exec("sudo rabbitmqctl change_password {$u} {$p}", 'change-admin-password');
        }
        $ssh->exec("sudo rabbitmqctl set_user_tags {$u} administrator", 'set-admin-tags');
        $ssh->exec("sudo rabbitmqctl set_permissions -p / {$u} \".*\" \".*\" \".*\"", 'set-admin-permissions');

        // Persist connection info
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
        $ssh = $this->service->server->ssh();

        // Stop/disable
        $ssh->exec('sudo systemctl stop rabbitmq-server', 'stop-rabbitmq');
        $ssh->exec('sudo systemctl disable rabbitmq-server', 'disable-rabbitmq');

        // Remove packages
        $ssh->exec('sudo apt-get purge -y rabbitmq-server', 'purge-rabbitmq');
        $ssh->exec('sudo apt-get autoremove -y', 'autoremove');

        // Remove data/config
        $ssh->exec('sudo rm -rf /etc/rabbitmq /var/lib/rabbitmq', 'remove-rabbitmq-config');

        // Clean up repo + key (optional, but keeps apt tidy)
        $ssh->exec('sudo rm -f /etc/apt/sources.list.d/rabbitmq.list', 'remove-rmq-repo');
        $ssh->exec('sudo rm -f /usr/share/keyrings/com.rabbitmq.team.gpg', 'remove-rmq-key');
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
