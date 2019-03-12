<?php declare(strict_types = 1);

/**
 * This file is part of the Nextras\Dbal library.
 * @license    MIT
 * @link       https://github.com/nextras/dbal
 */

namespace Nextras\Dbal\Drivers\Sqlite3;

use DateTimeZone;
use Nextras\Dbal\Connection;
use Nextras\Dbal\ConnectionException;
use Nextras\Dbal\DriverException;
use Nextras\Dbal\Drivers\IDriver;
use Nextras\Dbal\ForeignKeyConstraintViolationException;
use Nextras\Dbal\InvalidArgumentException;
use Nextras\Dbal\InvalidStateException;
use Nextras\Dbal\NotNullConstraintViolationException;
use Nextras\Dbal\NotSupportedException;
use Nextras\Dbal\Platforms\IPlatform;
use Nextras\Dbal\Platforms\SqlitePlatform;
use Nextras\Dbal\QueryException;
use Nextras\Dbal\Result\Result;
use Nextras\Dbal\UniqueConstraintViolationException;
use SQLite3;


class Sqlite3Driver implements IDriver
{
	/** @var SQLite3|null */
	private $connection;

	/** @var DateTimeZone Timezone for database connection. */
	private $connectionTz;

	/** @var callable */
	private $onQueryCallback;

	/** @var int */
	private $affectedRows = 0;

	/** @var float */
	private $timeTaken = 0.0;


	public function __destruct()
	{
		$this->disconnect();
	}


	public function connect(array $params, callable $onQueryCallback)
	{
		$this->onQueryCallback = $onQueryCallback;

		try {
			$this->connection = new SQLite3(
				$params['filename'],
				(int) $params['flags'] ?? SQLITE3_OPEN_READWRITE
			);
		} catch (\Exception $e) {
			throw $this->createException($e->getMessage(), $e->getCode(), null);
		}

		$this->loggedQuery('PRAGMA foreign_keys = ON');
	}


	public function disconnect()
	{
		if ($this->connection) {
			$this->connection->close();
			$this->connection = null;
		}
	}


	public function isConnected(): bool
	{
		return $this->connection !== null;
	}


	public function getResourceHandle()
	{
		return $this->connection;
	}


	public function query(string $query): Result
	{
		assert($this->connection !== null);

		$time = microtime(true);
		$result = @$this->connection->query($query);
		$this->timeTaken = microtime(true) - $time;

		$error = $this->connection->lastErrorCode();
		if ($error !== 0 || $result === false) {
			$errorMessage = $this->connection->lastErrorMsg();
			throw $this->createException($errorMessage, $error, $query);
		}

		$this->affectedRows = $this->connection->changes();
		return new Result(new Sqlite3ResultAdapter($result), $this);
	}


	public function getLastInsertedId(string $sequenceName = null)
	{
		assert($this->connection !== null);
		return $this->connection->lastInsertRowID();
	}


	public function getAffectedRows(): int
	{
		return $this->affectedRows;
	}


	public function getQueryElapsedTime(): float
	{
		return $this->timeTaken;
	}


	public function createPlatform(Connection $connection): IPlatform
	{
		return new SqlitePlatform($connection);
	}


	public function getServerVersion(): string
	{
		assert($this->connection !== null);
		return $this->connection->version()['versionString'];
	}


	public function ping(): bool
	{
		// no-op
		return true;
	}


	public function setTransactionIsolationLevel(int $level)
	{
		static $levels = [
			Connection::TRANSACTION_READ_UNCOMMITTED => '0',
			Connection::TRANSACTION_READ_COMMITTED => '1',
			Connection::TRANSACTION_REPEATABLE_READ => '1',
			Connection::TRANSACTION_SERIALIZABLE => '1',
		];
		if (isset($levels[$level])) {
			throw new NotSupportedException("Unsupported transation level $level");
		}
		$this->loggedQuery("PRAGMA read_uncommitted = {$levels[$level]}");
	}


	public function beginTransaction(): void
	{
		$this->loggedQuery('BEGIN');
	}


	public function commitTransaction(): void
	{
		$this->loggedQuery('COMMIT');
	}


	public function rollbackTransaction(): void
	{
		$this->loggedQuery('ROLLBACK');
	}


	public function createSavepoint(string $name): void
	{
		$this->loggedQuery('SAVEPOINT ' . $this->convertIdentifierToSql($name));
	}


