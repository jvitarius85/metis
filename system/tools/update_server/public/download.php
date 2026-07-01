<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

metis_update_server_app()->handleHttp();
