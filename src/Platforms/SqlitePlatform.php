<?php declare(strict_types = 1);

/**
 * This file is part of the Nextras\Dbal library.
 * @license    MIT
 * @link       https://github.com/nextras/dbal
 */

namespace Nextras\Dbal\Platforms;

use Nextras\Dbal\Connection;
use Nextras\Dbal\NotImplementedException;


class SqlitePlatform implements IPlatform
{
	/** @var Connection */
	private $connection;


	public function __construct(Connection $connection)
	{
		$this->connection = $connection;
	}


	public function getName(): string
	{
		return 'sqlite';
	}


	public function getTables(): array
	{
		throw new NotImplementedException();
	}


	public function getColumns(string $table): array
	{
		throw new NotImplementedException();
	}


	public function getForeignKeys(string $table): array
	{
		throw new NotImplementedException();
	}


	public function getPrimarySequenceName(string $table): ?string
	{
		throw new NotImplementedException();
	}


	public function isSupported(int $feature): bool
	{
		static $supported = [
			self::SUPPORT_QUERY_EXPLAIN => true,
		];
		return isset($supported[$feature]);
	}
}
