<?php
namespace Sandstorm\OptimizedRedisCacheBackend;

/*
 * This file is part of the Neos.Cache package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Cache\Backend\AbstractBackend as IndependentAbstractBackend;
use Neos\Cache\Backend\RequireOnceFromValueTrait;
use Neos\Cache\Backend\TaggableBackendInterface;
use Neos\Cache\EnvironmentConfiguration;
use Neos\Cache\Exception as CacheException;

class OptimizedRedisCacheBackend extends IndependentAbstractBackend implements TaggableBackendInterface
{
    use RequireOnceFromValueTrait;

    const MIN_REDIS_VERSION = '2.6.0';

    /**
     * @var \Redis
     */
    protected $redis;

    /**
     * @var integer Cursor used for iterating over cache entries
     */
    protected $entryCursor = 0;

    /**
     * @var boolean
     */
    protected $frozen = null;

    /**
     * @var string
     */
    protected $hostname = '127.0.0.1';

    /**
     * @var integer
     */
    protected $port = 6379;

    /**
     * @var integer
     */
    protected $database = 0;

    /**
     * @var integer
     */
    protected $compressionLevel = 0;

    /**
     * Constructs this backend
     *
     * @param EnvironmentConfiguration $environmentConfiguration
     * @param array $options Configuration options - depends on the actual backend
     */
    public function __construct(EnvironmentConfiguration $environmentConfiguration, array $options)
    {
        parent::__construct($environmentConfiguration, $options);
        if ($this->redis === null) {
            $this->redis = $this->getRedisClient();
        }
    }

    /**
     * Saves data in the cache.
     *
     * @param string $entryIdentifier An identifier for this specific cache entry
     * @param string $data The data to be stored
     * @param array $tags Tags to associate with this cache entry. If the backend does not support tags, this option can be ignored.
     * @param integer $lifetime Lifetime of this cache entry in seconds. If NULL is specified, the default lifetime is used. "0" means unlimited lifetime.
     * @throws \RuntimeException
     * @return void
     * @api
     */
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null)
    {
        if ($lifetime === null) {
            $lifetime = $this->defaultLifetime;
        }

        $setOptions = [];
        if ($lifetime > 0) {
            $setOptions['ex'] = $lifetime;
        }

        $redisTags = array_reduce($tags, function($redisTags, $tag) use ($lifetime, $entryIdentifier) {
            $expire = $this->calculateExpires($this->buildKey('tag:' . $tag), $lifetime);
            $redisTags[] = ['key' => $this->buildKey('tag:' . $tag), 'value' => $entryIdentifier, 'expire' => $expire];

            $expire = $this->calculateExpires($this->buildKey('tags:' . $entryIdentifier), $lifetime);
            $redisTags[] = ['key' => $this->buildKey('tags:' . $entryIdentifier), 'value' => $tag, 'expire' => $expire];
            return $redisTags;
        }, []);

        $this->redis->multi();
        $result = $this->redis->set($this->buildKey('entry:' . $entryIdentifier), $this->compress($data), $setOptions);
        if (!$result instanceof \Redis) {
            $this->verifyRedisVersionIsSupported();
        }
        foreach ($redisTags as $tag) {
            $this->redis->sAdd($tag['key'], $tag['value']);
            $this->redis->expire($tag['key'], $tag['expire']);
        }
        $this->redis->exec();
    }

    /**
     * Loads data from the cache.
     *
     * @param string $entryIdentifier An identifier which describes the cache entry to load
     * @return mixed The cache entry's content as a string or FALSE if the cache entry could not be loaded
     * @api
     */
    public function get($entryIdentifier)
    {
        return $this->uncompress($this->redis->get($this->buildKey('entry:' . $entryIdentifier)));
    }

    /**
     * Checks if a cache entry with the specified identifier exists.
     *
     * @param string $entryIdentifier An identifier specifying the cache entry
     * @return boolean TRUE if such an entry exists, FALSE if not
     * @api
     */
    public function has($entryIdentifier)
    {
        return $this->redis->exists($this->buildKey('entry:' . $entryIdentifier));
    }

    /**
     * Removes all cache entries matching the specified identifier.
     * Usually this only affects one entry but if - for what reason ever -
     * old entries for the identifier still exist, they are removed as well.
     *
     * @param string $entryIdentifier Specifies the cache entry to remove
     * @throws \RuntimeException
     * @return boolean TRUE if (at least) an entry could be removed or FALSE if no entry was found
     * @api
     */
    public function remove($entryIdentifier)
    {
        do {
            $tagsKey = $this->buildKey('tags:' . $entryIdentifier);
            $this->redis->watch($tagsKey);
            $tags = $this->redis->sMembers($tagsKey);
            $this->redis->multi();
            $this->redis->del($this->buildKey('entry:' . $entryIdentifier));
            foreach ($tags as $tag) {
                $this->redis->sRem($this->buildKey('tag:' . $tag), $entryIdentifier);
            }
            $this->redis->del($this->buildKey('tags:' . $entryIdentifier));
            $result = $this->redis->exec();
        } while ($result === false);

        return true;
    }

    /**
     * Removes all cache entries of this cache
     *
     * The flush method will use the EVAL command to flush all entries and tags for this cache
     * in an atomic way.
     *
     * @throws \RuntimeException
     * @return void
     * @api
     */
    public function flush()
    {
        // language=lua
        $script = "
        local keys = redis.call('KEYS', ARGV[1] .. '*')
		for k1,key in ipairs(keys) do
			redis.call('DEL', key)
		end
		";
        $this->redis->eval($script, [$this->buildKey('')], 0);

        $this->frozen = null;
    }

    /**
     * This backend does not need an externally triggered garbage collection
     *
     * @return void
     * @api
     */
    public function collectGarbage()
    {
    }

    /**
     * @param $identifier
     * @return string
     */
    private function buildKey($identifier)
    {
        return $this->cacheIdentifier . ':' . $identifier;
    }

    /**
     * Calculate the max lifetime for a tag
     *
     * @param string $tag
     * @param int $lifetime
     * @return int
     */
    private function calculateExpires($tag, $lifetime)
    {
        $ttl = $this->redis->ttl($tag);
        if ($ttl === -1 || $lifetime === self::UNLIMITED_LIFETIME) {
            return 0;
        } else {
            return max($ttl, $lifetime);
        }
    }

    /**
     * Removes all cache entries of this cache which are tagged by the specified tag.
     *
     * @param string $tag The tag the entries must have
     * @throws \RuntimeException
     * @return integer The number of entries which have been affected by this flush
     * @api
     */
    public function flushByTag($tag)
    {
        // language=lua
        $script = "
		local entries = redis.call('SMEMBERS', KEYS[1])
		for k1,entryIdentifier in ipairs(entries) do
			redis.call('DEL', ARGV[1]..'entry:'..entryIdentifier)
			redis.call('DEL', ARGV[1]..'tags:'..entryIdentifier)
		end
		redis.call('DEL', KEYS[1])
		return #entries
		";
        $count = $this->redis->eval($script, [$this->buildKey('tag:' . $tag), $this->buildKey('')], 1);
        return $count;
    }

    /**
     * Finds and returns all cache entry identifiers which are tagged by the
     * specified tag.
     *
     * @param string $tag The tag to search for
     * @return array An array with identifiers of all matching entries. An empty array if no entries matched
     * @api
     */
    public function findIdentifiersByTag($tag)
    {
        return $this->redis->sMembers($this->buildKey('tag:' . $tag));
    }

    /**
     * Sets the default lifetime for this cache backend
     *
     * @param integer $lifetime Default lifetime of this cache backend in seconds. If NULL is specified, the default lifetime is used. 0 means unlimited lifetime.
     * @return void
     * @api
     */
    public function setDefaultLifetime($lifetime)
    {
        $this->defaultLifetime = $lifetime;
    }

    /**
     * Sets the hostname or the socket of the Redis server
     *
     * @param string $hostname Hostname of the Redis server
     * @api
     */
    public function setHostname($hostname)
    {
        $this->hostname = $hostname;
    }

    /**
     * Sets the port of the Redis server.
     *
     * Leave this empty if you want to connect to a socket
     *
     * @param integer $port Port of the Redis server
     * @api
     */
    public function setPort($port)
    {
        $this->port = $port;
    }

    /**
     * Sets the database that will be used for this backend
     *
     * @param integer $database Database that will be used
     * @api
     */
    public function setDatabase($database)
    {
        $this->database = $database;
    }

    /**
     * @param integer $compressionLevel
     */
    public function setCompressionLevel($compressionLevel)
    {
        $this->compressionLevel = $compressionLevel;
    }

    /**
     * @param \Redis $redis
     */
    public function setRedis(\Redis $redis = null)
    {
        $this->redis = $redis;
    }

    /**
     * @param string $value
     * @return string
     */
    private function uncompress($value)
    {
        if (empty($value)) {
            return $value;
        }
        return $this->useCompression() ? gzdecode($value) : $value;
    }

    /**
     * @param string $value
     * @return string
     */
    private function compress($value)
    {
        return $this->useCompression() ? gzencode($value, $this->compressionLevel) : $value;
    }

    /**
     * @return boolean
     */
    private function useCompression()
    {
        return $this->compressionLevel > 0;
    }

    /**
     * @return \Redis
     * @throws CacheException
     */
    private function getRedisClient()
    {
        if (strpos($this->hostname, '/') !== false) {
            $this->port = null;
        }
        $redis = new \Redis();
        if (!$redis->connect($this->hostname, $this->port)) {
            throw new CacheException('Could not connect to Redis.', 1391972021);
        }
        $redis->select($this->database);
        return $redis;
    }

    /**
     * @return void
     * @throws CacheException
     */
    protected function verifyRedisVersionIsSupported()
    {
        // Redis client could be in multi mode, discard for checking the version
        $this->redis->discard();

        $serverInfo = $this->redis->info();
        if (!isset($serverInfo['redis_version'])) {
            throw new CacheException('Unsupported Redis version, the Redis cache backend needs at least version ' . self::MIN_REDIS_VERSION, 1438251553);
        }
        if (version_compare($serverInfo['redis_version'], self::MIN_REDIS_VERSION) < 0) {
            throw new CacheException('Redis version ' . $serverInfo['redis_version'] . ' not supported, the Redis cache backend needs at least version ' . self::MIN_REDIS_VERSION, 1438251628);
        }
    }
}
