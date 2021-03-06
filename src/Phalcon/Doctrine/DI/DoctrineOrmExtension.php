<?php

namespace VideoRecruit\Phalcon\Doctrine\DI;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Cache\CacheProvider;
use Kdyby\Doctrine\Configuration;
use Kdyby\Doctrine\Diagnostics\Panel;
use Kdyby\Doctrine\EntityManager;
use Kdyby\Doctrine\Mapping\AnnotationDriver;
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
	const PREFIX_CACHE = 'videorecruit.doctrine.cache.';

	const ENTITY_MANAGER = 'videorecruit.doctrine.entityManager';
	const CONNECTION = 'videorecruit.doctrine.connection';
	const CONFIGURATION = 'videorecruit.doctrine.configuration';
	const METADATA_DRIVER = 'videorecruit.doctrine.metadataDriver';
	const METADATA_READER = 'videorecruit.doctrine.metadataReader';
	const TRACY_PANEL = 'videorecruit.doctrine.diagnosticsPanel';

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
		'annotationCache' => 'default',
		'metadataCache' => 'default',
		'queryCache' => 'default',
		'resultCache' => 'default',
		'hydrationCache' => 'default',
		'classMetadataFactory' => 'Kdyby\Doctrine\Mapping\ClassMetadataFactory',
		'defaultRepositoryClassName' => 'Kdyby\Doctrine\EntityRepository',
		'repositoryFactoryClassName' => 'Doctrine\ORM\Repository\DefaultRepositoryFactory',
		'autoGenerateProxyClasses' => FALSE,
		'namingStrategy' => 'Doctrine\ORM\Mapping\UnderscoreNamingStrategy',
		'quoteStrategy' => 'Doctrine\ORM\Mapping\DefaultQuoteStrategy',
		'entityListenerResolver' => 'Doctrine\ORM\Mapping\DefaultEntityListenerResolver',
		'proxyDir' => NULL,
		'proxyNamespace' => 'Kdyby\GeneratedProxy',
		'metadata' => [],
	];

	/**
	 * @var array
	 */
	public $cacheDriverClasses = [
		'default' => 'Doctrine\Common\Cache\ArrayCache',
		'apc' => 'Doctrine\Common\Cache\ApcCache',
		'apcu' => 'Doctrine\Common\Cache\ApcuCache',
		'array' => 'Doctrine\Common\Cache\ArrayCache',
		'memcache' => 'Kdyby\DoctrineCache\MemcacheCache',
		'memcached' => 'VideoRecruit\Phalcon\Doctrine\Common\Cache\MemcachedCache',
		'redis' => 'Kdyby\DoctrineCache\RedisCache',
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

		$this->loadCache('annotations', $config['annotationCache']);
		$this->loadCache('metadata', $config['metadataCache']);
		$this->loadCache('query', $config['queryCache']);
		$this->loadCache('ormResult', $config['resultCache']);
		$this->loadCache('hydration', $config['hydrationCache']);

		$this->loadDebugTools();

		$this->loadMetadataDriver($config);
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
	 * @param string $suffix
	 * @param string $cache
	 * @throws InvalidArgumentException
	 */
	private function loadCache($suffix, $cache)
	{
		if (!is_string($cache)) {
			throw new InvalidArgumentException('Doctrine cache has to be specified by a string constant.');
		}

		$driver = $cache;
		$cacheServiceName = NULL;

		if (($pos = strpos($cache, '(')) !== FALSE) {
			$driver = substr($cache, 0, $pos);
			$cacheServiceName = substr($cache, $pos + 1, -1);
		}

		if (!array_key_exists($driver, $this->cacheDriverClasses)) {
			throw new InvalidArgumentException(sprintf('The `%s` cache driver not supported.', $driver));
		}

		$className = $this->cacheDriverClasses[$driver];
		$args = [];

		if ($cacheServiceName !== NULL) {
			$args[] = $this->di->get($cacheServiceName);
		}

		$serviceName = self::PREFIX_CACHE . $suffix;
		$this->di->setShared($serviceName, function () use ($className, $args, $serviceName) {
			/** @var CacheProvider $cache */
			$reflection = new \ReflectionClass($className);
			$cache = $reflection->newInstanceArgs($args);

			$ns = $serviceName;

			if (preg_match('~^(?P<projectRoot>.+)(?:\\\\|\\/)vendor(?:\\\\|\\/)videorecruit(?:\\\\|\\/)phalcon-doctrine(?:\\\\|\\/).+\\z~i', __DIR__, $m)) {
				$ns .= '_' . substr(md5($m['projectRoot']), 0, 8);
			}

			$cache->setNamespace($ns);

			return $cache;
		});
	}

	/**
	 * @param array $config
	 */
	private function loadMetadataDriver(array $config)
	{
		$this->di->setShared(self::METADATA_READER, function () {
			$annotationCache = $this->get(self::PREFIX_CACHE . 'annotations');

			return new CachedReader(new AnnotationReader(), $annotationCache);
		});

		$this->di->setShared(self::METADATA_DRIVER, function () use ($config) {
			$metadataCache = $this->get(self::PREFIX_CACHE . 'metadata');
			$annotationReader = $this->get(self::METADATA_READER);

			$driver = new AnnotationDriver($config['metadata'], $annotationReader, $metadataCache);

			AnnotationRegistry::registerLoader("class_exists");

			return $driver;
		});
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
			$metadataCache = $this->get(self::PREFIX_CACHE . 'metadata');
			$queryCache = $this->get(self::PREFIX_CACHE . 'query');
			$resultCache = $this->get(self::PREFIX_CACHE . 'ormResult');
			$hydrationCache = $this->get(self::PREFIX_CACHE . 'hydration');

			$metadataDriver = $this->get(self::METADATA_DRIVER);

			$configuration = new Configuration;
			$configuration->setMetadataCacheImpl($metadataCache);
			$configuration->setQueryCacheImpl($queryCache);
			$configuration->setResultCacheImpl($resultCache);
			$configuration->setHydrationCacheImpl($hydrationCache);
			$configuration->setMetadataDriverImpl($metadataDriver);
			$configuration->setClassMetadataFactoryName($config['classMetadataFactory']);
			$configuration->setDefaultRepositoryClassName($config['defaultRepositoryClassName']);
			$configuration->setRepositoryFactory(new $config['repositoryFactoryClassName']);
			$configuration->setProxyDir($config['proxyDir']);
			$configuration->setProxyNamespace($config['proxyNamespace']);
			$configuration->setAutoGenerateProxyClasses($config['autoGenerateProxyClasses']);
			$configuration->setNamingStrategy(new $config['namingStrategy']);
			$configuration->setQuoteStrategy(new $config['quoteStrategy']);
			$configuration->setEntityListenerResolver(new $config['entityListenerResolver']);

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
			$eventManagerConstant = 'VideoRecruit\Phalcon\Events\DI\EventsExtension::EVENT_MANAGER';
			$isEventManagerDefined = defined($eventManagerConstant);
			$eventManager = $isEventManagerDefined ? $this->get(constant($eventManagerConstant)) : NULL;

			$entityManager = EntityManager::create($connectionOptions, $this->get(self::CONFIGURATION), $eventManager);

			// bind entity manager to the tracy panel when tracy is available
			if ($this->has(self::TRACY_PANEL)) {
				/** @var Panel $panel */
				$panel = $this->get(self::TRACY_PANEL);
				$panel->bindEntityManager($entityManager);
				$panel->enableLogging();
			}

			return $entityManager;
		});
	}

	/**
	 * Register debugging service like a Tracy panel etc.
	 */
	private function loadDebugTools()
	{
		// register tracy services just in case tracy is available
		if (!interface_exists('Tracy\IBarPanel')) {
			return;
		}

		// register kdyby/doctrine tracy panel
		$this->di->setShared(self::TRACY_PANEL, function () {
			return new Panel;
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
		return Helpers::merge($config, $defaults);
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
