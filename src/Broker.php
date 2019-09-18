<?php
/**
 * Created by PhpStorm.
 * User: Manlin
 * Date: 2019/8/17
 * Time: 下午10:59
 */
namespace EasySwoole\Kafka;

use EasySwoole\Component\Singleton;
use EasySwoole\Kafka\Sasl\Plain;
use EasySwoole\Log\Logger;

class Broker
{
    use Singleton;

    /**
     * @var array
     */
    private $topics = [];

    /**
     * @var array
     */
    private $brokers = [];

    /**
     * @var Config
     */
    private $config;

    private $process;

    /**
     * @var Logger
     */
    private $logger;
    /**
     * @return mixed
     */
    public function getProcess()
    {
        return $this->process;
    }

    /**
     * @param mixed $process
     */
    public function setProcess($process): void
    {
        $this->process = $process;
    }

    /**
     * @return array
     */
    public function getTopics(): array
    {
        return $this->topics;
    }

    /**
     * @return array
     */
    public function getBrokers(): array
    {
        return $this->brokers;
    }

    /**
     * @return mixed
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param mixed $config
     */
    public function setConfig($config): void
    {
        $this->config = $config;
    }

    /**
     * @return mixed
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param mixed $logger
     */
    public function setLogger($logger): void
    {
        $this->logger = $logger;
    }

    public function setData(array $topics, array $brokersResult): bool
    {
        $brokers = [];

        foreach ($brokersResult as $value) {
            $brokers[$value['nodeId']] = $value['host'] . ':' . $value['port'];
        }

        $changed = false;

        // brokers发送前后是否改变，并存最新的brokers
        if (serialize($this->brokers) !== serialize($brokers)) {
            $this->brokers = $brokers;

            $changed = true;
        }

        $newTopics = [];
        foreach ($topics as $topic) {
            if ((int) $topic['errorCode'] !== Protocol::NO_ERROR) {
                $this->logger->log('Parse metadata for topic is error, error:'
                    . Protocol::getError($topic['errorCode']));
                continue;
            }

            $item = [];

            foreach ($topic['partitions'] as $part) {
                $item[$part['partitionId']] = $part['leader'];
            }

            $newTopics[$topic['topicName']] = $item;
        }

        // topics 发送前后是否改变，并存最新的topics
        if (serialize($this->topics) !== serialize($newTopics)) {
            $this->topics = $newTopics;

            $changed = true;
        }
        return $changed;
    }

    /**
     * 获取meta链接
     * @param string $key
     * @param bool   $modeSync
     * @return Client|null
     */
    public function getMetaConnect(string $key, bool $modeSync = false): ?Client
    {
        return $this->getConnect($key, 'metaClients', $modeSync);
    }

    /**
     * 获取data链接
     * @param string $key
     * @param bool   $modeSync
     * @return Client|null
     */
    public function getDataConnect(string $key, bool $modeSync = false): ?Client
    {
        return $this->getConnect($key, 'dataClients', $modeSync);
    }

    /**
     * @param string $key
     * @param string $type
     * @param bool   $modeSync
     * @return Client|null
     */
    public function getConnect(string $key, string $type, bool $modeSync = false): ?Client
    {
        // 如果之前连接了，返回之前的连接
        if (isset($this->{$type}[$key])) {
            return $this->{$type}[$key];
        }

        if (isset($this->brokers[$key])) {
            $hostname = $this->brokers[$key];
            if (isset($this->$type[$hostname])) {
                return $this->$type[$hostname];
            }
        }

        $host = null;
        $port = null;

        if (isset($this->brokers[$key])) {
            $hostname = $this->brokers[$key];

            [$host, $port] = explode(':', $hostname);
        }

        if (strpos($key, ':') !== false) {
            [$host, $port] = explode(':', $key);
        }

        if ($host === null || $port === null || (! $modeSync && $this->process === null)) {
            return null;
        }

        try {
            $client = $this->getClient((string)$host, (int)$port, $modeSync);
            if ($client->connect()) {
                $this->{$type}[$key] = $client;
                return $client;
            }
        } catch (\Throwable $exception) {
            $this->logger->log($exception->getMessage(), LOG_ERR);
        }
        return null;
    }

    /**
     * @param string $host
     * @param int    $port
     * @param bool   $modeSync
     * @return null|\swoole_client
     * @throws Exception\Config
     * @throws Exception\Exception
     */
    public function getClient(string $host, int $port, bool $modeSync): ?Client
    {
        $saslProvider = $this->judgeConnectionConfig();

        return new Client($host, $port, $this->config);
    }

    /**
     * @return SaslMechanism|null
     * @throws Exception
     * @throws Exception\Config
     */
    private function judgeConnectionConfig(): ?SaslMechanism
    {
        if ($this->config === null) {
            return null;
        }

        $plainConnections = [
            Config::SECURITY_PROTOCOL_PLAINTEXT,
            Config::SECURITY_PROTOCOL_SASL_PLAINTEXT,
        ];

        $saslConnections = [
            Config::SECURITY_PROTOCOL_SASL_SSL,
            Config::SECURITY_PROTOCOL_SASL_PLAINTEXT,
        ];

        $securityProtocol = $this->config->getSecurityProtocol();

        $this->config->setSslEnable(! in_array($securityProtocol, $plainConnections, true));

        if (in_array($securityProtocol, $saslConnections, true)) {
            return $this->getSaslMechanismProvider($this->config);
        }

        return null;
    }

    /**
     * @param Config $config
     * @return SaslMechanism
     * @throws Exception
     */
    private function getSaslMechanismProvider(Config $config): SaslMechanism
    {
        $mechanism  = $config->getSaslMechanism();
        $username   = $config->getSaslUsername();
        $password   = $config->getSaslPassword();

        switch ($mechanism) {
            case Config::SASL_MECHANISMS_PLAIN:
                return new Plain($username, $password);
                break;
            case Config::SASL_MECHANISMS_GSSAPI:
                break;
            case Config::SASL_MECHANISMS_SCRAM_SHA_512:
                break;
        }

        throw new Exception(sprintf('"%s" is an invalid SASL mechnism', $mechanism));
    }
}