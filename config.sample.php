<?php

return [
    "sourceURI"     => "mongodb://1.mongodb.rs0:27081,2.mongodb.rs0:27082/usercener?replicaSet=rs0",
    "destURI"       => "mongodb://localhost:27018",
    "flushCount"    => 10000,
    "db"            => "usercenter",
    "secretHost"    => "kms.cn-hangzhou.aliyuncs.com",
    "secretKeyName" => "usercenter-dev",
    "collections"   => [
        [
            "name" => "user_v2",
            "fields" => [
                [["e", "m"], "security"]
            ]
        ]
    ]

];
