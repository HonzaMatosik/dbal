<?php

use Nextras\Dbal\Connection;

return function (Connection $connection, $config) {
	$connection->disconnect();
	@unlink($config['filename']);
	$connection->connect();
};
