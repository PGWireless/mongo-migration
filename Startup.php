<?php

declare(ticks = 1);

use MongoDB\Driver\Query;
use MongoDB\BSON\Timestamp;
use MongoDB\Driver\Manager;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;

class Startup
{
    protected $typeMap = ['root' => 'array', 'document' => 'array'];

    /** @var Config */
    protected $config;

    private $startAt;

    public function __construct($configFile)
    {
        $ext = pathinfo($configFile, PATHINFO_EXTENSION);
        if ($ext === 'json') {
            $configData = file_get_contents($configFile);
            $configData = json_decode($configData, true);
        } elseif ($ext === 'php') {
            $configData = include_once $configFile;
        } else {
            throw new InvalidArgumentException('only json or php file supported');
        }

        $this->config = new Config($configData);

        $signalHandler = function () {
            $this->printLog('Exit');
            $now = new Timestamp(0, time());
            file_put_contents($this->config->timestampFile . '.end', serialize($now));
            exit(0);
        };

        pcntl_signal(SIGINT, $signalHandler);
        pcntl_signal(SIGTERM, $signalHandler);
    }

    /** @var Manager */
    private $sourceMgr;

    /** @var Manager */
    private $destMgr;

    public function run()
    {
        $this->startAt = time();
        $this->logTimestamp($this->startAt);

        $this->sourceMgr = new Manager($this->config->sourceURI);
        $this->destMgr = new Manager($this->config->destURI);

        $this->migrate();
    }

    protected function migrate()
    {
        foreach ($this->config->collections as $coll) {
            $ns = $this->config->db . '.' . $coll->name;
            $codec = $this->config->collectionCodec($coll->name);

            $this->printLog("Start migrate [$ns]");

            $query = new Query($coll->filter, ['sort' => ['$natural' => 1]]);
            $cursor = $this->sourceMgr->executeQuery($ns, $query);
            $cursor->setTypeMap($this->typeMap);

            $bulk = new BulkWrite();
            $count = 0;

            foreach ($cursor as $data) {
                $count++;
                $bulk->insert($codec->encode($data));

                if ($count % $this->config->flushCount === 0) {
                    $this->destMgr->executeBulkWrite($ns, $bulk);
                    $this->printLog("Flush $ns  $count");
                    $bulk = new BulkWrite();
                }
            }

            if ($count % $this->config->flushCount > 0) {
                $this->destMgr->executeBulkWrite($ns, $bulk);
                $this->printLog("Flush latest $ns $count");
            }

            $this->printLog('Create indexes');
            $this->createIndexes($coll->name);
        }

        $this->printLog('Finished migration, listen oplog');
        $this->listenOplog(); // blocking
    }

    protected function listenOplog()
    {
        $query = new Query([
            'ts' => ['$gt' => new Timestamp(0, $this->startAt)]
        ], [
            'sort'            => ['$natural' => 1],
            'awaitData'       => true,
            'noCursorTimeout' => true,
            'tailable'        => true,
        ]);

        $this->printLog('Begin listen oplog, waiting for find cursor......');

        $cursor = $this->sourceMgr->executeQuery('local.oplog.rs', $query);
        $cursor->setTypeMap($this->typeMap);

        $this->printLog('Find cursor in oplog');

        if (!$cursor instanceof Iterator) {
            $cursor = new IteratorIterator($cursor);
            $cursor->rewind();
        }

        $count = 0;
        while (true) {
            if ($cursor->valid()) {
                $doc = $cursor->current();
                $this->handleOplog($doc);
                $count++;

                if ($count % 5 === 0) {
                    $this->printLog('Handle oplog count ' . $count);
                }
            }

            $cursor->next();
        }
    }

    protected function printLog($content, $level = 'INFO')
    {
        printf("%s  [%s]    %s\n", date('m-d H:i:s'), $level, $content);
    }

    protected function logTimestamp($time)
    {
        $d = new Timestamp(0, $time);
        file_put_contents($this->config->timestampFile, serialize($d));
    }

    protected function handleOplog($data)
    {
        list($db, $collectionName) = explode('.', $data['ns'], 2);
        if ($db !== $this->config->db || !$this->config->collectionExist($collectionName)) {
            return;
        }
        if (isset($data['fromMigrate'])) {
            return;
        }
        $codec = $this->config->collectionCodec($collectionName);
        $bulk = new BulkWrite();
        switch ($data['op']) {
            case 'i':
                $bulk->insert($codec->encode($data['o']));
                break;
            case 'u':
                $bulk->update($data['o2'], $codec->encode($data['o']));
                break;
            case 'd':
                $bulk->delete($data['o']);
                break;
        }
        try {
            return $this->destMgr->executeBulkWrite($data['ns'], $bulk);
        } catch (Exception $e) {
            $info = sprintf("%s  %s  %s", $e->getCode(), $e->getMessage(), json_encode($data, 320));
            $this->printLog($info, 'WARNING');
            return;
        }
    }

    protected function createIndexes($collectionName)
    {
        $cmd = new Command(['listIndexes' => $collectionName]);
        $cursor = $this->sourceMgr->executeCommand($this->config->db, $cmd);
        $cursor->setTypeMap($this->typeMap);

        $createCmd = new Command([
            'createIndexes' => $collectionName,
            'indexes'       => iterator_to_array($cursor),
        ]);

        $this->destMgr->executeCommand($this->config->db, $createCmd);
    }
}
