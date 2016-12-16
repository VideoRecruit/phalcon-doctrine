<?php

namespace VideoRecruit\Phalcon\Doctrine\DI;

use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\Setup;
use Kdyby\Doctrine\Configuration;
use Kdyby\Doctrine\Connection;
use Kdyby\Doctrine\EntityManager;
use Nette\DI\Config\Helpers as ConfigHelpers;
use Phalcon\Config;
use Phalcon\DiInterface;
use VideoRecruit\Phalcon\Doctrine\InvalidArgumentException;

/**
 * Class DoctrineOrmExtension
 *
 * @package VideoRecruit\Phalcon\Doctrine\DI
 */
class DoctrineOrmExtension
{
	const ENTITY_MANAGER = 'videorecruit.doctrine.entityManager';
	const CONNECTION = 'videorecruit.doctrine.connection';
	const CONFIGURATION = 'videorecruit.doctrine.configuration';

	/**
	 * @var DiInterface
	 */
	private $di;

	/**
	 * @var array
	 */
	public $connectionDefaults = [
		'dbname' => NULL,
		'host' => '127.0.0.1',
		'port' => NULL,
		'user' => NULL,
		'password' => NULL,
		'charset' => 'UTF8',
		'driver' => 'pdo_mysql',
	];

	public $managerDefaults = [
		'metadataCache' => 'default',
		'queryCache' => 'default',
		'resultCache' => 'default',
		'hydrationCache' => 'default',
		'classMetadataFactory' => 'Kdyby\Doctrine\Mapping\ClassMetadataFactory',
		'defaultRepositoryClassName' => 'Kdyby\Doctrine\EntityRepository',
		'repositoryFactoryClassName' => 'Kdyby\Doctrine\RepositoryFactory',
		'queryBuilderClassName' => 'Kdyby\Doctrine\QueryBuilder',
		'autoGenerateProxyClasses' => FALSE,
		'namingStrategy' => 'Doctrine\ORM\Mapping\UnderscoreNamingStrategy',
		'quoteStrategy' => 'Doctrine\ORM\Mapping\DefaultQuoteStrategy',
		'entityListenerResolver' => 'Kdyby\Doctrine\Mapping\EntityListenerResolver',
		'proxyDir' => NULL,
		'proxyNamespace' => 'Kdyby\GeneratedProxy',
		'metadata' => [],
	];

	/**
	 * DoctrineOrmExtension constructor.
	 *
	 * @param DiInterface $di
	 * @param array|Config $config
	 * @throws InvalidArgumentException
	 */
	public function __construct(DiInterface $di, $config)
	{
		$this->di = $di;

		if ($config instanceof Config) {
			$config = $config->toArray();
		} elseif (!is_array($config)) {
			throw new InvalidArgumentException('Config has to be either an array or ' .
				'a configuration service name within the DI container.');
		}

		$config = $this->mergeConfigs($config, $this->managerDefaults + $this->connectionDefaults);

		$this->loadConfiguration($config);
		$this->loadEntityManager($config);
	}

	/**
	 * Register producer/consumer/RPCClient/RPCServer services into the DI container.
	 *
	 * @param DiInterface $di
	 * @param array|Config $config
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public static function register(DiInterface $di, $config)
	{
		return new self($di, $config);
	}

	/**
	 * @param array $config
	 * @throws InvalidArgumentException
	 */
	private function loadConfiguration(array $config)
	{
		if ($config['proxyDir'] === NULL) {
			throw new InvalidArgumentException('Proxy dir needs to be configured.');
		}

		$this->di->setShared(self::CONFIGURATION, function () use ($config) {
			$configuration = Setup::createAnnotationMetadataConfiguration(
				$config['metadata'],
				$config['autoGenerateProxyClasses'],
				$config['proxyDir'],
				NULL,
				FALSE
			);

			$configuration->setNamingStrategy(new UnderscoreNamingStrategy());
			$configuration->setQuoteStrategy(new DefaultQuoteStrategy());
			$configuration->setDefaultRepositoryClassName('Kdyby\Doctrine\EntityDao');
			$configuration->setProxyNamespace('Kdyby\GeneratedProxy');
			$configuration->setClassMetadataFactoryName('Kdyby\Doctrine\Mapping\ClassMetadataFactory');

			return $configuration;
		});
	}

	/**
	 * @param array $config
	 */
	private function loadEntityManager(array $config)
	{
		$connectionOptions = $this->resolveConfig($config, $this->connectionDefaults, $this->managerDefaults);

		$this->di->setShared(self::ENTITY_MANAGER, function () use ($connectionOptions) {
			return EntityManager::create($connectionOptions, $this->get(self::CONFIGURATION));
		});
	}

	/**
	 * Merge two configs.
	 *
	 * @param array $config
	 * @param array $defaults
	 * @return array
	 */
	private function mergeConfigs(array $config, array $defaults)
	{
		return ConfigHelpers::merge($config, $defaults);
	}

	/**
	 * @param array $provided
	 * @param array $defaults
	 * @param array $diff
	 * @return array
	 */
	private function resolveConfig(array $provided, array $defaults, array $diff = [])
	{
		return $this->mergeConfigs(
			array_diff_key($provided, array_diff_key($diff, $defaults)),
			$defaults
		);
	}
}