	public function releaseSavepoint(string $name): void
	{
		$this->loggedQuery('RELEASE SAVEPOINT ' . $this->convertIdentifierToSql($name));
	}


	public function rollbackSavepoint(string $name): void
	{
		$this->loggedQuery('ROLLBACK TO SAVEPOINT ' . $this->convertIdentifierToSql($name));
	}


	public function convertToPhp(string $value, $nativeType)
	{
		throw new InvalidStateException();
	}


	public function convertStringToSql(string $value): string
	{
		assert($this->connection !== null);
		return "'" . $this->connection->escapeString($value) . "'";
	}


	public function convertJsonToSql($value): string
	{
		$encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
		if (json_last_error()) {
			throw new InvalidArgumentException('JSON Encode Error: ' . json_last_error_msg());
		}
		assert(is_string($encoded));
		return $this->convertStringToSql($encoded);
	}


	public function convertLikeToSql(string $value, int $mode)
	{
		$value = addcslashes($this->connection->escapeString($value), '\\%_');
		return ($mode <= 0 ? "'%" : "'") . $value . ($mode >= 0 ? "%'" : "'") . " ESCAPE '\\'";
	}


	public function convertBoolToSql(bool $value): string
	{
		return $value ? '1' : '0';
	}


	public function convertIdentifierToSql(string $value): string
	{
		assert($this->connection !== null);
		$parts = explode('.', $value);
		foreach ($parts as &$part) {
			if ($part !== '*') {
				$part = strtr($part, '[]', '');
			}
		}
		return implode('.', $parts);
	}


	public function convertDateTimeToSql(\DateTimeInterface $value): string
	{
		assert($value instanceof \DateTime || $value instanceof \DateTimeImmutable);
		if ($value->getTimezone()->getName() !== $this->connectionTz->getName()) {
			if ($value instanceof \DateTimeImmutable) {
				$value = $value->setTimezone($this->connectionTz);
			} else {
				$value = clone $value;
				$value->setTimezone($this->connectionTz);
			}
		}
		return "'" . $value->format('Y-m-d H:i:s.u') . "'::timestamptz";
	}


	public function convertDateTimeSimpleToSql(\DateTimeInterface $value): string
	{
		return "'" . $value->format('Y-m-d H:i:s.u') . "'::timestamp";
	}


	public function convertDateIntervalToSql(\DateInterval $value): string
	{
		return $value->format('P%yY%mM%dDT%hH%iM%sS');
	}


	public function convertBlobToSql(string $value): string
	{
		assert($this->connection !== null);
		return "X'" . bin2hex($value) . "'";
	}


	public function modifyLimitQuery(string $query, ?int $limit, ?int $offset): string
	{
		if ($limit === null && $offset !== null) {
			return $query . ' LIMIT -1 OFFSET ' . $offset;
		}
		if ($limit !== null) {
			$query .= ' LIMIT ' . $limit;
		}
		if ($offset !== null) {
			$query .= ' OFFSET ' . $offset;
		}
		return $query;
	}


	/**
	 * This method is based on Doctrine\DBAL project.
	 * @link www.doctrine-project.org
	 */
	protected function createException($error, $errorNo, $query = null)
	{
		if (stripos($error, 'foreign key constraint failed') !== false) {
			return new ForeignKeyConstraintViolationException($error, $errorNo, null, null, $query);
		} elseif (strpos($error, 'must be unique') !== false ||
			strpos($error, 'is not unique') !== false ||
			strpos($error, 'are not unique') !== false ||
			strpos($error, 'UNIQUE constraint failed') !== false
		) {
			return new UniqueConstraintViolationException($error, $errorNo, null, null, $query);
		} elseif (strpos($error, 'may not be NULL') !== false ||
			strpos($error, 'NOT NULL constraint failed') !== false
		) {
			return new NotNullConstraintViolationException($error, $errorNo, null, null, $query);
		} elseif (strpos($error, 'unable to open database file') !== false) {
			return new ConnectionException($error, $errorNo, null);
		} elseif ($query !== null) {
			return new QueryException($error, $errorNo, null, null, $query);
		} else {
			return new DriverException($error, $errorNo, null);
		}
	}


	protected function loggedQuery(string $sql)
	{
		try {
			$result = $this->query($sql);
			($this->onQueryCallback)($sql, $this->getQueryElapsedTime(), $result, null);
			return $result;
		} catch (DriverException $exception) {
			($this->onQueryCallback)($sql, $this->getQueryElapsedTime(), null, $exception);
			throw $exception;
		}
	}
}
