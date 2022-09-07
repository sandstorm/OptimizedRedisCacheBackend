# Optimized Redis Cache Backend

## OBSOLETE Since Neos 8.0

NOTE: OptimizedRedisCacheBackend is OBSOLETE for Neos 8.0 and newer, as its functionality has been [integrated into the core](https://github.com/neos/flow-development-collection/pull/2721), and improved further on. Thanks Sebastian Helzle and everybody involved for that :-) :-)

### How to migrate from OptimizedRedisCacheBackend to Neos 8.x

The functionality of `Sandstorm\OptimizedRedisCacheBackend\OptimizedRedisCacheBackend` has been integrated and further improved into the core class `Neos\Cache\Backend\RedisBackend`.

Thus, to update, you need to go to your `Caches.yaml` and replace all occurences of `Sandstorm\OptimizedRedisCacheBackend\OptimizedRedisCacheBackend` with `Neos\Cache\Backend\RedisBackend`.


-----
-----
-----
-----

## Original Readme for Neos < 8.0

* for usage as Content Cache
* needed if the same tags apply to many elements

## Installation

```bash
composer require sandstorm/optimizedrediscachebackend
```

Version compatibility:

- >= 1.1.4: Support for `Neos.Flow.cache.applicationIdentifier` in `Settings.yaml` - this is only supported with the following
  Flow versions, because we rely on [this core bugfix](https://github.com/neos/flow-development-collection/pull/2622/commits/98af394ae947c59f851ac260449b293ccfe448b0):

  - 6.3.14 or newer (and < 7.0)
  - 7.0.11 or newer (and < 7.1)
  - 7.1.5 or newer (and < 7.2)
  - 7.2.2 or newer
  - 7.3.0 or newer (and all future versions)

- <= 1.1.3 - Flow 6 and 7 (all versions)

## Usage

in Caches.yaml, do:

```yaml

Neos_Fusion_Content:
  backend: Sandstorm\OptimizedRedisCacheBackend\OptimizedRedisCacheBackend
  
Neos_Media_ImageSize:
  backend: Sandstorm\OptimizedRedisCacheBackend\OptimizedRedisCacheBackend
  
Flow_Mvc_Routing_Route:
  backend: Sandstorm\OptimizedRedisCacheBackend\OptimizedRedisCacheBackend
  
Flow_Mvc_Routing_Resolve:
  backend: Sandstorm\OptimizedRedisCacheBackend\OptimizedRedisCacheBackend

```

If you are using a symlink-based deployment, you should set the `flushRedisDatabaseCompletely` option as follows to remove
stale data from previous releases (and ensure you use one redis DB per cache):

```yaml
Neos_Fusion_Content:
  backend: Sandstorm\OptimizedRedisCacheBackend\OptimizedRedisCacheBackend
  backendOptions:
    flushRedisDatabaseCompletely: true
```

## Problems with the current Redis cache implementation

* When doing a flushByTag, we do the following (pseudocode):
  - *iterate* over all entries for this tag
    - "unlink" the entry from all tags this entry is additionally tagged with
      - *iterate* over all tags for the current entry
    - remove the entry
    - remove the entry->tags relation
  - remove the tag

* You see, we have a twicely-nested iteration going on here. In a big customer
  project, the inner loop is sometimes called over 100 000 times for a single
  tag flush; making the Redis server unresponsive while the script runs.


## Optimizations in this cache backend

### Optimized FlushByTag

When doing flushByTag, we just do the following:

```
- *iterate* over all entries for this tag
  - remove the entry
  - remove the entry->tags relation
- remove the tag
```

**this means that the *other* tags for the cache entries are not removed**.
  
- This leaves some "cruft" in the Redis cache; namely tags where their assigned
  entries are already gone. This is not a big problem, as the entries might have also
  just timed out (because they have a TTL). 

- The only scenario I see where this might become a problem is:
  - entry1 with tags A,B is inserted
  - flushByTag(A) is done -> entry1 is removed, tag A is removed, tag B still exists, pointing to the (non-existing) entry1
  - entry1 with tag A is inserted **(Tag associations have changed!)**
  - -> there still is a connection between tag B (which has not been removed) and entry1.

- When relying on findIdentifiersByTag() of TaggableBackendInterface, this might be a problem -- but this is never
  used in the content cache.

- Aside from findIdentifiersByTag(), tags are only used for *clearing* certain parts of the cache. Thus, a stale
  edge from Tag B -> entry1 might lead to entry1 *being flushed too often*. **We assume that this does not matter much for the content
  cache.**

- **If the user wants to remove the cruft completely, he should call flush(), which completely resets the cache.**

- The whole scenario above is not so likely, because cache tags are mostly controlled by Fusion; and as long as
  this does not change, the cache tags stay stable.

### Removal of entries list of standard cache backend

The default Redis cache backend as an `entries` list storing all cache entries (needed for iteration on legacy
Redis versions). Finding elements in the list is O(n) in the number of list entries.

Today, one could use KEYS for iterating over entries; but our content cache does not rely on IterableBackendInterface.
Thus, we just remove the `entries` altogether. 

### More simple flush() implementation

For implementing flush(), we just iterate over the relevant part of the keyspace and remove all elements. Before, this
was done through a similar logic as explained in FlushByTag, because the Redis Backend was freezable (but we do not need
this for Neos Content Cache).
