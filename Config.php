<?php

use DBStorage\Codec\AliyunKMSService;
use DBStorage\Codec\ProjectConfig;
use DBStorage\Codec\CodecInterface;
use DBStorage\Codec\FixedSecretKey;

class Config
{
    public $sourceURI;
    public $destURI;
    public $flushCount = 10000;
    public $db;
    public $secretHost;
    public $secretKeyName;
    /**
     * collections
     *
     * @var ConfigCollection[]
     */
    public $collections = [];

    public $timestampFile = 'timestamp.bin';

    private $_project;

    public function __construct(array $data)
    {
        foreach ($data as $key => $v) {
            $this->$key = $v;
        }
        $collections = $this->collections;
        $this->collections = [];

        if (isset($_SERVER['DEBUG_SECRET_KEY'])) {
            $secretGetter = new FixedSecretKey($_SERVER['DEBUG_SECRET_KEY']);
        } else {
            $secretGetter = new AliyunKMSService($this->secretHost);
        }

        $project = new ProjectConfig($this->secretKeyName, $secretGetter);

        foreach ($collections as $coll) {
             /** @var array $coll */
            $this->collections[] = new ConfigCollection($coll);

            if (!empty($coll['fields'])) {
                $project->setCollection($coll['name'], $project->makeCollectionConfig(
                    $project->makeFields($coll['fields'])
                ));
            }
        }

        $this->_project = $project;
    }

    /**
     * get collection codec
     *
     * @param string $collectionName
     * @return CodecInterface
     */
    public function collectionCodec($collectionName)
    {
        return $this->_project->getCodec($collectionName);
    }

    public function collectionExist($collectionName)
    {
        foreach ($this->collections as $coll) {
            if ($coll->name === $collectionName) {
                return true;
            }
        }
        return false;
    }
}
