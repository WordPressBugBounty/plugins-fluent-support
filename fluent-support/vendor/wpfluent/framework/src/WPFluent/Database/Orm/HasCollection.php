<?php

namespace FluentSupport\Framework\Database\Orm;

/**
 * @template TCollection of \FluentSupport\Framework\Database\Orm\Collection
 */
trait HasCollection
{
    /**
     * Create a new Orm Collection instance.
     *
     * @param  array<array-key, \FluentSupport\Framework\Database\Orm\Model>  $models
     * @return TCollection
     */
    public function newCollection(array $models = [])
    {
        return new static::$collectionClass($models);
    }
}
