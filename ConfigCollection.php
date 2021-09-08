<?php


class ConfigCollection
{
    public $name;
    public $filter = [];
    public $fields = [];

    public function __construct(array $data)
    {
        foreach ($data as $key => $v) {
            $this->$key = $v;
        }
    }
}
