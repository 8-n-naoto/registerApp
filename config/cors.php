<?php

/*
| CORS（SEC-008）。画面（SPA）と API は同じオリジンで動くため、他のオリジンには許可を出さない。
| Laravel の既定（allowed_origins が '*'）のままだと、/api/* の応答に Access-Control-Allow-Origin: * が付き、
| 他のサイトの JS が Cookie 無しで API を呼んで応答を読める（Cookie を伴う要求は既定でも許可されないため実害は小さい。多層防御）。
| 別のオリジンから呼ぶ必要が出たときは、ここに 1 件ずつ足すこと（'*' にしない）
*/
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
