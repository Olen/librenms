<?php

namespace App\Console\Commands;

use App\Actions\Device\ValidateDeviceAndCreate;
use App\Console\LnmsCommand;
use App\Models\Device;
use App\Models\PollerGroup;
use Exception;
use Illuminate\Validation\Rule;
use LibreNMS\Config;
use LibreNMS\Enum\PortAssociationMode;
use LibreNMS\Exceptions\HostExistsException;
use LibreNMS\Exceptions\HostnameExistsException;
use LibreNMS\Exceptions\HostUnreachableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class DeviceAdd extends LnmsCommand
{
    /**
     * The name of the console command.
     *
     * @var string
     */
    protected $name = 'device:add';
    /**
     * Valid values for options
     *
     * @var string[][]|null
     */
    protected $optionValues;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->optionValues = [
            'transport' => ['udp', 'udp6', 'tcp', 'tcp6'],
            'port-association-mode' => PortAssociationMode::getModes(),
            'auth-protocol' => \LibreNMS\SNMPCapabilities::supportedAuthAlgorithms(),
            'privacy-protocol' => \LibreNMS\SNMPCapabilities::supportedCryptoAlgorithms(),
        ];

        $this->addArgument('device spec', InputArgument::REQUIRED);
        $this->addOption('v1', null, InputOption::VALUE_NONE);
        $this->addOption('v2c', null, InputOption::VALUE_NONE);
        $this->addOption('v3', null, InputOption::VALUE_NONE);
        $this->addOption('display-name', 'd', InputOption::VALUE_REQUIRED);
        $this->addOption('force', 'f', InputOption::VALUE_NONE);
        $this->addOption('poller-group', 'g', InputOption::VALUE_REQUIRED, null, Config::get('default_poller_group'));
        $this->addOption('ping-fallback', 'b', InputOption::VALUE_NONE);
        $this->addOption('port-association-mode', 'p', InputOption::VALUE_REQUIRED, null, Config::get('default_port_association_mode'));
        $this->addOption('community', 'c', InputOption::VALUE_REQUIRED);
        $this->addOption('transport', 't', InputOption::VALUE_REQUIRED, null, 'udp');
        $this->addOption('port', 'r', InputOption::VALUE_REQUIRED, null, 161);
        $this->addOption('security-name', 'u', InputOption::VALUE_REQUIRED, null, 'root');
        $this->addOption('auth-password', 'A', InputOption::VALUE_REQUIRED);
        $this->addOption('auth-protocol', 'a', InputOption::VALUE_REQUIRED, null, 'MD5');
        $this->addOption('privacy-password', 'X', InputOption::VALUE_REQUIRED);
        $this->addOption('privacy-protocol', 'x', InputOption::VALUE_REQUIRED, null, 'AES');
        $this->addOption('ping-only', 'P', InputOption::VALUE_NONE);
        $this->addOption('os', 'o', InputOption::VALUE_REQUIRED, null, 'ping');
        $this->addOption('hardware', 'w', InputOption::VALUE_REQUIRED);
        $this->addOption('sysName', 's', InputOption::VALUE_REQUIRED);
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->configureOutputOptions();

        $this->validate([
            'port' => 'numeric|between:1,65535',
            'poller-group' => ['numeric', Rule::in(PollerGroup::pluck('id')->prepend(0))],
        ]);

        $auth = $this->option('auth-password');
        $priv = $this->option('privacy-password');
        $device = new Device([
            'hostname' => $this->argument('device spec'),
            'display' => $this->option('display-name'),
            'snmpver' => $this->option('v3') ? 'v3' : ($this->option('v2c') ? 'v2c' : ($this->option('v1') ? 'v1' : '')),
            'port' => $this->option('port'),
            'transport' => $this->option('transport'),
            'poller_group' => $this->option('poller-group'),
            'port_association_mode' => PortAssociationMode::getId($this->option('port-association-mode')),
            'community' => $this->option('community'),
            'authlevel'  => ($auth ? 'auth' : 'noAuth') . (($priv && $auth) ? 'Priv' : 'NoPriv'),
            'authname'   => $this->option('security-name'),
            'authpass'   => $this->option('auth-password'),
            'authalgo'   => $this->option('auth-protocol'),
            'cryptopass' => $this->option('privacy-password'),
            'cryptoalgo' => $this->option('privacy-protocol'),
        ]);

        if ($this->option('ping-only')) {
            $device->snmp_disable = 1;
            $device->os = $this->option('os');
            $device->hardware = $this->option('hardware');
            $device->sysName = $this->option('sysName');
        }

        try {
            $result = (new ValidateDeviceAndCreate($device, $this->option('force'), $this->option('ping-fallback')))->execute();

            if (! $result) {
                $this->error(trans('commands.device:add.messages.save_failed', ['hostname' => $device->hostname]));

                return 4;
            }

            $this->info(trans('commands.device:add.messages.added', ['hostname' => $device->hostname, 'device_id' => $device->device_id]));

            return 0;
        } catch (HostUnreachableException $e) {
            // host unreachable errors
            $this->error($e->getMessage() . PHP_EOL . implode(PHP_EOL, $e->getReasons()));
            $this->line(trans('commands.device:add.messages.try_force'));

            return 1;
        } catch (HostExistsException $e) {
            // host exists errors
            $this->error($e->getMessage());

            if (! $e instanceof HostnameExistsException) {
                $this->line(trans('commands.device:add.messages.try_force'));
            }

            return 2;
        } catch (Exception $e) {
            // other errors?
            $this->error(get_class($e) . ': ' . $e->getMessage());

            return 3;
        }
    }
}
