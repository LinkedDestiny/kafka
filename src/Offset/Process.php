<?php
/**
 * Created by PhpStorm.
 * User: Manlin
 * Date: 2019/9/19
 * Time: 上午10:28
 */
namespace EasySwoole\Kafka\Offset;

use EasySwoole\Component\Singleton;
use EasySwoole\Kafka\BaseProcess;
use EasySwoole\Kafka\Config\ConsumerConfig;
use EasySwoole\Kafka\Config\OffsetConfig;
use EasySwoole\Kafka\Consumer\Assignment;
use EasySwoole\Kafka\Protocol;

class Process extends BaseProcess
{
    use Singleton;

    /**
     * Process constructor.
     * @throws \EasySwoole\Kafka\Exception\Exception
     */
    public function __construct()
    {
        parent::__construct();

        $this->config = $this->getConfig();
        Protocol::init($this->config->getBrokerVersion());
        $this->getBroker()->setConfig($this->config);

        $this->syncMeta();
    }

    /**
     * @return array
     * @throws \EasySwoole\Kafka\Exception\ConnectionException
     * @throws \EasySwoole\Kafka\Exception\Exception
     */
    public function listOffset(): array
    {
        $broker     = $this->getBroker();
        $topics     = $broker->getTopics();
        $topicList  = $this->config->getTopics();

        $connect = $broker->getMetaConnect($broker->getGroupBrokerId());

        if ($connect === null) {
            return [];
        }

        $data = [];
        foreach ($topics as $topic => $partitions) {
            foreach ($topicList as $topicName) {
                if ($topic !== $topicName) {
                    continue;
                }

                $item = [
                    'topic_name' => $topic,
                    'partitions' => [],
                ];

                foreach ($partitions as $partId => $leader) {
                    $item['partitions'][] = [
                        'partition_id' => $partId,
                        'offset' => 100,
                        'time' =>  -1,
                    ];
                }
                $data[] = $item;
            }
        }

        $params = [
            'replica_id' => -1,
            'data'       => $data,
        ];

        $this->logger->log('listOffset start, params:' . json_encode($params));
        $requestData = Protocol::encode(Protocol::OFFSET_REQUEST, $params);
        $data = $connect->send($requestData);
        $ret = Protocol::decode(Protocol::OFFSET_REQUEST, substr($data, 8));

        return $ret;
    }

    /**
     * @return array
     * @throws \EasySwoole\Kafka\Exception\ConnectionException
     * @throws \EasySwoole\Kafka\Exception\Exception
     */
    public function fetchOffset(): array
    {
        $broker     = $this->getBroker();
        $topics     = $broker->getTopics();
        $topicList  = $this->config->getTopics();

        $connect = $broker->getMetaConnect($broker->getGroupBrokerId());

        if ($connect === null) {
            return [];
        }

        $data   = [];

        foreach ($topics as $topic => $partitions) {
            foreach ($topicList as $topicName) {
                if ($topic !== $topicName) {
                    continue;
                }
                $partition          = [];

                if (isset($data[$topic]['partitions'])) {
                    $partition      = $data[$topic]['partitions'];
                }

                foreach ($partitions as $partId => $leader) {
                    $partition[]    = $partId;
                }
                $data[$topic]['partitions'] = $partition;
                $data[$topic]['topic_name'] = $topicName;
            }
        }

        $params = [
            'group_id' => $this->config->getGroupId(),
            'data'     => $data,
        ];

        $this->logger->log('Fetch Offset start, params:' . json_encode($params));
        $requestData    = Protocol::encode(Protocol::OFFSET_FETCH_REQUEST, $params);
        $data           = $connect->send($requestData);
        $ret            = Protocol::decode(Protocol::OFFSET_FETCH_REQUEST, substr($data, 8));

        return $ret;
    }

    /**
     * @param array $commitOffsets
     * @return array
     * @throws \EasySwoole\Kafka\Exception\ConnectionException
     * @throws \EasySwoole\Kafka\Exception\Exception
     */
    public function commit(array $commitOffsets): array
    {
        $broker     = $this->getBroker();
        $data = [];

        $connect = $broker->getMetaConnect($broker->getGroupBrokerId());

        foreach ($commitOffsets as $topicName => $topics) {
            $partitions = [];
            foreach ($topics as $partId => $offset) {
                $partitions[$partId]['partition']    = $partId;
                $partitions[$partId]['offset']       = $offset;
            }

            $data[$topicName]['partitions'] = $partitions;
            $data[$topicName]['topic_name'] = $topicName;
        }

        $params = [
            'group_id'  => ConsumerConfig::getInstance()->getGroupId(),
            'generation_id' => $this->getAssignment()->getGenerationId(),
            'member_id' => $this->getAssignment()->getMemberId(),
            'data'      => $data,
        ];

        $this->logger->log('Commit current fetch offset start, params:' . json_encode($params));
        $requestData = Protocol::encode(Protocol::OFFSET_COMMIT_REQUEST, $params);
        $data = $connect->send($requestData);
        $ret = Protocol::decode(Protocol::OFFSET_COMMIT_REQUEST, substr($data, 8));

        return $ret;
    }

    protected function getConfig(): OffsetConfig
    {
        return OffsetConfig::getInstance();
    }

    protected function getAssignment(): Assignment
    {
        return Assignment::getInstance();
    }
}