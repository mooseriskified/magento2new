<?php

namespace Riskified\Decider\Cron;

class Submission
{
    const MAX_ATTEMPTS = 7;
    const INTERVAL_BASE = 3;
    const BATCH_SIZE = 10;

    /**
     * @var \Riskified\Decider\Model\Queue
     */
    protected $queue;
    /**
     * @var \Riskified\Decider\Logger\Order
     */
    protected $logger;
    /**
     * @var \Riskified\Decider\Api\Order
     */
    protected $api;
    /**
     * @var \Riskified\Decider\Api\Config
     */
    protected $config;
    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $date;
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    protected $orderFactory;

    /**
     * Submission constructor.
     *
     * @param \Riskified\Decider\Model\Queue $queue
     * @param \Riskified\Decider\Api\Order $api
     * @param \Riskified\Decider\Api\Config $apiConfig
     * @param \Riskified\Decider\Logger\Order $logger
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderFactory
     */
    public function __construct(
        \Riskified\Decider\Model\Queue $queue,
        \Riskified\Decider\Api\Order $api,
        \Riskified\Decider\Api\Config $apiConfig,
        \Riskified\Decider\Logger\Order $logger,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderFactory

    ) {
        $this->queue = $queue;
        $this->api = $api;
        $this->logger = $logger;
        $this->date = $date;
        $this->config = $apiConfig;
        $this->orderFactory = $orderFactory;
    }

    /**
     * Execute.
     */
    public function execute()
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $this->logger->addInfo("Retrying failed order submissions");

        $retries = $this->queue->getCollection()
            ->addfieldtofilter(
                'attempts',
                array(
                    array('lt' => self::MAX_ATTEMPTS)
                )
            );

        $select = $retries->getSelect();
        $adapter = $select->getAdapter();
        $select
            ->where(sprintf(
                "TIMESTAMPDIFF(MINUTE, `updated_at`, %s) - POW(%s, attempts) > 0",
                $adapter->quote($this->date->gmtDate()),
                $adapter->quote(self::INTERVAL_BASE)
            ))
            ->order('updated_at ASC')
            ->limit(self::BATCH_SIZE);

        $mapperOrder = array();
        $orderIds = array();

        foreach ($retries as $retry) {
            $orderIds[] = $retry->getOrderId();
            $mapperOrder[$retry->getOrderId()] = $retry;
        }
        $collection = $this->orderFactory->create()->addFieldToFilter('entity_id', array('in' => $orderIds));

        foreach ($collection as $order) {
            $this->logger->addInfo("Retrying order " . $order->getId());

            try {
                $this->api->post($order, $mapperOrder[$order->getId()]->getAction());
            } catch (\Exception $e) {
                $this->logger->addCritical($e->getMessage());

                $mapperOrder[$order->getId()]
                    ->setLastError("Exception Message: " . $e->getMessage())
                    ->setAttempts($mapperOrder[$order->getId()]->getAttempts() + 1)
                    ->setUpdatedAt($this->date->gmtDate())
                    ->save();
            }
        }

        $this->logger->addInfo("Done retrying failed order submissions");
    }
}
