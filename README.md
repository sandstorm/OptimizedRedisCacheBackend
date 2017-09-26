# Optimized Redis Cache Backend

* for usage as Content Cache
* needed if the same tags apply to many elements

## Usage


in Caches.yaml, do:

```yaml
Neos_Fusion_Content:
  backend: Sandstorm\OptimizedRedisCacheBackend\OptimizedRedisCacheBackend
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