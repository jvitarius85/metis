<?php
return [
    'source' => 'update_server',
    'github' => [
        'owner' => 'jvitarius85',
        'repo' => 'metis',
        'metadata_owner' => 'jvitarius85',
        'metadata_repo' => 'metis-modules',
        'ref' => 'stable',
        'metadata_ref' => 'main',
        'token' => '',
        'token_enc' => 'wZQgNwGmaXPuKjrO+PhobJzIxLqRJZ662iTiNaQUMNxL2p8o9AwVsHiXsRHSC12tVclypF19ziQXDg1K+zXxYJ9lMIOjGCuQekWZDXQO4/08+C6c7T/EcbYeefXX2zq+n1ZqQOyj9k2kRTQzmyqoUg==',
    ],
    'update_server' => [
        'base_url' => 'https://update.vitarius.org',
        'channel' => 'stable',
        'connect_timeout' => 10,
        'timeout' => 30,
        'server_public_key' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAro6lbrAhUA8ZJDXUiNoV
ioEiOzR6NDx7T1VjUILS5FmIxX/O3bASRrrTWNouycX3vUaPiMiz3sn1mlOQuDJe
mHmmwnnI8RACH0Oca5G+Nh7cipDsasxmnJV/3LD6LPIwaofcdximC9bQhyJFqQxm
SHPQzgQ0x53pzpoTF0PuESCZTMGsOc2cLLxQl2mLUNc8p38ZsTH0cOebiKus9Ku/
88ZRFnIbqbDhF27Ffk1VctxQzPxrQ6t6cqzcvjcEz3c4nTWCVykzZyAtLJZOjYfj
+gvv34W1+kxVCW+aoGQ/g4hKPIR9bDDUg5BsT92cdqO2gIEDwXmQ36S9ZC9dhU4E
rwIDAQAB
-----END PUBLIC KEY-----
PEM,
    ],
];
